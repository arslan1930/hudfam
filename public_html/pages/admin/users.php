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
 * List-filter value from POST (save/generate) or GET (browse).
 */
function users_req(string $key): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST)) {
        return trim((string) $_POST[$key]);
    }
    return trim((string) get($key));
}

/**
 * @param array<string,mixed> $state
 */
function users_filter_hiddens(array $state): string
{
    $html = '';
    foreach (['q', 'role', 'active', 'awaiting', 'must_change'] as $k) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) ($state[$k] ?? '')) . '">';
    }
    $p = max(1, (int) ($state['p'] ?? 1));
    $html .= '<input type="hidden" name="p" value="' . $p . '">';
    return $html;
}

$q = users_req('q');
$roleFilter = users_req('role');
if (!in_array($roleFilter, ['admin', 'team'], true)) {
    $roleFilter = '';
}
$activeFilter = users_req('active');
if (!in_array($activeFilter, ['0', '1'], true)) {
    $activeFilter = '';
}
$awaitingFilter = users_req('awaiting') === '1' ? '1' : '';
$mustChangeFilter = users_req('must_change') === '1' ? '1' : '';
$pageNum = max(1, (int) users_req('p') ?: 1);

$listState = [
    'q' => $q,
    'role' => $roleFilter,
    'active' => $activeFilter,
    'awaiting' => $awaitingFilter,
    'must_change' => $mustChangeFilter,
    'p' => (string) $pageNum,
];

