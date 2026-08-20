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
    <a class="btn" href="index.php?page=admin_prospects">Countries</a>
  </div>
</div>

<?= render_frequent_country_chips($frequent, 'index.php?page=admin_prospects&country=') ?>

<div class="grid">
  <div class="card stat"><span class="muted">Sites (all countries)</span><strong><?= $prospectTotal ?></strong></div>
  <div class="card stat"><span class="muted">Added sites (days)</span><strong><?= $batchCount ?></strong></div>
  <div class="card stat"><span class="muted">Open tasks</span><strong><?= $openTasks ?></strong></div>
  <div class="card stat"><span class="muted">Active team users</span><strong><?= $teamCount ?></strong></div>
</div>

<section style="margin:1.5rem 0 0.75rem">
  <h2 style="margin:0 0 0.35rem">Sites Data</h2>
  <p class="muted" style="margin:0">Country folders, admin adds, and daily added-sites history.</p>
</section>
<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_prospects">
    <h2>Countries</h2>
    <p>Browse and manage sites by country folder.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_add<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">
    <h2>Sites add by admin</h2>
    <p><?= $topCountry !== '' ? 'Continue with ' . h($topCountry) . '.' : 'Paste domains into a country folder.' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_batches">
    <h2>Added sites</h2>
    <p>Who added sites, by day (admin + team).</p>
  </a>
</div>

<?php
$extractTotals = ['queue' => 0, 'extracted' => 0, 'with_emails' => 0];
try {
    ensure_extract_schema();
    $extractTotals = extract_totals();
} catch (Throwable $e) {
}
?>
<section style="margin:1.75rem 0 0.75rem">
  <h2 style="margin:0 0 0.35rem">Extracting Sites with Emails</h2>
  <p class="muted" style="margin:0">
    Block 1 queue <?= (int) $extractTotals['queue'] ?> ·
    Block 2 extracted <?= (int) $extractTotals['extracted'] ?> ·
    with emails <?= (int) $extractTotals['with_emails'] ?>
  </p>
</section>
<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_extract_sites">
    <h2>Extracted sites</h2>
    <p>Block 1 need extraction · Block 2 final lists (by country).</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_extract_emails">
    <h2>Extracted sites with Emails</h2>
    <p>Emails branched under each extracted site.</p>
  </a>
</div>

<section style="margin:1.75rem 0 0.75rem">
  <h2 style="margin:0 0 0.35rem">People</h2>
  <p class="muted" style="margin:0">Accounts, tasks, and your admin login.</p>
</section>
<div class="launch-cards">
  <a class="launch-card" href="index.php?page=admin_users">
    <h2>Users &amp; tasks</h2>
    <p><?= $openTasks ?> open tasks · manage teammates.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_account">
    <h2>Account</h2>
    <p>Email &amp; password.</p>
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
      <a class="btn" href="index.php?page=admin_prospect_add">Sites add by admin</a>
    </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
