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

$openTaskCount = 0;
try {
    $openTaskCount = count_open_tasks_for_user($uid);
} catch (Throwable $e) {
    $openTaskCount = 0;
}

render_header('Dashboard', 'team');
$frequent = user_frequent_countries($uid, 6);
$topCountry = $frequent[0]['name'] ?? '';
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Pick a country to save into, filter against all countries, then add only globally unique sites.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">Filter &amp; add<?= $topCountry !== '' ? ' · ' . h($topCountry) : '' ?></a>
</div>

<?php render_glossary('team'); ?>
<?= render_team_panel_guide() ?>
<?= render_frequent_country_chips($frequent, 'index.php?page=team_prospect_check&country=') ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_tasks">
    <h2>My tasks</h2>
    <p><?= $openTaskCount > 0 ? $openTaskCount . ' open task' . ($openTaskCount === 1 ? '' : 's') . ' from Admin.' : 'No open tasks right now.' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospect_check<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">
    <h2>Filter &amp; add</h2>
    <p><?= $topCountry !== '' ? 'Continue with ' . h($topCountry) . ' (your most-used).' : 'Select country → paste → Filter (all countries) → add unique.' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospects">
    <h2>Our database</h2>
    <p><?= $prospectTotal ?> URLs across country folders.</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Today’s history</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' sites added today' : 'No adds yet today' ?></p>
  </a>
</div>
<?php render_footer('team'); ?>
