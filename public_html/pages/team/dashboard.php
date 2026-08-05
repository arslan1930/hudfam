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

$prospectTotal = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
$todayBatch = null;
try {
    $tb = db()->prepare(
        'SELECT * FROM prospect_batches WHERE user_id=? AND batch_date=CURDATE() LIMIT 1'
    );
    $tb->execute([$uid]);
    $todayBatch = $tb->fetch() ?: null;
} catch (Throwable $e) {
    $todayBatch = null;
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
     ORDER BY pi.updated_at DESC LIMIT 8"
);
$results->execute($projectIds);
$results = $results->fetchAll();

render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Search the priced Catalog, filter unique inventory, and cut replied emails — Admin sets prices and campaigns.</p>
  </div>
</div>

<?php render_glossary('team'); ?>
<?= render_team_panel_guide() ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_search">
    <h2>Catalog search</h2>
    <p>Project → Country → Language, then find or add a priced site.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospect_check">
    <h2>Filter & add</h2>
    <p>Paste domains, drop duplicates, save unique sites (no prices).</p>
  </a>
  <a class="launch-card" href="index.php?page=team_email_search">
    <h2>Cut replied emails</h2>
    <p>Paste emails that replied so they leave the Ready send list.</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Today’s batch</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' sites added today' : 'No adds yet today — open your batches' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_projects">
    <h2>My projects</h2>
    <p><?= count($projects) ?> active · inventory has <?= $prospectTotal ?> prospect sites</p>
  </a>
</div>

<div class="card">
  <h2>Recent results</h2>
  <?php if ($results): ?>
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
  <?php else: ?>
    <div class="empty-state">
      <p>No project results yet.</p>
      <a class="btn secondary" href="index.php?page=team_projects">Open projects</a>
    </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
