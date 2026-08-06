<?php
require_admin();
ensure_prospect_schema();

$sheet = (string) get('country');
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
        <p class="muted">Each country is its own site database. Open a folder to browse, download, add, or delete sites. <?= (int) $grandTotal ?> sites total.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_prospect_batches">Add history</a>
      </div>
    </div>
    <?= render_page_purpose(
        'Our database — one folder per country',
        'Sites are stored separately for each country.',
        'Open a country folder. Select rows to delete, or remove by filter / uploaded .txt list.',
        [
            'Open a country folder.',
            'Download / view all names for large lists.',
            'Select sites with the mouse and Delete — or use Remove tools.',
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

// --- One country database ---
$countryName = $emptyCountry ? '' : $sheet;
$countryKey = $emptyCountry ? '_none' : $countryName;
$q = trim((string) (post('q') ?: get('q')));
$status = (string) (post('status') ?: get('status'));
$pageNum = max(1, (int) (post('p') ?: get('p', 1)));
$per = normalize_prospect_per_page((int) (post('per') ?: get('per', 100)));
$view = (string) get('view');
$export = (string) get('export');

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$baseQs = array_filter([
    'page' => 'admin_prospects',
    'country' => $countryKey,
    'q' => $q,
    'status' => $status,
    'per' => $per,
], static fn($v) => $v !== '' && $v !== null);
$qs = http_build_query($baseQs);
$exportUrl = 'index.php?' . http_build_query($baseQs + ['export' => 'txt']);
$namesUrl = 'index.php?' . http_build_query($baseQs + ['view' => 'names']);
$tableUrl = 'index.php?' . http_build_query($baseQs);

// Pending confirm for filter / upload delete
$pendingFilterDelete = null;
$pendingUploadDelete = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $confirmed = (string) post('confirm') === '1';

        if ($action === 'delete_selected') {
            $ids = post('ids');
            if (!is_array($ids)) {
                $ids = [];
            }
            if (!$confirmed) {
                flash('error', 'Delete was not confirmed.');
            } elseif (!$ids) {
                flash('error', 'Select at least one site (checkbox) to delete.');
            } else {
                $n = delete_prospect_sites_by_ids($ids, $countryKey);
                flash('ok', 'Deleted ' . $n . ' site(s) from ' . $sheetLabel . '.');
            }
            redirect($tableUrl . ($pageNum > 1 ? '&p=' . $pageNum : ''));
        }

        if ($action === 'delete_filter') {
            $matchCount = count_prospect_sites_filtered($countryKey, $q, $status);
            if ($matchCount <= 0) {
                flash('error', 'No sites match the current search/filter.');
                redirect($tableUrl);
            }
            if (!$confirmed) {
                $pendingFilterDelete = [
                    'count' => $matchCount,
                    'q' => $q,
                    'status' => $status,
                ];
            } else {
                $n = delete_prospect_sites_by_filter($countryKey, $q, $status);
                flash('ok', 'Deleted ' . $n . ' site(s) matching the filter in ' . $sheetLabel . '.');
                redirect('index.php?page=admin_prospects&country=' . urlencode($countryKey) . '&per=' . $per);
            }
        }

        if ($action === 'delete_upload') {
            $rawList = trim((string) post('domains_text'));
            if ($rawList === '' && !empty($_FILES['domains_file']['tmp_name'])) {
                $rawList = (string) file_get_contents($_FILES['domains_file']['tmp_name']);
            }
            if ($rawList === '') {
                flash('error', 'Paste domains or upload a .txt file to remove.');
                redirect($tableUrl);
            }
            $parsed = parse_plain_site_list($rawList);
            // Also salvage messy lines for upload remove
            $domains = $parsed['domains'];
            foreach ($parsed['invalid'] as $bad) {
                $root = extract_root_domain_candidate($bad);
                if ($root !== '') {
                    $domains[] = $root;
                }
            }
            $domains = array_values(array_unique($domains));
            if (!$domains) {
                flash('error', 'No valid root domains found in the upload/paste.');
                redirect($tableUrl);
            }
            // How many of these exist in this country?
            $existing = filter_domains_against_prospects($domains, $emptyCountry ? '' : $countryName);
            // For empty country, filter without country may be global — handle empty specially
            if ($emptyCountry) {
                $check = [];
                foreach (array_chunk($domains, 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $st = db()->prepare("SELECT domain FROM prospect_sites WHERE TRIM(country)='' AND domain IN ($ph)");
                    $st->execute($chunk);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
                        $check[$d] = true;
                    }
                }
                $toRemove = array_keys($check);
            } else {
                $toRemove = $existing['existing'];
            }

            if (!$toRemove) {
                flash('error', 'None of those domains are in ' . $sheetLabel . '.');
                redirect($tableUrl);
            }
            if (!$confirmed) {
                $pendingUploadDelete = [
                    'domains' => $toRemove,
                    'count' => count($toRemove),
                    'text' => implode("\n", $toRemove),
                ];
            } else {
                $n = delete_prospect_sites_by_domains($toRemove, $countryKey);
                flash('ok', 'Deleted ' . $n . ' site(s) from ' . $sheetLabel . ' using your list.');
                redirect($tableUrl);
            }
        }
    }
} catch (Throwable $e) {
    flash('error', 'Delete failed: ' . $e->getMessage());
    redirect($tableUrl);
}

