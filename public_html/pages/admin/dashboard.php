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
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?> — each country has its own URL database.</p>
  </div>
  <a class="btn" href="index.php?page=admin_prospect_add">Add sites</a>
</div>

<?php render_glossary('admin'); ?>
<?= render_admin_panel_guide() ?>

<div class="grid">
  <div class="card stat"><span class="muted">URLs (all countries)</span><strong><?= $prospectTotal ?></strong></div>
  <div class="card stat"><span class="muted">Add history days</span><strong><?= $batchCount ?></strong></div>
  <div class="card stat"><span class="muted">Active team users</span><strong><?= $teamCount ?></strong></div>
  <div class="card stat"><span class="muted">Client sheets</span><strong><?= $orderClientCount ?></strong></div>
  <div class="card stat"><span class="muted">Invoices</span><strong><?= $invoiceCount ?></strong></div>
</div>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_prospect_add">
    <h2>Add sites</h2>
    <p>Paste root domains into a country folder.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospects">
    <h2>Our database</h2>
    <p>Open country folders — one database each.</p>
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
      <a class="btn" href="index.php?page=admin_prospect_add">Add the first sites</a>
    </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
