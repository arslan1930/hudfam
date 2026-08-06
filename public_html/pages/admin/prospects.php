<?php
$user = require_admin();
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
        <p class="muted">Browse, download, add, or delete sites. Mistaken deletes can be undone. <?= (int) $grandTotal ?> sites total.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_prospect_batches">Add history</a>
      </div>
    </div>
    <?= render_page_purpose(
        'Our database — one folder per country',
        'Sites are stored separately for each country.',
        'Search by keyword → Select all → Delete. Use Undo if you delete by mistake.',
        [
            'Open a country folder.',
            'Search keywords, select sites, then delete.',
            'Undo restores the last delete from trash.',
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
$autoselect = (string) get('autoselect') === '1';

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

$pendingUploadDelete = null;
$preselectedIds = [];

/** @param array{deleted:int,undo_token:string} $result */
$rememberUndo = static function (array $result, string $countryKey, string $sheetLabel) use ($tableUrl): void {
    $n = (int) ($result['deleted'] ?? 0);
    $token = (string) ($result['undo_token'] ?? '');
    if ($n > 0 && $token !== '') {
        $_SESSION['prospect_last_undo'] = [
            'token' => $token,
            'country' => $countryKey,
            'count' => $n,
            'label' => $sheetLabel,
            'at' => time(),
        ];
        flash('ok', 'Deleted ' . $n . ' site(s) from ' . $sheetLabel . '. Use Undo below if this was a mistake.');
    } else {
        flash('error', 'No sites were deleted.');
    }
    redirect($tableUrl);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $confirmed = (string) post('confirm') === '1';
        $uid = (int) ($user['id'] ?? 0);

        if ($action === 'undo_delete') {
            $token = trim((string) post('undo_token'));
            $result = undo_prospect_delete($token);
            if (!empty($_SESSION['prospect_last_undo']['token']) && $_SESSION['prospect_last_undo']['token'] === $token) {
                unset($_SESSION['prospect_last_undo']);
            }
            if ($result['restored'] > 0) {
                $msg = 'Undo complete — restored ' . (int) $result['restored'] . ' site(s).';
                if ($result['skipped'] > 0) {
                    $msg .= ' Skipped ' . (int) $result['skipped'] . ' already present.';
                }
                flash('ok', $msg);
            } else {
                flash('error', 'Nothing to undo (already restored, or trash expired).');
            }
            redirect($tableUrl);
        }

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
                $rememberUndo(delete_prospect_sites_by_ids($ids, $countryKey, $uid), $countryKey, $sheetLabel);
            }
            redirect($tableUrl . ($pageNum > 1 ? '&p=' . $pageNum : ''));
        }

        if ($action === 'select_matching') {
            $matchCount = count_prospect_sites_filtered($countryKey, $q, $status);
            if ($matchCount <= 0) {
                flash('error', 'No sites match your keyword search.');
                redirect($tableUrl);
            }
            $redir = 'index.php?' . http_build_query(array_filter([
                'page' => 'admin_prospects',
                'country' => $countryKey,
                'q' => $q,
                'status' => $status,
                'per' => 1000,
                'p' => 1,
                'autoselect' => 1,
            ], static fn($v) => $v !== '' && $v !== null));
            flash('ok', 'Showing up to 1000 matches for your search. Checkboxes are selected — review, then Delete selected.');
            redirect($redir);
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
                $toRemove = filter_domains_against_prospects($domains, $countryName)['existing'];
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
                $rememberUndo(delete_prospect_sites_by_domains($toRemove, $countryKey, $uid), $countryKey, $sheetLabel);
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

// Preselect IDs when autoselect=1 (after keyword search → select all matches)
if ($autoselect) {
    $preselectedIds = array_fill_keys(list_prospect_ids_filtered($countryKey, $q, $status, 1000), true);
}

$lastUndo = null;
if (!empty($_SESSION['prospect_last_undo']) && is_array($_SESSION['prospect_last_undo'])) {
    $lu = $_SESSION['prospect_last_undo'];
    if (($lu['country'] ?? '') === $countryKey && (time() - (int) ($lu['at'] ?? 0)) < 86400) {
        $lastUndo = $lu;
    }
}
$trashBatches = list_prospect_trash_batches($countryKey, 8);

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
        <p class="muted"><?= (int) $plain['total'] ?> site<?= (int) $plain['total'] === 1 ? '' : 's' ?></p>
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
    <p class="muted"><?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?> · search keywords → select all → delete · Undo available</p>
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

<?php if ($lastUndo): ?>
<div class="card" style="border-color:#2a7">
  <h2>Undo last delete</h2>
  <p>You deleted <strong><?= (int) $lastUndo['count'] ?></strong> site(s) from <strong><?= h((string) $lastUndo['label']) ?></strong>. Restore them?</p>
  <form method="post" class="actions">
    <input type="hidden" name="action" value="undo_delete">
    <input type="hidden" name="undo_token" value="<?= h((string) $lastUndo['token']) ?>">
    <button class="btn" type="submit">Undo delete</button>
  </form>
</div>
<?php endif; ?>

<?php if ($pendingUploadDelete): ?>
<div class="card" style="border-color:#c44">
  <h2>Confirm delete from list</h2>
  <p>Delete <strong><?= (int) $pendingUploadDelete['count'] ?></strong> site(s) from <strong><?= h($sheetLabel) ?></strong>? You can Undo afterward.</p>
  <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($pendingUploadDelete['domains'], 0, 500))) ?><?= count($pendingUploadDelete['domains']) > 500 ? "\n… +" . (count($pendingUploadDelete['domains']) - 500) . ' more' : '' ?></textarea>
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