if ($export === 'txt') {
    stream_prospect_domains_export($countryKey, $q, $status);
}

// --- View all names ---
if ($view === 'names') {
    $plain = list_prospect_domains_plain($countryKey, $q, $status, 150000);
    $text = implode("\n", $plain['domains']);
    render_header('Our database · ' . $sheetLabel . ' · all names', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects'],
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
    render_footer('admin');
    return;
}

// --- Table view ---
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

$filterMatchCount = count_prospect_sites_filtered($countryKey, $q, $status);

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
    <p class="muted"><?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?> · select with mouse to delete (up to <?= (int) $per ?> per page)</p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= h($exportUrl) ?>">Download all (.txt)</a>
    <a class="btn secondary" href="<?= h($namesUrl) ?>">View all names</a>
    <?php if (!$emptyCountry): ?>
      <a class="btn secondary" href="index.php?page=admin_prospect_add&amp;country=<?= urlencode($countryName) ?>">Add sites</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?php if ($pendingFilterDelete): ?>
<div class="card" style="border-color:#c44">
  <h2>Confirm delete by filter</h2>
  <p>You are about to permanently delete <strong><?= (int) $pendingFilterDelete['count'] ?></strong> site(s) in <strong><?= h($sheetLabel) ?></strong>
    <?php if ($pendingFilterDelete['q'] !== ''): ?> matching search <code><?= h($pendingFilterDelete['q']) ?></code><?php endif; ?>
    <?php if ($pendingFilterDelete['status'] !== ''): ?> with status <code><?= h($pendingFilterDelete['status']) ?></code><?php endif; ?>.
  </p>
  <p class="help">This cannot be undone.</p>
  <form method="post" class="actions" style="margin-top:0.8rem">
    <input type="hidden" name="action" value="delete_filter">
    <input type="hidden" name="confirm" value="1">
    <input type="hidden" name="q" value="<?= h($pendingFilterDelete['q']) ?>">
    <input type="hidden" name="status" value="<?= h($pendingFilterDelete['status']) ?>">
    <input type="hidden" name="per" value="<?= (int) $per ?>">
    <button class="btn" type="submit" style="background:#b33;border-color:#b33">Yes, delete <?= (int) $pendingFilterDelete['count'] ?> sites</button>
    <a class="btn secondary" href="<?= h($tableUrl) ?>">Cancel</a>
  </form>
</div>
<?php endif; ?>

<?php if ($pendingUploadDelete): ?>
<div class="card" style="border-color:#c44">
  <h2>Confirm delete from list</h2>
  <p>Permanently delete <strong><?= (int) $pendingUploadDelete['count'] ?></strong> site(s) found in <strong><?= h($sheetLabel) ?></strong>?</p>
  <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($pendingUploadDelete['domains'], 0, 500))) ?><?= count($pendingUploadDelete['domains']) > 500 ? "\n… +" . (count($pendingUploadDelete['domains']) - 500) . ' more' : '' ?></textarea>
  <p class="help">This cannot be undone.</p>
  <form method="post" class="actions" style="margin-top:0.8rem">
    <input type="hidden" name="action" value="delete_upload">
    <input type="hidden" name="confirm" value="1">
    <input type="hidden" name="domains_text" value="<?= h($pendingUploadDelete['text']) ?>">
    <input type="hidden" name="per" value="<?= (int) $per ?>">
    <button class="btn" type="submit" style="background:#b33;border-color:#b33">Yes, delete <?= (int) $pendingUploadDelete['count'] ?> sites</button>
    <a class="btn secondary" href="<?= h($tableUrl) ?>">Cancel</a>
  </form>
