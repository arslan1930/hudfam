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
        $result = delete_extracted_sites_by_ids($countryName, [$siteId]);
        $ok = !empty($result['ok']);
        $domain = (string) (($result['removed'][0]['domain'] ?? '') ?: '');
        $left = count_extracted_sites_for_country($countryName);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$ok) {
                http_response_code(404);
            }
            echo json_encode((!$ok ? ['ok' => false, 'error' => 'Site not found in this country.'] : [
                'ok' => true,
                'domain' => $domain,
                'site_count' => $left,
                'redirect' => $left < 1 ? $sitesListUrl : null,
            ]) + (function_exists('sheet_history_state')
                ? sheet_history_state(sheet_history_key('extracted', $countryName))
                : []));
            exit;
        }
        if (!$ok) {
            flash('error', 'Site not found in this country.');
            redirect($countryReturnUrl());
        }
        flash('ok', 'Removed ' . $domain . ' from ' . $countryName . '.');
        if ($left < 1) {
            redirect($sitesListUrl);
        }
        redirect($countryReturnUrl());
    }

    if ($action === 'remove_selected') {
        $ids = function_exists('parse_posted_id_list') ? parse_posted_id_list(post('site_ids')) : [];
        $result = delete_extracted_sites_by_ids($countryName, $ids);
        $left = count_extracted_sites_for_country($countryName);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (empty($result['ok'])) {
                http_response_code(400);
            }
            echo json_encode($result + [
                'site_count' => $left,
            ] + (function_exists('sheet_history_state')
                ? sheet_history_state(sheet_history_key('extracted', $countryName))
                : []));
            exit;
        }
        flash($result['ok'] ? 'ok' : 'error', $result['ok']
            ? 'Removed ' . (int) $result['count'] . ' selected URL' . ((int) $result['count'] === 1 ? '' : 's') . '.'
            : (string) ($result['error'] ?? 'Could not remove selected URLs.'));
        redirect($countryReturnUrl());
    }

    $histKeyEx = function_exists('sheet_history_key')
        ? sheet_history_key('extracted', $countryName)
        : ('extracted:' . $countryName);
    if ($action === 'undo_last' || $action === 'redo_last') {
        $result = $action === 'redo_last'
            ? sheet_history_apply_redo($histKeyEx)
            : sheet_history_apply_undo($histKeyEx);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (empty($result['ok'])) {
                http_response_code(400);
            }
            echo json_encode($result);
            exit;
        }
        flash($result['ok'] ? 'ok' : 'error', $result['ok']
            ? ($action === 'redo_last' ? 'Redid last remove.' : 'Undid last remove.')
            : (string) ($result['error'] ?? 'Could not undo/redo.'));
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
          · Push also copies site names into Semrush Research (a separate list).
          Admin does not add URLs here — only Team Push does.
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_semrush_research">Semrush Research</a>
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
      <div class="table-wrap">
      <table class="extracted-country-table" id="extracted-country-table">
        <thead>
          <tr>
            <th>Country</th>
            <th class="num">URLs</th>
            <th>Last pushed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($countryRows as $r):
            $cName = (string) $r['country'];
            $cTotal = (int) $r['total'];
            $lastPushed = (string) ($r['last_pushed_at'] ?? '');
            $lastPushedLabel = $lastPushed !== '' ? substr($lastPushed, 0, 16) : '—';
            $searchHay = mb_strtolower(trim(
                $cName . ' '
                . $cTotal . ' urls '
                . $lastPushedLabel
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
            <td class="muted"><?= h($lastPushedLabel) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="sheet-search-empty" data-extracted-country-search-empty hidden>
            <td colspan="3" class="muted">No countries match your search.</td>
          </tr>
        </tbody>
      </table>
      </div>
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

$wantsAjax = (string) get('ajax') === '1';
if ($wantsAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $payload = [
        'ok' => true,
        'q' => $q,
        'country_total' => $countryTotal,
        'match_count' => $q !== '' ? (int) $searchMatchCount : 0,
        'page' => $pageNum,
        'pages' => $pages,
        'per_page' => $perPage,
        'rows_html' => extracted_url_items_html($rows, $listBase, $q, $pageNum),
        'has_rows' => $rows !== [],
        'list_start' => (int) (($pageNum - 1) * $perPage + 1),
        'qs' => $qs,
        'remove_confirm' => 'This removes ' . countable_label((int) $searchMatchCount, 'site', 'sites')
            . ' matching “' . $q . '” from ' . $countryName . ".\n\nThis cannot be undone.",
    ];
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

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
      <span id="extracted_match_line"<?= $q !== '' ? '' : ' hidden' ?>>
        · <strong id="extracted_match_count_label"><?= (int) $searchMatchCount ?></strong>
        match<span id="extracted_match_plural"><?= (int) $searchMatchCount === 1 ? '' : 'es' ?></span>
        for “<span id="extracted_match_q_label"><?= h($q) ?></span>”
      </span>
    </p>
  </div>
  <div class="actions">
    <button
      type="button"
      class="btn secondary"
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
    <?php if ($countryTotal > 0 || $q !== ''): ?>
    <div class="actions" style="margin-left:auto;align-items:center;flex-wrap:wrap;gap:0.45rem">
      <label class="sheet-search extracted-url-search" for="extracted-url-search" style="margin:0">
        <span class="visually-hidden">Search this country</span>
        <input id="extracted-url-search" type="search" placeholder="Search this country…"
               value="<?= h($q) ?>"
               autocomplete="off" spellcheck="false" data-no-draft
               title="Type to search the whole country folder · Enter = next match · Shift+Enter = previous">
        <span class="sheet-search-meta muted" data-extracted-url-search-meta hidden></span>
      </label>
      <?php
      render_sheet_edit_toolbar($listBase, sheet_history_key('extracted', $countryName), [
          'q' => $q,
          'p' => $pageNum,
          'country' => $countryName,
      ]);
      ?>
    </div>
    <?php endif; ?>
  </div>

  <form
    id="extracted-remove-matching"
    method="post"
    action="<?= h($listBase) ?>"
    style="margin-bottom:0.85rem"
    <?= ($q !== '' && $searchMatchCount > 0) ? '' : ' hidden' ?>
    <?= confirm_attrs(
        'This removes ' . countable_label((int) $searchMatchCount, 'site', 'sites')
        . ' matching “' . $q . '” from ' . $countryName . ".\n\nThis cannot be undone.",
        ['title' => 'Remove matching sites?', 'confirm_label' => 'Remove', 'danger' => true]
    ) ?>
  >
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_search">
    <input type="hidden" name="q" value="<?= h($q) ?>" id="extracted_remove_q">
    <p class="help" style="margin:0 0 0.55rem">
      Server search “<span id="extracted_remove_q_label"><?= h($q) ?></span>” matches
      <strong id="extracted_remove_count_label"><?= (int) $searchMatchCount ?></strong>
      URL<span id="extracted_remove_plural"><?= (int) $searchMatchCount === 1 ? '' : 's' ?></span> in this country.
    </p>
    <button class="btn danger small" type="submit" id="extracted_remove_matching_btn">Remove <?= (int) $searchMatchCount ?> matching</button>
  </form>

  <ol class="extracted-plain-list" id="extracted-plain-list"
      start="<?= (int) (($pageNum - 1) * $perPage + 1) ?>"
      <?= $rows ? '' : ' hidden' ?>>
    <?= extracted_url_items_html($rows, $listBase, $q, $pageNum) ?>
  </ol>
  <p class="help sheet-search-empty" data-extracted-url-search-empty hidden>No search matches on this page.</p>
  <div id="extracted-url-empty" class="empty-state"<?= $rows ? ' hidden' : '' ?>>
    <p data-extracted-empty-text><?= $q !== '' ? 'No search matches in this country.' : 'No URLs in this country yet.' ?></p>
    <a class="btn secondary" href="<?= h($sitesListUrl) ?>">Back to countries</a>
  </div>
  <div id="extracted-url-pager" class="actions" style="margin-top:0.85rem;justify-content:space-between;flex-wrap:wrap;gap:0.5rem"<?= $rows ? '' : ' hidden' ?>>
    <div class="actions" style="margin:0;gap:0.65rem;flex-wrap:wrap;align-items:center">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span class="muted" data-sheet-page-status
            data-extracted-page-label
            data-page="<?= (int) $pageNum ?>"
            data-pages="<?= (int) $pages ?>"
            data-on-page="<?= (int) count($rows) ?>"
            data-total="<?= (int) $total ?>">Page <?= $pageNum ?> / <?= $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?></span>
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
          <?= confirm_attrs(
              'This removes ' . countable_label((int) $countryTotal, 'URL', 'URLs')
              . ' from ' . $countryName . ".\n\nThis cannot be undone.",
              ['title' => 'Remove all URLs?', 'confirm_label' => 'Remove', 'danger' => true]
          ) ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove_all">
      <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
      <button class="btn danger" type="submit">Remove all</button>
    </form>
  </div>
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
    <?= confirm_attrs(
        'Remove all matching sites from this list in ' . $countryName . '?'
        . "\n\nThis cannot be undone.",
        ['title' => 'Remove listed sites?', 'confirm_label' => 'Remove', 'danger' => true]
    ) ?>
  >
    <?= csrf_field() ?>
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

<script src="<?= h(script_asset_url('js/sheet-select-undo.js')) ?>" defer></script>
<script src="<?= h(script_asset_url('js/extracted-admin.js')) ?>" defer></script>
<?= open_site_script_tag() ?>
<?php render_footer('admin'); ?>
