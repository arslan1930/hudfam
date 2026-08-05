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
$counts = [];
foreach ($projects as $p) {
    $c = db()->prepare('SELECT COUNT(*) FROM sites WHERE primary_project_id=?');
    $c->execute([(int) $p['id']]);
    $counts[(int) $p['id']] = (int) $c->fetchColumn();
}
render_header('Projects', 'team');
?>
<div class="topbar">
  <div>
    <h1>Projects</h1>
    <p class="muted">Each project has its own catalog. Use Catalog search (Project → Country → Language) to look up or add sites.</p>
  </div>
</div>
<?= guide_team_projects() ?>
<div class="folders">
<?php foreach ($projects as $p): ?>
  <a class="folder" href="index.php?page=team_project&id=<?= (int)$p['id'] ?>&tab=inventory">
    <h3><?= h($p['name']) ?></h3>
    <p class="muted"><?= h($p['niche'] ?: '—') ?></p>
    <p><?= h($p['countries'] ?: '—') ?> · <?= money_or_dash($p['price_min']) ?>–<?= money_or_dash($p['price_max']) ?> <?= h($p['currency']) ?></p>
    <p><span class="badge"><?= (int) ($counts[(int)$p['id']] ?? 0) ?> sites</span></p>
  </a>
<?php endforeach; ?>
<?php if (!$projects): ?><div class="card">No assigned projects.</div><?php endif; ?>
</div>
<?php render_footer('team'); ?>
