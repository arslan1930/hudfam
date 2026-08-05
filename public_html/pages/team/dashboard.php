<?php
$user = require_team();
$uid = (int) $user['id'];

$prospectTotal = 0;
$todayBatch = null;
try {
    $prospectTotal = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
} catch (Throwable $e) {
    $prospectTotal = 0;
}
try {
    $tb = db()->prepare(
        'SELECT * FROM prospect_batches WHERE user_id=? AND batch_date=CURDATE() LIMIT 1'
    );
    $tb->execute([$uid]);
    $todayBatch = $tb->fetch() ?: null;
} catch (Throwable $e) {
    $todayBatch = null;
}

render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Filter new sites against Our database, then add only the unique ones.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
</div>

<?php render_glossary('team'); ?>
<?= render_team_panel_guide() ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_prospect_check">
    <h2>Filter &amp; add</h2>
    <p>Paste domains, remove duplicates, save unique sites.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospects">
    <h2>Our database</h2>
    <p><?= $prospectTotal ?> unique domains shared with Admin.</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Today’s history</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' sites added today' : 'No adds yet today' ?></p>
  </a>
</div>
<?php render_footer('team'); ?>
