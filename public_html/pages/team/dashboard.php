<?php
$user = require_team();
$uid = (int) $user['id'];

$schemaOk = true;
$schemaError = '';
$todayBatch = null;
try {
    ensure_prospect_schema();
    $tb = db()->prepare(
        'SELECT * FROM prospect_batches WHERE user_id=? AND batch_date=CURDATE() LIMIT 1'
    );
    $tb->execute([$uid]);
    $todayBatch = $tb->fetch() ?: null;
} catch (Throwable $e) {
    $schemaOk = false;
    $schemaError = $e->getMessage();
    $todayBatch = null;
}

render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Pick a country database, filter new sites against it, then add only the unique ones.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
</div>

<?php if (!$schemaOk): ?>
<ul class="messages"><li class="error">
  Database tables are missing or broken<?= $schemaError !== '' ? ': ' . h($schemaError) : '.' ?>
  Ask Admin to open <a href="upgrade.php">upgrade.php</a>, then reload.
</li></ul>
<?php endif; ?>

<details class="panel-guide-wrap">
  <summary>How Team works</summary>
  <?php render_glossary('team'); ?>
  <?= render_team_panel_guide() ?>
</details>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_prospect_check">
    <h2>Filter &amp; add</h2>
    <p>Select country → paste → remove duplicates for that country.</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Today’s history</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' sites added today' : 'No adds yet today' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospect_batches">
    <h2>Site adding history</h2>
    <p>Your daily batches of new sites.</p>
  </a>
</div>
<?php render_footer('team'); ?>
