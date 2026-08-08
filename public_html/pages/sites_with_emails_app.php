<?php
/**
 * Shared Sites with emails UI.
 *
 * Expects:
 *   $sweUser (array), $swePanel ('admin'|'team'),
 *   $sweBase (page URL without country)
 * Optional:
 *   $sweScope ('team'|'admin'|'admin_all') — overrides panel default
 *
 * Team       → working copy from Extracting Results; Push final rows to Admin
 * Admin      → final archive (no push out)
 * Admin all  → Admin-only mirror synced from Sites with emails - Admin
 */
ensure_sites_with_emails_schema();

$swePanel = $swePanel ?? 'admin';
$sweUser = $sweUser ?? require_admin();
if (!isset($sweScope) || $sweScope === '') {
    $sweScope = ($swePanel === 'admin') ? 'admin' : 'team';
}
$sweScope = swe_normalize_scope((string) $sweScope);
$sweLabel = swe_label($sweScope);
$sweFolder = match ($sweScope) {
    'admin_all' => 'all_sites_with_emails',
    'admin' => 'sites_with_emails',
    default => null,
};
$sweBase = $sweBase ?? (
    $sweScope === 'team'
        ? 'index.php?page=team_sites_emails'
        : 'index.php?page=admin_emails_data&folder=' . $sweFolder
);
$sweAdminHub = $sweAdminHub ?? 'index.php?page=admin_emails_data';
$sweAdminHubLabel = $sweAdminHubLabel ?? 'Emails data';
$isAdmin = ($swePanel === 'admin' || $sweScope === 'admin' || $sweScope === 'admin_all');
$isAdminAll = ($sweScope === 'admin_all');
$isTeam = ($sweScope === 'team');

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
        stream_sites_with_emails_csv($sheet, $sweScope);
    }
    if ($mode === 'emails') {
        stream_sites_with_emails_emails_plain($sheet, $sweScope);
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
            $id > 0 ? $id : null,
            $sweScope
        );
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result + ['site_count' => count_sites_with_emails_for_country($countryName, $sweScope)]);
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
        $site = get_site_with_emails($siteId, $sweScope);
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
        delete_site_with_emails($siteId, $sweScope);
        $left = count_sites_with_emails_for_country($countryName, $sweScope);
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
        $result = remove_sites_with_emails_by_list($countryName, $raw, $sweScope);
        if ($result['removed'] < 1) {
            flash('error', 'No matching sites removed from the list.');
            redirect($back . '#remove-by-list');
        }
        flash('ok', 'Removed ' . (int) $result['removed'] . ' site(s).');
        if (count_sites_with_emails_for_country($countryName, $sweScope) < 1) {
            redirect($sweBase);
        }
        redirect($back);
    }

    if ($action === 'remove_all') {
        $n = delete_sites_with_emails_for_country($countryName, $sweScope);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from ' . $countryName . '.');
        redirect($sweBase);
    }

    if ($action === 'push_to_admin' && $isTeam) {
        $ready = count_sites_with_emails_ready_to_push($countryName);
        if ($ready < 1) {
            // Only when every site still has all 4 email boxes empty (nothing ready).
            flash('error', 'All email boxes are empty. Fill at least one email on a site, then Push to Admin.');
            redirect($back);
        }
        $pushed = push_sites_with_emails_team_to_admin($countryName, $sweUser);
        $msg = 'Pushed ' . ((int) $pushed['pushed'] + (int) $pushed['updated'])
            . ' site(s) with emails to Sites with emails - Admin · ' . $pushed['country'];
        if ((int) $pushed['pushed'] > 0 || (int) $pushed['updated'] > 0) {
            $msg .= ' (' . (int) $pushed['pushed'] . ' new';
            if ((int) $pushed['updated'] > 0) {
                $msg .= ', ' . (int) $pushed['updated'] . ' updated';
            }
            $msg .= ')';
        }
        if ((int) ($pushed['cleared'] ?? 0) > 0) {
            $msg .= ' · cleared from Team working copy';
        }
        if ((int) $pushed['skipped_empty'] > 0) {
            $msg .= ' · ' . (int) $pushed['skipped_empty'] . ' without emails left here';
        }
        flash('ok', $msg . '.');
        // After push, stay on country if unfinished rows remain; else country list.
        $remaining = count_sites_with_emails_for_country($countryName, 'team');
        redirect($remaining > 0 ? $back : $sweBase);
    }
}

