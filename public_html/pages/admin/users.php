<?php
$me = require_admin();
ensure_users_auth_schema();
if (function_exists('ensure_account_schema')) {
    ensure_account_schema();
}

/**
 * Stash Users form fields after validation failure (never store password).
 *
 * @param array<string,mixed> $fields
 */
function users_stash_form_draft(int $id, array $fields): void
{
    unset($fields['password'], $fields['_csrf'], $fields['action']);
    $_SESSION['users_form_draft'] = [
        'id' => $id,
        'fields' => $fields,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function users_take_form_draft(int $id): ?array
{
    $draft = $_SESSION['users_form_draft'] ?? null;
    unset($_SESSION['users_form_draft']);
    if (!is_array($draft) || (int) ($draft['id'] ?? -1) !== $id) {
        return null;
    }
    $fields = $draft['fields'] ?? null;
    return is_array($fields) ? $fields : null;
}

$revealedTemp = null;
if (!empty($_SESSION['revealed_temp_password']) && is_array($_SESSION['revealed_temp_password'])) {
    $revealedTemp = $_SESSION['revealed_temp_password'];
    unset($_SESSION['revealed_temp_password']);
}

// Generate a one-time temp password for another user (edit only; not self).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate_temp') {
    $id = (int) post('id');
    $myId = (int) ($me['id'] ?? 0);
    if ($id < 1) {
        flash('error', 'Select a user to edit first.');
        redirect('index.php?page=admin_users');
    }
    if ($id === $myId) {
        flash('error', 'Use the password field to change your own password.');
        redirect('index.php?page=admin_users&edit=' . $id);
    }
    $s = db()->prepare('SELECT id, username FROM users WHERE id=? LIMIT 1');
    $s->execute([$id]);
    $target = $s->fetch() ?: null;
    if (!$target) {
        flash('error', 'User not found.');
        redirect('index.php?page=admin_users');
    }
    $password = generate_temp_password();
    db()->prepare(
        'UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?'
    )->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    $_SESSION['revealed_temp_password'] = [
        'user_id' => $id,
        'username' => (string) $target['username'],
        'password' => $password,
    ];
    flash('ok', 'Temporary password generated. Copy it below (shown once). They must change it on next login.');
    redirect('index.php?page=admin_users&edit=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    $id = (int) post('id');
    $username = trim((string) post('username'));
    $role = post('role') === 'admin' ? 'admin' : 'team';
    $active = post('is_active') ? 1 : 0;
    $full = trim((string) post('full_name'));
    $email = trim((string) post('email'));
    $phone = trim((string) post('phone'));
    $contact = trim((string) post('contact_details'));
    $password = (string) post('password');
    $myId = (int) ($me['id'] ?? 0);

    $draftFields = [
        'username' => $username,
        'full_name' => $full,
        'email' => $email,
        'phone' => $phone,
        'contact_details' => $contact,
        'role' => $role,
        'is_active' => $active,
    ];
    $fail = static function (string $message) use ($id, $draftFields): void {
        users_stash_form_draft($id, $draftFields);
        flash('error', $message);
        redirect('index.php?page=admin_users' . ($id ? '&edit=' . $id : ''));
    };

    if ($username === '') {
        $fail('Username required.');
    }
    if ($role === 'admin' && $full === '') {
        $fail('Admins need a unique full name.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail('Enter a valid email address.');
    }

    $existing = null;
    if ($id > 0) {
        $s = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $s->execute([$id]);
        $existing = $s->fetch() ?: null;
        if (!$existing) {
            flash('error', 'User not found.');
            redirect('index.php?page=admin_users');
        }
    }

    // Self-lockout guards
    if ($id > 0 && $id === $myId) {
        if ($active === 0) {
            $fail('You cannot deactivate your own account.');
        }
        if ($role !== 'admin') {
            $fail('You cannot demote your own admin account.');
        }
    }

    // Last active admin guard
    if ($id > 0 && $existing) {
        $wasActiveAdmin = ($existing['role'] ?? '') === 'admin' && (int) ($existing['is_active'] ?? 0) === 1;
        $willBeActiveAdmin = $role === 'admin' && $active === 1;
        if ($wasActiveAdmin && !$willBeActiveAdmin) {
            $cnt = (int) db()->query(
                "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id<>" . (int) $id
            )->fetchColumn();
            if ($cnt < 1) {
                $fail('Cannot remove the last active admin.');
            }
        }
    }

    if ($role === 'admin' && $full !== '') {
        $dup = db()->prepare(
            "SELECT id FROM users WHERE role='admin' AND full_name=? AND id<>? LIMIT 1"
        );
        $dup->execute([$full, $id]);
        if ($dup->fetchColumn()) {
            $fail('Another admin already uses this full name. Each admin needs a unique name.');
        }
    }

    if ($role === 'admin' && $email !== '' && admin_email_taken_by_other($email, $id)) {
        $fail('Another active admin already uses this email. Admin emails must be unique for login and password reset.');
    }

    $settingPassword = $password !== '' || $id === 0;
    if ($id === 0 && $password === '') {
        $password = generate_temp_password();
    }
    if ($settingPassword && $password !== '') {
        if (strlen($password) < 8) {
            $fail('Password must be at least 8 characters.');
        }
        if (in_array($password, known_weak_passwords(), true)) {
            $fail('Do not use demo default passwords (admin123 / team123).');
        }
    }

    $oldEmail = trim((string) ($existing['email'] ?? ''));
    $emailChanged = $id > 0 && strcasecmp($oldEmail, $email) !== 0;
    // Changing an admin's email must re-verify (same as Admin → Account).
    $clearEmailVerify = $role === 'admin' && $emailChanged;
    $wasActive = $existing && (int) ($existing['is_active'] ?? 0) === 1;
    $justDeactivated = $id > 0 && $wasActive && $active === 0;

    $appendDeactivateNote = static function (string $msg) use ($id, $justDeactivated): string {
        if (!$justDeactivated || !function_exists('user_deactivation_residue')) {
            return $msg;
        }
        $res = user_deactivation_residue($id);
        $m = (int) ($res['memberships'] ?? 0);
        $t = (int) ($res['open_tasks'] ?? 0);
        if ($m < 1 && $t < 1) {
            return $msg;
        }
        $extra = ' Still in ' . $m . ' department(s)';
        if ($t > 0) {
            $extra .= ', assigned on ' . $t . ' open task(s)';
        }
        return $msg . $extra . ' — review under Departments (memberships were not auto-removed).';
    };

    try {
        if ($id) {
            if ($password !== '') {
                // Editing your own password: apply immediately (no forced re-change).
                $mustChange = ($id === $myId) ? 0 : 1;
                if ($clearEmailVerify) {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=?, password_hash=?, must_change_password=?, email_verified_at=NULL WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, password_hash($password, PASSWORD_DEFAULT), $mustChange, $id]);
                } else {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=?, password_hash=?, must_change_password=? WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, password_hash($password, PASSWORD_DEFAULT), $mustChange, $id]);
                }
                if ($id === $myId) {
                    clear_must_change_password_flag($id);
                    flash('ok', $appendDeactivateNote('User updated. Your new password is active now.'));
                } else {
                    flash('ok', $appendDeactivateNote('User updated. They must change the password on next login.'));
                }
            } else {
                if ($clearEmailVerify) {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=?, email_verified_at=NULL WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, $id]);
                } else {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=? WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, $id]);
                }
                flash('ok', $appendDeactivateNote('User updated.'));
            }
            unset($_SESSION['users_form_draft']);
            redirect('index.php?page=admin_users');
        }

        db()->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role, is_active, must_change_password)
             VALUES (?,?,?,?,?,?,?,?,1)'
        )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full, $email, $phone, $contact, $role, $active]);
        $newId = (int) db()->lastInsertId();
        $_SESSION['revealed_temp_password'] = [
            'user_id' => $newId,
            'username' => $username,
            'password' => $password,
        ];
        unset($_SESSION['users_form_draft']);
        flash('ok', 'User created. Copy the temporary password below (shown once). They must change it on first login.');
        redirect('index.php?page=admin_users');
    } catch (PDOException $e) {
        users_stash_form_draft($id, $draftFields);
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if ($sqlState === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
            flash('error', 'Could not save — username must be unique.');
        } else {
            flash('error', 'Could not save. Try again or pick a different username.');
        }
        redirect('index.php?page=admin_users' . ($id ? '&edit=' . $id : ''));
    }
}

