<?php
/**
 * Team · Our database (read-only browse).
 * Country folders · whole-folder search · Copy all / CSV · Copy matches.
 * Add / Remove stay Admin (or Team Filter & add).
 */
$user = require_team();
ensure_prospect_schema();

if (!team_page_unlocked($user, 'team_prospects')) {
    flash('error', 'Our database browse is for Site Finding members.');
    redirect('index.php?page=team_dashboard');
}

seed_countries_if_empty(db());
if (function_exists('dedupe_countries_catalog')) {
    try {
        dedupe_countries_catalog();
    } catch (Throwable $e) {
        // ignore
    }
}

$pageKey = 'team_prospects';
$base = 'index.php?page=' . $pageKey;

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
$emptyCountry = ($sheet === '_none');
if (!$emptyCountry && $sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet !== null && $canonSheet['name'] !== $sheet) {
        redirect($base . '&country=' . urlencode($canonSheet['name']));
    }
    if ($canonSheet === null) {
        flash('error', 'That country folder is not in the country list.');
        redirect($base);
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

// Stream domain list / CSV (read-only).
if ($inCountry && !$emptyCountry && (string) get('export') !== '') {
    $mode = (string) get('export');
    $exportQ = trim((string) get('q'));
    if ($mode === 'domains' || $mode === 'download') {
        stream_prospect_domains_plain($sheet, $mode === 'download', $exportQ);
    }
    if ($mode === 'csv') {
        stream_prospect_domains_csv($sheet, $exportQ);
    }
}

// --- Hub: country folders ---
if (!$inCountry && !$emptyCountry) {
    $folders = prospect_country_folders();
    $byRegion = [];
    foreach (regions() as $regionKey => $regionLabel) {
        $byRegion[$regionLabel] = [];
    }
    foreach ($folders as $f) {
        $label = (string) ($f['region_label'] ?? 'Other');
        if (!isset($byRegion[$label])) {
            $byRegion[$label] = [];
        }
        $byRegion[$label][] = $f;
    }
    foreach ($byRegion as $k => $list) {
        if ($list === []) {
            unset($byRegion[$k]);
        }
    }
    $grandTotal = 0;
    foreach ($folders as $f) {
        $grandTotal += (int) $f['total'];
    }

    render_header('Our database', 'team');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
        ['label' => 'Our database'],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info('Our database', 'Browse country folders (read-only). Use Filter & add to submit new unique sites.') ?></h1>
        <p class="muted">
          <?= (int) $grandTotal ?> sites total · browse, search, copy, and download — no add/remove here
        </p>
      </div>
      <div class="actions">
        <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
          <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
        <?php endif; ?>
        <?php if (team_page_unlocked($user, 'team_prospect_batches')): ?>
          <a class="btn secondary" href="index.php?page=team_prospect_batches">Site adding history</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($folders): ?>
    <div class="card prospect-markets-toolbar">
      <div class="invoice-list-toolbar" style="margin-bottom:0">
        <h2 style="margin:0">Markets</h2>
        <label class="sheet-search" for="prospect-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="prospect-country-search" type="search" placeholder="Search country name…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type a country name · Enter = next match">
          <span class="sheet-search-meta muted" data-prospect-country-search-meta hidden></span>
        </label>
      </div>
    </div>

    <div id="prospect-markets">
    <?php
    $marketIndex = 0;
    foreach ($byRegion as $regionLabel => $list):
        $marketIndex++;
        $marketId = 'market-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($regionLabel));
        $openByDefault = $marketIndex <= 2;
        $marketTotal = 0;
        foreach ($list as $f) {
            $marketTotal += (int) $f['total'];
        }
        ?>
      <div class="card prospect-market<?= $openByDefault ? ' is-open' : '' ?>"
           data-prospect-market
           data-market-label="<?= h(mb_strtolower($regionLabel)) ?>">
        <button type="button" class="prospect-market-toggle" data-prospect-market-toggle
                aria-expanded="<?= $openByDefault ? 'true' : 'false' ?>"
                aria-controls="<?= h($marketId) ?>">
          <span class="prospect-market-title"><?= h($regionLabel) ?></span>
          <span class="prospect-market-meta muted">
            <?= count($list) ?> countr<?= count($list) === 1 ? 'y' : 'ies' ?>
            · <?= (int) $marketTotal ?> site<?= (int) $marketTotal === 1 ? '' : 's' ?>
          </span>
          <span class="prospect-market-chevron" aria-hidden="true"></span>
        </button>
        <div class="prospect-market-body" id="<?= h($marketId) ?>" <?= $openByDefault ? '' : 'hidden' ?>>
          <div class="folders" style="margin-top:0.7rem">
            <?php foreach ($list as $f):
                $href = $f['country'] !== '' ? $f['country'] : '_none';
                $label = (string) ($f['country'] !== '' ? $f['country'] : 'No country');
                $siteCount = (int) $f['total'];
                $searchHay = mb_strtolower(trim($label . ' ' . (string) $regionLabel . ' ' . $siteCount . ' sites'));
                ?>
              <a class="folder"
                 href="<?= h($base) ?>&amp;country=<?= urlencode($href) ?>"
                 data-prospect-country
                 data-search="<?= h($searchHay) ?>"
                 title="<?= h($label) ?>">
                <h3><span class="prospect-folder-label"><?= h($label) ?></span></h3>
                <p class="muted">
                  <span class="prospect-folder-count"><?= $siteCount ?></span> no. of sites
                </p>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="help sheet-search-empty" data-prospect-country-empty hidden>No countries in this market match.</p>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="help sheet-search-empty" data-prospect-country-search-empty hidden style="margin-top:0.75rem">
      No countries match your search.
    </p>
    <script>
    (function () {
      var searchInput = document.getElementById('prospect-country-search');
      var matchCards = [], matchIndex = -1;
      var meta = document.querySelector('[data-prospect-country-search-meta]');
      var emptyAll = document.querySelector('[data-prospect-country-search-empty]');
      function clearHits() {
        document.querySelectorAll('[data-prospect-country].sheet-search-hit').forEach(function (el) {
          el.classList.remove('sheet-search-hit');
        });
      }
      function setMarketOpen(market, open) {
        if (!market) return;
        market.classList.toggle('is-open', !!open);
        var body = market.querySelector('.prospect-market-body');
        var btn = market.querySelector('[data-prospect-market-toggle]');
        if (body) body.hidden = !open;
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      document.querySelectorAll('[data-prospect-market-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var market = btn.closest('[data-prospect-market]');
          if (!market) return;
          setMarketOpen(market, !market.classList.contains('is-open'));
        });
      });
      function filterCountries() {
        if (!searchInput) return;
        var q = String(searchInput.value || '').trim().toLowerCase();
        matchCards = [];
        clearHits();
        var anyShown = 0;
        document.querySelectorAll('[data-prospect-market]').forEach(function (market) {
          var shownInMarket = 0;
          market.querySelectorAll('[data-prospect-country]').forEach(function (card) {
            var hay = String(card.getAttribute('data-search') || '');
            var hit = !q || hay.indexOf(q) !== -1;
            card.hidden = !hit;
            if (hit) {
              shownInMarket++;
              anyShown++;
              if (q) matchCards.push(card);
            }
          });
          market.hidden = shownInMarket < 1;
          if (q && shownInMarket > 0) setMarketOpen(market, true);
          var emptyM = market.querySelector('[data-prospect-country-empty]');
          if (emptyM) emptyM.hidden = shownInMarket > 0 || !q;
        });
        matchIndex = -1;
        if (meta) {
          if (!q) { meta.hidden = true; meta.textContent = ''; }
          else {
            meta.hidden = false;
            meta.textContent = !matchCards.length ? '0 · Enter = next'
              : (matchCards.length + ' matches · Enter = next');
          }
        }
        if (emptyAll) emptyAll.hidden = anyShown > 0 || !q;
      }
      function jump(dir) {
        if (!matchCards.length) return;
        matchIndex = matchIndex < 0 ? (dir > 0 ? 0 : matchCards.length - 1)
          : (matchIndex + dir + matchCards.length) % matchCards.length;
        clearHits();
        var row = matchCards[matchIndex];
        if (row) {
          row.classList.add('sheet-search-hit');
          try { row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
          catch (e) { row.scrollIntoView(true); }
        }
        if (meta) meta.textContent = (matchIndex + 1) + ' of ' + matchCards.length + ' · Enter = next';
      }
      if (searchInput) {
        searchInput.addEventListener('input', function () { matchIndex = -1; filterCountries(); });
        searchInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); jump(e.shiftKey ? -1 : 1); }
        });
      }
    })();
    </script>
    <?php else: ?>
      <div class="card empty-state"><p>No countries configured yet.</p></div>
    <?php endif;
    render_footer('team');
    return;
}

