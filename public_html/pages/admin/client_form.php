<?php
$user = require_admin();
require_once __DIR__ . '/../../includes/orders.php';

$id = (int) get('id');
$preProject = (int) get('project_id');
$client = [
    'project_id' => $preProject ?: '',
    'name' => '',
    'email' => '',
    'notes' => '',
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id=?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if ($found) {
        $client = $found;
    }
}
$projects = db()->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = (int) post('project_id');
    $name = trim((string) post('name'));
    $email = trim((string) post('email'));
    $notes = trim((string) post('notes'));
    if (!$projectId || $name === '' || $email === '') {
        flash('error', 'Project, name, and email are required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address.');
    } else {
        try {
            if ($id) {
                db()->prepare(
                    'UPDATE clients SET project_id=?, name=?, email=?, notes=? WHERE id=?'
                )->execute([$projectId, $name, $email, $notes, $id]);
            } else {
                db()->prepare(
                    'INSERT INTO clients (project_id, name, email, notes, created_by) VALUES (?,?,?,?,?)'
                )->execute([$projectId, $name, $email, $notes, $user['id']]);
                $id = (int) db()->lastInsertId();
            }
            flash('ok', 'Client folder saved.');
            redirect('index.php?page=admin_client&id=' . $id);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                flash('error', 'This email already exists in that project.');
            } else {
                flash('error', 'Could not save client: ' . $e->getMessage());
            }
        }
    }
}

render_header($id ? 'Edit client' : 'New client folder', 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= $id ? 'Edit client folder' : 'New client folder' ?></h1>
    <p class="muted">Name + email identify this client’s deals under a project.</p>
  </div>
</div>
<div class="card">
<form method="post">
  <div class="form-grid">
    <div>
      <label>Project</label>
      <select name="project_id" required>
        <option value="">Select project…</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (string)$client['project_id']===(string)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Client name</label><input name="name" value="<?= h($client['name']) ?>" required></div>
    <div><label>Client email</label><input type="email" name="email" value="<?= h($client['email']) ?>" required></div>
    <div class="full"><label>Notes</label><textarea name="notes" rows="3"><?= h($client['notes']) ?></textarea></div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save folder</button>
    <a class="btn secondary" href="index.php?page=admin_clients">Cancel</a>
  </p>
</form>
</div>
<?php render_footer('admin'); ?>
