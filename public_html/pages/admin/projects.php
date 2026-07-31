<?php
require_admin();
$q = trim((string) get('q'));
$sql = 'SELECT p.*, (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id=p.id) member_count FROM projects p';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE p.name LIKE ? OR p.client_name LIKE ? OR p.niche LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY p.name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();
render_header('Projects', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Project folders</h1>
    <p class="muted">Each client campaign has its own requirements.</p>
  </div>
  <a class="btn" href="index.php?page=admin_project_form">New project</a>
</div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_projects">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
  <button class="btn" type="submit">Search</button>
</form>
<div class="folders">
<?php foreach ($projects as $p): ?>
  <a class="folder" href="index.php?page=admin_project&id=<?= (int) $p['id'] ?>">
    <h3><?= h($p['name']) ?></h3>
    <p class="muted"><?= h($p['client_name'] ?: 'Client project') ?> · <?= h($p['niche'] ?: 'No niche') ?></p>
    <p><?= badge($p['status']) ?> · <?= (int) $p['member_count'] ?> team · <?= h($p['budget'] ?: '—') ?></p>
  </a>
<?php endforeach; ?>
<?php if (!$projects): ?><div class="card">No projects yet.</div><?php endif; ?>
</div>
<?php render_footer('admin'); ?>
