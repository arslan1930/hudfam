<?php
/**
 * Admin · Our database — country folders, inventory, copy/export, remove, edit, super search.
 * History policy A: deleting from Our DB leaves Site adding history rows intact.
 */
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
$emptyCountry = ($sheet === '_none');
$filterUser = (int) (get('created_by') ?: 0);
$superQ = trim((string) get('super_q'));
$nonEmptyOnly = (string) get('nonempty') === '1';
$sortByCount = (string) get('sort') !== 'name'; // default: count desc
$editId = (int) get('edit');

// Canonicalize / reject unknown country (not _none / all / empty folders view)
if (!$emptyCountry && $sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet === null) {
        flash('error', 'That country folder is not in the country list.');
        redirect('index.php?page=admin_prospects');
    }
    if ($canonSheet['name'] !== $sheet) {
        $redir = 'index.php?page=admin_prospects&country=' . urlencode($canonSheet['name']);
        if ($filterUser > 0) {
            $redir .= '&created_by=' . $filterUser;
        }
        redirect($redir);
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

$filterUserName = '';
if ($filterUser > 0) {
    try {
        $uStmt = db()->prepare('SELECT full_name, username FROM users WHERE id=? LIMIT 1');
        $uStmt->execute([$filterUser]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $filterUserName = (string) ($uRow['full_name'] ?: $uRow['username']);
        } else {
            $filterUser = 0;
        }
    } catch (Throwable $e) {
        $filterUser = 0;
    }
}

$returnQs = null; // reserved

// ——— POST actions ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');

    if ($action === 'remove_site') {
        $siteId = (int) post('site_id');
        $returnSuper = trim((string) post('super_q'));
        $returnCountry = trim((string) post('country'));
        $removed = delete_prospect_site_by_id($siteId);
        if (!$removed) {
            flash('error', 'Site not found.');
        } else {
            flash('ok', 'Removed ' . (string) $removed['domain'] . ' from Our database · '
                . ((string) ($removed['country'] ?: 'No country'))
                . ' (history left unchanged).');
        }
        if ($returnSuper !== '') {
            redirect('index.php?page=admin_prospects&super_q=' . rawurlencode($returnSuper) . '#super-search');
        }
        $redir = 'index.php?page=admin_prospects';
        if ($returnCountry !== '') {
            $redir .= '&country=' . rawurlencode($returnCountry);
        }
        if ($filterUser > 0) {
            $redir .= '&created_by=' . $filterUser;
        }
        foreach (['q', 'language', 'status', 'per'] as $k) {
            $v = trim((string) post($k));
            if ($v !== '') {
                $redir .= '&' . $k . '=' . rawurlencode($v);
            }
        }
        redirect($redir);
    }

    if ($action === 'remove_list') {
        $removeCountry = trim((string) post('country'));
        $raw = (string) post('remove_text');
        try {
            if ($removeCountry === '' || resolve_canonical_country($removeCountry) === null) {
                flash('error', 'Open a country folder first, then remove by list.');
                redirect('index.php?page=admin_prospects');
            }
            $result = remove_prospect_sites_by_list($removeCountry, $raw);
            if ($result['removed'] < 1) {
                flash(
                    'error',
                    $result['invalid'] > 0
                        ? 'No matching sites removed. Check the list and try again ('
                            . (int) $result['invalid'] . ' invalid line(s)).'
                        : 'No sites from that list were found in ' . $result['country'] . '.'
                );
                redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']) . '#remove-by-list');
            }
            $msg = 'Removed ' . (int) $result['removed'] . ' site(s) from Our database · ' . $result['country'];
            if ((int) $result['not_found'] > 0) {
                $msg .= ' · ' . (int) $result['not_found'] . ' not found';
            }
            if ((int) $result['invalid'] > 0) {
                $msg .= ' · ' . (int) $result['invalid'] . ' invalid skipped';
            }
            $msg .= ' (history left unchanged).';
            flash('ok', $msg);
            redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect(
                $removeCountry !== ''
                    ? 'index.php?page=admin_prospects&country=' . urlencode($removeCountry) . '#remove-by-list'
                    : 'index.php?page=admin_prospects'
            );
        }
    }

    if ($action === 'save_site_meta') {
        $siteId = (int) post('site_id');
        $returnCountry = trim((string) post('country'));
        $result = update_prospect_site_meta(
            $siteId,
            (string) post('language'),
            (string) post('status'),
            (string) post('notes')
        );
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Could not update.'));
        } else {
            flash('ok', 'Site details updated.');
        }
        $redir = 'index.php?page=admin_prospects';
        if ($returnCountry !== '') {
            $redir .= '&country=' . urlencode($returnCountry);
        } elseif ($emptyCountry) {
            $redir .= '&country=_none';
        }
        if ($filterUser > 0) {
            $redir .= '&created_by=' . $filterUser;
        }
        redirect($redir);
    }
}

