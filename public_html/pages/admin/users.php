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

/**
 * Users list URL that keeps search / role / active / page (and optional edit).
 * Safe to call during POST when the form action carried those as query params.
 */
function users_list_url(array $overrides = []): string
{
    $role = array_key_exists('role', $overrides) ? (string) $overrides['role'] : (string) ($_GET['role'] ?? '');
    if (!in_array($role, ['admin', 'team'], true)) {
        $role = '';
    }
    $active = array_key_exists('active', $overrides) ? (string) $overrides['active'] : (string) ($_GET['active'] ?? '');
    if (!in_array($active, ['0', '1'], true)) {
        $active = '';
    }
    $q = array_key_exists('q', $overrides) ? (string) $overrides['q'] : trim((string) ($_GET['q'] ?? ''));
    $edit = array_key_exists('edit', $overrides) ? (string) $overrides['edit'] : (string) ($_GET['edit'] ?? '');
    $p = array_key_exists('p', $overrides) ? (string) $overrides['p'] : (string) ($_GET['p'] ?? '1');
    $unassigned = array_key_exists('unassigned', $overrides) ? (string) $overrides['unassigned'] : (string) ($_GET['unassigned'] ?? '');
    if ($unassigned !== '1') {
        $unassigned = '';
    }
    $params = [
        'page' => 'admin_users',
        'q' => $q,
        'role' => $role,
        'active' => $active,
        'unassigned' => $unassigned,
        'edit' => $edit,
        'p' => $p,
    ];
    $bits = [];
    foreach ($params as $k => $v) {
        $v = (string) $v;
        if ($v === '' || ($k === 'p' && $v === '1')) {
            continue;
        }
        $bits[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    return 'index.php?' . implode('&', $bits);
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
        redirect(users_list_url(['edit' => '']));
    }
    if ($id === $myId) {
        flash('error', 'Use the password field to change your own password.');
        redirect(users_list_url(['edit' => (string) $id]));
    }
    $s = db()->prepare('SELECT id, username FROM users WHERE id=? LIMIT 1');
    $s->execute([$id]);
    $target = $s->fetch() ?: null;
    if (!$target) {
        flash('error', 'User not found.');
        redirect(users_list_url(['edit' => '']));
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
    redirect(users_list_url(['edit' => (string) $id]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'send_verify') {
    $id = (int) post('id');
    if ($id < 1) {
        flash('error', 'Select a user to edit first.');
        redirect(users_list_url(['edit' => '']));
    }
    $target = load_user_by_id($id);
    if (!$target) {
        flash('error', 'User not found.');
        redirect(users_list_url(['edit' => '']));
    }
    $result = send_admin_email_verification($target);
    if (!empty($result['ok'])) {
        flash('ok', 'Verification email sent to ' . trim((string) ($target['email'] ?? '')) . '.');
    } else {
        flash('error', (string) ($result['error'] ?? 'Could not send verification email.'));
    }
    redirect(users_list_url(['edit' => (string) $id]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_departments') {
    $id = (int) post('id');
    if ($id < 1) {
        flash('error', 'Select a user to edit first.');
        redirect(users_list_url(['edit' => '']));
    }
    $target = load_user_by_id($id);
    if (!$target) {
        flash('error', 'User not found.');
        redirect(users_list_url(['edit' => '']));
    }
    if (($target['role'] ?? '') !== 'team') {
        flash('error', 'Departments are for Team users. Admins already see every tool.');
        redirect(users_list_url(['edit' => (string) $id]));
    }
    $wanted = array_map('intval', (array) ($_POST['dept_ids'] ?? []));
    $validIds = [];
    foreach (list_departments(true) as $dept) {
        $validIds[] = (int) ($dept['id'] ?? 0);
    }
    $wanted = array_values(array_filter(
        array_unique($wanted),
        static fn (int $did) => $did > 0 && in_array($did, $validIds, true)
    ));
    $current = user_department_ids($id);
    $added = 0;
    $removed = 0;
    foreach ($wanted as $did) {
        if (!in_array($did, $current, true) && add_department_member($did, $id, $me)) {
            $added++;
        }
    }
    foreach ($current as $did) {
        if (!in_array($did, $wanted, true) && remove_department_member($did, $id)) {
            $removed++;
        }
    }
    if ($added < 1 && $removed < 1) {
        flash('ok', 'Departments unchanged.');
    } else {
        flash('ok', 'Departments updated (' . $added . ' added, ' . $removed . ' removed).');
    }
    redirect(users_list_url(['edit' => (string) $id]));
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
        redirect(users_list_url(['edit' => $id ? (string) $id : '']));
    };

    if ($username === '') {
        $fail('Username required.');
    }
    if (preg_match('/\s/u', $username)) {
        $fail('Username cannot contain spaces.');
    }
    if (strlen($username) > 100) {
        $fail('Username must be 100 characters or fewer.');
    }
    $taken = db()->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id<>? LIMIT 1');
    $taken->execute([$username, $id]);
    if ($taken->fetchColumn()) {
        $fail('Could not save — username must be unique.');
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
            redirect(users_list_url(['edit' => '']));
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
        if (!$justDeactivated) {
            return $msg;
        }
        if (function_exists('user_deactivation_residue')) {
            $res = user_deactivation_residue($id);
            $m = (int) ($res['memberships'] ?? 0);
            $t = (int) ($res['open_tasks'] ?? 0);
            if ($m > 0 || $t > 0) {
                $extra = ' Still in ' . $m . ' department(s)';
                if ($t > 0) {
                    $extra .= ', assigned on ' . $t . ' open task(s)';
                }
                $msg .= $extra . ' — review under Departments (memberships were not auto-removed).';
            }
        }
        return $msg . ' They cannot sign in again; open sessions end on their next request.';
    };

    $appendEmailNote = static function (string $msg) use ($role, $email, $clearEmailVerify): string {
        if ($role === 'admin' && $email === '') {
            $msg .= ' This admin has no email — they cannot use Forgot password or email login.';
        } elseif ($clearEmailVerify) {
            $msg .= ' They must verify the new address. You can send a link from this form.';
        }
        return $msg;
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
                    refresh_current_user_from_db();
                    flash('ok', $appendEmailNote($appendDeactivateNote('User updated. Your new password is active now.')));
                } else {
                    flash('ok', $appendEmailNote($appendDeactivateNote('User updated. They must change the password on next login.')));
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
                if ($id === $myId) {
                    refresh_current_user_from_db();
                }
                flash('ok', $appendEmailNote($appendDeactivateNote('User updated.')));
            }
            unset($_SESSION['users_form_draft']);
            redirect(users_list_url(['edit' => (string) $id]));
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
        flash('ok', $appendEmailNote('User created. Copy the temporary password below (shown once). They must change it on first login.'));
        redirect(users_list_url([
            'edit' => (string) $newId,
            'q' => '',
            'role' => '',
            'active' => '',
            'unassigned' => '',
            'p' => '1',
        ]));
    } catch (PDOException $e) {
        users_stash_form_draft($id, $draftFields);
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if ($sqlState === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
            flash('error', 'Could not save — username must be unique.');
        } else {
            flash('error', 'Could not save. Try again or pick a different username.');
        }
        redirect(users_list_url(['edit' => $id ? (string) $id : '']));
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
        redirect(users_list_url(['edit' => '']));
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
$unassignedFilter = (string) get('unassigned') === '1';

$sql = 'SELECT * FROM users WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= " AND (username LIKE ? ESCAPE '\\\\' OR full_name LIKE ? ESCAPE '\\\\' OR email LIKE ? ESCAPE '\\\\' OR phone LIKE ? ESCAPE '\\\\')";
    $like = '%' . users_like_escape($q) . '%';
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
if ($unassignedFilter) {
    $sql .= " AND role='team' AND is_active=1
              AND NOT EXISTS (SELECT 1 FROM department_members m WHERE m.user_id = users.id)";
}
$sql .= ' ORDER BY role, full_name, username';
if ($params) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} else {
    $users = db()->query($sql)->fetchAll();
}

$perPage = 50;
$pageNum = max(1, (int) get('p', 1));
$totalUsers = count($users);
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$usersPage = array_slice($users, ($pageNum - 1) * $perPage, $perPage);

$usersListQs = static function (array $overrides) use ($editId, $pageNum): string {
    if (!array_key_exists('edit', $overrides) && $editId > 0) {
        $overrides['edit'] = (string) $editId;
    }
    if (!array_key_exists('p', $overrides)) {
        $overrides['p'] = (string) $pageNum;
    }
    return users_list_url($overrides);
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

<div class="users-layout">
<div class="card">
  <h2>Users</h2>
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
        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="team" <?= $roleFilter === 'team' ? 'selected' : '' ?>>Team</option>
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
    <div>
      <label for="users_unassigned">Departments</label>
      <select id="users_unassigned" name="unassigned">
        <option value="" <?= !$unassignedFilter ? 'selected' : '' ?>>All</option>
        <option value="1" <?= $unassignedFilter ? 'selected' : '' ?>>Awaiting assignment</option>
      </select>
    </div>
    <button class="btn secondary" type="submit">Filter</button>
    <?php if ($q !== '' || $roleFilter !== '' || $activeFilter !== '' || $unassignedFilter): ?>
      <a class="btn secondary" href="<?= h($usersListQs(['q' => '', 'role' => '', 'active' => '', 'unassigned' => '', 'p' => '1'])) ?>">Clear</a>
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
        <th>Verified</th>
        <th>Must change</th>
        <th>Active</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($usersPage as $u): ?>
      <?php
        $uid = (int) $u['id'];
        $depts = $deptByUser[$uid] ?? [];
        $deptLabel = $depts ? implode(', ', $depts) : '—';
        $isTeam = ($u['role'] ?? '') === 'team';
        $isActive = !empty($u['is_active']);
        $awaiting = $isTeam && $isActive && !$depts;
        $isSelf = $uid === (int) ($me['id'] ?? 0);
        $isEditing = $editId > 0 && $uid === $editId;
        $roleLabel = ucfirst((string) ($u['role'] ?? ''));
      ?>
      <tr<?= $isEditing ? ' class="users-row-editing"' : '' ?>>
        <td><?= h($u['username']) ?><?php if ($isSelf): ?> <span class="users-you">You</span><?php endif; ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td><span class="badge"><?= h($roleLabel) ?></span></td>
        <td class="help"><?= h($u['email'] ?: '—') ?><?= !empty($u['phone']) ? ' · ' . h($u['phone']) : '' ?></td>
        <td class="help">
          <?php if ($depts): ?>
            <?= h($deptLabel) ?>
          <?php elseif ($awaiting): ?>
            <span class="badge users-pill-awaiting">Awaiting</span>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <?php if (($u['role'] ?? '') === 'admin'): ?>
            <?php if (admin_email_is_verified($u)): ?>
              <span class="badge agreed">Verified</span>
            <?php else: ?>
              <span class="badge sent">Not verified</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?php if (!empty($u['must_change_password'])): ?><span class="badge sent">Must change</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?php if ($isActive): ?><span class="badge active">Active</span><?php else: ?><span class="badge skipped">Inactive</span><?php endif; ?></td>
        <td class="actions">
          <a href="<?= h($usersListQs(['edit' => (string) $uid, 'p' => (string) $pageNum])) ?>">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$usersPage): ?>
      <tr><td colspan="9" class="muted"><?php
        if ($unassignedFilter) {
            echo 'No team awaiting assignment — assign under Departments.';
        } else {
            echo 'No users match these filters.';
        }
      ?></td></tr>
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
<div class="card" id="users-edit-card">
  <h2><?= $edit ? 'Edit user' : 'New admin / team user' ?></h2>
  <?php if ($edit): ?>
    <p class="help">Editing <strong><?= h((string) ($edit['username'] ?? '')) ?></strong>
      <?php if (!empty($edit['created_at'])): ?>
        · created <?= h(substr((string) $edit['created_at'], 0, 10)) ?>
      <?php endif; ?>
      <?php if (($edit['role'] ?? '') === 'team'): ?>
        · <a href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $edit['id'] ?>">Site adding history</a>
      <?php endif; ?>
      · <a href="<?= h($usersListQs(['edit' => ''])) ?>">Cancel edit</a>
    </p>
  <?php endif; ?>
  <form method="post" action="<?= h($usersListQs([])) ?>" id="users-save-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Username</label>
    <input name="username" value="<?= h($form['username'] ?? '') ?>" required>
    <label>Full name <?= (($form['role'] ?? '') === 'admin' || !$edit) ? '(unique for admins)' : '' ?></label>
    <input name="full_name" value="<?= h($form['full_name'] ?? '') ?>">
    <label>Email</label>
    <input name="email" value="<?= h($form['email'] ?? '') ?>" type="email">
    <?php if ($edit && ($edit['role'] ?? '') === 'admin'): ?>
      <p class="help">
        <?php if (admin_email_is_verified($edit)): ?>
          Email is <strong>verified</strong> — Forgot password can send a reset.
        <?php elseif (trim((string) ($edit['email'] ?? '')) !== ''): ?>
          Email is <strong>not verified</strong>. Send a link below after Save if you just changed it.
        <?php else: ?>
          No email — this admin cannot use Forgot password or email login.
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <label>Phone</label>
    <input name="phone" value="<?= h($form['phone'] ?? '') ?>">
    <label>Contact details</label>
    <textarea name="contact_details" rows="2" placeholder="Slack, secondary email, working hours…"><?= h($form['contact_details'] ?? '') ?></textarea>
    <label>Role</label>
    <select name="role">
      <option value="team" <?= ($form['role'] ?? '')==='team'?'selected':'' ?>>Team</option>
      <option value="admin" <?= ($form['role'] ?? '')==='admin'?'selected':'' ?>>Admin</option>
    </select>
    <label>Password <?= $edit ? '(blank = keep)' : '(min 8 chars; blank = generate)' ?></label>
    <input type="password" name="password" id="users_password" autocomplete="new-password" minlength="8"
           data-editing-other="<?= ($edit && (int) ($edit['id'] ?? 0) !== (int) ($me['id'] ?? 0)) ? '1' : '0' ?>">
    <p class="help">Passwords must be at least 8 characters (not demo defaults). Admin emails must be unique.</p>
    <?php if ($edit && ($edit['role'] ?? '') === 'admin'): ?>
      <p class="help">Admins see all tools; departments are for Team.</p>
    <?php endif; ?>
    <label style="font-weight:500;margin-top:0.8rem"><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>
           id="users_is_active"
           <?php if ($edit && (int) ($edit['id'] ?? 0) !== (int) ($me['id'] ?? 0) && !empty($edit['is_active'])): ?>
             <?php
               $res = function_exists('user_deactivation_residue')
                   ? user_deactivation_residue((int) $edit['id'])
                   : ['memberships' => 0, 'open_tasks' => 0];
             ?>
             data-deactivate-user="<?= h((string) ($edit['username'] ?? 'this user')) ?>"
             data-memberships="<?= (int) ($res['memberships'] ?? 0) ?>"
             data-open-tasks="<?= (int) ($res['open_tasks'] ?? 0) ?>"
           <?php endif; ?>
           > Active</label>
    <p class="help">Uncheck to deactivate — they cannot log in. Do not delete users (site-adding history stays).</p>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit">Save</button>
      <?php if ($edit): ?>
        <a class="btn secondary" href="<?= h($usersListQs(['edit' => ''])) ?>">Cancel</a>
      <?php endif; ?>
    </p>
  </form>
  <?php if ($edit && ($edit['role'] ?? '') === 'team'): ?>
  <form method="post" action="<?= h($usersListQs([])) ?>" id="users-dept-form" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border, #ddd)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_departments">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="help" style="margin-top:0">Tick departments to unlock tools. Uncheck to remove. This does not change the profile fields above.</p>
    <?php
      $allDepartments = function_exists('list_departments') ? list_departments(true) : [];
      $editDeptIds = user_department_ids((int) $edit['id']);
    ?>
    <?php if (!$allDepartments): ?>
      <p class="muted">No departments yet. Run upgrade.php once.</p>
    <?php else: ?>
      <?php foreach ($allDepartments as $dept): ?>
        <?php $did = (int) ($dept['id'] ?? 0); ?>
        <label style="font-weight:500;display:block">
          <input type="checkbox" name="dept_ids[]" value="<?= $did ?>" <?= in_array($did, $editDeptIds, true) ? 'checked' : '' ?>>
          <?= h((string) ($dept['name'] ?? '')) ?>
        </label>
      <?php endforeach; ?>
      <p class="actions" style="margin-top:0.75rem">
        <button class="btn secondary" type="submit">Save departments</button>
        <a href="index.php?page=admin_departments">Open Departments</a>
      </p>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if ($edit && (int) ($edit['id'] ?? 0) !== (int) ($me['id'] ?? 0)): ?>
  <form method="post" action="<?= h($usersListQs([])) ?>" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border, #ddd)"
        onsubmit="return confirm(<?= json_encode('Generate a temporary password for ' . (string) ($edit['username'] ?? 'this user') . '? They must change it on next login.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate_temp">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="help" style="margin-top:0">Reset without typing a password — shows once on the next page.</p>
    <button class="btn secondary" type="submit">Generate temporary password</button>
  </form>
  <?php endif; ?>
  <?php if ($edit && ($edit['role'] ?? '') === 'admin' && trim((string) ($edit['email'] ?? '')) !== '' && !admin_email_is_verified($edit)): ?>
  <form method="post" action="<?= h($usersListQs([])) ?>" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border, #ddd)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_verify">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="help" style="margin-top:0">Sends a 48-hour verification link to <?= h((string) $edit['email']) ?>.</p>
    <button class="btn secondary" type="submit">Send verification email</button>
  </form>
  <?php endif; ?>
</div>
</div>
<script>
(function () {
  var form = document.getElementById('users-save-form');
  var pwd = document.getElementById('users_password');
  if (!form || !pwd) return;
  form.addEventListener('submit', function (e) {
    var active = document.getElementById('users_is_active');
    if (active && active.getAttribute('data-deactivate-user') && !active.checked) {
      var name = active.getAttribute('data-deactivate-user') || 'this user';
      var m = parseInt(active.getAttribute('data-memberships') || '0', 10) || 0;
      var t = parseInt(active.getAttribute('data-open-tasks') || '0', 10) || 0;
      var msg = 'Deactivate ' + name + '? They cannot sign in again.';
      if (m > 0 || t > 0) {
        msg += ' Still in ' + m + ' department(s)';
        if (t > 0) msg += ', assigned on ' + t + ' open task(s)';
        msg += '. Memberships are not removed automatically.';
      }
      if (!window.confirm(msg)) {
        e.preventDefault();
        return;
      }
    }
    if (pwd.getAttribute('data-editing-other') !== '1') return;
    if (!String(pwd.value || '').trim()) return;
    if (!window.confirm('Set a new password for this user? They must change it on next login.')) {
      e.preventDefault();
    }
  });
})();
</script>
<?php render_footer('admin'); ?>
