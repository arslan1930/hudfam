<?php
$user = require_team();
$uid = (int) $user['id'];

$counts = [];
foreach (['draft', 'negotiating', 'agreed', 'sent', 'rejected', 'processing', 'completed'] as $st) {
    $s = db()->prepare('SELECT COUNT(*) FROM sites WHERE assigned_to=? AND status=?');
    $s->execute([$uid, $st]);
    $counts[$st] = (int) $s->fetchColumn();
}

// Country counts for quick folders
$countryCounts = db()->prepare(
    "SELECT country, COUNT(*) c FROM sites
     WHERE country <> '' AND (assigned_to = ? OR status = 'agreed')
     GROUP BY country ORDER BY c DESC LIMIT 8"
);
$countryCounts->execute([$uid]);
$countryCounts = $countryCounts->fetchAll();

if (is_admin($user)) {
    $projects = db()->query("SELECT * FROM projects WHERE status='active' ORDER BY name LIMIT 6")->fetchAll();
} else {
    $stmt = db()->prepare(
        "SELECT p.* FROM projects p
         JOIN project_members pm ON pm.project_id=p.id
         WHERE pm.user_id=? AND p.status='active' ORDER BY p.name LIMIT 6"
    );
    $stmt->execute([$uid]);
    $projects = $stmt->fetchAll();
}
$projectIds = array_column($projects, 'id') ?: [0];
$in = implode(',', array_fill(0, count($projectIds), '?'));
$results = db()->prepare(
    "SELECT pi.*, s.domain, p.name project_name
     FROM pitch_items pi
     JOIN sites s ON s.id=pi.site_id
     JOIN pitches ph ON ph.id=pi.pitch_id
     JOIN projects p ON p.id=ph.project_id
     WHERE ph.project_id IN ($in) AND pi.item_status != 'sent'
     ORDER BY pi.updated_at DESC LIMIT 10"
);
$results->execute($projectIds);
$results = $results->fetchAll();

render_header('Team dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Work in the inventory catalog. Contact website owners, save quote + agreed price. Projects are briefs/results only.</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=team_site_form">Add inventory site</a>
    <a class="btn secondary" href="index.php?page=team_countries">Country folders</a>
  </div>
</div>
<div class="grid">
  <?php foreach ($counts as $k => $v): ?>
    <div class="card stat"><span class="muted"><?= h($k) ?></span><strong><?= $v ?></strong></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>Country folders</h2>
  <div class="folders" style="margin-top:0.8rem">
  <?php foreach ($countryCounts as $c): ?>
    <a class="folder" href="index.php?page=team_country&name=<?= urlencode($c['country']) ?>">
      <h3><?= h($c['country']) ?></h3>
      <p><span class="badge"><?= (int) $c['c'] ?> sites</span></p>
    </a>
  <?php endforeach; ?>
  <?php if (!$countryCounts): ?>
    <p class="muted">No country data yet. <a href="index.php?page=team_countries">Browse all countries</a>.</p>
  <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Project briefs (read-only)</h2>
  <p class="muted">View requirements and Admin results — do not add sites into projects.</p>
  <div class="folders" style="margin-top:0.8rem">
  <?php foreach ($projects as $p): ?>
    <a class="folder" href="index.php?page=team_project&id=<?= (int) $p['id'] ?>">
      <h3><?= h($p['name']) ?></h3>
      <p class="muted"><?= h($p['niche'] ?: '—') ?> · <?= h($p['countries'] ?: '—') ?></p>
    </a>
  <?php endforeach; ?>
  <?php if (!$projects): ?><p class="muted">No projects assigned.</p><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Latest results (read-only)</h2>
  <?php foreach ($results as $item): ?>
    <div class="history-item">
      <strong><?= h($item['domain']) ?></strong> · <?= h($item['project_name']) ?>
      <?= badge($item['item_status']) ?>
      <div class="muted">
        <?php if ($item['reject_reason_code']): ?><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?> — <?php endif; ?>
        <?= h($item['reject_comment'] ?: $item['client_notes']) ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$results): ?><p class="muted">No results yet.</p><?php endif; ?>
</div>
<?php render_footer('team'); ?>