// --- One country folder (read-only) ---
$countryName = $emptyCountry ? '' : $sheet;
$wantsAjax = (string) get('ajax') === '1';
if (!$emptyCountry && !$wantsAjax) {
    try {
        purge_duplicate_prospect_site_rows($countryName);
    } catch (Throwable $e) {
        // ignore
    }
}
$q = trim((string) get('q'));
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));
$perPage = resolve_sheet_per_page();
$inv = prospect_inventory_query([
    'q' => $q,
    'country' => $countryName,
    'status' => $status,
] + ($emptyCountry ? [] : []), $pageNum, $perPage);

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
    $pages = max(1, (int) ceil($total / $perPage));
    $offset = ($pageNum - 1) * $perPage;
    $stmt = db()->prepare(
        "SELECT p.*, u.username added_by_name, u.full_name added_by_full
         FROM prospect_sites p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE $whereSql ORDER BY p.created_at DESC LIMIT {$perPage} OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $rows = $inv['rows'];
    $total = $inv['total'];
    $pages = $inv['pages'];
}

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$countryTotal = $emptyCountry
    ? (int) $total
    : count_prospect_sites_matching($countryName, '');
$searchMatchCount = ($q !== '' && !$emptyCountry) ? (int) $total : 0;
$exportBase = $base . '&country=' . rawurlencode($emptyCountry ? '_none' : $countryName);
$exportAllUrl = $exportBase . '&export=domains';
$downloadTxtUrl = $exportBase . '&export=download';
$downloadCsvUrl = $exportBase . '&export=csv';
$exportMatchesUrl = $q !== '' ? ($exportBase . '&export=domains&q=' . rawurlencode($q)) : '';
$downloadMatchesTxtUrl = $q !== '' ? ($exportBase . '&export=download&q=' . rawurlencode($q)) : '';
$downloadMatchesCsvUrl = $q !== '' ? ($exportBase . '&export=csv&q=' . rawurlencode($q)) : '';
$exportAllBasename = !$emptyCountry ? prospect_export_basename($countryName, '') : 'sites-our-database';
$exportMatchesBasename = (!$emptyCountry && $q !== '')
    ? prospect_export_basename($countryName, $q)
    : '';
