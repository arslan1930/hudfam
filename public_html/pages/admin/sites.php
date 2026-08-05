<?php
$user = require_admin();
$ctx = catalog_context_from_request('admin');
$projectId = (int) $ctx['project_id'];
$sheet = (string) get('sheet');
// Prefer session country when sheet not in URL
if ($sheet === '' && $ctx['country'] !== '') {
    // stay on project folder view unless sheet explicitly requested
}
$language = $ctx['language'];
$q = trim((string) get('q'));
$status = (string) get('status');
$orderStatus = (string) get('order_status');
$region = (string) get('region');
$pageNum = max(1, (int) get('p', 1));

$projects = db()->query(
    "SELECT p.id, p.name, p.client_name, p.countries,
      (SELECT COUNT(*) FROM sites s WHERE s.primary_project_id=p.id) AS site_count
     FROM projects p WHERE p.status!='archived' ORDER BY p.name"
)->fetchAll();

$globalMode = ((string) get('global') === '1');

// --- Global country catalog (company-wide) kept as secondary ---
if ($globalMode) {
    ensure_country_catalog_schema();
    $emptyCountry = ($sheet === '_none');
    $inSheet = ($sheet !== '' && $sheet !== 'all');
    if (!$inSheet && $sheet !== '_none') {
        $folders = country_catalog_folder_list();
        render_header('Global catalog', 'admin');
        ?>
        <?php render_breadcrumbs([
            ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
            ['label' => 'Global country catalogs'],
        ]); ?>
        <div class="topbar">
          <div>
            <h1>Global country catalogs</h1>
            <p class="muted">Company-wide sheets (not tied to one project).</p>
          </div>
          <div class="actions">
            <a class="btn secondary" href="index.php?page=admin_sites">Project catalogs</a>
            <a class="btn" href="index.php?page=admin_catalog_site_form">Add global site</a>
          </div>
        </div>
        <?= guide_admin_global_catalog() ?>
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
              <?php $hrefCountry = $f['country'] !== '' ? $f['country'] : '_none'; ?>
              <a class="folder" href="index.php?page=admin_sites&amp;global=1&amp;sheet=<?= urlencode($hrefCountry) ?>">
                <h3><?= h($f['country'] !== '' ? $f['country'] : 'No country') ?></h3>
                <p class="muted"><?= (int) $f['total'] ?> site<?= (int) $f['total'] === 1 ? '' : 's' ?></p>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach;
        render_footer('admin');
        return;
    }
    $countryName = $emptyCountry ? '' : $sheet;
    catalog_context_save('admin', ['country' => $countryName]);
    $inventory = country_catalog_query([
        'country' => $countryName,
        'empty_country' => $emptyCountry,
        'q' => $q,
        'status' => $status,
        'order_status' => $orderStatus,
        'region' => $region,
        'language' => trim((string) get('language')),
    ], $pageNum, 50);
    $rows = $inventory['rows'];
    $total = $inventory['total'];
    $pages = $inventory['pages'];
    $sheetLabel = $emptyCountry ? 'No country' : $countryName;
    $qs = http_build_query(array_filter([
        'page' => 'admin_sites', 'global' => '1', 'sheet' => $sheet, 'q' => $q,
        'status' => $status, 'order_status' => $orderStatus, 'region' => $region,
    ], static fn($v) => $v !== '' && $v !== null));
    render_header('Global · ' . $sheetLabel, 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
        ['label' => 'Global', 'href' => 'index.php?page=admin_sites&global=1'],
        ['label' => $sheetLabel],
    ]); ?>
    <div class="topbar">
      <div><h1><?= h($sheetLabel) ?></h1><p class="muted"><?= (int) $total ?> domains (global)</p></div>
      <div class="actions">
        <a class="btn" href="index.php?page=admin_catalog_site_form&amp;country=<?= urlencode($countryName) ?>">Add site</a>
        <a class="btn secondary" href="index.php?page=admin_sites&amp;global=1">All countries</a>
      </div>
    </div>
    <div class="card">
      <table>
        <thead><tr><th>Domain</th><th>Language</th><th>DR / DA / Traffic</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
          <tr>
            <td><a href="index.php?page=admin_catalog_site_form&amp;id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
            <td><?= h($s['language'] ?: '—') ?></td>
            <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
            <td><?= badge($s['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$rows): ?><div class="empty-state"><p>No sites.</p></div><?php endif; ?>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- Project catalogs (primary) ---
if ($projectId <= 0) {
    render_header('Catalog', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Catalog'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Project catalogs</h1>
        <p class="muted">Each project has its own country sheets. Open a project, then a country folder.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_sites&amp;global=1">Global country catalogs</a>
        <a class="btn secondary" href="index.php?page=admin_bulk_import">Bulk import</a>
      </div>
    </div>
    <?= guide_admin_catalog() ?>
    <div class="folders">
      <?php foreach ($projects as $p): ?>
        <a class="folder" href="index.php?page=admin_sites&amp;project_id=<?= (int) $p['id'] ?>">
          <h3><?= h($p['name']) ?></h3>
          <p class="muted"><?= h($p['client_name'] ?: 'Client project') ?> · <?= (int) $p['site_count'] ?> sites</p>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (!$projects): ?>
      <div class="card empty-state"><p>No projects yet.</p><a class="btn" href="index.php?page=admin_project_form">New project</a></div>
    <?php endif;
    render_footer('admin');
    return;
}

$project = null;
foreach ($projects as $p) {
    if ((int) $p['id'] === $projectId) {
        $project = $p;
        break;
    }
}
if (!$project) {
    catalog_context_save('admin', ['project_id' => 0]);
    redirect('index.php?page=admin_sites');
}
catalog_context_save('admin', ['project_id' => $projectId]);

$emptyCountry = ($sheet === '_none');
$inSheet = ($sheet !== '' && $sheet !== 'all');

if (!$inSheet && $sheet !== '_none') {
    $sheets = project_country_sheets($projectId, (string) ($project['countries'] ?? ''));
    // Also list countries table grouped for empty sheets
    render_header('Catalog · ' . $project['name'], 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
        ['label' => $project['name']],
    ]); ?>
    <div class="topbar">
      <div>
        <h1><?= h($project['name']) ?> · countries</h1>
        <p class="muted">Country folders for this project’s catalog. Selection is remembered after refresh.</p>
      </div>
      <div class="actions">
        <a class="btn" href="index.php?page=admin_site_form&amp;project_id=<?= $projectId ?>">Add site</a>
        <a class="btn secondary" href="index.php?page=admin_bulk_import&amp;project_id=<?= $projectId ?>">Bulk import</a>
        <a class="btn secondary" href="index.php?page=admin_sites">All projects</a>
      </div>
    </div>
    <div class="folders">
      <a class="folder" href="index.php?page=admin_sites&amp;project_id=<?= $projectId ?>&amp;sheet=all">
        <h3>All countries</h3>
        <p class="muted"><?= (int) $project['site_count'] ?> sites</p>
      </a>
      <?php foreach ($sheets as $sh): ?>
        <?php
          $name = (string) ($sh['country'] ?? '');
          $href = $name !== '' ? $name : '_none';
          $label = $name !== '' ? $name : 'No country';
        ?>
        <a class="folder" href="index.php?page=admin_sites&amp;project_id=<?= $projectId ?>&amp;sheet=<?= urlencode($href) ?>">
          <h3><?= h($label) ?></h3>
          <p class="muted"><?= (int) ($sh['total'] ?? 0) ?> site<?= (int) ($sh['total'] ?? 0) === 1 ? '' : 's' ?></p>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
    render_footer('admin');
    return;
}

$countryName = $emptyCountry ? '' : ($sheet === 'all' ? '' : $sheet);
if ($countryName !== '') {
    catalog_context_save('admin', ['country' => $countryName]);
}
$langFilter = trim((string) (get('language') !== '' || isset($_GET['language']) ? get('language') : $language));
if (isset($_GET['language']) || isset($_POST['language'])) {
    catalog_context_save('admin', ['language' => $langFilter]);
    $language = $langFilter;
}

$inventory = project_inventory_query($projectId, [
    'q' => $q,
    'status' => $status,
    'order_status' => $orderStatus,
    'region' => $region,
    'country' => $countryName,
    'language' => $langFilter,
    'empty_country' => $emptyCountry,
], $pageNum, 50);
$rows = $inventory['rows'];
$total = $inventory['total'];
$pages = $inventory['pages'];
$langOptions = project_country_language_options($projectId, $countryName);
$sheetLabel = $sheet === 'all' ? 'All countries' : ($emptyCountry ? 'No country' : $sheet);
$qs = http_build_query(array_filter([
    'page' => 'admin_sites',
    'project_id' => $projectId,
    'sheet' => $sheet,
    'q' => $q,
    'status' => $status,
    'order_status' => $orderStatus,
    'region' => $region,
    'language' => $langFilter,
], static fn($v) => $v !== '' && $v !== null));

render_header('Catalog · ' . $project['name'] . ' · ' . $sheetLabel, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
    ['label' => $project['name'], 'href' => 'index.php?page=admin_sites&project_id=' . $projectId],
    ['label' => $sheetLabel],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($project['name']) ?> · <?= h($sheetLabel) ?></h1>
    <p class="muted"><?= (int) $total ?> domain<?= $total === 1 ? '' : 's' ?></p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_site_form&amp;project_id=<?= $projectId ?>&amp;country=<?= urlencode($countryName) ?>">Add site</a>
    <a class="btn secondary" href="index.php?page=admin_bulk_import&amp;project_id=<?= $projectId ?>&amp;country=<?= urlencode($countryName) ?>">Bulk import</a>
    <a class="btn secondary" href="index.php?page=admin_sites&amp;project_id=<?= $projectId ?>">Countries</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_sites">
  <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">
  <input type="hidden" name="sheet" value="<?= h($sheet) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
  <div><label>Language</label>
    <select name="language">
      <option value="">All</option>
      <?php foreach ($langOptions as $lang): ?>
        <option value="<?= h($lang) ?>" <?= $langFilter === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Site status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Country / lang</th><th>DR / DA / Traffic</th>
        <th>Quote / Agreed</th><th>Order status</th><th>Comments</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&amp;id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['publisher_quote_price'] ?? null) ?> / <?= money_or_dash($s['backlink_price'] ?? null) ?></td>
        <td><?= h(inventory_order_statuses()[$s['order_status']] ?? ($s['order_status'] ?: '—')) ?></td>
        <td class="help"><?php $c = (string) ($s['admin_comments'] ?? ''); echo h(strlen($c) > 60 ? substr($c, 0, 57) . '…' : ($c ?: '—')); ?></td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
  <div class="empty-state">
    <p>No sites in this sheet.</p>
    <a class="btn" href="index.php?page=admin_site_form&amp;project_id=<?= $projectId ?>&amp;country=<?= urlencode($countryName) ?>">Add site</a>
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