$editId = (int) get('edit');
$edit = null;
if ($editId) {
    $s = db()->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$editId]);
    $edit = $s->fetch() ?: null;
    if (!$edit) {
        flash('error', 'User not found.');
        redirect('index.php?page=admin_users');
    }
}

$form = $edit ?: [
    'id' => 0,
    'username' => '',
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'contact_details' => '',
    'role' => 'team',
    'is_active' => 1,
];
$draft = users_take_form_draft($editId);
if ($draft) {
    $form = array_merge($form, $draft);
}


$q = trim((string) get('q'));
$roleFilter = (string) get('role');
if (!in_array($roleFilter, ['admin', 'team'], true)) {
    $roleFilter = '';
}
$activeFilter = (string) get('active');
if (!in_array($activeFilter, ['0', '1'], true)) {
    $activeFilter = '';
}

$sql = 'SELECT * FROM users WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (username LIKE ? OR full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($roleFilter !== '') {
    $sql .= ' AND role=?';
    $params[] = $roleFilter;
}
if ($activeFilter !== '') {
    $sql .= ' AND is_active=?';
    $params[] = (int) $activeFilter;
}
$sql .= ' ORDER BY role, full_name, username';
if ($params) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} else {
    $users = db()->query($sql)->fetchAll();
}
$admins = array_values(array_filter($users, fn($u) => $u['role'] === 'admin'));