$exportAllTxtName = $exportAllBasename . '.txt';
$exportMatchesTxtName = $exportMatchesBasename !== '' ? ($exportMatchesBasename . '.txt') : '';

$qs = http_build_query(array_filter([
    'page' => $pageKey,
    'country' => $emptyCountry ? '_none' : $countryName,
    'q' => $q,
    'status' => $status,
    'per_page' => $perPage,
], static fn ($v) => $v !== '' && $v !== null));

if ($wantsAjax && !$emptyCountry) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $exportMatchesUrlAjax = $q !== '' ? ($exportBase . '&export=domains&q=' . rawurlencode($q)) : '';
    $downloadMatchesTxtAjax = $q !== '' ? ($exportBase . '&export=download&q=' . rawurlencode($q)) : '';
    $downloadMatchesCsvAjax = $q !== '' ? ($exportBase . '&export=csv&q=' . rawurlencode($q)) : '';
    $matchesBase = $q !== '' ? prospect_export_basename($countryName, $q) : '';
    $payload = [
        'ok' => true,
        'q' => $q,
        'country_total' => $countryTotal,
        'match_count' => $q !== '' ? (int) $total : 0,
        'page' => $pageNum,
        'pages' => $pages,
        'per_page' => $perPage,
        'rows_html' => prospect_site_rows_html($rows),
        'has_rows' => $rows !== [],
        'export_matches_url' => $exportMatchesUrlAjax,
        'download_matches_txt' => $downloadMatchesTxtAjax,
        'download_matches_csv' => $downloadMatchesCsvAjax,
        'download_matches_name' => $matchesBase !== '' ? ($matchesBase . '.txt') : '',
        'qs' => $qs,
    ];
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

render_header('Our database · ' . $sheetLabel, 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Our database', 'href' => $base],
    ['label' => $sheetLabel],
]);
?>
<div class="topbar">
  <div>
    <h1><?= h($sheetLabel) ?></h1>
    <p class="muted">
      <span id="prospect_country_total_label"><?= (int) $countryTotal ?></span>
      site<?= (int) $countryTotal === 1 ? '' : 's' ?> in this country’s database
      <span id="prospect_match_line"<?= $q !== '' ? '' : ' hidden' ?>>
        · <strong id="prospect_match_count_label"><?= (int) $searchMatchCount ?></strong>
        match<span id="prospect_match_plural"><?= (int) $searchMatchCount === 1 ? '' : 'es' ?></span>
        for “<span id="prospect_match_q_label"><?= h($q) ?></span>”
      </span>
      · read-only
    </p>
  </div>
  <div class="actions">
    <?php if (!$emptyCountry): ?>
      <button
        type="button"
        class="btn"
        id="prospect_copy_all"
        data-export-url="<?= h($exportAllUrl) ?>"
        data-download-name="<?= h($exportAllTxtName) ?>"
        data-fallback-download-url="<?= h($downloadTxtUrl) ?>"
        data-count="<?= (int) $countryTotal ?>"
        <?= $countryTotal > 0 ? '' : 'disabled' ?>
      >Copy all</button>
      <a class="btn secondary" href="<?= h($downloadTxtUrl) ?>">Download .txt</a>
      <a class="btn secondary" href="<?= h($downloadCsvUrl) ?>">Download CSV</a>
    <?php endif; ?>
    <a class="btn secondary" href="<?= h($base) ?>">All countries</a>
  </div>
