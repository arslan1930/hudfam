<?php
require_admin();
ensure_extracted_schema();
seed_countries_if_empty(db());

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
$emptyCountry = ($sheet === '_none');
if (!$emptyCountry && $sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet !== null && $canonSheet['name'] !== $sheet) {
        redirect('index.php?page=admin_extracted&country=' . urlencode($canonSheet['name']));
    }
    if ($canonSheet === null) {
        flash('error', 'That country folder is not in the country list.');
        redirect('index.php?page=admin_extracted');
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

// --- Country folders ---
if (!$inCountry && !$emptyCountry) {
    $folders = extracted_country_folders();
    $byRegion = [];
    foreach ($folders as $f) {
        $byRegion[$f['region_label']][] = $f;
    }
    $grandTotal = 0;
    foreach ($folders as $f) {
        $grandTotal += (int) $f['total'];
    }

    render_header('Extracted URLs', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracted URLs'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Extracted sites</h1>
        <p class="muted">Sites teammates push from Extracting Results, stored per country. <?= (int) $grandTotal ?> extracted site<?= (int) $grandTotal === 1 ? '' : 's' ?> total.</p>
      </div>
    </div>
    <?= render_page_purpose(
        'Extracted URLs — extracted sites by country',
        'Each country has its own extracted-sites folder.',
        'When Team pastes sites into Extracting Results and clicks Push, they land in that country’s extracted sites here.',
        [
            'Team opens Extracting sites for a country.',
            'Pastes results into Extracting Results and clicks Push.',
            'Open the same country folder here to review extracted sites.',
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
            <a class="folder" href="index.php?page=admin_extracted&amp;country=<?= urlencode($href) ?>">
              <h3><?= h($label) ?></h3>
              <p class="muted"><?= (int) $f['total'] ?> site<?= (int) $f['total'] === 1 ? '' : 's' ?><?= $f['language'] !== '' ? ' · ' . h($f['language']) : '' ?></p>
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

// --- One country extracted sites ---
$countryName = $emptyCountry ? '' : $sheet;
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));
$inv = extracted_inventory_query([
    'q' => $q,
    'country' => $countryName,
], $pageNum, 50);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$qs = http_build_query(array_filter([
    'page' => 'admin_extracted',
    'country' => $emptyCountry ? '_none' : $countryName,
    'q' => $q,
], static fn ($v) => $v !== '' && $v !== null));

render_header('Extracted URLs · ' . $sheetLabel, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
    ['label' => $sheetLabel],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($sheetLabel) ?> · Extracted sites</h1>
    <p class="muted"><?= (int) $total ?> extracted site<?= (int) $total === 1 ? '' : 's' ?> in this country</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_extracted">All countries</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_extracted">
  <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Domain</th>
        <th>Language</th>
        <th>Pushed by</th>
        <th>When</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><strong><?= h((string) $s['domain']) ?></strong></td>
        <td><?= h((string) ($s['language'] ?: '—')) ?></td>
        <td><?= h((string) ($s['pushed_by_full'] ?: $s['pushed_by_name'] ?: '—')) ?></td>
        <td><?= h(substr((string) $s['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
    <div class="empty-state">
      <p>No extracted sites in this country yet.</p>
      <p class="muted">They appear when Team clicks Push in Extracting Results for <?= h($sheetLabel) ?>.</p>
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
