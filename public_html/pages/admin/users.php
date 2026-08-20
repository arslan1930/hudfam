<?php
require_admin();
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
                } else {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=? WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, $id]);
                }
                flash('ok', 'User updated.');
            } else {
                if ($password === '') {
                    $password = bin2hex(random_bytes(4));
                }
                db()->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role, is_active)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full, $email, $phone, $contact, $role, $active]);
                flash('ok', 'User created. Temporary password: ' . $password);
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
$activeCount = count(array_filter($users, fn($u) => !empty($u['is_active'])));

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
    <p class="muted">Who can log in — Admins manage the database; Team adds new URLs.</p>
  </div>
  <?php if (!$edit): ?>
    <a class="btn" href="#user-form">Add user</a>
  <?php else: ?>
    <a class="btn secondary" href="index.php?page=admin_users">Back to list</a>
  <?php endif; ?>
</div>

<div class="users-summary">
  <div class="users-summary-item">
    <strong><?= count($users) ?></strong>
    <span>Total</span>
  </div>
  <div class="users-summary-item">
    <strong><?= count($admins) ?></strong>
    <span>Admins</span>
  </div>
  <div class="users-summary-item">
    <strong><?= count($team) ?></strong>
    <span>Team</span>
  </div>
  <div class="users-summary-item">
    <strong><?= (int) $activeCount ?></strong>
    <span>Can log in</span>
  </div>
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
