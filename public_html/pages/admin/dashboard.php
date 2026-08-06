<?php
$user = require_admin();
$prospectTotal = 0;
$batchCount = 0;
$teamCount = 0;
try {
    $prospectTotal = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
} catch (Throwable $e) {
    $prospectTotal = 0;
}
try {
    $batchCount = (int) db()->query('SELECT COUNT(*) FROM prospect_batches')->fetchColumn();
} catch (Throwable $e) {
    $batchCount = 0;
}
try {
    $teamCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='team' AND is_active=1")->fetchColumn();
} catch (Throwable $e) {
    $teamCount = 0;
}

$recent = [];
try {
    $recent = list_prospect_batches(null, 8);
} catch (Throwable $e) {
    $recent = [];
}
$openTasks = 0;
try {
    ensure_tasks_schema();
    $openTasks = (int) db()->query(
        "SELECT COUNT(*) FROM team_tasks WHERE status IN ('open','in_progress')"
    )->fetchColumn();
} catch (Throwable $e) {
    $openTasks = 0;
}

render_header('Dashboard', 'admin');
$frequent = user_frequent_countries((int) $user['id'], 6);
$topCountry = $frequent[0]['name'] ?? '';
?>
<div class="topbar">
  <div>
    <h1>Admin dashboard</h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?>.</p>
  </div>
  <div class="actions" style="align-items:center;gap:0.75rem">
    <time id="live-datetime" class="live-datetime" datetime="<?= h(date('c')) ?>"><?= h(date('l · d M Y · H:i:s')) ?></time>
    <a class="btn" href="index.php?page=admin_prospect_add<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">Add sites<?= $topCountry !== '' ? ' · ' . h($topCountry) : '' ?></a>
  </div>
</div>

<?= render_frequent_country_chips($frequent, 'index.php?page=admin_prospect_add&country=') ?>

<div class="grid">
  <div class="card stat"><span class="muted">URLs (all countries)</span><strong><?= $prospectTotal ?></strong></div>
  <div class="card stat"><span class="muted">Added sites (days)</span><strong><?= $batchCount ?></strong></div>
  <div class="card stat"><span class="muted">Open tasks</span><strong><?= $openTasks ?></strong></div>
  <div class="card stat"><span class="muted">Active team users</span><strong><?= $teamCount ?></strong></div>
</div>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_prospect_add<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">
    <h2>Add sites</h2>
    <p><?= $topCountry !== '' ? 'Continue with ' . h($topCountry) . '.' : 'Paste domains into a country folder.' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=admin_users">
    <h2>Users &amp; tasks</h2>
    <p><?= $openTasks ?> open tasks · manage teammates.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospects">
    <h2>Our database</h2>
    <p>Browse by country.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_account">
    <h2>Account</h2>
    <p>Email &amp; password.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_batches">
    <h2>Added sites</h2>
    <p>Who added sites, by day.</p>
  </a>
</div>

<div class="card">
  <h2>Recent adds</h2>
  <?php if ($recent): ?>
    <table>
      <thead><tr><th>Date</th><th>Person</th><th>Sites</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b): ?>
        <tr>
          <td><?= h($b['batch_date']) ?></td>
          <td><?= h($b['full_name'] ?: $b['username']) ?></td>
          <td><?= (int) $b['site_count'] ?></td>
          <td><a href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="empty-state">
      <p>No sites added yet.</p>
      <a class="btn" href="index.php?page=admin_prospect_add">Add the first sites</a>
    </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
