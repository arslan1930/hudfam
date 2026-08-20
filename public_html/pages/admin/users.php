<?php
require_admin();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    $id = (int) post('id');
    if ($id <= 0) {
        flash('error', 'Invalid user.');
        redirect('index.php?page=admin_users');
    }
    if ((int) ($me['id'] ?? 0) === $id) {
        flash('error', 'You cannot delete your own account.');
        redirect('index.php?page=admin_users');
    }
    $s = db()->prepare('SELECT id, username, role FROM users WHERE id=?');
    $s->execute([$id]);
    $target = $s->fetch();
    if (!$target) {
        flash('error', 'User not found.');
        redirect('index.php?page=admin_users');
    }
    if ($target['role'] === 'admin') {
        $adminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($adminCount <= 1) {
            flash('error', 'Cannot delete the last admin account.');
            redirect('index.php?page=admin_users');
        }
    }
    try {
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        flash('ok', 'Removed user “' . $target['username'] . '”. Their added sites stay in Countries.');
    } catch (PDOException $e) {
        flash('error', 'Could not delete user.');
    }
    redirect('index.php?page=admin_users');
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

    if ($username === '') {
        flash('error', 'Login name is required.');
    } elseif ($role === 'admin' && $full === '') {
        flash('error', 'Admins need a unique full name.');
    } elseif (!$id && $password === '') {
        flash('error', 'Set a password for the new user (only Admin can set passwords).');
    } else {
        if ($role === 'admin' && $full !== '') {
            $dup = db()->prepare(
                "SELECT id FROM users WHERE role='admin' AND full_name=? AND id<>? LIMIT 1"
            );
            $dup->execute([$full, $id]);
            if ($dup->fetchColumn()) {
                flash('error', 'Another admin already uses this full name. Each admin needs a unique name.');
                redirect('index.php?page=admin_users' . ($id ? '&edit=' . $id : ''));
            }
        }
        try {
            if ($id) {
                if ($password !== '') {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=?, password_hash=? WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, password_hash($password, PASSWORD_DEFAULT), $id]);
                    flash('ok', 'User updated (password changed).');
                } else {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=? WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, $id]);
                    flash('ok', 'User updated.');
                }
            } else {
                db()->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role, is_active)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full, $email, $phone, $contact, $role, $active]);
                flash('ok', 'User created. Share the password with them securely — only Admin can set or change passwords.');
            }
        } catch (PDOException $e) {
            flash('error', 'Could not save — login name must be unique.');
        }
    }
    redirect('index.php?page=admin_users');
}

$editId = (int) get('edit');
$edit = null;
if ($editId) {
    $s = db()->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$editId]);
    $edit = $s->fetch();
}

$filter = (string) get('filter', 'all');
if (!in_array($filter, ['all', 'admin', 'team'], true)) {
    $filter = 'all';
}

$users = db()->query('SELECT * FROM users ORDER BY role, full_name, username')->fetchAll();
$admins = array_values(array_filter($users, fn($u) => $u['role'] === 'admin'));
$team = array_values(array_filter($users, fn($u) => $u['role'] === 'team'));

ensure_tasks_schema();
$openTasks = list_team_tasks(null, 'open', 20);
$inProgressTasks = list_team_tasks(null, 'in_progress', 20);
$recentTasks = array_merge($openTasks, $inProgressTasks);
usort($recentTasks, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
$recentTasks = array_slice($recentTasks, 0, 12);

$list = match ($filter) {
    'admin' => $admins,
    'team' => $team,
    default => $users,
};

render_header('Users', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Users'],
]); ?>

<div class="topbar users-topbar">
  <div>
    <h1>Users</h1>
    <p class="muted">Manage accounts and assign tasks to teammates.</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_tasks">Assign tasks</a>
  </div>
</div>

<div class="card">
  <div class="topbar" style="margin:0;padding:0;border:0">
    <div>
      <h2 style="margin:0">Tasks</h2>
      <p class="muted" style="margin:0.35rem 0 0">Assign country work to teammates from here.</p>
    </div>
    <a class="btn" href="index.php?page=admin_tasks">New task</a>
  </div>
  <?php if (!$recentTasks): ?>
    <p class="help" style="margin-top:0.85rem">No open tasks yet.</p>
  <?php else: ?>
    <table style="margin-top:0.85rem">
      <thead><tr><th>Task</th><th>Teammate</th><th>Country</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recentTasks as $t): ?>
        <tr>
          <td><strong><?= h($t['title']) ?></strong></td>
          <td><?= h($t['assignee_name'] ?: $t['assignee_username']) ?></td>
          <td><?= h($t['country'] ?: '—') ?></td>
          <td><span class="badge"><?= h(task_status_label((string) $t['status'])) ?></span></td>
          <td><a href="index.php?page=admin_tasks&amp;edit=<?= (int) $t['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Admin directory</h2>
  <table>
    <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Contact details</th><th>Active</th></tr></thead>
    <tbody>
    <?php foreach ($admins as $u): ?>
      <tr>
        <td><strong><?= h($u['full_name'] ?: '—') ?></strong></td>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['email'] ?: '—') ?></td>
        <td><?= h(($u['phone'] ?? '') !== '' ? $u['phone'] : '—') ?></td>
        <td class="help"><?= h(($u['contact_details'] ?? '') !== '' ? $u['contact_details'] : '—') ?></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$admins): ?><tr><td colspan="6" class="muted">No admins yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="grid" style="grid-template-columns:1.2fr 1fr">
