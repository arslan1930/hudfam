<?php
$user = require_admin();
ensure_extracted_schema();
seed_countries_if_empty(db());
if (function_exists('clear_admin_new_data')) {
    clear_admin_new_data('extracted_sites', $user);
}

$folder = (string) get('folder');
// Back-compat: old ?country= links open inside Extracted Sites
if ($folder === '' && (string) get('country') !== '') {
    $folder = 'extracted_sites';
}
// Sites with emails - Admin moved to Emails data panel
if ($folder === 'sites_with_emails') {
    $qs = 'index.php?page=admin_emails_data&folder=sites_with_emails';
    if ((string) get('country') !== '') {
        $qs .= '&country=' . urlencode((string) get('country'));
    }
    if ((string) get('export') !== '') {
        $qs .= '&export=' . urlencode((string) get('export'));
    }
    if ((string) get('q') !== '') {
        $qs .= '&q=' . urlencode((string) get('q'));
    }
    if ((string) get('p') !== '') {
        $qs .= '&p=' . urlencode((string) get('p'));
    }
    redirect($qs);
}

$allowedFolders = ['extracted_sites'];
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
    $returnQ = trim((string) post('q'));
    if ($returnQ === '') {
        $returnQ = trim((string) get('q'));
    }
    $returnP = max(1, (int) (post('p') ?: get('p', 1)));
    $returnPerPage = resolve_sheet_per_page();
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    $countryReturnUrl = static function () use ($sitesListUrl, $countryName, $returnQ, $returnP, $returnPerPage): string {
        $url = append_sheet_per_page_query(
            $sitesListUrl . '&country=' . rawurlencode($countryName),
            $returnPerPage
        );
        if ($returnQ !== '') {
            $url .= '&q=' . rawurlencode($returnQ);
        }
        if ($returnP > 1) {
            $url .= '&p=' . $returnP;
        }
        return $url;
    };

    if ($action === 'remove_list') {
        $raw = (string) post('remove_text');
        try {
            $fromFile = read_extracted_sites_upload($_FILES['remove_csv'] ?? null);
            if ($fromFile !== '') {
                $raw = trim($raw) !== '' ? ($raw . "\n" . $fromFile) : $fromFile;
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect($countryReturnUrl() . '#remove-by-list');
        }
        $result = remove_extracted_sites_by_list($countryName, $raw);
        if ($result['removed'] < 1) {
            flash(
                'error',
                $result['invalid'] > 0
                    ? 'No matching sites removed. Check the list (root domains) and try again.'
                    : 'No sites from that list were found in ' . $countryName . '.'
            );
            redirect($countryReturnUrl() . '#remove-by-list');
        }
        $msg = 'Removed ' . (int) $result['removed'] . ' site(s) from ' . $countryName;
        if ((int) $result['not_found'] > 0) {
            $msg .= ' · ' . (int) $result['not_found'] . ' not found';
        }
        if ((int) $result['invalid'] > 0) {
            $msg .= ' · ' . (int) $result['invalid'] . ' invalid skipped';
        }
        flash('ok', $msg . '.');
        if (count_extracted_sites_for_country($countryName) < 1) {
            redirect($sitesListUrl);
        }
        redirect($countryReturnUrl());
    }

    if ($action === 'remove_search') {
        $qRemove = trim((string) post('q'));
        $matchCount = count_extracted_sites_matching($countryName, $qRemove);
        if ($qRemove === '' || $matchCount < 1) {
            flash('error', 'No sites match that search to remove.');
            redirect($countryReturnUrl());
        }
        $n = remove_extracted_sites_by_search($countryName, $qRemove);
        flash('ok', 'Removed ' . $n . ' site(s) matching “' . $qRemove . '”.');
        if (count_extracted_sites_for_country($countryName) < 1) {
            redirect($sitesListUrl);
        }
        // After bulk remove-matching, stay on country without the old search (list is gone).
        redirect($sitesListUrl . '&country=' . urlencode($countryName));
    }

    if ($action === 'remove_site') {
        $siteId = (int) post('site_id');
        $site = get_extracted_site($siteId);
        if (!$site || (string) $site['country'] !== $countryName) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Site not found in this country.']);
                exit;
            }
            flash('error', 'Site not found in this country.');
            redirect($countryReturnUrl());
        }
        $domain = (string) $site['domain'];
        delete_extracted_site($siteId);
        $left = count_extracted_sites_for_country($countryName);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'domain' => $domain,
                'site_count' => $left,
                'redirect' => $left < 1 ? $sitesListUrl : null,
            ]);
            exit;
        }
        flash('ok', 'Removed ' . $domain . ' from ' . $countryName . '.');
        if ($left < 1) {
            redirect($sitesListUrl);
        }
        redirect($countryReturnUrl());
    }

    if ($action === 'remove_all') {
        $n = delete_extracted_sites_for_country($countryName);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from ' . $countryName . '.');
        redirect($sitesListUrl);
    }
}

