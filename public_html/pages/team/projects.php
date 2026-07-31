<?php
$user = require_team();
$uid = (int) $user['id'];
if (is_admin($user)) {
    $projects = db()->query("SELECT * FROM projects WHERE status='active' ORDER BY name")->fetchAll();
} else {
    $stmt = db()->prepare(
        "SELECT p.* FROM projects p JOIN project_members pm ON pm.project_id=p.id
         WHERE pm.user_id=? AND p.status='active' ORDER BY p.name"
    );
    $stmt->execute([$uid]);
    $projects = $stmt->fetchAll();
}
render_header('My projects', 'team');
?>
<div class="topbar">
  <div>
    <h1>My project folders</h1>
    <p class="muted">Click a folder to start work for that client.</p>
  </div>
</div>
<div class="folders">
<?php foreach ($projects as $p): ?>
  <a class="folder" href="index.php?page=team_project&id=<?= (int)$p['id'] ?>">
    <h3><?= h($p['name']) ?></h3>
    <p class="muted"><?= h($p['niche'] ?: '—') ?></p>
    <p><?= h($p['countries'] ?: '—') ?> · <?= money_or_dash($p['price_min']) ?>–<?= money_or_dash($p['price_max']) ?> <?= h($p['currency']) ?></p>
  </a>
<?php endforeach; ?>
<?php if (!$projects): ?><div class="card">No assigned projects.</div><?php endif; ?>
</div>
<?php render_footer('team'); ?>
