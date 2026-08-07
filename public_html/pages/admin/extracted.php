<?php
$user = require_admin();
ensure_extracted_schema();
seed_countries_if_empty(db());

$folder = (string) get('folder');
// Back-compat: old ?country= links open inside Extracted Sites
if ($folder === '' && (string) get('country') !== '') {
    $folder = 'extracted_sites';
}
$allowedFolders = ['extracted_sites', 'sites_with_emails'];
if ($folder !== '' && !in_array($folder, $allowedFolders, true)) {
    flash('error', 'Unknown folder.');
    redirect('index.php?page=admin_extracted');
}

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
if ($sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet === null) {
        flash('error', 'That country is not in the country list.');
        redirect('index.php?page=admin_extracted&folder=extracted_sites');
    }
    if ($canonSheet['name'] !== $sheet) {
        $qs = 'index.php?page=admin_extracted&folder=extracted_sites&country=' . urlencode($canonSheet['name']);
        if ((string) get('export') !== '') {
            $qs .= '&export=' . urlencode((string) get('export'));
        }
        redirect($qs);
    }
    $sheet = $canonSheet['name'];
    if ($folder === '') {
        $folder = 'extracted_sites';
    }
}
$inCountry = ($folder === 'extracted_sites' && $sheet !== '' && $sheet !== 'all');
$sitesListUrl = 'index.php?page=admin_extracted&folder=extracted_sites';

// Stream plain domain list for Copy all / Download (up to ~100k, no HTML embedding)
if ($inCountry && (string) get('export') !== '') {
    $mode = (string) get('export');
    if ($mode === 'domains' || $mode === 'download') {
        stream_extracted_domains_plain($sheet, $mode === 'download');
    }
}

// --- Mutations on country detail ---
if ($inCountry && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $countryName = $sheet;

    if ($action === 'remove_search') {
        $qRemove = trim((string) post('q'));
        $matchCount = count_extracted_sites_matching($countryName, $qRemove);
        if ($qRemove === '' || $matchCount < 1) {
            flash('error', 'No sites match that search to remove.');
            redirect($sitesListUrl . '&country=' . urlencode($countryName) . ($qRemove !== '' ? '&q=' . urlencode($qRemove) : ''));
        }
        $n = remove_extracted_sites_by_search($countryName, $qRemove);
        flash('ok', 'Removed ' . $n . ' site(s) matching “' . $qRemove . '”.');
        if (count_extracted_sites_for_country($countryName) < 1) {
            redirect($sitesListUrl);
        }
        redirect($sitesListUrl . '&country=' . urlencode($countryName));
    }

    if ($action === 'edit_site') {
        $siteId = (int) post('site_id');
        $newDomain = (string) post('domain');
        $site = get_extracted_site($siteId);
        if (!$site || (string) $site['country'] !== $countryName) {
            flash('error', 'Site not found in this country.');
            redirect($sitesListUrl . '&country=' . urlencode($countryName));
        }
        $result = update_extracted_site_domain($siteId, $newDomain);
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not update site.'));
        } else {
            flash('ok', 'Updated site to ' . (string) $result['domain'] . '.');
        }
        redirect($sitesListUrl . '&country=' . urlencode($countryName));
    }

    if ($action === 'remove_site') {
        $siteId = (int) post('site_id');
        $site = get_extracted_site($siteId);
        if (!$site || (string) $site['country'] !== $countryName) {
            flash('error', 'Site not found in this country.');
            redirect($sitesListUrl . '&country=' . urlencode($countryName));
        }
        $domain = (string) $site['domain'];
        delete_extracted_site($siteId);
        flash('ok', 'Removed ' . $domain . ' from ' . $countryName . '.');
        if (count_extracted_sites_for_country($countryName) < 1) {
            redirect($sitesListUrl);
        }
        redirect($sitesListUrl . '&country=' . urlencode($countryName));
    }

    if ($action === 'remove_all') {
        $n = delete_extracted_sites_for_country($countryName);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from ' . $countryName . '.');
        redirect($sitesListUrl);
    }
}

