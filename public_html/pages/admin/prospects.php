<?php
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
$emptyCountry = ($sheet === '_none');
if (!$emptyCountry && $sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet !== null && $canonSheet['name'] !== $sheet) {
        redirect('index.php?page=admin_prospects&country=' . urlencode($canonSheet['name']));
    }
    if ($canonSheet === null) {
        flash('error', 'That country folder is not in the country list. Sites only live in existing countries.');
        redirect('index.php?page=admin_prospects');
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

$addRaw = '';
$addCountry = $inCountry && !$emptyCountry ? $sheet : trim((string) (post('country') ?: get('add_country')));
$addLanguage = trim((string) (post('language') ?: get('language')));
if ($addCountry !== '') {
    $canonAdd = resolve_canonical_country($addCountry);
    if ($canonAdd !== null) {
        $addCountry = $canonAdd['name'];
        if ($addLanguage === '') {
            $addLanguage = $canonAdd['language'];
        }
    }
}

// --- Add sites into Our database (same panel) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'add_sites') {
    $addRaw = (string) post('urls');
    $addCountry = trim((string) post('country'));
    $addLanguage = trim((string) post('language'));
    try {
        if ($addCountry === '' || resolve_canonical_country($addCountry) === null) {
            flash('error', 'Select a country folder first (type to search, then Enter).');
            redirect('index.php?page=admin_prospects');
        }
        $canon = require_canonical_country($addCountry);
        $addCountry = $canon['name'];
        if (trim($addRaw) === '') {
            flash('error', 'Paste at least one root domain.');
            redirect('index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites');
        }
        $parsed = parse_domain_list_strict($addRaw);
        if ($parsed['invalid_count'] > 0) {
            flash('error', 'Remove invalid lines first (Clean errors). Root domains only — e.g. example.com or my-site.co.uk.');
            $_SESSION['admin_prospects_add_draft'] = [
                'country' => $addCountry,
                'language' => $addLanguage,
                'urls' => $parsed['valid_text'] !== ''
                    ? $parsed['valid_text'] . "\n" . implode("\n", array_column($parsed['invalid'], 'raw'))
                    : $addRaw,
            ];
            redirect('index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites');
        }
        $result = admin_add_urls_to_database($addRaw, $user, $addCountry, $addLanguage);
        if ($result['total'] <= 0) {
            flash('error', 'No valid root domains found. Example: example.com or my-site.co.uk');
            redirect('index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites');
        }
        $msg = 'Saved ' . (int) $result['total'] . ' site(s) to ' . $result['country'] . '.';
        $msg .= ' New: ' . (int) $result['inserted'] . '.';
        if ((int) $result['updated'] > 0) {
            $msg .= ' Already in this country (kept/updated): ' . (int) $result['updated'] . '.';
        }
        flash('ok', $msg);
        unset($_SESSION['admin_prospects_add_draft']);
        redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
    } catch (Throwable $e) {
        flash('error', 'Could not save sites. ' . $e->getMessage());
        redirect(
            $addCountry !== ''
                ? 'index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites'
                : 'index.php?page=admin_prospects#add-sites'
        );
    }
}

// Restore draft after Clean-errors style validation failure
$draft = $_SESSION['admin_prospects_add_draft'] ?? null;
if (is_array($draft)) {
    if ($addRaw === '' && !empty($draft['urls'])) {
        $addRaw = (string) $draft['urls'];
    }
    if ($addCountry === '' && !empty($draft['country'])) {
        $addCountry = (string) $draft['country'];
    }
    if ($addLanguage === '' && !empty($draft['language'])) {
        $addLanguage = (string) $draft['language'];
    }
    unset($_SESSION['admin_prospects_add_draft']);
}

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
        <h1>Our database</h1>
        <p class="muted">Each country is its own site database. Team adds merge into these same folders. <?= (int) $grandTotal ?> sites total.</p>
      </div>
      <div class="actions">
        <a class="btn" href="#add-sites">Add sites</a>
        <a class="btn secondary" href="index.php?page=admin_prospect_batches">Add history</a>
      </div>
    </div>
    <?= render_page_purpose(
        'Our database — one folder per country',
        'Sites are stored separately for each country.',
        'Open a country folder to browse, or use Add sites below to paste into a country database.',
        [
            'Add sites with the form below (Our database only).',
            'Or open a country folder to browse and add more there.',
            'Team Filter & add checks against the same country lists.',
        ]
    ) ?>

    <div class="card" id="add-sites">
      <h2>Add sites</h2>
      <p class="help">Paste root domains into one country’s database. Use Clean errors for https/paths/subdomains.</p>
      <form method="post" action="index.php?page=admin_prospects#add-sites">
        <input type="hidden" name="action" value="add_sites">
        <div class="form-grid">
          <?= render_country_typeahead($addCountry, [
              'id' => 'add_country',
              'label' => 'Country',
              'required' => true,
          ]) ?>
          <?= render_language_typeahead($addLanguage, ['id' => 'add_language']) ?>
        </div>
        <div style="margin-top:0.9rem">
          <?= render_domains_paste_field('urls', $addRaw, [
              'id' => 'urls',
              'label' => 'Sites (root domains)',
              'required' => true,
              'rows' => 12,
          ]) ?>
        </div>
        <p class="actions" style="margin-top:1rem">
          <button class="btn" type="submit">Save to country database</button>
        </p>
      </form>
    </div>
    <?= sites_form_script_tag() ?>

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
], static fn ($v) => $v !== '' && $v !== null));

if (!$emptyCountry && $addCountry === '') {
    $addCountry = $countryName;
}
if (!$emptyCountry && $addLanguage === '') {
    $canonLang = resolve_canonical_country($countryName);
    $addLanguage = $canonLang ? $canonLang['language'] : '';
}

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
      <a class="btn" href="#add-sites">Add sites</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?php if (!$emptyCountry): ?>
<div class="card" id="add-sites">
  <h2>Add sites to <?= h($countryName) ?></h2>
  <p class="help">Paste root domains into this country’s Our database folder. Use Clean errors for https/paths/subdomains.</p>
  <form method="post" action="index.php?page=admin_prospects&amp;country=<?= urlencode($countryName) ?>#add-sites">
    <input type="hidden" name="action" value="add_sites">
    <input type="hidden" name="country" value="<?= h($countryName) ?>">
    <div class="form-grid">
      <?= render_language_typeahead($addLanguage, ['id' => 'add_language']) ?>
    </div>
    <div style="margin-top:0.9rem">
      <?= render_domains_paste_field('urls', $addRaw, [
          'id' => 'urls',
          'label' => 'Sites (root domains)',
          'required' => true,
          'rows' => 10,
      ]) ?>
    </div>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit">Save to <?= h($countryName) ?></button>
    </p>
  </form>
</div>
<?= sites_form_script_tag() ?>
<?php endif; ?>

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
      <p>No sites in this country yet.</p>
      <?php if (!$emptyCountry): ?>
        <a class="btn" href="#add-sites">Add sites above</a>
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