</div>
<p class="help" id="prospect_copy_status" hidden></p>

<form class="card filters" method="get" id="prospect-country-filters">
  <input type="hidden" name="page" value="<?= h($pageKey) ?>">
  <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
  <input type="hidden" name="q" id="prospect_q_hidden" value="<?= h($q) ?>">
  <div>
    <label>Status</label>
    <select name="status" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach (prospect_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="prospects_per_page">Per page</label>
    <select id="prospects_per_page" name="per_page" onchange="this.form.submit()">
      <?php foreach (sheet_per_page_options() as $n): ?>
        <option value="<?= (int) $n ?>" <?= (int) $perPage === (int) $n ? 'selected' : '' ?>><?= (int) $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="card" id="prospect-sites-card">
  <div class="invoice-list-toolbar prospect-site-toolbar" style="margin-bottom:0.75rem;flex-wrap:wrap;gap:0.65rem">
    <h2 style="margin:0">Sites</h2>
    <?php if (!$emptyCountry && ($countryTotal > 0 || $q !== '')): ?>
    <div class="actions prospect-site-search-row" style="margin-left:auto;align-items:center;flex-wrap:wrap;gap:0.45rem">
      <label class="sheet-search" for="prospect-site-search" style="margin:0">
        <span class="visually-hidden">Search this country</span>
        <input id="prospect-site-search" type="search" placeholder="Search this country…"
               value="<?= h($q) ?>"
               autocomplete="off" spellcheck="false" data-no-draft
               title="Type to search the whole country folder · Enter = next match · Shift+Enter = previous">
        <span class="sheet-search-meta muted" data-prospect-site-search-meta hidden></span>
      </label>
      <div class="actions prospect-match-actions" id="prospect-match-actions"
          <?= ($q !== '' && $searchMatchCount > 0) ? '' : ' hidden' ?>>
        <button
          type="button"
          class="btn secondary small"
          id="prospect_copy_matches"
          data-export-url="<?= h($exportMatchesUrl) ?>"
          data-download-name="<?= h($exportMatchesTxtName !== '' ? $exportMatchesTxtName : 'matches-our-database.txt') ?>"
          data-fallback-download-url="<?= h($downloadMatchesTxtUrl) ?>"
          data-count="<?= (int) $searchMatchCount ?>"
        >Copy matches</button>
        <a class="btn secondary small" id="prospect_matches_txt" href="<?= h($downloadMatchesTxtUrl !== '' ? $downloadMatchesTxtUrl : '#') ?>">Matches .txt</a>
        <a class="btn secondary small" id="prospect_matches_csv" href="<?= h($downloadMatchesCsvUrl !== '' ? $downloadMatchesCsvUrl : '#') ?>">Matches CSV</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <table id="prospect-site-table"<?= $rows ? '' : ' hidden' ?>>
    <thead><tr><th>Domain</th><th>URL</th><th>Language</th><th>Status</th><th>Added by</th><th>When</th></tr></thead>
    <tbody id="prospect-site-tbody">
    <?= prospect_site_rows_html($rows) ?>
    </tbody>
  </table>
  <div id="prospect-site-empty" class="empty-state"<?= $rows ? ' hidden' : '' ?>>
    <p data-prospect-empty-text><?= $q !== '' ? 'No sites in this country match your search.' : 'No sites in this country yet.' ?></p>
  </div>
  <div id="prospect-site-pager"<?= !$rows ? ' hidden' : '' ?>>
    <div class="actions" style="margin-top:0.8rem;align-items:center;gap:0.65rem;flex-wrap:wrap">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span data-prospect-page-label>Page <?= $pageNum ?> / <?= $pages ?> · <?= (int) $perPage ?> per page</span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
      <?php
      render_sheet_per_page_filter([
          'page' => $pageKey,
          'country' => $emptyCountry ? '_none' : $countryName,
          'q' => $q,
          'status' => $status,
      ], $perPage);
      ?>
    </div>
  </div>
</div>
<script src="<?= h(script_asset_url('js/prospects-country.js')) ?>" defer></script>
<?php
render_footer('team');
