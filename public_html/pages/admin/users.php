<?php
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    $id = (int) post('id');
    $username = trim((string) post('username'));
    $role = post('role') === 'admin' ? 'admin' : 'team';
    $active = post('is_active') ? 1 : 0;
    $full = trim((string) post('full_name'));
    $email = trim((string) post('email'));
    $password = (string) post('password');
    if ($username === '') {
        flash('error', 'Username required.');
    } elseif ($id) {
        if ($password !== '') {
            db()->prepare('UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=?, password_hash=? WHERE id=?')
                ->execute([$username, $full, $email, $role, $active, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            db()->prepare('UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=? WHERE id=?')
                ->execute([$username, $full, $email, $role, $active, $id]);
        }
        flash('ok', 'User updated.');
    } else {
        if ($password === '') {
            $password = bin2hex(random_bytes(4));
        }
        db()->prepare('INSERT INTO users (username, password_hash, full_name, email, role, is_active) VALUES (?,?,?,?,?,?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full, $email, $role, $active]);
        flash('ok', 'User created. Password: ' . $password);
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
$users = db()->query('SELECT * FROM users ORDER BY role, username')->fetchAll();
render_header('Users', 'admin');
?>
<div class="topbar">
  <div><h1>Users</h1><p class="muted">Admin manages clients/statuses. Team supplies sites.</p></div>
</div>
<div class="grid" style="grid-template-columns:1.2fr 1fr">
<div class="card">
  <table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['full_name'] ?: '—') ?></td>
        <td><span class="badge"><?= h($u['role']) ?></span></td>
        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
        <td><a href="index.php?page=admin_users&edit=<?= (int)$u['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="card">
  <h2><?= $edit ? 'Edit user' : 'New user' ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Username</label>
    <input name="username" value="<?= h($edit['username'] ?? '') ?>" required>
    <label>Full name</label>
    <input name="full_name" value="<?= h($edit['full_name'] ?? '') ?>">
    <label>Email</label>
    <input name="email" value="<?= h($edit['email'] ?? '') ?>">
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
