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

render_header('Dashboard', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Admin dashboard</h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?> — manage the shared URL database.</p>
  </div>
  <a class="btn" href="index.php?page=admin_prospect_add">Add URLs</a>
</div>

<?php render_glossary('admin'); ?>
<?= render_admin_panel_guide() ?>

<div class="grid">
  <div class="card stat"><span class="muted">URLs in database</span><strong><?= $prospectTotal ?></strong></div>
  <div class="card stat"><span class="muted">Add history days</span><strong><?= $batchCount ?></strong></div>
  <div class="card stat"><span class="muted">Active team users</span><strong><?= $teamCount ?></strong></div>
</div>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_prospect_add">
    <h2>Add URLs</h2>
    <p>Paste websites into Our database.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospects">
    <h2>Our database</h2>
    <p>Browse and search all unique domains.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_batches">
    <h2>Add history</h2>
    <p>See who added sites, by day.</p>
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
      <a class="btn" href="index.php?page=admin_prospect_add">Add the first URLs</a>
    </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
