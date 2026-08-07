<?php
$user = require_team();
$uid = (int) $user['id'];

$todayBatch = null;
$extractCount = 0;
try {
    $tb = db()->prepare(
        'SELECT * FROM prospect_batches WHERE user_id=? AND batch_date=CURDATE() LIMIT 1'
    );
    $tb->execute([$uid]);
    $todayBatch = $tb->fetch() ?: null;
} catch (Throwable $e) {
    $todayBatch = null;
}
try {
    $extractCount = count_extract_batches();
} catch (Throwable $e) {
    $extractCount = 0;
}

render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Filter new sites against a country database, then add only the unique ones. Existing country lists stay private.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
</div>

<?php render_glossary('team'); ?>
<?= render_team_panel_guide() ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_prospect_check">
    <h2>Filter &amp; add</h2>
    <p>Filter against the country database, then add only new unique sites.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_extracting">
    <h2>Extracting sites</h2>
    <p><?= $extractCount > 0 ? $extractCount . ' country batch' . ($extractCount === 1 ? '' : 'es') . ' ready' : 'Waiting for sites from the team mate' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_sites_emails">
    <h2>Sites with emails - Team</h2>
    <p>Add emails after Extracting Results Push, then Push to Admin.</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Today’s history</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' sites added today' : 'No adds yet today' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospect_batches">
    <h2>Add history</h2>
    <p>Sites you added, saved by day.</p>
  </a>
</div>
<?php render_footer('team'); ?>
