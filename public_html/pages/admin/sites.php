<?php
$user = require_admin();
ensure_country_catalog_schema();

$sheet = (string) get('sheet');
$q = trim((string) get('q'));
$status = (string) get('status');
$orderStatus = (string) get('order_status');
$region = (string) get('region');
$language = trim((string) get('language'));
$pageNum = max(1, (int) get('p', 1));

$folders = country_catalog_folder_list();
$emptyCountry = ($sheet === '_none');
$inSheet = ($sheet !== '' && $sheet !== 'all');

if (!$inSheet && $sheet !== '_none') {
    // Folder overview
    render_header('Catalog', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Catalog'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Country catalogs</h1>
        <p class="muted">Each country is its own catalog. Add websites manually or via Bulk import — not tied to a client project.</p>
      </div>
      <div class="actions">
        <a class="btn" href="index.php?page=admin_catalog_site_form">Add site</a>
        <a class="btn secondary" href="index.php?page=admin_bulk_import">Bulk import</a>
      </div>
    </div>

    <?php
    $byRegion = [];
    foreach ($folders as $f) {
        $byRegion[$f['region_label']][] = $f;
    }
    foreach ($byRegion as $regionLabel => $list):
    ?>
    <div class="card">
      <h2><?= h($regionLabel) ?></h2>
      <div class="folders">
        <?php foreach ($list as $f): ?>
          <?php
            $hrefCountry = $f['country'] !== '' ? $f['country'] : '_none';
            $label = $f['country'] !== '' ? $f['country'] : 'No country';
          ?>
          <a class="folder" href="index.php?page=admin_sites&amp;sheet=<?= urlencode($hrefCountry) ?>">
            <h3><?= h($label) ?></h3>
            <p class="muted"><?= (int) $f['total'] ?> site<?= (int) $f['total'] === 1 ? '' : 's' ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$folders): ?>
    <div class="card empty-state">
      <p>No countries yet. Run upgrade/install or add countries under Setup.</p>
      <a class="btn" href="index.php?page=admin_countries">Countries</a>
    </div>
    <?php endif; ?>
    <?php
    render_footer('admin');
    return;
}

$countryName = $emptyCountry ? '' : $sheet;
$inventory = country_catalog_query([
    'country' => $countryName,
    'empty_country' => $emptyCountry,
    'q' => $q,
    'status' => $status,
    'order_status' => $orderStatus,
    'region' => $region,
    'language' => $language,
], $pageNum, 50);
$rows = $inventory['rows'];
$total = $inventory['total'];
$pages = $inventory['pages'];
$langs = [];
try {
    $stmt = db()->prepare(
        "SELECT DISTINCT language FROM country_catalog_sites
         WHERE language <> '' AND " . ($emptyCountry ? "TRIM(COALESCE(country,''))=''" : 'TRIM(country)=?')
         . ' ORDER BY language'
    );
    $stmt->execute($emptyCountry ? [] : [$countryName]);
    $langs = array_column($stmt->fetchAll(), 'language');
} catch (Throwable $e) {
    $langs = [];
}
$qs = http_build_query(array_filter([
    'page' => 'admin_sites',
    'sheet' => $sheet,
    'q' => $q,
    'status' => $status,
    'order_status' => $orderStatus,
    'region' => $region,
    'language' => $language,
], static fn($v) => $v !== '' && $v !== null));
$sheetLabel = $emptyCountry ? 'No country' : $countryName;

render_header('Catalog · ' . $sheetLabel, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
    ['label' => $sheetLabel],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($sheetLabel) ?></h1>
    <p class="muted"><?= (int) $total ?> domain<?= $total === 1 ? '' : 's' ?> in this country catalog</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_catalog_site_form&amp;country=<?= urlencode($countryName) ?>">Add site</a>
    <a class="btn secondary" href="index.php?page=admin_bulk_import&amp;country=<?= urlencode($countryName) ?>">Bulk import</a>
    <a class="btn secondary" href="index.php?page=admin_sites">All countries</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_sites">
  <input type="hidden" name="sheet" value="<?= h($sheet) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain, client, comments…"></div>
  <div><label>Site status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Order status</label>
    <select name="order_status">
      <?php foreach (inventory_order_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $orderStatus === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Region</label>
    <select name="region">
      <option value="">All</option>
      <?php foreach (regions() as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Language</label>
    <select name="language">
      <option value="">All</option>
      <?php foreach ($langs as $lang): ?>
        <option value="<?= h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Language</th><th>DR / DA / Traffic</th>
        <th>Quote / Agreed</th><th>Order status</th><th>Client</th>
        <th>Comments</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_catalog_site_form&amp;id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td>
          <?= money_or_dash($s['publisher_quote_price'] ?? null) ?>
          / <?= money_or_dash($s['backlink_price'] ?? null) ?> <?= h($s['currency'] ?? '') ?>
        </td>
        <td><?= h(inventory_order_statuses()[$s['order_status']] ?? ($s['order_status'] ?: '—')) ?></td>
        <td><?= h($s['inventory_client_name'] ?: '—') ?></td>
        <td class="help"><?php $c = (string) ($s['admin_comments'] ?? ''); echo h(strlen($c) > 80 ? substr($c, 0, 77) . '…' : ($c ?: '—')); ?></td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
  <div class="empty-state">
    <p>No sites in this country yet.</p>
    <a class="btn" href="index.php?page=admin_catalog_site_form&amp;country=<?= urlencode($countryName) ?>">Add site manually</a>
    <a class="btn secondary" href="index.php?page=admin_bulk_import&amp;country=<?= urlencode($countryName) ?>">Bulk import CSV</a>
  </div>
  <?php else: ?>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