// --- Country list ---
if (!$inCountry) {
    $countryRows = list_sites_with_emails_country_rows($sweScope);
    $grandTotal = 0;
    $emailSites = 0;
    foreach ($countryRows as $r) {
        $grandTotal += (int) $r['total'];
        $emailSites += (int) $r['with_emails'];
    }

    render_header($sweLabel, $swePanel);
    $crumbs = $isAdmin
        ? [
            ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
            ['label' => $sweAdminHubLabel, 'href' => $sweAdminHub],
            ['label' => $sweLabel],
        ]
        : [
            ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
            ['label' => $sweLabel],
        ];
    render_breadcrumbs($crumbs);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info(
            $sweLabel,
            $isTeam
                ? 'Working copy: site names arrive from Extracting Results Push. Add emails, then Push to Admin — pushed rows leave this list. Sites without emails stay here.'
                : ($isAdminAll
                    ? 'Admin-only mirror of Sites with emails - Admin. Synced automatically. Not linked to Team.'
                    : 'Final archive from Team Push. Also synced to All sites with emails - Final. Communication Team can super-search this data.')
        ) ?></h1>
        <p class="muted">
          <?php if ($isTeam): ?>
            Site names arrive from Extracting Results → Push.
            Add emails, then Push again to Sites with emails - Admin ·
          <?php elseif ($isAdminAll): ?>
            Admin-only duplicate of Sites with emails - Admin (synced automatically; not linked to Team) ·
          <?php else: ?>
            Final site + email list from Team Push. Also synced to All sites with emails - Final ·
          <?php endif; ?>
          <?= count($countryRows) ?> countr<?= count($countryRows) === 1 ? 'y' : 'ies' ?> ·
          <?= (int) $grandTotal ?> site<?= (int) $grandTotal === 1 ? '' : 's' ?> ·
          <?= (int) $emailSites ?> with email<?= (int) $emailSites === 1 ? '' : 's' ?>
        </p>
      </div>
      <div class="actions">
        <?php if ($isAdmin): ?>
          <a class="btn secondary" href="<?= h($sweAdminHub) ?>">All folders</a>
        <?php else: ?>
          <a class="btn" href="index.php?page=team_admin_emails_delete">Admin emails search</a>
          <a class="btn secondary" href="index.php?page=team_extracting">Extracting sites</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isTeam): ?>
    <div class="card" style="margin-bottom:1rem">
      <h2><?= label_with_info('Admin emails search', 'Live search across Sites with emails - Admin. Delete the whole site row, or remove one email only and keep the site name.') ?></h2>
      <p class="help">
        Type a site or email — live suggestions come from
        <strong>Sites with emails - Admin</strong>.
        Select a match, then delete the whole row or remove one email only (site name stays).
      </p>
      <p class="actions" style="margin-top:0.65rem">
        <a class="btn" href="index.php?page=team_admin_emails_delete">Open Admin super search</a>
      </p>
    </div>
    <?php endif; ?>

    <div class="card">
      <?php if ($countryRows): ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <h2 style="margin:0"><?= label_with_info('By country', 'Open a country to see its sites and emails. Counts show how many sites have at least one email.') ?></h2>
        <label class="sheet-search" for="swe-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="swe-country-search" type="search" placeholder="Search country name…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type a country name · Enter = next match">
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
            $hay = mb_strtolower($cName . ' ' . (int) $r['total'] . ' sites');
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
        <?php if ($isTeam): ?>
          <p>No sites yet.</p>
          <p class="muted">They appear here when you click Push in Extracting Results.</p>
        <?php elseif ($isAdminAll): ?>
          <p>No mirrored sites yet.</p>
          <p class="muted">They sync here whenever Sites with emails - Admin receives data.</p>
        <?php else: ?>
          <p>No final sites yet.</p>
          <p class="muted">They appear when Team pushes from Sites with emails - Team (after adding emails).</p>
        <?php endif; ?>
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
], $pageNum, $perPage, $sweScope);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryTotal = count_sites_with_emails_for_country($countryName, $sweScope);
$readyToPush = $isTeam ? count_sites_with_emails_ready_to_push($countryName) : 0;
$listBase = $sweBase . '&country=' . rawurlencode($countryName);
$csvUrl = $listBase . '&export=csv';
$emailsExportUrl = $listBase . '&export=emails';
$qs = http_build_query(array_filter([
    'page' => $isAdmin ? 'admin_emails_data' : 'team_sites_emails',
    'folder' => $sweFolder,
    'country' => $countryName,
    'q' => $q,
], static fn ($v) => $v !== '' && $v !== null));

render_header($sweLabel . ' · ' . $countryName, $swePanel);
$crumbs = $isAdmin
    ? [
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => $sweAdminHubLabel, 'href' => $sweAdminHub],
        ['label' => $sweLabel, 'href' => $sweBase],
        ['label' => $countryName],
    ]
    : [
        ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
        ['label' => $sweLabel, 'href' => $sweBase],
        ['label' => $countryName],
    ];