$usersUrl = static function (array $overrides = []) use (&$listState): string {
    return admin_users_url($listState, $overrides);
};

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
        redirect($usersUrl(['edit' => '']));
    }
    if ($id === $myId) {
        flash('error', 'Use the password field to change your own password.');
        redirect($usersUrl(['edit' => (string) $id]));
    }
    $s = db()->prepare('SELECT id, username, is_active FROM users WHERE id=? LIMIT 1');
    $s->execute([$id]);
    $target = $s->fetch() ?: null;
    if (!$target) {
        flash('error', 'User not found.');
        redirect($usersUrl(['edit' => '']));
    }
    $password = generate_temp_password();
    db()->prepare(
        'UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?'
    )->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    bump_user_session_version($id, false);
    $_SESSION['revealed_temp_password'] = [
        'user_id' => $id,
        'username' => (string) $target['username'],
        'password' => $password,
    ];
    $msg = 'Temporary password generated. Copy it below (shown once). They must change it on next login.';
    if ((int) ($target['is_active'] ?? 0) !== 1) {
        $msg .= ' This account is inactive — they cannot sign in until you tick Active.';
    }
    flash('ok', $msg);
    redirect($usersUrl(['edit' => (string) $id]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'send_verify') {
    $id = (int) post('id');
    if ($id < 1) {
        flash('error', 'Select a user to edit first.');
        redirect($usersUrl(['edit' => '']));
    }
    $target = function_exists('load_user_by_id') ? load_user_by_id($id) : null;
    if (!$target) {
        flash('error', 'User not found.');
        redirect($usersUrl(['edit' => '']));
    }
    $result = send_admin_email_verification($target);
    if (!empty($result['ok'])) {
        flash('ok', 'Verification email sent to ' . (string) ($target['email'] ?? '') . '.');
    } else {
        flash('error', (string) ($result['error'] ?? 'Could not send verification.'));
    }
    redirect($usersUrl(['edit' => (string) $id]));
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
    $fail = static function (string $message) use ($id, $draftFields, $usersUrl): void {
        users_stash_form_draft($id, $draftFields);
        flash('error', $message);
        redirect($usersUrl(['edit' => $id ? (string) $id : '']));
    };

    $userErr = username_format_error($username);
    if ($userErr !== '') {
        $fail($userErr);
    }
    if (username_taken_by_other($username, $id)) {
        $fail('That username is already in use.');
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
            redirect($usersUrl(['edit' => '']));
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
        $fail('Another admin already uses this email. Admin emails must be unique for login and password reset.');
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
            $roleChanged = (string) ($existing['role'] ?? '') !== $role;
            if ($justDeactivated || $roleChanged || $password !== '') {
                bump_user_session_version($id, $id === $myId);
            }
            unset($_SESSION['users_form_draft']);
            redirect($usersUrl(['edit' => (string) $id]));
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
        redirect($usersUrl(['edit' => (string) $newId, 'p' => '1']));
    } catch (PDOException $e) {
        users_stash_form_draft($id, $draftFields);
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if ($sqlState === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
            flash('error', 'Could not save — username must be unique.');
        } else {
            flash('error', 'Could not save. Try again or pick a different username.');
        }
        redirect($usersUrl(['edit' => $id ? (string) $id : '']));
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
        redirect($usersUrl(['edit' => '']));
    }
}
if ($editId > 0) {
    $listState['edit'] = (string) $editId;
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

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR IFNULL(u.contact_details, \'\') LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($roleFilter !== '') {
    $where[] = 'u.role=?';
    $params[] = $roleFilter;
}
if ($activeFilter !== '') {
    $where[] = 'u.is_active=?';
    $params[] = (int) $activeFilter;
}
if ($awaitingFilter === '1') {
    if (function_exists('ensure_departments_schema')) {
        ensure_departments_schema();
    }
    $where[] = "u.role='team' AND u.is_active=1 AND NOT EXISTS (SELECT 1 FROM department_members m WHERE m.user_id = u.id)";
}
if ($mustChangeFilter === '1') {
    $where[] = 'u.must_change_password=1';
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
$countStmt->execute($params);
$totalUsers = (int) $countStmt->fetchColumn();

$perPage = 50;
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
    $listState['p'] = (string) $pageNum;
}
$offset = ($pageNum - 1) * $perPage;
$listSql = 'SELECT u.* FROM users u WHERE ' . $whereSql
    . ' ORDER BY u.role, u.full_name, u.username LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
$listStmt = db()->prepare($listSql);
$listStmt->execute($params);
$usersPage = $listStmt->fetchAll() ?: [];

$adminTotal = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();

$deptByUser = [];
$pageIds = [];
foreach ($usersPage as $u) {
    $uid = (int) ($u['id'] ?? 0);
    if ($uid > 0) {
        $pageIds[] = $uid;
    }
}
if ($editId > 0) {
    $pageIds[] = $editId;
}
$pageIds = array_values(array_unique($pageIds));
if ($pageIds && function_exists('ensure_departments_schema')) {
    try {
        ensure_departments_schema();
        $in = implode(',', array_map('intval', $pageIds));
        $deptRows = db()->query(
            "SELECT m.user_id, d.name
             FROM department_members m
             INNER JOIN departments d ON d.id = m.department_id
             WHERE d.is_active = 1 AND m.user_id IN ({$in})
             ORDER BY d.sort_order ASC, d.name ASC"
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

$editResidue = ['memberships' => 0, 'open_tasks' => 0];
if ($edit && (int) ($edit['id'] ?? 0) !== (int) ($me['id'] ?? 0) && function_exists('user_deactivation_residue')) {
    $editResidue = user_deactivation_residue((int) $edit['id']);
}

$filtersOn = $q !== '' || $roleFilter !== '' || $activeFilter !== '' || $awaitingFilter === '1' || $mustChangeFilter === '1';

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

<div class="users-office">
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
      <label for="users_awaiting">Assignment</label>
      <select id="users_awaiting" name="awaiting">
        <option value="" <?= $awaitingFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="1" <?= $awaitingFilter === '1' ? 'selected' : '' ?>>Awaiting assignment</option>
      </select>
    </div>
    <div>
      <label for="users_must_change">Password</label>
      <select id="users_must_change" name="must_change">
        <option value="" <?= $mustChangeFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="1" <?= $mustChangeFilter === '1' ? 'selected' : '' ?>>Must change password</option>
      </select>
    </div>
    <button class="btn secondary" type="submit">Filter</button>
    <?php if ($filtersOn): ?>
      <a class="btn secondary" href="<?= h($usersUrl(['q' => '', 'role' => '', 'active' => '', 'awaiting' => '', 'must_change' => '', 'p' => '1'])) ?>">Clear</a>
    <?php endif; ?>
  </form>
  <p class="muted" style="margin:0 0 0.75rem">
    <?= (int) $totalUsers ?> user<?= $totalUsers === 1 ? '' : 's' ?>
    · <?= (int) $adminTotal ?> admin<?= $adminTotal === 1 ? '' : 's' ?>
    <?php if ($totalPages > 1): ?>
      · page <?= (int) $pageNum ?> / <?= (int) $totalPages ?>
      · showing <?= count($usersPage) ?>
    <?php endif; ?>
  </p>
  <div class="table-wrap">
  <table class="users-table">
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
        $isEditing = $editId > 0 && $uid === $editId;
        $verified = function_exists('admin_email_is_verified') && admin_email_is_verified($u);
        $emailBit = (string) ($u['email'] ?: '—');
        if (($u['role'] ?? '') === 'admin' && trim((string) ($u['email'] ?? '')) !== '') {
            $emailBit .= $verified ? ' (verified)' : ' (not verified)';
        }
      ?>
      <tr<?= $isEditing ? ' class="is-editing"' : '' ?>>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td><span class="badge"><?= h($u['role']) ?></span></td>
        <td class="help"><?= h($emailBit) ?><?= !empty($u['phone']) ? ' · ' . h($u['phone']) : '' ?></td>
        <td class="help"><?= h($deptLabel) ?></td>
        <td><?= !empty($u['must_change_password']) ? 'Yes' : 'No' ?></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="actions">
          <a href="<?= h($usersUrl(['edit' => (string) $uid, 'p' => (string) $pageNum])) ?>">Edit</a>
          <?php if ($u['role'] === 'team'): ?>
            <a href="index.php?page=admin_departments">Departments</a>
            <a href="index.php?page=admin_prospect_batches&amp;user=<?= $uid ?>">Site adding history</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$usersPage): ?>
      <tr><td colspan="8" class="muted"><?php
        if ($awaitingFilter === '1' && !$q && $roleFilter === '' && $activeFilter === '' && $mustChangeFilter !== '1') {
            echo 'No team users awaiting assignment.';
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
      · <a href="<?= h($usersUrl(['p' => (string) ($pageNum - 1)])) ?>">Previous</a>
    <?php endif; ?>
    <?php if ($pageNum < $totalPages): ?>
      · <a href="<?= h($usersUrl(['p' => (string) ($pageNum + 1)])) ?>">Next</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<div class="card">
  <h2><?= $edit ? 'Edit user' : 'New admin / team user' ?></h2>
  <form method="post" action="index.php?page=admin_users" id="users-save-form"
        data-was-active="<?= $edit && !empty($edit['is_active']) ? '1' : '0' ?>"
        data-memberships="<?= (int) ($editResidue['memberships'] ?? 0) ?>"
        data-open-tasks="<?= (int) ($editResidue['open_tasks'] ?? 0) ?>">
    <?= csrf_field() ?>
    <?= users_filter_hiddens($listState) ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Username</label>
    <input name="username" value="<?= h($form['username'] ?? '') ?>" required maxlength="100">
    <label>Full name <?= (($form['role'] ?? '') === 'admin' || !$edit) ? '(unique for admins)' : '' ?></label>
    <input name="full_name" value="<?= h($form['full_name'] ?? '') ?>">
    <label>Email</label>
    <input name="email" value="<?= h($form['email'] ?? '') ?>" type="email">
    <?php if ($edit && ($edit['role'] ?? '') === 'admin'): ?>
      <?php $editVerified = function_exists('admin_email_is_verified') && admin_email_is_verified($edit); ?>
      <p class="help" style="margin-top:0.2rem">
        <?php if (trim((string) ($edit['email'] ?? '')) === ''): ?>
          Add an email to allow Admin email login and password reset.
        <?php elseif ($editVerified): ?>
          Verified — this address can sign in and reset a password.
        <?php else: ?>
          Not verified — send a verification link below (or they verify under Account).
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p class="help" style="margin-top:0.2rem">Team signs in with username. Email is for your records only.</p>
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
        data-generate-temp
        data-inactive="<?= empty($edit['is_active']) ? '1' : '0' ?>"
        data-username="<?= h((string) ($edit['username'] ?? 'this user')) ?>">
    <?= csrf_field() ?>
    <?= users_filter_hiddens($listState) ?>
    <input type="hidden" name="action" value="generate_temp">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="help" style="margin-top:0">Reset without typing a password — shows once on the next page.</p>
    <button class="btn secondary" type="submit">Generate temporary password</button>
  </form>
  <?php endif; ?>
  <?php if ($edit && ($edit['role'] ?? '') === 'admin' && trim((string) ($edit['email'] ?? '')) !== ''): ?>
  <form method="post" action="index.php?page=admin_users" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border, #ddd)">
    <?= csrf_field() ?>
    <?= users_filter_hiddens($listState) ?>
    <input type="hidden" name="action" value="send_verify">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="help" style="margin-top:0">Send a verification link to <?= h((string) $edit['email']) ?>.</p>
    <button class="btn secondary" type="submit">Send verification</button>
  </form>
  <?php endif; ?>
</div>
</div>
<script>
(function () {
  var form = document.getElementById('users-save-form');
  var pwd = document.getElementById('users_password');
  if (form) {
    form.addEventListener('submit', function (e) {
      if (pwd && pwd.getAttribute('data-editing-other') === '1' && String(pwd.value || '').trim()) {
        if (!window.confirm('Set a new password for this user? They must change it on next login.')) {
          e.preventDefault();
          return;
        }
      }
      var box = form.querySelector('[name="is_active"]');
      var was = form.getAttribute('data-was-active') === '1';
      if (was && box && !box.checked) {
        var m = parseInt(form.getAttribute('data-memberships') || '0', 10) || 0;
        var t = parseInt(form.getAttribute('data-open-tasks') || '0', 10) || 0;
        var msg = 'Deactivate this user? They will be signed out.';
        if (m > 0 || t > 0) {
          msg += ' Still in ' + m + ' department(s)';
          if (t > 0) {
            msg += ', assigned on ' + t + ' open task(s)';
          }
          msg += '. Memberships are not removed automatically.';
        }
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      }
    });
  }
  var gen = document.querySelector('form[data-generate-temp]');
  if (gen) {
    gen.addEventListener('submit', function (e) {
      var name = gen.getAttribute('data-username') || 'this user';
      var msg = 'Generate a temporary password for ' + name + '? They must change it on next login.';
      if (gen.getAttribute('data-inactive') === '1') {
        msg += ' This account is inactive, so they cannot sign in until you tick Active.';
      }
      if (!window.confirm(msg)) {
        e.preventDefault();
      }
    });
  }
})();
</script>
<?php render_footer('admin'); ?>
