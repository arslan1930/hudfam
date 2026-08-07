<?php
/**
 * Shared Sites with emails UI (admin Extracted URLs folder + team sidebar).
 *
 * Expects:
 *   $sweUser (array), $swePanel ('admin'|'team'),
 *   $sweBase ('index.php?page=admin_extracted&folder=sites_with_emails' or team page)
 */
ensure_sites_with_emails_schema();

$swePanel = $swePanel ?? 'admin';
$sweUser = $sweUser ?? require_admin();
$sweBase = $sweBase ?? 'index.php?page=admin_extracted&folder=sites_with_emails';
$isAdmin = ($swePanel === 'admin');

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
if ($sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet === null) {
        flash('error', 'That country is not in the country list.');
        redirect($sweBase);
    }
    if ($canonSheet['name'] !== $sheet) {
        redirect($sweBase . '&country=' . urlencode($canonSheet['name']));
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

// Exports
if ($inCountry && (string) get('export') !== '') {
    $mode = (string) get('export');
    if ($mode === 'csv') {
        stream_sites_with_emails_csv($sheet);
    }
    if ($mode === 'emails') {
        stream_sites_with_emails_emails_plain($sheet);
    }
}

if ($inCountry && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $countryName = $sheet;
    $returnQ = trim((string) post('q'));
    $returnP = max(1, (int) (post('p') ?: 1));
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $back = $sweBase . '&country=' . rawurlencode($countryName);
    if ($returnQ !== '') {
        $back .= '&q=' . rawurlencode($returnQ);
    }
    if ($returnP > 1) {
        $back .= '&p=' . $returnP;
    }

    if ($action === 'save_row') {
        $id = (int) post('site_id');
        $domain = (string) post('domain');
        $emails = [
            (string) post('email1'),
            (string) post('email2'),
            (string) post('email3'),
            (string) post('email4'),
        ];
        $result = save_site_with_emails_row(
            $countryName,
            $domain,
            $emails,
            $sweUser,
            $id > 0 ? $id : null
        );
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result + ['site_count' => count_sites_with_emails_for_country($countryName)]);
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not save.'));
        } else {
            flash('ok', $id > 0 ? 'Updated row.' : 'Added site row.');
        }
        redirect($back);
    }

    if ($action === 'remove_site') {
        $siteId = (int) post('site_id');
        $site = get_site_with_emails($siteId);
        if (!$site || (string) $site['country'] !== $countryName) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Row not found.']);
                exit;
            }
            flash('error', 'Row not found.');
            redirect($back);
        }
        $domain = (string) $site['domain'];
        delete_site_with_emails($siteId);
        $left = count_sites_with_emails_for_country($countryName);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'domain' => $domain,
                'site_count' => $left,
                'redirect' => $left < 1 ? $sweBase : null,
            ]);
            exit;
        }
        flash('ok', 'Removed ' . $domain . '.');
        if ($left < 1) {
            redirect($sweBase);
        }
        redirect($back);
    }

    if ($action === 'remove_list') {
        $raw = (string) post('remove_text');
        try {
            $fromFile = read_extracted_sites_upload($_FILES['remove_csv'] ?? null);
            if ($fromFile !== '') {
                $raw = trim($raw) !== '' ? ($raw . "\n" . $fromFile) : $fromFile;
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect($back . '#remove-by-list');
        }
        $result = remove_sites_with_emails_by_list($countryName, $raw);
        if ($result['removed'] < 1) {
            flash('error', 'No matching sites removed from the list.');
            redirect($back . '#remove-by-list');
        }
        flash('ok', 'Removed ' . (int) $result['removed'] . ' site(s).');
        if (count_sites_with_emails_for_country($countryName) < 1) {
            redirect($sweBase);
        }
        redirect($back);
    }

    if ($action === 'remove_all' && $isAdmin) {
        $n = delete_sites_with_emails_for_country($countryName);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from ' . $countryName . '.');
        redirect($sweBase);
    }
}