render_breadcrumbs($crumbs);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info(
        $countryName,
        $isTeam
            ? 'Add up to 4 emails per site (paste all four at once). Changes autosave. Push to Admin moves rows that have at least one email.'
            : 'Search finds site + emails together. Clear an email with Backspace (autosave). Remove deletes the whole row.'
    ) ?></h1>
    <p class="muted">
      <span id="swe_total_label"><?= (int) $countryTotal ?></span> site<?= (int) $countryTotal === 1 ? '' : 's' ?>
      <?= $q !== '' ? ' · ' . (int) $total . ' match' . ((int) $total === 1 ? '' : 'es') : '' ?>
      · up to 4 emails each
      <?php if ($isTeam): ?>
        · <?= (int) $readyToPush ?> ready to Push
      <?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <?php if ($isTeam): ?>
    <form method="post" action="<?= h($listBase) ?>" style="display:inline" id="swe-push-form"
          onsubmit="return confirm('Push <?= (int) $readyToPush ?> site(s) with emails to Sites with emails - Admin?\n\nThose rows will leave this Team working copy.');">
      <input type="hidden" name="action" value="push_to_admin">
      <button class="btn" type="submit" id="swe-push-btn" <?= $readyToPush > 0 ? '' : 'disabled' ?>
              title="<?= $readyToPush > 0 ? 'Push sites that have at least one email' : 'Add at least one email on a site first' ?>">
        Push to Admin
      </button>
    </form>
    <?php endif; ?>
    <button type="button" class="btn secondary" id="swe_copy_emails"
            data-export-url="<?= h($emailsExportUrl) ?>"
            <?= $countryTotal > 0 ? '' : 'disabled' ?>>Copy all emails</button>
    <a class="btn secondary" href="<?= h($csvUrl) ?>">Download CSV / Excel</a>
    <a class="btn secondary" href="<?= h($sweBase) ?>">All countries</a>
  </div>
</div>
<p class="help" id="swe_status" role="status" aria-live="polite" hidden></p>
<?php if ($isTeam): ?>
<p class="help">
  Paste up to 4 emails into any email box (one per line or commas). Edits <strong>autosave</strong>.
  <strong>Push to Admin</strong> sends sites that have at least one email.
</p>
<?php elseif ($isAdminAll): ?>
<p class="help">
  Synced Admin mirror. Search finds a <strong>site + its emails</strong> together.
  Edits stay in sync with Sites with emails - Admin · Clear an email with Backspace (autosaves) · Remove deletes the whole row.
</p>
<?php else: ?>
<p class="help">
  Final archive. Search finds a <strong>site + its emails</strong> together.
  Clear an email with Backspace (autosaves) · Remove deletes the whole row.
  Changes sync to All sites with emails - Final.
</p>
<?php endif; ?>