// --- Hub: skip one-card hop — land on country list ---
if ($folder === '') {
    redirect($sitesListUrl);
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
        ['label' => 'Extracted Sites'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info('Extracted Sites', 'Country folders of URLs pushed from Team Extracting Results. Open a country to copy or remove sites.') ?></h1>
        <p class="muted">
          New URLs from Team Push ·
          <?= (int) $countryCount ?> countr<?= $countryCount === 1 ? 'y' : 'ies' ?> ·
          <?= (int) $grandTotal ?> URL<?= (int) $grandTotal === 1 ? '' : 's' ?>
        </p>
      </div>
    </div>

    <div class="card">
      <?php if ($countryRows): ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <h2 style="margin:0">By country</h2>
        <label class="sheet-search extracted-country-search" for="extracted-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="extracted-country-search" type="search" placeholder="Search country name…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type a country name · Enter = next match · Shift+Enter = previous">
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

// --- Extracted Sites → one country detail (plain numbered list) ---
$countryName = $sheet;
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));
$perPage = resolve_sheet_per_page();
$inv = extracted_inventory_query([
    'q' => $q,
    'country' => $countryName,
], $pageNum, $perPage);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryTotal = count_extracted_sites_for_country($countryName);
$searchMatchCount = $q !== '' ? count_extracted_sites_matching($countryName, $q) : 0;
$exportUrl = $sitesListUrl . '&country=' . rawurlencode($countryName) . '&export=domains';
$downloadUrl = $sitesListUrl . '&country=' . rawurlencode($countryName) . '&export=download';
$listBase = append_sheet_per_page_query(
    $sitesListUrl . '&country=' . rawurlencode($countryName),
    $perPage
);

$qs = http_build_query(array_filter([
    'page' => 'admin_extracted',
    'folder' => 'extracted_sites',
    'country' => $countryName,
    'q' => $q,
    'per_page' => $perPage,
], static fn ($v) => $v !== '' && $v !== null));

