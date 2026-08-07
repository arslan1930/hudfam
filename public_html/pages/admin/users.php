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
        flash('error', 'Username required.');
    } elseif ($role === 'admin' && $full === '') {
        flash('error', 'Admins need a unique full name.');
    } else {
        // Enforce unique full name among admins
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
            ensure_users_auth_schema();
            if ($id) {
                if ($password !== '') {
                    if (in_array($password, known_weak_passwords(), true)) {
                        flash('error', 'Do not use demo default passwords (admin123 / team123).');
                        redirect('index.php?page=admin_users&edit=' . $id);
                    }
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=?, password_hash=?, must_change_password=1 WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    db()->prepare(
                        'UPDATE users SET username=?, full_name=?, email=?, phone=?, contact_details=?, role=?, is_active=? WHERE id=?'
                    )->execute([$username, $full, $email, $phone, $contact, $role, $active, $id]);
                }
                flash('ok', 'User updated.' . ($password !== '' ? ' They must change the password on next login.' : ''));
            } else {
                if ($password === '') {
                    $password = bin2hex(random_bytes(5));
                }
                if (in_array($password, known_weak_passwords(), true)) {
                    flash('error', 'Do not use demo default passwords (admin123 / team123).');
                    redirect('index.php?page=admin_users');
                }
                db()->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role, is_active, must_change_password)
                     VALUES (?,?,?,?,?,?,?,?,1)'
                )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full, $email, $phone, $contact, $role, $active]);
                flash('ok', 'User created. Temporary password: ' . $password . ' (must change on first login)');
            }
        } catch (PDOException $e) {
            flash('error', 'Could not save (username must be unique).');
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
$users = db()->query('SELECT * FROM users ORDER BY role, full_name, username')->fetchAll();
$admins = array_values(array_filter($users, fn($u) => $u['role'] === 'admin'));

render_header('Admins & users', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Users</h1>
    <p class="muted">Admin and Team logins for the shared URL database.</p>
  </div>
</div>
<?= guide_admin_users() ?>

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
  <h2>All users</h2>
  <table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Contact</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td><span class="badge"><?= h($u['role']) ?></span></td>
        <td class="help"><?= h($u['email'] ?: '—') ?><?= !empty($u['phone']) ? ' · ' . h($u['phone']) : '' ?></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="actions">
          <a href="index.php?page=admin_users&edit=<?= (int)$u['id'] ?>">Edit</a>
          <?php if ($u['role'] === 'team'): ?>
            <a href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $u['id'] ?>">Site adding history</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="card">
  <h2><?= $edit ? 'Edit user' : 'New admin / team user' ?></h2>
  <form method="post" action="index.php?page=admin_users">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Username</label>
    <input name="username" value="<?= h($edit['username'] ?? '') ?>" required>
    <label>Full name <?= ($edit['role'] ?? '') === 'admin' || !isset($edit) ? '(unique for admins)' : '' ?></label>
    <input name="full_name" value="<?= h($edit['full_name'] ?? '') ?>">
    <label>Email</label>
    <input name="email" value="<?= h($edit['email'] ?? '') ?>" type="email">
    <label>Phone</label>
    <input name="phone" value="<?= h($edit['phone'] ?? '') ?>">
    <label>Contact details</label>
    <textarea name="contact_details" rows="2" placeholder="Slack, secondary email, working hours…"><?= h($edit['contact_details'] ?? '') ?></textarea>
    <label>Role</label>
    <select name="role">
      <option value="team" <?= ($edit['role'] ?? '')==='team'?'selected':'' ?>>team</option>
      <option value="admin" <?= ($edit['role'] ?? '')==='admin'?'selected':'' ?>>admin</option>
    </select>
    <label>Password <?= $edit ? '(blank = keep)' : '' ?></label>
    <input type="password" name="password">
    <label style="font-weight:500;margin-top:0.8rem"><input type="checkbox" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>> Active</label>
    <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save</button></p>
  </form>
</div>
</div>
<?php render_footer('admin'); ?>
