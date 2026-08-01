<?php
$user = require_team();
$uid = (int) $user['id'];

if (is_admin($user)) {
    $projects = db()->query("SELECT * FROM projects WHERE status='active' ORDER BY name LIMIT 12")->fetchAll();
} else {
    $stmt = db()->prepare(
        "SELECT p.* FROM projects p
         JOIN project_members pm ON pm.project_id=p.id
         WHERE pm.user_id=? AND p.status='active' ORDER BY p.name LIMIT 12"
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

$projectIds = array_column($projects, 'id') ?: [0];
$in = implode(',', array_fill(0, count($projectIds), '?'));
$results = db()->prepare(
    "SELECT pi.*, s.domain, s.our_mailbox, p.name project_name
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
    <p class="muted">Use Super search to check domains (site details only). Work inside your assigned projects to add/filter sites.</p>
  </div>
  <a class="btn" href="index.php?page=team_search">Super search</a>
</div>

<div class="card">
  <h2>Your projects</h2>
  <div class="folders" style="margin-top:0.8rem">
  <?php foreach ($projects as $p): ?>
    <a class="folder" href="index.php?page=team_project&id=<?= (int) $p['id'] ?>&tab=inventory">
      <h3><?= h($p['name']) ?></h3>
      <p class="muted"><?= h($p['niche'] ?: '—') ?> · <?= h($p['countries'] ?: '—') ?></p>
      <p><span class="badge"><?= (int) ($counts[(int) $p['id']] ?? 0) ?> sites</span></p>
    </a>
  <?php endforeach; ?>
  <?php if (!$projects): ?><p class="muted">No projects assigned.</p><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Latest results</h2>
  <?php foreach ($results as $item): ?>
    <div class="history-item">
      <strong><?= h($item['domain']) ?></strong> · <?= h($item['project_name']) ?>
      <?= badge($item['item_status']) ?>
      <div class="muted">
        Mailbox: <?= h($item['our_mailbox'] ?: '—') ?> ·
        <?php if ($item['reject_reason_code']): ?><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?> — <?php endif; ?>
        <?= h($item['reject_comment'] ?: $item['client_notes']) ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$results): ?><p class="muted">No results yet.</p><?php endif; ?>
</div>
<?php render_footer('team'); ?>
