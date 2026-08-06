<?php
$user = require_team();
ensure_prospect_schema();

$sheet = (string) get('country');
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

    render_header('Our database', 'team');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
        ['label' => 'Our database'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Country databases</h1>
        <p class="muted">Same folders as Admin. For large lists, open a country and use Download all / View all names. <?= (int) $grandTotal ?> sites total.</p>
      </div>
      <div class="actions">
        <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
        <a class="btn secondary" href="index.php?page=team_prospect_batches">Add history</a>
      </div>
    </div>
    <?= guide_inventory() ?>
    <?php
      $freqTeam = user_frequent_countries((int) $user['id'], 8);
      echo render_frequent_country_chips($freqTeam, 'index.php?page=team_prospects&country=');
    ?>
    <div data-folder-scope>
    <div class="card folder-search-bar">
      <label for="folder_search_team">Find a country <span class="help">(type to filter folders)</span></label>
      <input type="search" id="folder_search_team" data-folder-search placeholder="e.g. Germany, Austria…" autocomplete="off">
    </div>
    <?php foreach ($byRegion as $regionLabel => $list): ?>
      <div class="card">
        <h2><?= h($regionLabel) ?></h2>
        <div class="folders" style="margin-top:0.7rem">
          <?php foreach ($list as $f): ?>
            <?php
              $href = $f['country'] !== '' ? $f['country'] : '_none';
              $label = $f['country'] !== '' ? $f['country'] : 'No country';
            ?>
            <a class="folder" href="index.php?page=team_prospects&amp;country=<?= urlencode($href) ?>">
              <h3><?= h($label) ?></h3>
              <p class="muted"><?= (int) $f['total'] ?> site<?= (int) $f['total'] === 1 ? '' : 's' ?><?= $f['language'] !== '' ? ' · ' . h($f['language']) : '' ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$folders): ?>
      <div class="card empty-state"><p>No countries configured. Ask Admin to run upgrade.php once.</p></div>
    <?php endif; ?>
    <?php
    render_footer('team');
    return;
}

// --- One country database ---
$countryName = $emptyCountry ? '' : $sheet;
$countryKey = $emptyCountry ? '_none' : $countryName;
$q = trim((string) get('q'));
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));
$per = normalize_prospect_per_page((int) get('per', 100));
$view = (string) get('view');
$export = (string) get('export');

if ($export === 'txt') {
    stream_prospect_domains_export($countryKey, $q, $status);
}

$baseQs = array_filter([
    'page' => 'team_prospects',
    'country' => $countryKey,
    'q' => $q,
    'status' => $status,
    'per' => $per,
], static fn($v) => $v !== '' && $v !== null);
$qs = http_build_query($baseQs);
$exportUrl = 'index.php?' . http_build_query($baseQs + ['export' => 'txt']);
$namesUrl = 'index.php?' . http_build_query($baseQs + ['view' => 'names']);
$tableUrl = 'index.php?' . http_build_query($baseQs);
$sheetLabel = $emptyCountry ? 'No country' : $countryName;

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
        redirect('index.php?' . http_build_query($baseQs + ['p' => $pageNum]));
    }

    if ($view === 'names') {
        $plain = list_prospect_domains_plain($countryKey, $q, $status, 150000);
        $text = implode("\n", $plain['domains']);
        render_header('Our database · ' . $sheetLabel . ' · all names', 'team');
        ?>
        <?php render_breadcrumbs([
            ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
            ['label' => 'Our database', 'href' => 'index.php?page=team_prospects'],
            ['label' => $sheetLabel, 'href' => $tableUrl],
            ['label' => 'All names'],
        ]); ?>
        <div class="topbar">
          <div>
            <h1><?= h($sheetLabel) ?> — all site names</h1>
            <p class="muted">
              <?= (int) $plain['total'] ?> site<?= (int) $plain['total'] === 1 ? '' : 's' ?>
              <?php if ($plain['truncated']): ?>
                · showing first <?= count($plain['domains']) ?> — download .txt for the full list
              <?php else: ?>
                · one per line (copy or download)
              <?php endif; ?>
            </p>
          </div>
          <div class="actions">
            <a class="btn" href="<?= h($exportUrl) ?>">Download all (.txt)</a>
            <a class="btn secondary" href="<?= h($tableUrl) ?>">Table view</a>
            <?php if (!$emptyCountry): ?>
              <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($countryName) ?>">Filter &amp; add</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="card">
          <textarea class="inventory-box" rows="28" readonly id="all_names"><?= h($text) ?></textarea>
          <p class="actions" style="margin-top:0.8rem">
            <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('all_names').value)">Copy all</button>
            <a class="btn" href="<?= h($exportUrl) ?>">Download all (.txt)</a>
          </p>
        </div>
        <?php
        render_footer('team');
        return;
    }

    if ($emptyCountry) {
        [$whereSql, $params] = prospect_country_where($countryKey, $q, $status);
        $count = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $per));
        $offset = ($pageNum - 1) * $per;
        $stmt = db()->prepare(
            "SELECT p.*, u.username added_by_name, u.full_name added_by_full
             FROM prospect_sites p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE $whereSql ORDER BY p.domain ASC LIMIT $per OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        $inv = prospect_inventory_query([
            'q' => $q,
            'country' => $countryName,
            'status' => $status,
        ], $pageNum, $per);
        $rows = $inv['rows'];
        $total = $inv['total'];
        $pages = $inv['pages'];
    }
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload.');
}

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
    <p class="muted"><?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?> · for large lists use Download or View all names</p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= h($exportUrl) ?>">Download all (.txt)</a>
    <a class="btn secondary" href="<?= h($namesUrl) ?>">View all names</a>
    <?php if (!$emptyCountry): ?>
      <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($countryName) ?>">Filter &amp; add</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=team_prospects">All countries</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_prospects">
  <input type="hidden" name="country" value="<?= h($countryKey) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <div><label>Status</label>
    <select name="status" data-searchable="1">
      <option value="">All</option>
      <?php foreach (prospect_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Per page</label>
    <select name="per" data-searchable="1">
      <?php foreach (prospect_per_page_choices() as $n): ?>
        <option value="<?= (int) $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= (int) $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Site</th><th>Language</th><th>Status</th>
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
    <p>No sites in this country yet.</p>
    <?php if (!$emptyCountry): ?>
      <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($countryName) ?>">Filter &amp; add</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="actions" style="margin-top:0.8rem;flex-wrap:wrap;gap:0.75rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?> · <?= (int) $per ?> per page</span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
    <a class="btn secondary" href="<?= h($namesUrl) ?>">View all names</a>
    <a class="btn" href="<?= h($exportUrl) ?>">Download all (.txt)</a>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