// --- Hub: two folders ---
if ($folder === '') {
    $countryRows = list_extracted_country_rows();
    $extractedTotal = 0;
    foreach ($countryRows as $r) {
        $extractedTotal += (int) $r['total'];
    }
    $countryCount = count($countryRows);

    render_header('Extracted URLs', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracted URLs'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Extracted URLs</h1>
        <p class="muted">Open a folder to work with extracted sites or sites with emails.</p>
      </div>
    </div>

    <div class="card">
      <div class="folders">
        <a class="folder" href="<?= h($sitesListUrl) ?>">
          <h3>Extracted Sites</h3>
          <p class="muted">
            <?= (int) $countryCount ?> countr<?= $countryCount === 1 ? 'y' : 'ies' ?>
            · <?= (int) $extractedTotal ?> new URL<?= (int) $extractedTotal === 1 ? '' : 's' ?>
          </p>
        </a>
        <a class="folder" href="index.php?page=admin_extracted&amp;folder=sites_with_emails">
          <h3>Sites with emails</h3>
          <p class="muted">Sites that include email contacts</p>
        </a>
      </div>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- Folder: Sites with emails ---
if ($folder === 'sites_with_emails') {
    render_header('Sites with emails', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
        ['label' => 'Sites with emails'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Sites with emails</h1>
        <p class="muted">Sites that include email contacts.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_extracted">All folders</a>
      </div>
    </div>
    <div class="card">
      <div class="empty-state">
        <p>This folder is ready.</p>
        <p class="muted">No sites with emails have been added yet.</p>
      </div>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- Folder: Extracted Sites → country rows ---
if ($folder === 'extracted_sites' && !$inCountry) {
    $countryRows = list_extracted_country_rows();
    $grandTotal = 0;
    foreach ($countryRows as $r) {
        $grandTotal += (int) $r['total'];
    }
    $countryCount = count($countryRows);

    render_header('Extracted Sites', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
        ['label' => 'Extracted Sites'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Extracted Sites</h1>
        <p class="muted">
          New URLs from Team Push ·
          <?= (int) $countryCount ?> countr<?= $countryCount === 1 ? 'y' : 'ies' ?> ·
          <?= (int) $grandTotal ?> URL<?= (int) $grandTotal === 1 ? '' : 's' ?>
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_extracted">All folders</a>
      </div>
    </div>

    <div class="card">
      <?php if ($countryRows): ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <h2 style="margin:0">By country</h2>
        <label class="sheet-search extracted-country-search" for="extracted-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="extracted-country-search" type="search" placeholder="Search…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type to filter · Enter = next match · Shift+Enter = previous">
          <span class="sheet-search-meta muted" data-extracted-country-search-meta hidden></span>
        </label>
      </div>
      <table class="extracted-country-table" id="extracted-country-table">
        <thead>
          <tr>
            <th>Country</th>
            <th class="num">URLs</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($countryRows as $r):
            $cName = (string) $r['country'];
            $cTotal = (int) $r['total'];
            $searchHay = mb_strtolower(trim(
                $cName . ' '
                . (string) ($r['language'] ?? '') . ' '
                . (string) ($r['region'] ?? '') . ' '
                . $cTotal . ' urls'
            ));
            ?>
          <tr data-extracted-country-row data-search="<?= h($searchHay) ?>">
            <td>
              <a class="extracted-country-link" href="<?= h($sitesListUrl) ?>&amp;country=<?= urlencode($cName) ?>">
                <?= h($cName) ?>
              </a>
            </td>
            <td class="num">
              <a class="extracted-country-count" href="<?= h($sitesListUrl) ?>&amp;country=<?= urlencode($cName) ?>"
                 title="Open <?= h($cName) ?>">
                <?= $cTotal ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
          <tr class="sheet-search-empty" data-extracted-country-search-empty hidden>
            <td colspan="2" class="muted">No countries match your search.</td>
          </tr>
        </tbody>
      </table>
      <script>
      (function () {
        var input = document.getElementById('extracted-country-search');
        if (!input) return;
        var matchRows = [];
        var matchIndex = -1;
        var meta = document.querySelector('[data-extracted-country-search-meta]');
        var empty = document.querySelector('[data-extracted-country-search-empty]');

        function clearHits() {
          document.querySelectorAll('#extracted-country-table .sheet-search-hit').forEach(function (el) {
            el.classList.remove('sheet-search-hit');
          });
        }

        function filterCountries() {
          var q = String(input.value || '').trim().toLowerCase();
          var rows = document.querySelectorAll('[data-extracted-country-row]');
          var shown = 0;
          matchRows = [];
          clearHits();
          rows.forEach(function (row) {
            var hay = String(row.getAttribute('data-search') || '');
            var hit = !q || hay.indexOf(q) !== -1;
            row.hidden = !hit;
            if (hit) {
              shown++;
              if (q) matchRows.push(row);
            }
          });
          if (empty) empty.hidden = !(q && shown === 0);
          if (matchIndex >= matchRows.length) matchIndex = matchRows.length ? 0 : -1;
          if (meta) {
            if (q) {
              meta.hidden = false;
              if (!matchRows.length) {
                meta.textContent = '0 · Enter = next';
              } else if (matchIndex >= 0) {
                meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
              } else {
                meta.textContent = matchRows.length + (matchRows.length === 1 ? ' match' : ' matches')
                  + ' · Enter = next';
              }
            } else {
              meta.hidden = true;
              meta.textContent = '';
              matchIndex = -1;
            }
          }
        }

        function jumpToMatch(dir) {
          var q = String(input.value || '').trim();
          if (!q) return;
          filterCountries();
          if (!matchRows.length) return;
          if (matchIndex < 0) {
            matchIndex = dir > 0 ? 0 : matchRows.length - 1;
          } else {
            matchIndex = (matchIndex + dir + matchRows.length) % matchRows.length;
          }
          var row = matchRows[matchIndex];
          if (!row) return;
          clearHits();
          row.hidden = false;
          row.classList.add('sheet-search-hit');
          row.scrollIntoView({ block: 'center', behavior: 'smooth' });
          if (meta) {
            meta.hidden = false;
            meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
          }
          window.setTimeout(function () {
            try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
            try {
              var len = String(input.value || '').length;
              input.setSelectionRange(len, len);
            } catch (err2) {}
          }, 0);
        }

        input.addEventListener('input', function () {
          matchIndex = -1;
          filterCountries();
        });
        input.addEventListener('search', function () {
          matchIndex = -1;
          filterCountries();
        });
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            jumpToMatch(e.shiftKey ? -1 : 1);
          }
        });
      })();
      </script>
      <?php else: ?>
      <div class="empty-state">
        <p>No new URLs yet.</p>
        <p class="muted">They appear only when Team clicks Push in Extracting Results.</p>
      </div>
      <?php endif; ?>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- Extracted Sites → one country detail ---
$countryName = $sheet;
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));
$inv = extracted_inventory_query([
    'q' => $q,
    'country' => $countryName,
], $pageNum, 100);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryTotal = count_extracted_sites_for_country($countryName);
$searchMatchCount = $q !== '' ? count_extracted_sites_matching($countryName, $q) : 0;
$exportUrl = $sitesListUrl . '&country=' . rawurlencode($countryName) . '&export=domains';
$downloadUrl = $sitesListUrl . '&country=' . rawurlencode($countryName) . '&export=download';

$qs = http_build_query(array_filter([
    'page' => 'admin_extracted',
    'folder' => 'extracted_sites',
    'country' => $countryName,
    'q' => $q,
], static fn ($v) => $v !== '' && $v !== null));

render_header('Extracted Sites · ' . $countryName, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
    ['label' => 'Extracted Sites', 'href' => $sitesListUrl],
    ['label' => $countryName],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($countryName) ?></h1>
    <p class="muted"><?= (int) $countryTotal ?> site<?= (int) $countryTotal === 1 ? '' : 's' ?><?= $q !== '' ? ' · showing ' . (int) $total . ' match' . ((int) $total === 1 ? '' : 'es') : '' ?></p>
  </div>
  <div class="actions">
    <button
      type="button"
      class="btn"
      id="extracted_copy_all"
      data-export-url="<?= h($exportUrl) ?>"
      data-count="<?= (int) $countryTotal ?>"
      <?= $countryTotal > 0 ? '' : 'disabled' ?>
    >Copy all sites</button>
    <a class="btn secondary" href="<?= h($downloadUrl) ?>">Download .txt</a>
    <a class="btn secondary" href="<?= h($sitesListUrl) ?>">All countries</a>
  </div>
</div>
<p class="help" id="extracted_copy_status" hidden></p>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_extracted">
  <input type="hidden" name="folder" value="extracted_sites">
  <input type="hidden" name="country" value="<?= h($countryName) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <button class="btn" type="submit">Filter</button>
</form>
<?php if ($q !== '' && $searchMatchCount > 0): ?>
<form
  class="card"
  method="post"
  action="<?= h($sitesListUrl . '&country=' . rawurlencode($countryName)) ?>"
  onsubmit="return confirm('Remove <?= (int) $searchMatchCount ?> site(s) matching “<?= h($q) ?>”?');"
  style="margin-top:0.75rem"
>
  <input type="hidden" name="action" value="remove_search">
  <input type="hidden" name="q" value="<?= h($q) ?>">
  <p class="help" style="margin:0 0 0.6rem">
    Search “<?= h($q) ?>” matches <strong><?= (int) $searchMatchCount ?></strong> site<?= (int) $searchMatchCount === 1 ? '' : 's' ?>.
  </p>
  <button class="btn danger" type="submit">Remove <?= (int) $searchMatchCount ?> matching</button>
</form>
<?php endif; ?>

<div class="card">
  <?php if ($rows): ?>
  <table class="extracted-sites-table">
    <thead>
      <tr>
        <th>Site</th>
        <th>Pushed by</th>
        <th>When</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td>
          <form method="post" class="extracted-edit-form">
            <input type="hidden" name="action" value="edit_site">
            <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
            <div class="extracted-edit-row">
              <input name="domain" value="<?= h((string) $s['domain']) ?>" required>
              <button class="btn secondary small" type="submit">Save</button>
            </div>
          </form>
        </td>
        <td><?= h((string) ($s['pushed_by_full'] ?: $s['pushed_by_name'] ?: '—')) ?></td>
        <td class="muted"><?= h(substr((string) $s['created_at'], 0, 16)) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove <?= h((string) $s['domain']) ?> from <?= h($countryName) ?>?');">
            <input type="hidden" name="action" value="remove_site">
            <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
            <button class="btn small danger" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem;justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
    <div>
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span class="muted">Page <?= $pageNum ?> / <?= $pages ?></span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
    </div>
    <form method="post" onsubmit="return confirm('Remove ALL <?= (int) $countryTotal ?> sites from <?= h($countryName) ?>?');">
      <input type="hidden" name="action" value="remove_all">
      <button class="btn secondary small danger" type="submit">Remove all</button>
    </form>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <p>No extracted sites<?= $q !== '' ? ' match this search' : ' in this country yet' ?>.</p>
    <a class="btn secondary" href="<?= h($sitesListUrl) ?>">Back to countries</a>
  </div>
  <?php endif; ?>
</div>

<script src="<?= h(script_asset_url('js/extracted-admin.js')) ?>" defer></script>
<?php render_footer('admin'); ?>