// ——— Export download (filtered domains) ———
if ((string) get('export') === 'txt' && ($inCountry || $emptyCountry)) {
    $exportFilters = [
        'country' => $emptyCountry ? '' : $sheet,
        'q' => trim((string) get('q')),
        'language' => trim((string) get('language')),
        'status' => (string) get('status'),
    ];
    if ($filterUser > 0) {
        $exportFilters['created_by'] = $filterUser;
    }
    $pack = list_prospect_domains_for_export($exportFilters, 20000);
    $label = $emptyCountry ? 'no-country' : preg_replace('/[^a-zA-Z0-9_-]+/', '-', $sheet);
    $filename = 'our-database-' . $label . '-' . date('Ymd') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo implode("\n", $pack['domains']);
    if ($pack['domains'] !== []) {
        echo "\n";
    }
    exit;
}

// ——— Folders view ———
if (!$inCountry && !$emptyCountry) {
    $langFilter = trim((string) get('language'));
    $folders = prospect_country_folders();

    // Language filter: country default OR countries that have sites in that language
    if ($langFilter !== '') {
        $countriesWithLang = [];
        try {
            $langStmt = db()->prepare(
                'SELECT DISTINCT TRIM(country) AS country FROM prospect_sites WHERE language = ?'
            );
            $langStmt->execute([$langFilter]);
            foreach ($langStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cn) {
                $countriesWithLang[(string) $cn] = true;
            }
        } catch (Throwable $e) {
            // ignore
        }
        $folders = array_values(array_filter(
            $folders,
            static function ($f) use ($langFilter, $countriesWithLang) {
                if (strcasecmp((string) $f['language'], $langFilter) === 0) {
                    return true;
                }
                return isset($countriesWithLang[(string) $f['country']]);
            }
        ));
    }

    if ($nonEmptyOnly) {
        $folders = array_values(array_filter(
            $folders,
            static fn ($f) => (int) $f['total'] > 0
        ));
    }

    usort($folders, static function ($a, $b) use ($sortByCount) {
        if ($sortByCount) {
            $ta = (int) $a['total'];
            $tb = (int) $b['total'];
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }
        }
        $ra = (string) $a['region_label'];
        $rb = (string) $b['region_label'];
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcasecmp((string) $a['country'], (string) $b['country']);
    });

    $byRegion = [];
    foreach ($folders as $f) {
        $byRegion[$f['region_label']][] = $f;
    }
    $grandTotal = 0;
    foreach ($folders as $f) {
        $grandTotal += (int) $f['total'];
    }

    $superResults = $superQ !== '' ? search_prospect_sites_global($superQ, 200) : [];
    $superByCountry = [];
    foreach ($superResults as $hit) {
        $cKey = (string) ($hit['country'] !== '' ? $hit['country'] : 'No country');
        $superByCountry[$cKey][] = $hit;
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
        <p class="muted">Each country is its own site database. Open a folder to view, copy, edit, or remove sites. <?= (int) $grandTotal ?> sites total.</p>
      </div>
      <div class="actions">
        <a class="btn" href="index.php?page=admin_prospect_add">Add sites</a>
        <a class="btn secondary" href="#super-search">Super search</a>
        <a class="btn secondary" href="index.php?page=admin_prospect_batches">Site adding history</a>
      </div>
    </div>

    <?php if ($filterUser > 0 && $filterUserName !== ''): ?>
      <ul class="messages"><li>
        Showing sites added by <strong><?= h($filterUserName) ?></strong>
        · open a country folder to apply this filter
        · <a href="index.php?page=admin_prospects">Clear</a>
      </li></ul>
    <?php endif; ?>

    <details class="panel-guide-wrap" open>
      <summary>What is Our database?</summary>
      <?= guide_inventory() ?>
    </details>

    <div class="card" id="super-search">
      <h2 style="margin:0 0 0.45rem">Super search</h2>
      <p class="help" style="margin-top:0">Find a domain or URL across every country folder.</p>
      <form method="get" action="index.php" class="super-search-form">
        <input type="hidden" name="page" value="admin_prospects">
        <?php if ($filterUser > 0): ?>
          <input type="hidden" name="created_by" value="<?= (int) $filterUser ?>">
        <?php endif; ?>
        <label class="visually-hidden" for="super_q">Site name</label>
        <div class="super-search-row">
          <input id="super_q" name="super_q" type="search" value="<?= h($superQ) ?>"
                 placeholder="example.com or https://…" autocomplete="off" spellcheck="false">
          <button class="btn" type="submit">Super search</button>
          <?php if ($superQ !== ''): ?>
            <a class="btn secondary" href="index.php?page=admin_prospects">Clear</a>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($superQ !== ''): ?>
        <?php if (!$superResults): ?>
          <div class="empty-state" style="margin-top:0.75rem">
            <p>No matches for “<?= h($superQ) ?>” in any country database.</p>
          </div>
        <?php else: ?>
          <p class="muted" style="margin-top:0.75rem">
            Found <strong><?= count($superResults) ?></strong> match<?= count($superResults) === 1 ? '' : 'es' ?>
            in <strong><?= count($superByCountry) ?></strong> countr<?= count($superByCountry) === 1 ? 'y' : 'ies' ?>.
          </p>
          <div class="table-wrap" style="margin-top:0.5rem">
            <table>
              <thead><tr><th>Domain</th><th>Country</th><th>Added by</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($superResults as $hit): ?>
                <?php
                  $cHref = $hit['country'] !== '' ? (string) $hit['country'] : '_none';
                  $cLabel = $hit['country'] !== '' ? (string) $hit['country'] : 'No country';
                ?>
                <tr>
                  <td><strong><?= h($hit['domain']) ?></strong></td>
                  <td><a href="index.php?page=admin_prospects&amp;country=<?= urlencode($cHref) ?>"><?= h($cLabel) ?></a></td>
                  <td><?= h($hit['added_by_full'] ?: $hit['added_by_name'] ?: '—') ?></td>
                  <td class="actions">
                    <form method="post" action="index.php?page=admin_prospects#super-search" style="display:inline"
                          onsubmit="return confirm('Remove <?= h($hit['domain']) ?> from <?= h($cLabel) ?>?');">
                      <input type="hidden" name="action" value="remove_site">
                      <input type="hidden" name="site_id" value="<?= (int) $hit['id'] ?>">
                      <input type="hidden" name="super_q" value="<?= h($superQ) ?>">
                      <input type="hidden" name="country" value="<?= h((string) $hit['country']) ?>">
                      <button class="btn danger small" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <form class="card country-finder" method="get" action="index.php">
      <input type="hidden" name="page" value="admin_prospects">
      <?php if ($filterUser > 0): ?>
        <input type="hidden" name="created_by" value="<?= (int) $filterUser ?>">
      <?php endif; ?>
      <?php if ($sortByCount): ?>
        <input type="hidden" name="sort" value="count">
      <?php endif; ?>
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
      <div class="actions" style="margin-top:0.75rem;align-items:center">
        <label style="font-weight:500;display:flex;gap:0.4rem;align-items:center;margin:0">
          <input type="checkbox" name="nonempty" value="1" <?= $nonEmptyOnly ? 'checked' : '' ?>
                 onchange="this.form.submit()">
          Non-empty only
        </label>
        <a class="btn secondary small" href="index.php?page=admin_prospects&amp;sort=<?= $sortByCount ? 'name' : 'count' ?><?= $nonEmptyOnly ? '&amp;nonempty=1' : '' ?><?= $langFilter !== '' ? '&amp;language=' . urlencode($langFilter) : '' ?>">
          Sort: <?= $sortByCount ? 'by count' : 'by name' ?> (switch)
        </a>
      </div>
      <div style="margin-top:0.75rem">
        <label for="folder_live_search">Or filter folders below by typing</label>
        <input id="folder_live_search" type="search" data-folder-search placeholder="Type country or language…" autocomplete="off">
        <p class="help" style="margin:0.4rem 0 0">
          Showing <strong data-folder-count><?= count($folders) ?></strong> countries.
          <?php if ($langFilter !== ''): ?>
            Filtered by language “<?= h($langFilter) ?>” · <a href="index.php?page=admin_prospects">Clear</a>
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
              $folderUrl = 'index.php?page=admin_prospects&country=' . urlencode($href);
              if ($filterUser > 0) {
                  $folderUrl .= '&created_by=' . $filterUser;
              }
            ?>
            <a class="folder" data-search="<?= h($label . ' ' . $f['language']) ?>"
               href="<?= h($folderUrl) ?>">
              <h3><?= h($label) ?></h3>
              <p class="muted"><?= (int) $f['total'] ?> site<?= (int) $f['total'] === 1 ? '' : 's' ?><?= $f['language'] !== '' ? ' · ' . h($f['language']) : '' ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$folders): ?>
      <div class="card empty-state" data-folder-empty>
        <p>
          <?= $langFilter !== ''
              ? 'No countries match the language “' . h($langFilter) . '”.'
              : ($nonEmptyOnly ? 'No countries have sites yet.' : 'No countries configured. Run upgrade.php once.') ?>
        </p>
        <?php if ($langFilter !== '' || $nonEmptyOnly): ?>
          <a class="btn secondary" href="index.php?page=admin_prospects">Show all countries</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
    render_footer('admin');
    return;
}