<div class="card">
  <h2>Teammates</h2>
  <table>
    <thead><tr><th>Username</th><th>Name</th><th>Contact</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($team as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td class="help"><?= h($u['email'] ?: '—') ?><?= !empty($u['phone']) ? ' · ' . h($u['phone']) : '' ?></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="actions">
          <a href="index.php?page=admin_users&edit=<?= (int)$u['id'] ?>">Edit</a>
          <a href="index.php?page=admin_tasks&amp;user=<?= (int) $u['id'] ?>">Assign task</a>
          <a href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $u['id'] ?>">Added sites</a>
          <form method="post" style="display:inline" onsubmit="return confirm(<?= h(json_encode('Remove teammate ' . $u['username'] . '? Their sites stay in Countries.', JSON_UNESCAPED_UNICODE)) ?>);">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button class="btn-link danger" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$team): ?><tr><td colspan="5" class="muted">No teammates yet — create one on the right.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h2 style="margin-top:1.5rem">All users</h2>
  <table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td><span class="badge"><?= h($u['role']) ?></span></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="actions">
          <a href="index.php?page=admin_users&edit=<?= (int)$u['id'] ?>">Edit</a>
          <?php if ((int) $u['id'] !== (int) ($me['id'] ?? 0)): ?>
            <?php if ($u['role'] !== 'admin' || count($admins) > 1): ?>
              <form method="post" style="display:inline" onsubmit="return confirm(<?= h(json_encode('Remove ' . $u['username'] . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn-link danger" type="submit">Remove</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="card">
  <h2><?= $edit ? 'Edit user' : 'New teammate / admin' ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Username</label>
    <input name="username" value="<?= h($edit['username'] ?? '') ?>" required autocomplete="off">
    <label>Full name <?= ($edit['role'] ?? '') === 'admin' || !isset($edit) ? '(unique for admins)' : '' ?></label>
    <input name="full_name" value="<?= h($edit['full_name'] ?? '') ?>">
    <label>Email</label>
    <input name="email" value="<?= h($edit['email'] ?? '') ?>" type="email">
    <label>Phone</label>
    <input name="phone" value="<?= h($edit['phone'] ?? '') ?>">
    <label>Contact details</label>
    <textarea name="contact_details" rows="2" placeholder="Slack, secondary email, working hours…"><?= h($edit['contact_details'] ?? '') ?></textarea>
    <label>Role</label>
    <select name="role" data-searchable="1">
      <option value="team" <?= ($edit['role'] ?? 'team')==='team'?'selected':'' ?>>team</option>
      <option value="admin" <?= ($edit['role'] ?? '')==='admin'?'selected':'' ?>>admin</option>
    </select>
    <label>Password <?= $edit ? '(leave blank to keep current)' : '(required — only Admin can set)' ?></label>
    <input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?> placeholder="<?= $edit ? '••••••••' : 'Set password' ?>">
    <p class="help">Team users cannot change their own password. Uncheck Active to disable login without deleting.</p>
    <label style="font-weight:500;margin-top:0.8rem"><input type="checkbox" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>> Active (can log in)</label>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Create user' ?></button>
      <?php if ($edit): ?>
        <a class="btn secondary" href="index.php?page=admin_users">Cancel</a>
      <?php endif; ?>
    </p>
  </form>
</div>

