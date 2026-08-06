<?php
require_admin();
ensure_prospect_schema();

$sheet = (string) get('country');
// Prefer ?country= for folder; also accept sheet=
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
$emptyCountry = ($sheet === '_none');
$inCountry = ($sheet !== '' && $sheet !== 'all');

// --- Country folders (default) ---
if (!$inCountry && !$emptyCountry) {
    $folders = prospect_country_folders();
    $byRegion = [];
    foreach ($folders as $f) {
        $byRegion[$f['region_label']][] = $f;
    }
    $grandTotal = 0;
    foreach ($folders as $f) {
        $grandTotal += (int) $f['total'];
    }

    render_header('Our database', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Our database'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Country databases</h1>
        <p class="muted">Each country is its own URL database. Open a folder to view or add URLs. <?= (int) $grandTotal ?> URLs total.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_prospect_batches">Add history</a>
      </div>
    </div>
    <?= render_page_purpose(
        'Our database — one folder per country',
        'URLs are stored separately for each country.',
        'Click a country folder to open that country’s database. Use Add URLs inside the folder to paste sites into that country only.',
        [
            'Open a country folder.',
            'Add URLs into that country’s database.',
            'Team Filter & add can check against the same country list.',
        ]
    ) ?>
    <?php foreach ($byRegion as $regionLabel => $list): ?>
      <div class="card">
        <h2><?= h($regionLabel) ?></h2>
        <div class="folders" style="margin-top:0.7rem">
          <?php foreach ($list as $f): ?>
            <?php
              $href = $f['country'] !== '' ? $f['country'] : '_none';
              $label = $f['country'] !== '' ? $f['country'] : 'No country';
            ?>
            <a class="folder" href="index.php?page=admin_prospects&amp;country=<?= urlencode($href) ?>">
              <h3><?= h($label) ?></h3>
              <p class="muted"><?= (int) $f['total'] ?> URL<?= (int) $f['total'] === 1 ? '' : 's' ?><?= $f['language'] !== '' ? ' · ' . h($f['language']) : '' ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$folders): ?>
      <div class="card empty-state"><p>No countries configured. Run upgrade.php once.</p></div>
    <?php endif; ?>
    <?php
    render_footer('admin');
    return;
}

// --- One country database ---
$countryName = $emptyCountry ? '' : $sheet;
$q = trim((string) get('q'));
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));
$inv = prospect_inventory_query([
    'q' => $q,
    'country' => $countryName,
    'status' => $status,
] + ($emptyCountry ? [] : []), $pageNum, 50);

// For empty country, prospect_inventory_query with country='' won't filter empty — need special case
if ($emptyCountry) {
    $where = ["TRIM(p.country)=''"];
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.domain LIKE ? OR p.url LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 50));
    $offset = ($pageNum - 1) * 50;
    $stmt = db()->prepare(
        "SELECT p.*, u.username added_by_name, u.full_name added_by_full
         FROM prospect_sites p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE $whereSql ORDER BY p.created_at DESC LIMIT 50 OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $rows = $inv['rows'];
    $total = $inv['total'];
    $pages = $inv['pages'];
}

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$qs = http_build_query(array_filter([
    'page' => 'admin_prospects',
    'country' => $emptyCountry ? '_none' : $countryName,
    'q' => $q,
    'status' => $status,
], static fn($v) => $v !== '' && $v !== null));

render_header('Our database · ' . $sheetLabel, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects'],
    ['label' => $sheetLabel],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($sheetLabel) ?></h1>
    <p class="muted"><?= (int) $total ?> URL<?= (int) $total === 1 ? '' : 's' ?> in this country’s database</p>
  </div>
  <div class="actions">
    <?php if (!$emptyCountry): ?>
      <a class="btn" href="index.php?page=admin_prospect_add&amp;country=<?= urlencode($countryName) ?>">Add URLs</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospects">
  <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain or url…"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (prospect_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>URL</th><th>Language</th><th>Status</th><th>Added by</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><strong><?= h($s['domain']) ?></strong></td>
        <td class="help"><?= h($s['url'] !== '' ? $s['url'] : '—') ?></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['added_by_full'] ?: $s['added_by_name'] ?: '—') ?></td>
        <td><?= h(substr((string) $s['created_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
    <div class="empty-state">
      <p>No URLs in this country yet.</p>
      <?php if (!$emptyCountry): ?>
        <a class="btn" href="index.php?page=admin_prospect_add&amp;country=<?= urlencode($countryName) ?>">Add URLs</a>
      <?php endif; ?>
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