$perPage = 50;
$pageNum = max(1, (int) get('p', 1));
$totalUsers = count($users);
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$usersPage = array_slice($users, ($pageNum - 1) * $perPage, $perPage);

$usersListQs = static function (array $overrides) use ($q, $roleFilter, $activeFilter, $editId): string {
    $params = array_merge([
        'page' => 'admin_users',
        'q' => $q,
        'role' => $roleFilter,
        'active' => $activeFilter,
        'edit' => $editId > 0 ? (string) $editId : '',
        'p' => '1',
    ], $overrides);
    $bits = [];
    foreach ($params as $k => $v) {
        $v = (string) $v;
        if ($v === '' || ($k === 'p' && $v === '1')) {
            continue;
        }
        $bits[] = rawurlencode((string) $k) . '=' . rawurlencode($v);
    }
    return 'index.php?' . implode('&', $bits);
};
$deptByUser = [];
if (function_exists('ensure_departments_schema')) {
    try {
        ensure_departments_schema();
        $deptRows = db()->query(
            'SELECT m.user_id, d.name
             FROM department_members m
             INNER JOIN departments d ON d.id = m.department_id
             WHERE d.is_active = 1
             ORDER BY d.sort_order ASC, d.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($deptRows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid < 1) {
                continue;
            }
            $deptByUser[$uid][] = (string) ($row['name'] ?? '');
        }
    } catch (Throwable $e) {
        $deptByUser = [];
    }
}

render_header('Admins & users', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Users'],
]); ?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Users', 'Admin and Team logins. Assign Team users under Departments so they unlock tools.') ?></h1>
    <p class="muted">Admin and Team logins. Assign Team users under Departments.</p>
  </div>
</div>
<?= guide_admin_users() ?>

<?php if ($revealedTemp): ?>
<div class="card" style="border-color:var(--accent, #2a7a4b)">
  <h2>Temporary password (shown once)</h2>
  <p class="muted">Copy this now — it will not appear again after you leave this page.</p>
  <p><strong>User:</strong> <?= h((string) ($revealedTemp['username'] ?? '')) ?></p>
  <p><strong>Password:</strong>
    <code id="temp-password-reveal"><?= h((string) ($revealedTemp['password'] ?? '')) ?></code>
  </p>
  <p class="actions">
    <button type="button" class="btn secondary" id="copy-temp-password">Copy password</button>
  </p>