<div class="users-workspace<?= $edit ? ' is-editing' : '' ?>">
  <section class="card users-list-panel" aria-labelledby="users-list-title">
    <div class="users-list-head">
      <div>
        <h2 id="users-list-title">People</h2>
        <p class="muted users-list-hint">Click Edit to change a person. Team members also have Site adding history.</p>
      </div>
      <div class="users-filters" role="tablist" aria-label="Filter by role">
        <a class="users-filter<?= $filter === 'all' ? ' active' : '' ?>" href="index.php?page=admin_users">All (<?= count($users) ?>)</a>
        <a class="users-filter<?= $filter === 'admin' ? ' active' : '' ?>" href="index.php?page=admin_users&amp;filter=admin">Admins (<?= count($admins) ?>)</a>
        <a class="users-filter<?= $filter === 'team' ? ' active' : '' ?>" href="index.php?page=admin_users&amp;filter=team">Team (<?= count($team) ?>)</a>
      </div>
    </div>

    <?php if (!$list): ?>
      <div class="users-empty">
        <p>No <?= $filter === 'all' ? 'users' : ($filter === 'admin' ? 'admins' : 'team members') ?> yet.</p>
        <a class="btn" href="#user-form">Add the first user</a>
      </div>
    <?php else: ?>
      <ul class="user-rows">
        <?php foreach ($list as $u): ?>
          <?php
            $isEditing = $edit && (int) $edit['id'] === (int) $u['id'];
            $displayName = trim((string) ($u['full_name'] ?: $u['username']));
            $initial = strtoupper(substr($displayName, 0, 1));
            $contactBits = array_filter([
                trim((string) ($u['email'] ?? '')),
                trim((string) ($u['phone'] ?? '')),
            ], fn($v) => $v !== '');
          ?>
          <li class="user-row<?= $isEditing ? ' is-selected' : '' ?><?= empty($u['is_active']) ? ' is-inactive' : '' ?>">
            <div class="user-row-main">
              <span class="user-avatar" aria-hidden="true"><?= h($initial) ?></span>
              <div class="user-row-text">
                <div class="user-row-title">
                  <strong><?= h($displayName) ?></strong>
                  <span class="badge role-<?= h($u['role']) ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Team' ?></span>
                  <?php if (empty($u['is_active'])): ?>
                    <span class="badge badge-off">Inactive</span>
                  <?php endif; ?>
                </div>
                <div class="user-row-meta">
                  <span>Login: <code><?= h($u['username']) ?></code></span>
                  <?php if ($contactBits): ?>
                    <span><?= h(implode(' · ', $contactBits)) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="user-row-actions">
              <a class="btn secondary small" href="index.php?page=admin_users&amp;edit=<?= (int) $u['id'] ?><?= $filter !== 'all' ? '&amp;filter=' . h($filter) : '' ?>">
                <?= $isEditing ? 'Editing…' : 'Edit' ?>
              </a>
              <?php if ($u['role'] === 'team'): ?>
                <a class="btn secondary small" href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $u['id'] ?>">History</a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card users-form-panel" id="user-form" aria-labelledby="user-form-title">
    <?php if ($edit): ?>
      <div class="users-form-banner">
        <span class="user-avatar" aria-hidden="true"><?= h(strtoupper(substr(trim((string) ($edit['full_name'] ?: $edit['username'])), 0, 1))) ?></span>
        <div>
          <h2 id="user-form-title">Edit <?= h($edit['full_name'] ?: $edit['username']) ?></h2>
          <p class="muted">Update login, role, or password for this person.</p>
        </div>
      </div>
    <?php else: ?>
      <h2 id="user-form-title">Add user</h2>
      <p class="muted users-form-intro">Create an Admin or Team login. Share the password securely after saving.</p>
    <?php endif; ?>

    <form method="post" class="users-form" autocomplete="off">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

      <fieldset class="users-fieldset">
        <legend>Account</legend>
        <div class="users-fields">
          <div>
            <label for="username">Login name</label>
            <input id="username" name="username" value="<?= h($edit['username'] ?? '') ?>" required autocomplete="off" placeholder="e.g. sara">
          </div>
          <div>
            <label for="full_name">Full name <?= ($edit['role'] ?? 'team') === 'admin' || !$edit ? '<span class="field-note">(unique for admins)</span>' : '' ?></label>
            <input id="full_name" name="full_name" value="<?= h($edit['full_name'] ?? '') ?>" placeholder="e.g. Sara Khan">
          </div>
          <div>
            <label for="role">Role</label>
            <select id="role" name="role">
              <option value="team" <?= ($edit['role'] ?? 'team') === 'team' ? 'selected' : '' ?>>Team — adds URLs via Filter &amp; add</option>
              <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin — manages database &amp; users</option>
            </select>
          </div>
          <div>
            <label for="password">Password <?= $edit ? '<span class="field-note">(leave blank to keep)</span>' : '' ?></label>
            <input id="password" type="password" name="password" autocomplete="new-password" placeholder="<?= $edit ? '••••••••' : 'Set a password' ?>">
          </div>
        </div>
      </fieldset>

      <fieldset class="users-fieldset">
        <legend>Contact <span class="field-note">(optional)</span></legend>
        <div class="users-fields">
          <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= h($edit['email'] ?? '') ?>" placeholder="name@company.com">
          </div>
          <div>
            <label for="phone">Phone</label>
            <input id="phone" name="phone" value="<?= h($edit['phone'] ?? '') ?>" placeholder="+92 …">
          </div>
          <div class="users-field-full">
            <label for="contact_details">Notes</label>
            <textarea id="contact_details" name="contact_details" rows="2" placeholder="Slack, working hours, secondary email…"><?= h($edit['contact_details'] ?? '') ?></textarea>
          </div>
        </div>
      </fieldset>

      <fieldset class="users-fieldset users-fieldset-access">
        <legend>Access</legend>
        <label class="users-check">
          <input type="checkbox" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>>
          <span>
            <strong>Can log in</strong>
            <span class="muted">Uncheck to block login without deleting the account.</span>
          </span>
        </label>
      </fieldset>

      <div class="users-form-actions">
        <button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Create user' ?></button>
        <?php if ($edit): ?>
          <a class="btn secondary" href="index.php?page=admin_users<?= $filter !== 'all' ? '&amp;filter=' . h($filter) : '' ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </section>
</div>
<?php render_footer('admin'); ?>
