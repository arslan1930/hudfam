<?php
require_admin();
require_once __DIR__ . '/../../includes/orders.php';

$q = trim((string) get('q'));
$projectId = (int) get('project_id');

$sql = 'SELECT c.*, p.name AS project_name,
        (SELECT COUNT(*) FROM publication_orders o WHERE o.client_id=c.id) AS order_count,
        (SELECT COUNT(*) FROM publication_orders o WHERE o.client_id=c.id AND o.status="processing") AS processing_count
        FROM clients c
        JOIN projects p ON p.id = c.project_id
        WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (c.name LIKE ? OR c.email LIKE ? OR p.name LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
if ($projectId) {
    $sql .= ' AND c.project_id = ?';
    $params[] = $projectId;
}
$sql .= ' ORDER BY p.name, c.name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();
$projects = db()->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();

render_header('Clients', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Client email folders</h1>
    <p class="muted">Each client (name + email) holds their publication deals.</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_client_form<?= $projectId ? '&project_id='.$projectId : '' ?>">New client folder</a>
    <a class="btn secondary" href="index.php?page=admin_orders_export">Download spreadsheet</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_clients">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="name, email, project"></div>
  <div>
    <label>Project</label>
    <select name="project_id">
      <option value="">All projects</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $projectId===(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="folders">
<?php foreach ($clients as $c): ?>
  <a class="folder" href="index.php?page=admin_client&id=<?= (int)$c['id'] ?>">
    <h3><?= h($c['name']) ?></h3>
    <p class="muted"><?= h($c['email']) ?></p>
    <p>Project: <?= h($c['project_name']) ?></p>
    <p>
      <span class="badge"><?= (int)$c['order_count'] ?> orders</span>
      <?php if ((int)$c['processing_count'] > 0): ?>
        <span class="badge processing"><?= (int)$c['processing_count'] ?> processing</span>
      <?php endif; ?>
    </p>
  </a>
<?php endforeach; ?>
<?php if (!$clients): ?><div class="card">No client folders yet. Create one under a project.</div><?php endif; ?>
</div>
<?php render_footer('admin'); ?>