// --- Country list ---
if (!$inCountry) {
    $countryRows = list_sites_with_emails_country_rows();
    $grandTotal = 0;
    $emailSites = 0;
    foreach ($countryRows as $r) {
        $grandTotal += (int) $r['total'];
        $emailSites += (int) $r['with_emails'];
    }

    render_header('Sites with emails', $swePanel);
    $crumbs = $isAdmin
        ? [
            ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
            ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
            ['label' => 'Sites with emails'],
        ]
        : [
            ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
            ['label' => 'Sites with emails'],
        ];
    render_breadcrumbs($crumbs);
    ?>
    <div class="topbar">
      <div>
        <h1>Sites with emails</h1>
        <p class="muted">
          Site names arrive from Team Push (same as Extracted Sites).
          Add up to 4 emails per site ·
          <?= count($countryRows) ?> countr<?= count($countryRows) === 1 ? 'y' : 'ies' ?> ·
          <?= (int) $grandTotal ?> site<?= (int) $grandTotal === 1 ? '' : 's' ?> ·
          <?= (int) $emailSites ?> with email<?= (int) $emailSites === 1 ? '' : 's' ?>
        </p>
      </div>
      <div class="actions">
        <?php if ($isAdmin): ?>
          <a class="btn secondary" href="index.php?page=admin_extracted">All folders</a>
        <?php else: ?>
          <a class="btn secondary" href="index.php?page=team_extracting">Extracting sites</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <?php if ($countryRows): ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <h2 style="margin:0">By country</h2>
        <label class="sheet-search" for="swe-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="swe-country-search" type="search" placeholder="Search…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type to filter · Enter = next match">
          <span class="sheet-search-meta muted" data-swe-country-search-meta hidden></span>
        </label>
      </div>
      <table class="extracted-country-table" id="swe-country-table">
        <thead>
          <tr>
            <th>Country</th>
            <th class="num">Sites</th>
            <th class="num">With emails</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($countryRows as $r):
            $cName = (string) $r['country'];
            $hay = mb_strtolower($cName . ' ' . (string) $r['language'] . ' ' . (string) $r['region']);
            ?>
          <tr data-swe-country-row data-search="<?= h($hay) ?>">
            <td>
              <a class="extracted-country-link" href="<?= h($sweBase) ?>&amp;country=<?= urlencode($cName) ?>">
                <?= h($cName) ?>
              </a>
            </td>
            <td class="num">
              <a class="extracted-country-count" href="<?= h($sweBase) ?>&amp;country=<?= urlencode($cName) ?>">
                <?= (int) $r['total'] ?>
              </a>
            </td>
            <td class="num muted"><?= (int) $r['with_emails'] ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="sheet-search-empty" data-swe-country-search-empty hidden>
            <td colspan="3" class="muted">No countries match your search.</td>
          </tr>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <p>No sites yet.</p>
        <p class="muted">They appear here when Team clicks Push in Extracting Results (same sites as Extracted Sites).</p>
      </div>
      <?php endif; ?>
    </div>
    <script>
    (function () {
      var input = document.getElementById('swe-country-search');
      if (!input) return;
      var matchRows = [], matchIndex = -1;
      var meta = document.querySelector('[data-swe-country-search-meta]');
      var empty = document.querySelector('[data-swe-country-search-empty]');
      function clearHits() {
        document.querySelectorAll('#swe-country-table .sheet-search-hit').forEach(function (el) {
          el.classList.remove('sheet-search-hit');
        });
      }
      function filter() {
        var q = String(input.value || '').trim().toLowerCase();
        matchRows = []; clearHits();
        var shown = 0;
        document.querySelectorAll('[data-swe-country-row]').forEach(function (row) {
          var hit = !q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1;
          row.hidden = !hit;
          if (hit) { shown++; if (q) matchRows.push(row); }
        });
        if (empty) empty.hidden = !(q && shown === 0);
        if (meta) {
          if (!q) { meta.hidden = true; meta.textContent = ''; matchIndex = -1; return; }
          meta.hidden = false;
          meta.textContent = !matchRows.length ? '0 · Enter = next'
            : (matchIndex >= 0 ? (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next'
              : matchRows.length + ' matches · Enter = next');
        }
      }
      function jump(dir) {
        if (!String(input.value || '').trim()) return;
        filter();
        if (!matchRows.length) return;
        matchIndex = matchIndex < 0 ? (dir > 0 ? 0 : matchRows.length - 1)
          : (matchIndex + dir + matchRows.length) % matchRows.length;
        var row = matchRows[matchIndex];
        clearHits();
        row.classList.add('sheet-search-hit');
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        if (meta) meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
      }
      input.addEventListener('input', function () { matchIndex = -1; filter(); });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); jump(e.shiftKey ? -1 : 1); }
      });
    })();
    </script>
    <?php
    render_footer($swePanel);
    return;
}