// ——— One country database ———
$countryName = $emptyCountry ? '' : $sheet;
$q = trim((string) get('q'));
$status = (string) get('status');
$language = trim((string) get('language'));
$pageNum = max(1, (int) get('p', 1));
$perAllowed = [50, 100, 500, 1000];
$perPage = (int) get('per', 100);
if (!in_array($perPage, $perAllowed, true)) {
    $perPage = 100;
}

$filters = [
    'q' => $q,
    'country' => $countryName,
    'language' => $language,
    'status' => $status,
];
if ($filterUser > 0) {
    $filters['created_by'] = $filterUser;
}

$inv = prospect_inventory_query($filters, $pageNum, $perPage);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$qsParts = array_filter([
    'page' => 'admin_prospects',
    'country' => $emptyCountry ? '_none' : $countryName,
    'q' => $q,
    'language' => $language,
    'status' => $status,
    'created_by' => $filterUser > 0 ? (string) $filterUser : '',
    'per' => (string) $perPage,
], static fn ($v) => $v !== '' && $v !== null);
$qs = http_build_query($qsParts);

$exportPack = list_prospect_domains_for_export($filters, 20000);
$exportText = implode("\n", $exportPack['domains']);

$editRow = null;
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int) $r['id'] === $editId) {
            $editRow = $r;
            break;
        }
    }
    if (!$editRow) {
        try {
            $es = db()->prepare('SELECT * FROM prospect_sites WHERE id=? LIMIT 1');
            $es->execute([$editId]);
            $editRow = $es->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($editRow && !$emptyCountry && (string) $editRow['country'] !== $countryName) {
                $editRow = null;
            }
            if ($editRow && $emptyCountry && trim((string) $editRow['country']) !== '') {
                $editRow = null;
            }
        } catch (Throwable $e) {
            $editRow = null;
        }
    }
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
    <p class="muted">
      <?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?> in this country’s database
      · <?= (int) $perPage ?> per page
    </p>
  </div>
  <div class="actions">
    <?php if (!$emptyCountry): ?>
      <a class="btn" href="index.php?page=admin_prospect_add&amp;country=<?= urlencode($countryName) ?>">Add sites</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?php if ($filterUser > 0 && $filterUserName !== ''): ?>
  <ul class="messages"><li>
    Showing sites added by <strong><?= h($filterUserName) ?></strong>
    · <a href="index.php?page=admin_prospects&amp;country=<?= urlencode($emptyCountry ? '_none' : $countryName) ?>">Clear person filter</a>
  </li></ul>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="card" id="edit-site">
  <h2 style="margin:0 0 0.45rem">Edit · <?= h($editRow['domain']) ?></h2>
  <p class="help">Domain and country stay fixed. Update language, status, or notes.</p>
  <form method="post" action="index.php?page=admin_prospects&amp;country=<?= urlencode($emptyCountry ? '_none' : $countryName) ?>">
    <input type="hidden" name="action" value="save_site_meta">
    <input type="hidden" name="site_id" value="<?= (int) $editRow['id'] ?>">
    <input type="hidden" name="country" value="<?= h($emptyCountry ? '' : $countryName) ?>">
    <div class="form-grid">
      <div>
        <label for="edit_language">Language</label>
        <input id="edit_language" name="language" value="<?= h((string) ($editRow['language'] ?? '')) ?>">
      </div>
      <div>
        <label for="edit_status">Status</label>
        <select id="edit_status" name="status">
          <?php foreach (prospect_statuses() as $code => $label): ?>
            <option value="<?= h($code) ?>" <?= (string) $editRow['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <label for="edit_notes">Notes</label>
        <textarea id="edit_notes" name="notes" rows="2"><?= h((string) ($editRow['notes'] ?? '')) ?></textarea>
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit">Save</button>
      <a class="btn secondary" href="?<?= h($qs) ?>">Cancel</a>
    </p>
  </form>
</div>
<?php endif; ?>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospects">
  <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
  <?php if ($filterUser > 0): ?>
    <input type="hidden" name="created_by" value="<?= (int) $filterUser ?>">
  <?php endif; ?>
  <div><label for="q">Search</label><input id="q" name="q" value="<?= h($q) ?>" placeholder="domain or url…"></div>
  <div>
    <label for="language">Language <span class="help">(optional)</span></label>
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
  <div>
    <label for="per">Per page</label>
    <select id="per" name="per">
      <?php foreach ($perAllowed as $n): ?>
        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<?php if ($total > 0): ?>
<div class="card" id="copy-export">
  <h2 style="margin:0 0 0.45rem">Copy / export</h2>
  <p class="help" style="margin-top:0">
    Domains matching the current filters
    (<?= (int) $exportPack['total'] ?> total<?= $exportPack['capped'] ? ', showing first ' . (int) $exportPack['cap'] : '' ?>).
  </p>
  <textarea id="export_domains" class="inventory-box" rows="8" readonly><?= h($exportText) ?></textarea>
  <div class="actions" style="margin-top:0.75rem">
    <button type="button" class="btn secondary" id="copy_domains_btn">Copy all</button>
    <a class="btn secondary" href="?<?= h($qs) ?>&amp;export=txt">Download .txt</a>
  </div>
  <p class="help" id="copy_domains_status" hidden></p>
</div>
<script>
(function () {
  var btn = document.getElementById('copy_domains_btn');
  var ta = document.getElementById('export_domains');
  var status = document.getElementById('copy_domains_status');
  if (!btn || !ta) return;
  btn.addEventListener('click', function () {
    var text = ta.value || '';
    function done(ok) {
      if (!status) return;
      status.hidden = false;
      status.textContent = ok ? 'Copied ' + (text ? text.split(/\n/).filter(Boolean).length : 0) + ' domain(s).' : 'Copy failed — select the box and copy manually.';
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () {
        try { ta.focus(); ta.select(); done(document.execCommand('copy')); } catch (e) { done(false); }
      });
    } else {
      try { ta.focus(); ta.select(); done(document.execCommand('copy')); } catch (e) { done(false); }
    }
  });
})();
</script>
<?php endif; ?>

