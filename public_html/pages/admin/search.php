<?php
$user = require_admin();
require_once __DIR__ . '/../../includes/admin_dashboard.php';

$q = trim((string) get('q'));
$results = admin_global_search($q, 50);
$domainCount = count($results['domains']);
$emailCount = count($results['emails']);
$orderCount = count($results['orders']);
$total = $domainCount + $emailCount + $orderCount;

render_header('Search', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Search'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Search', 'Find domains across Our database / Extracted / Emails Admin, contact emails, and order sheet rows.') ?></h1>
    <p class="muted">Results for domains, emails, and orders.</p>
  </div>
</div>

<form class="card dashboard-search-form" method="get" action="index.php" role="search">
  <input type="hidden" name="page" value="admin_search">
  <label for="admin-search-q">Query</label>
  <div class="dashboard-search-form-row">
    <input id="admin-search-q" type="search" name="q" value="<?= h($q) ?>" required minlength="2"
           placeholder="example.com or name@mail.com or client…"
           autofocus autocomplete="off" spellcheck="false" data-no-draft>
    <button class="btn" type="submit">Search</button>
  </div>
</form>

<?php if ($q === ''): ?>
  <div class="card empty-state">
    <p>Type at least 2 characters to search.</p>
  </div>
<?php elseif ($total < 1): ?>
  <div class="card empty-state">
    <p>No matches for “<?= h($q) ?>”.</p>
    <p class="muted">Try a shorter domain fragment, email local-part, or client name.</p>
  </div>
<?php else: ?>

  <?php if ($domainCount > 0): ?>
  <section class="card">
    <h2>Domains <span class="muted">(<?= (int) $domainCount ?>)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Domain</th><th>Country</th><th>Source</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['domains'] as $row): ?>
          <tr>
            <td><strong><?= h((string) $row['domain']) ?></strong></td>
            <td><?= h((string) $row['country']) ?></td>
            <td><?= h((string) $row['source']) ?></td>
            <td><a href="<?= h((string) $row['href']) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($emailCount > 0): ?>
  <section class="card">
    <h2>Emails <span class="muted">(<?= (int) $emailCount ?>)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Site</th><th>Country</th><th>Emails</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['emails'] as $row): ?>
          <tr>
            <td><strong><?= h((string) $row['domain']) ?></strong></td>
            <td><?= h((string) $row['country']) ?></td>
            <td class="help"><?= h(implode(' · ', $row['emails'] ?: ['—'])) ?></td>
            <td><a href="<?= h((string) $row['href']) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($orderCount > 0): ?>
  <section class="card">
    <h2>Orders <span class="muted">(<?= (int) $orderCount ?>)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Site</th><th>Client</th><th>Country</th><th>Price</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['orders'] as $row): ?>
          <tr>
            <td><strong><?= h((string) $row['site']) ?></strong></td>
            <td><?= h((string) $row['client']) ?></td>
            <td><?= h((string) $row['country']) ?></td>
            <td><?= h(format_euro($row['price'])) ?></td>
            <td><?= !empty($row['paid']) ? 'Paid' : (trim((string) $row['live_url']) !== '' ? 'LIVE unpaid' : 'Waiting LIVE') ?></td>
            <td><a href="<?= h((string) $row['href']) ?>">Sheet</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

<?php endif; ?>

<?php render_footer('admin'); ?>
