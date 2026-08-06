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
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?>.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">Filter &amp; add<?= $topCountry !== '' ? ' · ' . h($topCountry) : '' ?></a>
</div>

<?= render_frequent_country_chips($frequent, 'index.php?page=team_prospect_check&country=') ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_tasks">
    <h2>My tasks</h2>
    <p><?= $openTaskCount > 0 ? $openTaskCount . ' open' : 'None open' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospect_check<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">
    <h2>Filter &amp; add</h2>
    <p><?= $topCountry !== '' ? 'Continue with ' . h($topCountry) . '.' : 'Paste → filter → add unique.' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospects">
    <h2>Our database</h2>
    <p><?= $prospectTotal ?> URLs</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Added sites</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' today' : 'None today' ?></p>
  </a>
</div>
<?php render_footer('team'); ?>