</div>
<?php endif; ?>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospects">
  <input type="hidden" name="country" value="<?= h($countryKey) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (prospect_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Per page</label>
    <select name="per">
      <?php foreach (prospect_per_page_choices() as $n): ?>
        <option value="<?= (int) $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= (int) $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<form class="card" method="post" id="bulk_delete_form">
  <input type="hidden" name="action" value="delete_selected">
  <input type="hidden" name="confirm" id="delete_confirm" value="0">
  <input type="hidden" name="q" value="<?= h($q) ?>">
  <input type="hidden" name="status" value="<?= h($status) ?>">
  <input type="hidden" name="per" value="<?= (int) $per ?>">
  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">

  <div class="actions" style="margin-bottom:0.8rem;flex-wrap:wrap;gap:0.6rem;align-items:center">
    <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
      <input type="checkbox" id="select_all_page"> Select all on this page
    </label>
    <span class="help" id="selected_count">0 selected</span>
    <button class="btn" type="submit" id="delete_selected_btn" style="background:#b33;border-color:#b33" disabled>Delete selected</button>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:2.2rem"></th>
        <th>Site</th><th>Language</th><th>Status</th><th>Added by</th><th>When</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $s['id'] ?>"></td>
        <td><strong><?= h($s['domain']) ?></strong></td>
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
        <a class="btn" href="index.php?page=admin_prospect_add&amp;country=<?= urlencode($countryName) ?>">Add sites</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="actions" style="margin-top:0.8rem;flex-wrap:wrap;gap:0.75rem">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span>Page <?= $pageNum ?> / <?= $pages ?> · <?= (int) $per ?> per page</span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
      <a class="btn secondary" href="<?= h($namesUrl) ?>">View all names</a>
      <a class="btn secondary" href="<?= h($exportUrl) ?>">Download all (.txt)</a>
    </div>
  <?php endif; ?>
</form>

<div class="card">
  <h2>Remove tools</h2>
  <p class="muted" style="margin:0 0 0.8rem">Delete many sites at once. Always asks for confirmation.</p>

  <div class="form-grid">
    <div>
      <h3 style="margin:0 0 0.4rem;font-size:1rem">Delete by current filter</h3>
      <p class="help">
        Current filter matches <strong><?= (int) $filterMatchCount ?></strong> site(s)
        <?= $q !== '' ? ' for search “' . h($q) . '”' : '' ?>
        <?= $status !== '' ? ' · status ' . h($status) : '' ?>.
      </p>
      <form method="post" style="margin-top:0.5rem">
        <input type="hidden" name="action" value="delete_filter">
        <input type="hidden" name="q" value="<?= h($q) ?>">
        <input type="hidden" name="status" value="<?= h($status) ?>">
        <input type="hidden" name="per" value="<?= (int) $per ?>">
        <button class="btn secondary" type="submit" <?= $filterMatchCount <= 0 ? 'disabled' : '' ?>>
          Delete all matching filter…
        </button>
      </form>
    </div>
    <div>
      <h3 style="margin:0 0 0.4rem;font-size:1rem">Delete from .txt / paste</h3>
      <p class="help">Upload or paste root domains to remove from this country only.</p>
      <form method="post" enctype="multipart/form-data" style="margin-top:0.5rem">
        <input type="hidden" name="action" value="delete_upload">
        <input type="hidden" name="per" value="<?= (int) $per ?>">
        <label>Upload .txt</label>
        <input type="file" name="domains_file" accept=".txt,text/plain">
        <label style="margin-top:0.5rem">Or paste domains</label>
        <textarea name="domains_text" rows="5" placeholder="site1.com&#10;site2.de"></textarea>
        <p class="actions" style="margin-top:0.6rem">
          <button class="btn secondary" type="submit">Preview delete from list…</button>
        </p>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  var form = document.getElementById('bulk_delete_form');
  if (!form) return;
  var selectAll = document.getElementById('select_all_page');
  var checks = form.querySelectorAll('.row-check');
  var countEl = document.getElementById('selected_count');
  var btn = document.getElementById('delete_selected_btn');
  var confirmField = document.getElementById('delete_confirm');

  function sync(){
    var n = 0;
    checks.forEach(function(c){ if (c.checked) n++; });
    if (countEl) countEl.textContent = n + ' selected';
    if (btn) btn.disabled = n === 0;
    if (selectAll) {
      selectAll.checked = n > 0 && n === checks.length;
      selectAll.indeterminate = n > 0 && n < checks.length;
    }
  }
  if (selectAll) {
    selectAll.addEventListener('change', function(){
      checks.forEach(function(c){ c.checked = selectAll.checked; });
      sync();
    });
  }
  checks.forEach(function(c){ c.addEventListener('change', sync); });
  form.addEventListener('submit', function(e){
    var n = 0;
    checks.forEach(function(c){ if (c.checked) n++; });
    if (n === 0) {
      e.preventDefault();
      return;
    }
    var ok = window.confirm('Permanently delete ' + n + ' selected site(s) from ' + <?= json_encode($sheetLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> + '?\n\nThis cannot be undone.');
    if (!ok) {
      e.preventDefault();
      confirmField.value = '0';
      return;
    }
    confirmField.value = '1';
  });
  sync();
})();
</script>
<?php render_footer('admin'); ?>