// --- Country detail ---
$countryName = $sheet;
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));
$perPage = 100;
$inv = sites_with_emails_inventory_query([
    'country' => $countryName,
    'q' => $q,
], $pageNum, $perPage);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryTotal = count_sites_with_emails_for_country($countryName);
$listBase = $sweBase . '&country=' . rawurlencode($countryName);
$csvUrl = $listBase . '&export=csv';
$emailsExportUrl = $listBase . '&export=emails';
$qs = http_build_query(array_filter([
    'page' => $isAdmin ? 'admin_extracted' : 'team_sites_emails',
    'folder' => $isAdmin ? 'sites_with_emails' : null,
    'country' => $countryName,
    'q' => $q,
], static fn ($v) => $v !== '' && $v !== null));

render_header('Sites with emails · ' . $countryName, $swePanel);
$crumbs = $isAdmin
    ? [
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
        ['label' => 'Sites with emails', 'href' => $sweBase],
        ['label' => $countryName],
    ]
    : [
        ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
        ['label' => 'Sites with emails', 'href' => $sweBase],
        ['label' => $countryName],
    ];
render_breadcrumbs($crumbs);
?>
<div class="topbar">
  <div>
    <h1><?= h($countryName) ?></h1>
    <p class="muted">
      <span id="swe_total_label"><?= (int) $countryTotal ?></span> site<?= (int) $countryTotal === 1 ? '' : 's' ?>
      <?= $q !== '' ? ' · ' . (int) $total . ' match' . ((int) $total === 1 ? '' : 'es') : '' ?>
      · up to 4 emails each
    </p>
  </div>
  <div class="actions">
    <button type="button" class="btn" id="swe_copy_emails"
            data-export-url="<?= h($emailsExportUrl) ?>"
            <?= $countryTotal > 0 ? '' : 'disabled' ?>>Copy all emails</button>
    <a class="btn secondary" href="<?= h($csvUrl) ?>">Download CSV / Excel</a>
    <a class="btn secondary" href="<?= h($sweBase) ?>">All countries</a>
  </div>
</div>
<p class="help" id="swe_status" hidden></p>