render_header('Extracted Sites · ' . $countryName, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted Sites', 'href' => $sitesListUrl],
    ['label' => $countryName],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($countryName) ?></h1>
    <p class="muted">
      <span id="extracted_total_label"><?= (int) $countryTotal ?></span> URL<?= (int) $countryTotal === 1 ? '' : 's' ?>
      <?= $q !== '' ? ' · ' . (int) $total . ' match' . ((int) $total === 1 ? '' : 'es') : '' ?>
    </p>
  </div>
  <div class="actions">
    <button
      type="button"
      class="btn"
      id="extracted_copy_all"
      data-export-url="<?= h($exportUrl) ?>"
      data-count="<?= (int) $countryTotal ?>"
      <?= $countryTotal > 0 ? '' : 'disabled' ?>
    >Copy all</button>
    <a class="btn secondary" href="<?= h($downloadUrl) ?>">Download .txt</a>
    <a class="btn secondary" href="<?= h($sitesListUrl) ?>">All countries</a>
  </div>
</div>
<p class="help" id="extracted_copy_status" hidden></p>

<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem;flex-wrap:wrap;gap:0.65rem">
    <h2 style="margin:0">URLs</h2>
    <?php if ($countryTotal > 0): ?>
    <div class="actions" style="margin-left:auto;align-items:center;flex-wrap:wrap;gap:0.45rem">
      <label class="sheet-search extracted-url-search" for="extracted-url-search" style="margin:0">
        <span class="visually-hidden">Search URLs</span>
        <input id="extracted-url-search" type="search" placeholder="Search…"
               value="<?= h($q) ?>"
               autocomplete="off" spellcheck="false" data-no-draft
               title="Type to filter this page · Enter = next match · Shift+Enter = previous · or use Search all pages">
        <span class="sheet-search-meta muted" data-extracted-url-search-meta hidden></span>
      </label>
      <button type="button" class="btn secondary small" id="extracted_search_all_pages"
              title="Search every page in this country (enables Remove matching)">Search all pages</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($q !== '' && $searchMatchCount > 0): ?>
  <form
    method="post"
    action="<?= h($listBase) ?>"
    onsubmit="return confirm(<?= h(json_encode('Remove ' . (int) $searchMatchCount . ' site(s) matching “' . $q . '”?', JSON_UNESCAPED_UNICODE)) ?>);"
    style="margin-bottom:0.85rem"
  >
    <input type="hidden" name="action" value="remove_search">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <p class="help" style="margin:0 0 0.55rem">
      Server search “<?= h($q) ?>” matches <strong><?= (int) $searchMatchCount ?></strong> URL<?= (int) $searchMatchCount === 1 ? '' : 's' ?> in this country.
    </p>
    <button class="btn danger small" type="submit">Remove <?= (int) $searchMatchCount ?> matching</button>
  </form>
  <?php endif; ?>

  <?php if ($rows): ?>
  <ol class="extracted-plain-list" id="extracted-plain-list" start="<?= (int) (($pageNum - 1) * $perPage + 1) ?>">
    <?php foreach ($rows as $s):
        $domain = (string) $s['domain'];
        ?>
      <li
        class="extracted-plain-item"
        data-extracted-url-row
        data-search="<?= h(mb_strtolower($domain)) ?>"
      >
        <span class="extracted-plain-domain"><?= h($domain) ?></span>
        <form method="post" class="extracted-plain-remove" action="<?= h($listBase) ?>"
              data-remove-site
              onsubmit="return confirm(<?= h(json_encode('Remove ' . $domain . '?', JSON_UNESCAPED_UNICODE)) ?>);">
          <input type="hidden" name="action" value="remove_site">
          <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
          <input type="hidden" name="q" value="<?= h($q) ?>" data-remove-q>
          <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
          <button class="btn secondary small" type="submit">Remove</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ol>
  <p class="help sheet-search-empty" data-extracted-url-search-empty hidden>No URLs on this page match your search.</p>
  <div class="actions" style="margin-top:0.85rem;justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
    <div class="actions" style="margin:0;gap:0.65rem;flex-wrap:wrap;align-items:center">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span class="muted">Page <?= $pageNum ?> / <?= $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?></span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
      <?php
      render_sheet_per_page_filter([
          'page' => 'admin_extracted',
          'folder' => 'extracted_sites',
          'country' => $countryName,
          'q' => $q,
      ], $perPage);
      ?>
    </div>
    <form method="post" action="<?= h($listBase) ?>"
          onsubmit="return confirm(<?= h(json_encode('Remove ALL ' . (int) $countryTotal . ' URLs from ' . $countryName . '?', JSON_UNESCAPED_UNICODE)) ?>);">
      <input type="hidden" name="action" value="remove_all">
      <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
      <button class="btn secondary small danger" type="submit">Remove all</button>
    </form>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <p>No URLs<?= $q !== '' ? ' match this search' : ' in this country yet' ?>.</p>
    <a class="btn secondary" href="<?= h($sitesListUrl) ?>">Back to countries</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($countryTotal > 0): ?>
<div class="card" id="remove-by-list" style="margin-top:1rem">
  <h2>Remove by list</h2>
  <p class="help">
    Paste site names (or upload a 1-column CSV) to remove those exact URLs from
    <strong><?= h($countryName) ?></strong>.
  </p>
  <form
    method="post"
    action="<?= h($listBase) ?>#remove-by-list"
    enctype="multipart/form-data"
    onsubmit="return confirm(<?= h(json_encode('Remove all matching sites from this list in ' . $countryName . '?', JSON_UNESCAPED_UNICODE)) ?>);"
  >
    <input type="hidden" name="action" value="remove_list">
    <textarea name="remove_text" class="inventory-box" rows="8" placeholder="site-to-remove.com"></textarea>
    <label style="display:block;margin-top:0.6rem">CSV (1 column)</label>
    <input type="file" name="remove_csv" accept=".csv,text/csv,text/plain,.txt">
    <p class="help">One site name per row. Only domains already in this country are removed.</p>
    <div class="actions" style="margin-top:0.75rem">
      <button class="btn danger" type="submit">Remove listed sites</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script src="<?= h(script_asset_url('js/extracted-admin.js')) ?>" defer></script>
<?php render_footer('admin'); ?>