<form class="card filters" method="get" id="search_form">
  <input type="hidden" name="page" value="admin_prospects">
  <input type="hidden" name="country" value="<?= h($countryKey) ?>">
  <div><label>Search by keywords</label><input name="q" value="<?= h($q) ?>" placeholder="e.g. shop, blog, .de…"></div>
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
  <button class="btn" type="submit">Search</button>
</form>

<?php if ($q !== '' || $status !== ''): ?>
<div class="card" style="padding:0.85rem 1rem">
  <p style="margin:0" class="help">
    Keyword search matches <strong><?= (int) $filterMatchCount ?></strong> site(s).
    Next: select them, then delete.
  </p>
  <form method="post" class="actions" style="margin-top:0.6rem">
    <input type="hidden" name="action" value="select_matching">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input type="hidden" name="per" value="<?= (int) $per ?>">
    <button class="btn secondary" type="submit" <?= $filterMatchCount <= 0 ? 'disabled' : '' ?>>
      Select all matching (max 1000)
    </button>
  </form>
</div>
<?php endif; ?>

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
      <?php $checked = isset($preselectedIds[(int) $s['id']]); ?>
      <tr>
        <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $s['id'] ?>" <?= $checked ? 'checked' : '' ?>></td>
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
      <p>No sites<?= $q !== '' ? ' match this search' : ' in this country yet' ?>.</p>
    </div>
  <?php else: ?>
    <div class="actions" style="margin-top:0.8rem;flex-wrap:wrap;gap:0.75rem">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span>Page <?= $pageNum ?> / <?= $pages ?> · <?= (int) $per ?> per page</span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
</form>

<div class="card">
  <h2>Remove from .txt / paste</h2>
  <p class="muted">Upload or paste domains to remove from this country. Confirm first — then you can Undo.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="delete_upload">
    <input type="hidden" name="per" value="<?= (int) $per ?>">
    <div class="form-grid">
      <div>
        <label>Upload .txt</label>
        <input type="file" name="domains_file" accept=".txt,text/plain">
      </div>
      <div class="full">
        <label>Or paste domains</label>
        <textarea name="domains_text" rows="5" placeholder="site1.com&#10;site2.de"></textarea>
      </div>
    </div>
    <p class="actions" style="margin-top:0.6rem">
      <button class="btn secondary" type="submit">Preview delete from list…</button>
    </p>
  </form>
</div>

<?php if ($trashBatches): ?>
<div class="card">
  <h2>Recently deleted (Undo)</h2>
  <table>
    <thead><tr><th>When</th><th>Sites</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($trashBatches as $b): ?>
      <tr>
        <td><?= h((string) $b['deleted_at']) ?></td>
        <td><?= (int) $b['site_count'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="undo_delete">
            <input type="hidden" name="undo_token" value="<?= h((string) $b['undo_token']) ?>">
            <button class="btn secondary" type="submit">Undo / restore</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

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
    if (n === 0) { e.preventDefault(); return; }
    var ok = window.confirm('Delete ' + n + ' selected site(s) from ' + <?= json_encode($sheetLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> + '?\n\nYou can Undo afterward.');
    if (!ok) { e.preventDefault(); confirmField.value = '0'; return; }
    confirmField.value = '1';
  });
  <?php if ($autoselect): ?>
  if (selectAll) { selectAll.checked = true; }
  checks.forEach(function(c){ c.checked = true; });
  <?php endif; ?>
  sync();
})();
</script>
<?php render_footer('admin'); ?>