<div class="card">
  <div class="table-wrap inventory-table-wrap">
  <table class="inventory-table">
    <thead><tr><th>Domain</th><th>URL</th><th>Language</th><th>Status</th><th>Added by</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><strong><?= h($s['domain']) ?></strong></td>
        <td class="help"><?= h($s['url'] !== '' ? $s['url'] : '—') ?></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['added_by_full'] ?: $s['added_by_name'] ?: '—') ?></td>
        <td><?= h(substr((string) $s['created_at'], 0, 10)) ?></td>
        <td class="actions">
          <a class="btn secondary small" href="?<?= h($qs) ?>&amp;edit=<?= (int) $s['id'] ?>#edit-site">Edit</a>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Remove <?= h($s['domain']) ?> from <?= h($sheetLabel) ?>? History stays unchanged.');">
            <input type="hidden" name="action" value="remove_site">
            <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="language" value="<?= h($language) ?>">
            <input type="hidden" name="status" value="<?= h($status) ?>">
            <input type="hidden" name="per" value="<?= (int) $perPage ?>">
            <button class="btn danger small" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (!$rows): ?>
    <div class="empty-state">
      <p>No sites in this country yet<?= $q !== '' || $language !== '' || $status !== '' || $filterUser > 0 ? ' for these filters' : '' ?>.</p>
      <?php if (!$emptyCountry): ?>
        <a class="btn" href="index.php?page=admin_prospect_add&amp;country=<?= urlencode($countryName) ?>">Add sites</a>
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

<?php if (!$emptyCountry): ?>
<div class="card" id="remove-by-list" style="margin-top:1rem">
  <h2 style="margin:0 0 0.45rem">Remove sites</h2>
  <p class="help">
    Paste domains to remove from <strong><?= h($countryName) ?></strong> in Our database.
    Site adding history is left unchanged.
  </p>
  <form
    method="post"
    action="index.php?page=admin_prospects&amp;country=<?= urlencode($countryName) ?>#remove-by-list"
    onsubmit="return confirm('Remove all matching sites from this list in <?= h($countryName) ?> (Our database)?');"
  >
    <input type="hidden" name="action" value="remove_list">
    <input type="hidden" name="country" value="<?= h($countryName) ?>">
    <textarea name="remove_text" class="inventory-box" rows="8" placeholder="site-to-remove.com"></textarea>
    <p class="help">One domain per line. Only domains already in this country folder are removed.</p>
    <div class="actions" style="margin-top:0.75rem">
      <button class="btn danger" type="submit">Remove listed sites</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
