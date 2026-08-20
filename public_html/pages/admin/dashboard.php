<?php
$user = require_admin();
$schemaOk = true;
$schemaError = '';
$prospectTotal = 0;
$batchCount = 0;
$teamCount = 0;
$countryCount = 0;

try {
    ensure_prospect_schema();
    $prospectTotal = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
    $batchCount = (int) db()->query('SELECT COUNT(*) FROM prospect_batches')->fetchColumn();
    $countryCount = (int) db()->query(
        "SELECT COUNT(DISTINCT TRIM(country)) FROM prospect_sites WHERE TRIM(country) <> ''"
    )->fetchColumn();
} catch (Throwable $e) {
    $schemaOk = false;
    $schemaError = $e->getMessage();
    $prospectTotal = 0;
    $batchCount = 0;
    $countryCount = 0;
}
try {
    $teamCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='team' AND is_active=1")->fetchColumn();
} catch (Throwable $e) {
    $teamCount = 0;
    if ($schemaOk) {
        $schemaOk = false;
        $schemaError = $e->getMessage();
    }
}

$recent = [];
try {
    $recent = list_prospect_batches(null, 8);
} catch (Throwable $e) {
    $recent = [];
    if ($schemaOk) {
        $schemaOk = false;
        $schemaError = $e->getMessage();
    }
}
$orderClientCount = 0;
$invoiceCount = 0;
try {
    ensure_order_schema();
    $orderClientCount = (int) db()->query('SELECT COUNT(*) FROM order_clients')->fetchColumn();
} catch (Throwable $e) {
    $orderClientCount = 0;
}
try {
    ensure_invoice_schema();
    $invoiceCount = (int) db()->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
} catch (Throwable $e) {
    $invoiceCount = 0;
}

render_header('Dashboard', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Admin dashboard</h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?> — each country has its own site database.</p>
  </div>
  <a class="btn" href="index.php?page=admin_prospects">Our database</a>
</div>

<?php if (!$schemaOk): ?>
<ul class="messages"><li class="error">
  Database tables are missing or broken<?= $schemaError !== '' ? ': ' . h($schemaError) : '.' ?>
  Open <a href="upgrade.php">upgrade.php</a> once, then reload this page.
</li></ul>
<?php endif; ?>

<details class="panel-guide-wrap">
  <summary>How Admin works</summary>
  <?php render_glossary('admin'); ?>
  <?= render_admin_panel_guide() ?>
</details>

<div class="grid">
  <div class="card stat"><span class="muted">Sites (all countries)</span><strong><?= (int) $prospectTotal ?></strong></div>
  <div class="card stat"><span class="muted">Countries with sites</span><strong><?= (int) $countryCount ?></strong></div>
  <div class="card stat"><span class="muted">Site adding history days</span><strong><?= (int) $batchCount ?></strong></div>
  <div class="card stat"><span class="muted">Active team users</span><strong><?= (int) $teamCount ?></strong></div>
</div>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_prospects">
    <h2>Our database</h2>
    <p>Open country folders — browse and add sites.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_add">
    <h2>Add sites</h2>
    <p>Paste websites into a country folder.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_orders">
    <h2>Order management</h2>
    <p>Client sheets — sites, prices, profit, live URL.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_invoices">
    <h2>Invoices</h2>
    <p>Generate printable invoices from completed articles.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_batches">
    <h2>Site adding history</h2>
    <p>See who added sites, by day — edit or delete.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_users">
    <h2>Users</h2>
    <p>Add &amp; edit who can log in.</p>
  </a>
</div>

<div class="card">
  <h2>Recent adds</h2>
  <?php if ($recent): ?>
    <table>
      <thead><tr><th>Date</th><th>Person</th><th>Country</th><th>Sites</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b): ?>
        <tr>
          <td><?= h($b['batch_date']) ?></td>
          <td><?= h($b['full_name'] ?: $b['username']) ?></td>
          <td><?= h($b['country'] ?: '—') ?></td>
          <td><?= (int) $b['site_count'] ?></td>
          <td><a href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">Edit</a></td>
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