</div>
<script>
(function () {
  var btn = document.getElementById('copy-temp-password');
  var el = document.getElementById('temp-password-reveal');
  if (!btn || !el) return;
  btn.addEventListener('click', function () {
    var text = el.textContent || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        btn.textContent = 'Copied';
      }).catch(function () {
        btn.textContent = 'Select and copy manually';
      });
    } else {
      btn.textContent = 'Select and copy manually';
    }
  });
})();
</script>
<?php endif; ?>

<div class="card">
  <h2>Admin directory</h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Contact details</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $u): ?>
      <tr>
        <td><strong><?= h($u['full_name'] ?: '—') ?></strong></td>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['email'] ?: '—') ?></td>
        <td><?= h(($u['phone'] ?? '') !== '' ? $u['phone'] : '—') ?></td>
        <td class="help"><?= h(($u['contact_details'] ?? '') !== '' ? $u['contact_details'] : '—') ?></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="actions"><a href="index.php?page=admin_users&edit=<?= (int) $u['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$admins): ?><tr><td colspan="7" class="muted">No admins<?= ($q !== '' || $roleFilter !== '' || $activeFilter !== '') ? ' match these filters' : ' yet' ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="grid" style="grid-template-columns:1.2fr 1fr">
<div class="card">
  <h2>All users</h2>
  <form method="get" action="index.php" class="users-filters" style="display:flex;flex-wrap:wrap;gap:0.6rem;align-items:end;margin:0 0 1rem">
    <input type="hidden" name="page" value="admin_users">
    <?php if ($editId): ?><input type="hidden" name="edit" value="<?= (int) $editId ?>"><?php endif; ?>
    <div>
      <label for="users_q">Search</label>
      <input id="users_q" name="q" value="<?= h($q) ?>" placeholder="Username, name, email…" style="min-width:12rem">
    </div>
    <div>
      <label for="users_role">Role</label>
      <select id="users_role" name="role">
        <option value="" <?= $roleFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>admin</option>
        <option value="team" <?= $roleFilter === 'team' ? 'selected' : '' ?>>team</option>
      </select>
    </div>
    <div>
      <label for="users_active">Active</label>
      <select id="users_active" name="active">
        <option value="" <?= $activeFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Yes</option>
        <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>No</option>
      </select>
    </div>
    <button class="btn secondary" type="submit">Filter</button>
    <?php if ($q !== '' || $roleFilter !== '' || $activeFilter !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_users<?= $editId ? '&edit=' . (int) $editId : '' ?>">Clear</a>
    <?php endif; ?>
  </form>
  <p class="muted" style="margin:0 0 0.75rem">
    <?= (int) $totalUsers ?> user<?= $totalUsers === 1 ? '' : 's' ?>
    <?php if ($totalPages > 1): ?>
      · page <?= (int) $pageNum ?> / <?= (int) $totalPages ?>
      · showing <?= count($usersPage) ?>
    <?php endif; ?>
  </p>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Username</th>
        <th>Name</th>
        <th>Role</th>
        <th>Contact</th>
        <th>Departments</th>
        <th>Must change pwd</th>
        <th>Active</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($usersPage as $u): ?>
      <?php
        $uid = (int) $u['id'];
        $depts = $deptByUser[$uid] ?? [];
        $deptLabel = $depts ? implode(', ', $depts) : '—';
      ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td><span class="badge"><?= h($u['role']) ?></span></td>
        <td class="help"><?= h($u['email'] ?: '—') ?><?= !empty($u['phone']) ? ' · ' . h($u['phone']) : '' ?></td>
        <td class="help"><?= h($deptLabel) ?></td>
        <td><?= !empty($u['must_change_password']) ? 'Yes' : 'No' ?></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="actions">
          <a href="<?= h($usersListQs(['edit' => (string) $uid, 'p' => (string) $pageNum])) ?>">Edit</a>
          <?php if ($u['role'] === 'team'): ?>
            <a href="index.php?page=admin_departments">Departments</a>
            <a href="index.php?page=admin_prospect_batches&amp;user=<?= $uid ?>">Site adding history</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$usersPage): ?>
      <tr><td colspan="8" class="muted">No users match these filters.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <p class="muted" style="margin-top:0.85rem">
    Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
    <?php if ($pageNum > 1): ?>
      · <a href="<?= h($usersListQs(['p' => (string) ($pageNum - 1)])) ?>">Previous</a>
    <?php endif; ?>
    <?php if ($pageNum < $totalPages): ?>
      · <a href="<?= h($usersListQs(['p' => (string) ($pageNum + 1)])) ?>">Next</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<div class="card">
  <h2><?= $edit ? 'Edit user' : 'New admin / team user' ?></h2>
  <form method="post" action="index.php?page=admin_users">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Username</label>
    <input name="username" value="<?= h($form['username'] ?? '') ?>" required>
    <label>Full name <?= (($form['role'] ?? '') === 'admin' || !$edit) ? '(unique for admins)' : '' ?></label>
    <input name="full_name" value="<?= h($form['full_name'] ?? '') ?>">
    <label>Email</label>
    <input name="email" value="<?= h($form['email'] ?? '') ?>" type="email">
    <label>Phone</label>
    <input name="phone" value="<?= h($form['phone'] ?? '') ?>">
    <label>Contact details</label>
    <textarea name="contact_details" rows="2" placeholder="Slack, secondary email, working hours…"><?= h($form['contact_details'] ?? '') ?></textarea>
    <label>Role</label>
    <select name="role">
      <option value="team" <?= ($form['role'] ?? '')==='team'?'selected':'' ?>>team</option>
      <option value="admin" <?= ($form['role'] ?? '')==='admin'?'selected':'' ?>>admin</option>
    </select>
    <label>Password <?= $edit ? '(blank = keep)' : '(min 8 chars; blank = generate)' ?></label>
    <input type="password" name="password" id="users_password" autocomplete="new-password" minlength="8"
           data-editing-other="<?= ($edit && (int) ($edit['id'] ?? 0) !== (int) ($me['id'] ?? 0)) ? '1' : '0' ?>">
    <p class="help">Passwords must be at least 8 characters (not demo defaults). Admin emails must be unique.</p>
    <?php if ($edit && ($edit['role'] ?? '') === 'team'): ?>
      <p class="help">
        Departments:
        <?php
          $editDepts = $deptByUser[(int) $edit['id']] ?? [];
          echo $editDepts ? h(implode(', ', $editDepts)) : 'none yet';
        ?>
        · <a href="index.php?page=admin_departments">Assign in Departments</a>
      </p>
    <?php endif; ?>
    <label style="font-weight:500;margin-top:0.8rem"><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>> Active</label>
    <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save</button></p>
  </form>
  <?php if ($edit && (int) ($edit['id'] ?? 0) !== (int) ($me['id'] ?? 0)): ?>
  <form method="post" action="index.php?page=admin_users" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border, #ddd)"
        onsubmit="return confirm(<?= json_encode('Generate a temporary password for ' . (string) ($edit['username'] ?? 'this user') . '? They must change it on next login.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate_temp">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="help" style="margin-top:0">Reset without typing a password — shows once on the next page.</p>
    <button class="btn secondary" type="submit">Generate temporary password</button>
  </form>
  <?php endif; ?>
</div>
</div>
<script>
(function () {
  var form = document.querySelector('form[action="index.php?page=admin_users"] input[name="action"][value="save"]');
  if (!form) return;
  form = form.closest('form');
  var pwd = document.getElementById('users_password');
  if (!form || !pwd) return;
  form.addEventListener('submit', function (e) {
    if (pwd.getAttribute('data-editing-other') !== '1') return;
    if (!String(pwd.value || '').trim()) return;
    if (!window.confirm('Set a new password for this user? They must change it on next login.')) {
      e.preventDefault();
    }
  });
})();
</script>
<?php render_footer('admin'); ?>