<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
    <h2 style="margin:0">Sites · Emails</h2>
    <?php if ($countryTotal > 0 || $q !== ''): ?>
    <label class="sheet-search" for="swe-row-search">
      <span class="visually-hidden">Search</span>
      <input id="swe-row-search" type="search" placeholder="Search sites or emails…"
             value="<?= h($q) ?>" autocomplete="off" spellcheck="false" data-no-draft
             title="Filter this page · Enter = next · Ctrl+Enter = search all pages">
      <span class="sheet-search-meta muted" data-swe-row-search-meta hidden></span>
    </label>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <table class="swe-table" id="swe-table">
      <thead>
        <tr>
          <th class="swe-col-site">Site name</th>
          <th class="swe-col-emails">Emails (up to 4)</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="swe-tbody">
      <?php foreach ($rows as $s):
          $domain = (string) $s['domain'];
          $hay = mb_strtolower(
              $domain . ' '
              . (string) $s['email1'] . ' '
              . (string) $s['email2'] . ' '
              . (string) $s['email3'] . ' '
              . (string) $s['email4']
          );
          ?>
        <tr data-swe-row data-search="<?= h($hay) ?>" data-site-id="<?= (int) $s['id'] ?>">
          <td colspan="3">
            <form method="post" action="<?= h($listBase) ?>" class="swe-row-form" data-swe-save>
              <input type="hidden" name="action" value="save_row">
              <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <div class="swe-row-grid">
                <div class="swe-site-block">
                  <label class="visually-hidden">Site name</label>
                  <input class="swe-domain" name="domain" value="<?= h($domain) ?>" required
                         spellcheck="false" autocomplete="off" aria-label="Site name">
                </div>
                <div class="swe-emails" aria-label="Emails">
                  <input type="email" name="email1" value="<?= h((string) $s['email1']) ?>" placeholder="email 1" spellcheck="false">
                  <input type="email" name="email2" value="<?= h((string) $s['email2']) ?>" placeholder="email 2" spellcheck="false">
                  <input type="email" name="email3" value="<?= h((string) $s['email3']) ?>" placeholder="email 3" spellcheck="false">
                  <input type="email" name="email4" value="<?= h((string) $s['email4']) ?>" placeholder="email 4" spellcheck="false">
                </div>
                <div class="swe-row-actions">
                  <button class="btn small" type="submit">Save</button>
                  <button class="btn secondary small" type="submit" form="swe-remove-<?= (int) $s['id'] ?>"
                          onclick="return confirm('Remove <?= h($domain) ?>?');">Remove</button>
                </div>
              </div>
            </form>
            <form id="swe-remove-<?= (int) $s['id'] ?>" method="post" action="<?= h($listBase) ?>" data-swe-remove hidden>
              <input type="hidden" name="action" value="remove_site">
              <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="help sheet-search-empty" data-swe-row-search-empty hidden>No rows on this page match your search.</p>

  <?php if (!$rows && $q === ''): ?>
  <div class="empty-state">
    <p>No sites in this country yet.</p>
    <p class="muted">Push from Extracting Results to fill site names here.</p>
  </div>
  <?php endif; ?>

  <div class="actions" style="margin-top:0.85rem;justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
    <div>
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <?php if ($rows || $q !== ''): ?>
        <span class="muted">Page <?= $pageNum ?> / <?= $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?></span>
      <?php endif; ?>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
    </div>
    <?php if ($isAdmin && $countryTotal > 0): ?>
    <form method="post" action="<?= h($listBase) ?>"
          onsubmit="return confirm('Remove ALL <?= (int) $countryTotal ?> sites from <?= h($countryName) ?>?');">
      <input type="hidden" name="action" value="remove_all">
      <button class="btn secondary small danger" type="submit">Remove all</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Add site row</h2>
  <p class="help">Add a site manually, then fill up to 4 emails. Pushed sites already appear above.</p>
  <form method="post" action="<?= h($listBase) ?>" class="swe-add-form">
    <input type="hidden" name="action" value="save_row">
    <input type="hidden" name="site_id" value="0">
    <div class="form-grid" style="grid-template-columns:1.2fr 1fr 1fr;gap:0.65rem">
      <div>
        <label for="swe_add_domain">Site name</label>
        <input id="swe_add_domain" name="domain" required placeholder="example.com" spellcheck="false">
      </div>
      <div>
        <label for="swe_add_e1">Email 1</label>
        <input id="swe_add_e1" type="email" name="email1" placeholder="name@example.com" spellcheck="false">
      </div>
      <div>
        <label for="swe_add_e2">Email 2</label>
        <input id="swe_add_e2" type="email" name="email2" spellcheck="false">
      </div>
      <div>
        <label for="swe_add_e3">Email 3</label>
        <input id="swe_add_e3" type="email" name="email3" spellcheck="false">
      </div>
      <div>
        <label for="swe_add_e4">Email 4</label>
        <input id="swe_add_e4" type="email" name="email4" spellcheck="false">
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit">Add row</button>
    </p>
  </form>
</div>

<?php if ($countryTotal > 0): ?>
<div class="card" id="remove-by-list" style="margin-top:1rem">
  <h2>Remove by list</h2>
  <p class="help">Paste site names (or 1-column CSV) to remove those rows from <?= h($countryName) ?>.</p>
  <form method="post" action="<?= h($listBase) ?>#remove-by-list" enctype="multipart/form-data"
        onsubmit="return confirm('Remove matching sites from <?= h($countryName) ?>?');">
    <input type="hidden" name="action" value="remove_list">
    <textarea name="remove_text" class="inventory-box" rows="6" placeholder="site-to-remove.com"></textarea>
    <label style="display:block;margin-top:0.55rem">CSV (1 column)</label>
    <input type="file" name="remove_csv" accept=".csv,text/csv,text/plain,.txt">
    <div class="actions" style="margin-top:0.75rem">
      <button class="btn danger" type="submit">Remove listed sites</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script src="<?= h(script_asset_url('js/sites-with-emails.js')) ?>" defer></script>
<?php
render_footer($swePanel);
return;