<div class="card">
  <div class="invoice-list-toolbar swe-list-toolbar">
    <div>
      <h2 style="margin:0"><?= label_with_info('Sites · Emails', 'Each row is one site with up to 4 emails. Search matches site name or any email on that row.') ?></h2>
      <p class="help" style="margin:0.25rem 0 0">
        Search shows both columns together (site + its emails).
        <?php if ($isAdmin): ?>
          Edit or Backspace to clear an email · Remove deletes the complete row.
        <?php else: ?>
          Paste up to 4 emails at once · autosave · Remove deletes the row.
        <?php endif; ?>
      </p>
    </div>
    <label class="sheet-search swe-row-search-wrap" for="swe-row-search">
      <span class="visually-hidden">Search sites and emails</span>
      <input id="swe-row-search" type="search" placeholder="Search site or email…"
             value="<?= h($q) ?>" autocomplete="off" spellcheck="false" data-no-draft
             <?= ($countryTotal < 1 && $q === '') ? 'disabled' : '' ?>
             title="Filter · Enter = next match · Ctrl/Cmd+Enter = search all pages">
      <span class="sheet-search-meta muted" data-swe-row-search-meta hidden></span>
    </label>
  </div>

  <div class="table-wrap">
    <table class="swe-table" id="swe-table">
      <thead>
        <tr>
          <th class="swe-col-site">Site name</th>
          <th class="swe-col-emails">Emails (up to 4)</th>
          <th class="swe-col-actions">Actions</th>
        </tr>
      </thead>
      <tbody id="swe-tbody">
      <?php foreach ($rows as $s):
          $domain = (string) $s['domain'];
          $e1 = (string) $s['email1'];
          $e2 = (string) $s['email2'];
          $e3 = (string) $s['email3'];
          $e4 = (string) $s['email4'];
          $hasEmail = $e1 !== '' || $e2 !== '' || $e3 !== '' || $e4 !== '';
          $hay = mb_strtolower($domain . ' ' . $e1 . ' ' . $e2 . ' ' . $e3 . ' ' . $e4);
          ?>
        <tr data-swe-row data-search="<?= h($hay) ?>" data-site-id="<?= (int) $s['id'] ?>"
            data-has-email="<?= $hasEmail ? '1' : '0' ?>">
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
                <div class="swe-emails" aria-label="Emails" data-swe-emails>
                  <input type="text" inputmode="email" name="email1" value="<?= h($e1) ?>"
                         placeholder="email 1 · or paste up to 4" spellcheck="false" autocomplete="off" data-swe-email>
                  <input type="text" inputmode="email" name="email2" value="<?= h($e2) ?>"
                         placeholder="email 2" spellcheck="false" autocomplete="off" data-swe-email>
                  <input type="text" inputmode="email" name="email3" value="<?= h($e3) ?>"
                         placeholder="email 3" spellcheck="false" autocomplete="off" data-swe-email>
                  <input type="text" inputmode="email" name="email4" value="<?= h($e4) ?>"
                         placeholder="email 4" spellcheck="false" autocomplete="off" data-swe-email>
                </div>
                <div class="swe-row-actions">
                  <button class="btn secondary small" type="submit" form="swe-remove-<?= (int) $s['id'] ?>"
                          onclick="return confirm('Remove complete row for <?= h($domain) ?>?');">Remove row</button>
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
  <p class="help sheet-search-empty" data-swe-row-search-empty hidden>
    No matching <strong>site + emails</strong> rows on this page. Try Ctrl/Cmd+Enter to search all pages.
  </p>

  <?php if (!$rows && $q === ''): ?>
  <div class="empty-state">
    <?php if ($isTeam): ?>
      <p>No sites in this country yet.</p>
      <p class="muted">Push from Extracting Results to fill site names here.</p>
    <?php elseif ($isAdminAll): ?>
      <p>No mirrored sites in this country yet.</p>
      <p class="muted">They sync here from Sites with emails - Admin.</p>
    <?php else: ?>
      <p>No final sites in this country yet.</p>
      <p class="muted">Waiting for Team to Push from Sites with emails - Team.</p>
    <?php endif; ?>
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
    <?php if ($countryTotal > 0): ?>
    <form method="post" action="<?= h($listBase) ?>"
          onsubmit="return confirm('Remove ALL <?= (int) $countryTotal ?> sites from <?= h($countryName) ?>?');">
      <input type="hidden" name="action" value="remove_all">
      <button class="btn secondary small danger" type="submit">Remove all</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($isTeam): ?>
<div class="card" style="margin-top:1rem">
  <h2><?= label_with_info('Add site row', 'Optional manual add. Most site names arrive from Extracting Results → Push.') ?></h2>
  <p class="help">Optional manual add. Most sites arrive from Extracting Results → Push.</p>
  <form method="post" action="<?= h($listBase) ?>" class="swe-add-form">
    <input type="hidden" name="action" value="save_row">
    <input type="hidden" name="site_id" value="0">
    <div class="form-grid" style="gap:0.65rem">
      <div class="full">
        <label for="swe_add_domain">Site name</label>
        <input id="swe_add_domain" name="domain" required placeholder="example.com" spellcheck="false">
      </div>
      <div class="full" data-swe-emails>
        <label>Emails (up to 4 — paste all at once into any box)</label>
        <div class="swe-emails swe-emails-add">
          <input id="swe_add_e1" type="text" inputmode="email" name="email1" placeholder="email 1 · or paste up to 4" spellcheck="false" autocomplete="off" data-swe-email>
          <input id="swe_add_e2" type="text" inputmode="email" name="email2" placeholder="email 2" spellcheck="false" autocomplete="off" data-swe-email>
          <input id="swe_add_e3" type="text" inputmode="email" name="email3" placeholder="email 3" spellcheck="false" autocomplete="off" data-swe-email>
          <input id="swe_add_e4" type="text" inputmode="email" name="email4" placeholder="email 4" spellcheck="false" autocomplete="off" data-swe-email>
        </div>
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit">Add row</button>
    </p>
  </form>
</div>
<?php endif; ?>

<?php if ($countryTotal > 0): ?>
<div class="card" id="remove-by-list" style="margin-top:1rem">
  <h2><?= label_with_info('Remove by list', 'Paste site names or upload a 1-column CSV. Matching rows in this country are removed.') ?></h2>
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
