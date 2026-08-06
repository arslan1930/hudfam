<?php
$user = require_team();
ensure_prospect_schema();

$sheet = (string) get('country');
$emptyCountry = ($sheet === '_none');
$inCountry = ($sheet !== '' && $sheet !== 'all');

// --- Country folders (default) ---
if (!$inCountry && !$emptyCountry) {
    $langFilter = trim((string) get('language'));
    $folders = prospect_country_folders();
    if ($langFilter !== '') {
        $folders = array_values(array_filter(
            $folders,
            static fn($f) => strcasecmp((string) $f['language'], $langFilter) === 0
        ));
    }
    $byRegion = [];
    foreach ($folders as $f) {
        $byRegion[$f['region_label']][] = $f;
    }
    $grandTotal = 0;
    foreach ($folders as $f) {
        $grandTotal += (int) $f['total'];
    }

    render_header('Our database', 'team');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
        ['label' => 'Our database'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Country databases</h1>
        <p class="muted">Same folders as Admin — each country is its own URL list. <?= (int) $grandTotal ?> URLs total.</p>
      </div>
      <div class="actions">
        <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
        <a class="btn secondary" href="index.php?page=team_prospect_batches">Add history</a>
      </div>
    </div>

    <form class="card country-finder" method="get" action="index.php">
      <input type="hidden" name="page" value="team_prospects">
      <div class="country-finder-grid">
        <div>
          <label for="finder_country">Country <span class="help">(type to search)</span></label>
          <?= render_country_select('country', '', 'finder_country', false, 'Type a country…') ?>
        </div>
        <div>
          <label for="finder_language">Language <span class="help">(optional — type to search)</span></label>
          <?= render_language_select('language', $langFilter, 'finder_language', false) ?>
        </div>
        <button class="btn" type="submit">Open / filter</button>
      </div>
      <div style="margin-top:0.75rem">
        <label for="folder_live_search">Or filter folders below by typing</label>
        <input id="folder_live_search" type="search" data-folder-search placeholder="Type country or language…" autocomplete="off">
        <p class="help" style="margin:0.4rem 0 0">
          Showing <strong data-folder-count><?= count($folders) ?></strong> countries.
          <?php if ($langFilter !== ''): ?>
            Filtered by language “<?= h($langFilter) ?>” · <a href="index.php?page=team_prospects">Clear</a>
          <?php else: ?>
            Pick a country to open it, or a language then Open to list matching countries.
          <?php endif; ?>
        </p>
      </div>
    </form>

    <div data-folder-scope>
    <?php foreach ($byRegion as $regionLabel => $list): ?>
      <div class="card" data-folder-group>
        <h2><?= h($regionLabel) ?></h2>
        <div class="folders" style="margin-top:0.7rem">
          <?php foreach ($list as $f): ?>
            <?php
              $href = $f['country'] !== '' ? $f['country'] : '_none';
              $label = $f['country'] !== '' ? $f['country'] : 'No country';
            ?>
            <a class="folder" data-search="<?= h($label . ' ' . $f['language']) ?>"
               href="index.php?page=team_prospects&amp;country=<?= urlencode($href) ?>">
              <h3><?= h($label) ?></h3>
              <p class="muted"><?= (int) $f['total'] ?> URL<?= (int) $f['total'] === 1 ? '' : 's' ?><?= $f['language'] !== '' ? ' · ' . h($f['language']) : '' ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$folders): ?>
      <div class="card empty-state">
        <p>
          <?= $langFilter !== ''
              ? 'No countries use the language “' . h($langFilter) . '”.'
              : 'No countries configured. Ask Admin to run upgrade.php once.' ?>
        </p>
        <?php if ($langFilter !== ''): ?>
          <a class="btn secondary" href="index.php?page=team_prospects">Show all countries</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
    render_footer('team');
    return;
}

// --- One country database ---
$countryName = $emptyCountry ? '' : $sheet;
$q = trim((string) get('q'));
$status = (string) get('status');
$language = trim((string) get('language'));
$pageNum = max(1, (int) get('p', 1));
$rows = [];
$total = 0;
$pages = 1;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update_status') {
        $id = (int) post('id');
        $st = (string) post('status');
        if (in_array($st, ['new', 'contacting', 'replied', 'skipped'], true)) {
            db()->prepare('UPDATE prospect_sites SET status=? WHERE id=?')->execute([$st, $id]);
            flash('ok', 'Prospect status updated.');
        }
        redirect('index.php?page=team_prospects&' . http_build_query(array_filter([
            'country' => $emptyCountry ? '_none' : $countryName,
            'q' => $q,
            'language' => $language,
            'status' => $status,
            'p' => $pageNum,
        ])));
    }

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
        if ($language !== '') {
            $where[] = 'p.language = ?';
            $params[] = $language;
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
        $inv = prospect_inventory_query([
            'q' => $q,
            'country' => $countryName,
            'language' => $language,
            'status' => $status,
        ], $pageNum, 50);
        $rows = $inv['rows'];
        $total = $inv['total'];
        $pages = $inv['pages'];
    }
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload.');
}

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$qs = http_build_query(array_filter([
    'page' => 'team_prospects',
    'country' => $emptyCountry ? '_none' : $countryName,
    'q' => $q,
    'language' => $language,
    'status' => $status,
], static fn($v) => $v !== '' && $v !== null));

render_header('Our database · ' . $sheetLabel, 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Our database', 'href' => 'index.php?page=team_prospects'],
    ['label' => $sheetLabel],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($sheetLabel) ?></h1>
    <p class="muted"><?= (int) $total ?> URL<?= (int) $total === 1 ? '' : 's' ?> in this country’s database</p>
  </div>
  <div class="actions">
    <?php if (!$emptyCountry): ?>
      <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($countryName) ?>">Filter &amp; add</a>
      <a class="btn secondary" href="index.php?page=team_prospect_form&amp;country=<?= urlencode($countryName) ?>">Add one</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=team_prospects">All countries</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_prospects">
  <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
  <div><label for="q">Search</label><input id="q" name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <div>
    <label for="language">Language <span class="help">(optional — type to search)</span></label>
    <?= render_language_select('language', $language, 'language', false) ?>
  </div>
  <div><label for="status">Status</label>
    <select id="status" name="status">
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
    <thead>
      <tr>
        <th>Domain</th><th>Language</th><th>Status</th>
        <th>Added by</th><th>When</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=team_prospect_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['added_by_full'] ?: $s['added_by_name'] ?: '—') ?></td>
        <td class="help"><?= h(substr((string) $s['created_at'], 0, 10)) ?></td>
        <td>
          <form method="post" class="actions">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <select name="status" onchange="this.form.submit()">
              <?php foreach (prospect_statuses() as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $s['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
  <div class="empty-state">
    <p>No URLs in this country yet.</p>
    <?php if (!$emptyCountry): ?>
      <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($countryName) ?>">Filter &amp; add</a>
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
<?php render_footer('team'); ?>
