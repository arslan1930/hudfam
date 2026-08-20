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
        $sentExport = (string) get('sent');
        if ($sweScope !== 'admin' || ($sentExport !== '0' && $sentExport !== '1')) {
            $sentExport = null;
        }
        stream_sites_with_emails_emails_plain($sheet, $sweScope, $sentExport);
    }
}

if ($inCountry && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $countryName = $sheet;
    $returnQ = trim((string) post('q'));
    $returnP = max(1, (int) (post('p') ?: 1));
    $returnSent = (string) post('sent');
    $returnPerPage = resolve_sheet_per_page();
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $back = $sweBase . '&country=' . rawurlencode($countryName);
    if ($returnQ !== '') {
        $back .= '&q=' . rawurlencode($returnQ);
    }
    if ($returnSent === '0' || $returnSent === '1') {
        $back .= '&sent=' . $returnSent;
    }
    $back = append_sheet_per_page_query($back, $returnPerPage);
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

    // Campaign progress — Admin only (Final stays a neutral duplicate archive).
    if ($action === 'mark_email_sent' && $sweScope === 'admin') {
        $siteId = (int) post('site_id');
        $sent = (string) post('email_sent') === '1';
        $result = set_site_with_emails_admin_email_sent($siteId, $sent);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(400);
            }
            echo json_encode($result + count_sites_with_emails_sent_stats($countryName));
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not update sent mark.'));
        } else {
            flash(
                'ok',
                ($sent ? 'Marked emailed: ' : 'Cleared emailed mark: ')
                . (string) ($result['domain'] ?? 'site')
            );
        }
        redirect($back);
    }

    if ($action === 'mark_emailed_up_to' && $sweScope === 'admin') {
        $siteId = (int) post('site_id');
        $result = mark_sites_with_emails_admin_emailed_up_to($siteId);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(400);
            }
            echo json_encode($result + count_sites_with_emails_sent_stats($countryName));
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not mark checkpoint.'));
        } else {
            flash(
                'ok',
                'Marked emailed up to ' . (string) ($result['domain'] ?? 'site')
                . ' · ' . (int) ($result['marked'] ?? 0) . ' newly marked.'
                . ' Final archive stays unchanged.'
            );
        }
        redirect($back);
    }

    if ($action === 'clear_emailed_up_to' && $sweScope === 'admin') {
        $siteId = (int) post('site_id');
        $result = clear_sites_with_emails_admin_emailed_up_to($siteId);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(400);
            }
            echo json_encode($result + count_sites_with_emails_sent_stats($countryName));
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not clear checkpoint.'));
        } else {
            flash(
                'ok',
                'Cleared emailed up to ' . (string) ($result['domain'] ?? 'site')
                . ' · ' . (int) ($result['cleared'] ?? 0) . ' cleared.'
                . ' You can mark again to redo this stretch.'
            );
        }
        redirect($back);
    }

    if ($action === 'clear_all_emailed' && $sweScope === 'admin') {
        $result = clear_all_sites_with_emails_admin_emailed($countryName);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(400);
            }
            echo json_encode($result + count_sites_with_emails_sent_stats($countryName));
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not clear emailed marks.'));
        } else {
            flash(
                'ok',
                'Cleared all emailed marks on ' . $countryName
                . ' · ' . (int) ($result['cleared'] ?? 0) . ' sites.'
                . ' Ready to resend and track again. Final archive stays unchanged.'
            );
        }
        redirect($back);
    }

    if ($action === 'remove_all') {
        $n = delete_sites_with_emails_for_country($countryName, $sweScope);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from ' . $countryName . '.');
        redirect($sweBase);
    }

    if ($action === 'push_site' && $isTeam) {
        $siteId = (int) post('site_id');
        $result = push_one_site_with_emails_team_to_admin($siteId, $sweUser);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(400);
            }
            $left = (int) ($result['site_count'] ?? count_sites_with_emails_for_country($countryName, 'team'));
            echo json_encode($result + [
                'ready_count' => count_sites_with_emails_ready_to_push($countryName),
                'redirect' => $left < 1 ? $sweBase : null,
            ]);
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not push this site.'));
            redirect($back);
        }
        flash(
            'ok',
            'Pushed ' . (string) ($result['domain'] ?? 'site')
            . ' to Sites with emails - Admin · cleared from Team.'
        );
        $left = (int) ($result['site_count'] ?? 0);
        redirect($left > 0 ? $back : $sweBase);
    }

    if ($action === 'push_to_admin' && $isTeam) {
        $ready = count_sites_with_emails_ready_to_push($countryName);
        if ($ready < 1) {
            // Only when every site still has all 4 email boxes empty (nothing ready).
            flash('error', 'All email boxes are empty. Fill at least one email on a site, then Push.');
            redirect($back);
        }
        $pushed = push_sites_with_emails_team_to_admin($countryName, $sweUser);
        $msg = 'Pushed all ' . ((int) $pushed['pushed'] + (int) $pushed['updated'])
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
$sentFilter = (string) get('sent'); // Admin only: '', '0' (not sent), '1' (sent)
if ($sweScope !== 'admin' || ($sentFilter !== '0' && $sentFilter !== '1')) {
    $sentFilter = '';
}
$pageNum = max(1, (int) get('p', 1));
$perPage = resolve_sheet_per_page();
$inv = sites_with_emails_inventory_query([
    'country' => $countryName,
    'q' => $q,
    'sent' => $sentFilter,
], $pageNum, $perPage, $sweScope);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryTotal = count_sites_with_emails_for_country($countryName, $sweScope);
$sentStats = ($sweScope === 'admin') ? count_sites_with_emails_sent_stats($countryName) : null;
$readyToPush = $isTeam ? count_sites_with_emails_ready_to_push($countryName) : 0;
$listBase = $sweBase . '&country=' . rawurlencode($countryName);
$listBase = append_sheet_per_page_query($listBase, $perPage);
$csvUrl = $listBase . '&export=csv';
$emailsExportUrl = $listBase . '&export=emails';
$emailsExportUnsentUrl = $emailsExportUrl . '&sent=0';
$emailsExportSentUrl = $emailsExportUrl . '&sent=1';
$qsBase = [
    'page' => $isAdmin ? 'admin_emails_data' : 'team_sites_emails',
    'folder' => $sweFolder,
    'country' => $countryName,
    'q' => $q,
    'sent' => $sentFilter,
    'per_page' => $perPage,
];
$qs = http_build_query(array_filter($qsBase, static fn ($v) => $v !== '' && $v !== null));

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
            ? 'Add emails (autosave). Push one site with its row button, or Push all sites that have at least one email.'
            : 'Search finds site + emails together. Clear an email with Backspace (autosave). Remove deletes the whole row.'
    ) ?></h1>
    <p class="muted">
      <span id="swe_total_label"><?= (int) $countryTotal ?></span> site<?= (int) $countryTotal === 1 ? '' : 's' ?>
      <?= $q !== '' || $sentFilter !== '' ? ' · ' . (int) $total . ' shown' : '' ?>
      · <?= (int) $perPage ?> per page
      · up to 4 emails each
      <?php if ($sentStats): ?>
        · <span id="swe_unsent_label"><?= (int) $sentStats['unsent'] ?></span> not emailed
        · <span id="swe_sent_label"><?= (int) $sentStats['sent'] ?></span> emailed
      <?php endif; ?>
      <?php if ($isTeam): ?>
        · <span id="swe_ready_label"><?= (int) $readyToPush ?></span> ready to Push
      <?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <?php
    if ($isTeam) {
        render_task_presence('swe_team:' . $countryName, 'Others on Sites with emails · ' . $countryName);
    } elseif ($sweScope === 'admin') {
        render_task_presence('swe_admin:' . $countryName, 'Others on Admin emails · ' . $countryName);
    }
    ?>
    <?php if ($isTeam): ?>
    <form method="post" action="<?= h($listBase) ?>" style="display:inline" id="swe-push-form"
          data-show-processing="Pushing sites to Admin…"
          data-confirm-push-all="Push ALL <?= (int) $readyToPush ?> site(s) with emails to Sites with emails - Admin?&#10;&#10;Those rows will leave this Team working copy.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="push_to_admin">
      <button class="btn" type="submit" id="swe-push-btn" <?= $readyToPush > 0 ? '' : 'disabled' ?>
              title="<?= $readyToPush > 0 ? 'Push every site on this country that has at least one email' : 'Add at least one email on a site first' ?>">
        Push all to Admin
      </button>
    </form>
    <?php endif; ?>
    <?php if ($sweScope === 'admin'): ?>
    <div class="swe-copy-group" role="group" aria-label="Copy emails by sent status">
      <button type="button" class="btn secondary" data-swe-copy-emails
              data-export-url="<?= h($emailsExportUnsentUrl) ?>"
              data-copy-label="not emailed"
              title="Copy emails from sites not marked emailed yet"
              <?= ($sentStats && (int) $sentStats['unsent'] > 0) ? '' : 'disabled' ?>>
        Copy not emailed
      </button>
      <button type="button" class="btn secondary" data-swe-copy-emails
              data-export-url="<?= h($emailsExportSentUrl) ?>"
              data-copy-label="emailed"
              title="Copy emails from sites already marked emailed"
              <?= ($sentStats && (int) $sentStats['sent'] > 0) ? '' : 'disabled' ?>>
        Copy emailed
      </button>
      <button type="button" class="btn secondary" id="swe_copy_emails" data-swe-copy-emails
              data-export-url="<?= h($emailsExportUrl) ?>"
              data-copy-label="all"
              title="Copy every email on this Admin country sheet"
              <?= $countryTotal > 0 ? '' : 'disabled' ?>>
        Copy all emails
      </button>
    </div>
    <?php else: ?>
    <button type="button" class="btn secondary" id="swe_copy_emails" data-swe-copy-emails
            data-export-url="<?= h($emailsExportUrl) ?>"
            data-copy-label="all"
            <?= $countryTotal > 0 ? '' : 'disabled' ?>>Copy all emails</button>
    <?php endif; ?>
    <a class="btn secondary" href="<?= h($csvUrl) ?>">Download CSV / Excel</a>
    <a class="btn secondary" href="<?= h($sweBase) ?>">All countries</a>
  </div>
</div>
<p class="help" id="swe_status" role="status" aria-live="polite" hidden></p>
<?php if ($isTeam): ?>
<p class="help">
  Paste up to 4 emails into any email box. Edits <strong>autosave</strong>.
  Use <strong>Push</strong> on a row for one site, or <strong>Push all to Admin</strong> for every site that has at least one email.
</p>
<?php elseif ($isAdminAll): ?>
<p class="help">
  Neutral duplicate archive (mirror of Admin). No campaign “emailed” marks here.
  Search finds a <strong>site + its emails</strong> together.
</p>
<?php else: ?>
<p class="help">
  Working archive from Team Push. Campaign progress is tracked on this Admin sheet only —
  <strong>Final stays neutral</strong> (no emailed marks).
</p>
<?php endif; ?>

<?php if ($sweScope === 'admin'): ?>
<div class="card swe-checkpoint-rule" style="margin-bottom:1rem">
  <h2 style="margin:0 0 0.45rem"><?= label_with_info('Emailed selection rule', 'How Mark emailed / Mark up to here / Clear up to here work on this Admin country sheet.') ?></h2>
  <ol class="swe-checkpoint-steps">
    <li><strong>Order:</strong> oldest sites at the top · newest Team pushes at the bottom.</li>
    <li><strong>Mark emailed:</strong> marks only that one site as done.</li>
    <li><strong>Mark up to here:</strong> marks this site <em>and every site above it</em> as emailed (checkpoint).</li>
    <li><strong>Clear up to here:</strong> clears emailed marks from the top through this site (redo that stretch).</li>
    <li><strong>Clear all emailed:</strong> resets the whole country sheet for a full resend.</li>
  </ol>
  <p class="help" style="margin:0.55rem 0 0">
    Highlighted rows = already emailed. Filters: All / Not emailed / Emailed.
    These marks never sync to Final.
  </p>
</div>
<?php endif; ?>

<div class="card">
  <div class="invoice-list-toolbar swe-list-toolbar">
    <div>
      <h2 style="margin:0"><?= label_with_info('Sites · Emails', 'Each row is one site with up to 4 emails. Sheets can reach ~100K sites — choose how many rows per page with the Per page filter. Search matches site name or any email on that row.') ?></h2>
      <p class="help" style="margin:0.25rem 0 0">
        Search shows both columns together (site + its emails).
        <?php if ($sweScope === 'admin'): ?>
          Use <strong>Status</strong> and the Actions buttons on each row for emailed / up to here.
        <?php elseif ($isAdmin): ?>
          Edit or Backspace to clear an email · Remove deletes the complete row.
        <?php else: ?>
          Paste up to 4 emails at once · autosave · Remove deletes the row.
        <?php endif; ?>
      </p>
      <?php if ($sweScope === 'admin'): ?>
      <p class="swe-sent-filters">
        <?php
        $sentLinks = [
            '' => 'All',
            '0' => 'Not emailed',
            '1' => 'Emailed',
        ];
        foreach ($sentLinks as $val => $label):
            $href = $sweBase . '&country=' . rawurlencode($countryName);
            $href = append_sheet_per_page_query($href, $perPage);
            if ($q !== '') {
                $href .= '&q=' . rawurlencode($q);
            }
            if ($val !== '') {
                $href .= '&sent=' . $val;
            }
            $active = $sentFilter === (string) $val;
            ?>
          <a class="btn small <?= $active ? '' : 'secondary' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
        <?php if ($sentStats && (int) $sentStats['sent'] > 0): ?>
        <form method="post" action="<?= h($listBase) ?>" class="swe-clear-all-emailed"
              data-swe-clear-all-emailed
              onsubmit="return confirm('Clear ALL emailed marks on <?= h($countryName) ?>?\n\nYou can resend and track this Admin sheet from scratch.\n\nFinal archive stays unchanged.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_all_emailed">
          <input type="hidden" name="q" value="<?= h($q) ?>">
          <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
          <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
          <?php if ($sentFilter !== ''): ?>
          <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
          <?php endif; ?>
          <button class="btn secondary small" type="submit" title="Clear every emailed mark on this Admin country sheet">
            Clear all emailed
          </button>
        </form>
        <?php endif; ?>
      </p>
      <?php endif; ?>
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

  <div class="table-wrap swe-sheet-wrap">
    <table class="swe-table swe-sheet-table<?= $sweScope === 'admin' ? ' is-admin-checkpoint' : '' ?>" id="swe-table">
      <thead>
        <tr>
          <th class="swe-col-site">Site</th>
          <th class="swe-col-lang">Language</th>
          <th class="swe-col-email">Email 1</th>
          <th class="swe-col-email">Email 2</th>
          <th class="swe-col-email">Email 3</th>
          <th class="swe-col-email">Email 4</th>
          <th class="swe-col-status">Status</th>
          <th class="swe-col-actions">Actions</th>
        </tr>
      </thead>
      <tbody id="swe-tbody">
      <?php foreach ($rows as $s):
          $sid = (int) $s['id'];
          $formId = 'swe-save-' . $sid;
          $domain = (string) $s['domain'];
          $lang = trim((string) ($s['language'] ?? ''));
          if ($lang === '') {
              $lang = '—';
          }
          $e1 = (string) $s['email1'];
          $e2 = (string) $s['email2'];
          $e3 = (string) $s['email3'];
          $e4 = (string) $s['email4'];
          $hasEmail = $e1 !== '' || $e2 !== '' || $e3 !== '' || $e4 !== '';
          $isEmailed = $sweScope === 'admin' && (int) ($s['email_sent'] ?? 0) === 1;
          $hay = mb_strtolower($domain . ' ' . $lang . ' ' . $e1 . ' ' . $e2 . ' ' . $e3 . ' ' . $e4);
          if ($sweScope === 'admin') {
              $statusLabel = $isEmailed ? 'Emailed' : 'Not emailed';
              $statusClass = $isEmailed ? 'is-emailed' : 'is-open';
          } elseif ($isTeam) {
              $statusLabel = $hasEmail ? 'Ready' : 'Needs email';
              $statusClass = $hasEmail ? 'is-ready' : 'is-open';
          } else {
              $statusLabel = 'Archive';
              $statusClass = 'is-archive';
          }
          ?>
        <tr data-swe-row data-swe-emails data-search="<?= h($hay) ?>" data-site-id="<?= $sid ?>"
            data-has-email="<?= $hasEmail ? '1' : '0' ?>"
            data-email-sent="<?= $isEmailed ? '1' : '0' ?>"
            class="<?= $isEmailed ? 'swe-row-emailed' : '' ?>">
          <td class="swe-td-site">
            <form id="<?= h($formId) ?>" method="post" action="<?= h($listBase) ?>" class="swe-row-form" data-swe-save>
              <input type="hidden" name="action" value="save_row">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
            </form>
            <label class="visually-hidden" for="swe-domain-<?= $sid ?>">Site</label>
            <input id="swe-domain-<?= $sid ?>" class="swe-domain" form="<?= h($formId) ?>" name="domain"
                   value="<?= h($domain) ?>" required spellcheck="false" autocomplete="off" aria-label="Site">
          </td>
          <td class="swe-td-lang"><span class="swe-cell-text"><?= h($lang) ?></span></td>
          <td class="swe-td-email">
            <?= render_clearable_email_input('email1', $e1, ['swe' => true, 'form' => $formId, 'placeholder' => 'email 1', 'aria_label' => 'Clear email 1']) ?>
          </td>
          <td class="swe-td-email">
            <?= render_clearable_email_input('email2', $e2, ['swe' => true, 'form' => $formId, 'placeholder' => 'email 2', 'aria_label' => 'Clear email 2']) ?>
          </td>
          <td class="swe-td-email">
            <?= render_clearable_email_input('email3', $e3, ['swe' => true, 'form' => $formId, 'placeholder' => 'email 3', 'aria_label' => 'Clear email 3']) ?>
          </td>
          <td class="swe-td-email">
            <?= render_clearable_email_input('email4', $e4, ['swe' => true, 'form' => $formId, 'placeholder' => 'email 4', 'aria_label' => 'Clear email 4']) ?>
          </td>
          <td class="swe-td-status">
            <span class="swe-status-badge <?= h($statusClass) ?>" data-swe-status><?= h($statusLabel) ?></span>
          </td>
          <td class="swe-td-actions">
            <div class="swe-row-actions">
              <?php if ($sweScope === 'admin'): ?>
              <button class="btn small <?= $isEmailed ? 'secondary' : '' ?>" type="submit"
                      form="swe-mark-<?= $sid ?>"
                      title="<?= $isEmailed ? 'Clear emailed mark on this site only' : 'Mark this site as emailed' ?>">
                <?= $isEmailed ? 'Clear emailed' : 'Mark emailed' ?>
              </button>
              <button class="btn secondary small" type="submit" form="swe-upto-<?= $sid ?>"
                      title="Mark this site and every older site above it as emailed"
                      onclick="return confirm('Mark emailed UP TO <?= h($domain) ?>?\n\nEvery older site from the top through this row will be marked emailed.\n\nFinal archive stays unchanged.');">
                Up to here
              </button>
              <button class="btn secondary small" type="submit" form="swe-clear-upto-<?= $sid ?>"
                      title="Clear emailed marks from the top through this site"
                      onclick="return confirm('Clear emailed UP TO <?= h($domain) ?>?\n\nEvery older emailed site from the top through this row will be unmarked.\n\nFinal archive stays unchanged.');">
                Clear up to
              </button>
              <?php endif; ?>
              <?php if ($isTeam): ?>
              <button class="btn small" type="submit" form="swe-push-<?= $sid ?>"
                      data-swe-push-btn <?= $hasEmail ? '' : 'disabled' ?>
                      title="<?= $hasEmail ? 'Push this site to Admin' : 'Add at least one email first' ?>"
                      onclick="return confirm('Push <?= h($domain) ?> to Sites with emails - Admin?\n\nThis row will leave the Team working copy.');">Push</button>
              <?php endif; ?>
              <button class="btn secondary small" type="submit" form="swe-remove-<?= $sid ?>"
                      onclick="return confirm('Remove complete row for <?= h($domain) ?>?');">Remove</button>
            </div>
            <?php if ($isTeam): ?>
            <form id="swe-push-<?= $sid ?>" method="post" action="<?= h($listBase) ?>" data-swe-push hidden>
              <input type="hidden" name="action" value="push_site">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
            </form>
            <?php endif; ?>
            <?php if ($sweScope === 'admin'): ?>
            <form id="swe-mark-<?= $sid ?>" method="post" action="<?= h($listBase) ?>" data-swe-mark hidden>
              <input type="hidden" name="action" value="mark_email_sent">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="email_sent" value="<?= $isEmailed ? '0' : '1' ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
            </form>
            <form id="swe-upto-<?= $sid ?>" method="post" action="<?= h($listBase) ?>" data-swe-mark-upto hidden>
              <input type="hidden" name="action" value="mark_emailed_up_to">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
            </form>
            <form id="swe-clear-upto-<?= $sid ?>" method="post" action="<?= h($listBase) ?>" data-swe-clear-upto hidden>
              <input type="hidden" name="action" value="clear_emailed_up_to">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
            </form>
            <?php endif; ?>
            <form id="swe-remove-<?= $sid ?>" method="post" action="<?= h($listBase) ?>" data-swe-remove hidden>
              <input type="hidden" name="action" value="remove_site">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
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

  <?php if (!$rows && $q === '' && $sentFilter === ''): ?>
  <div class="empty-state">
    <?php if ($isTeam): ?>
      <p>No sites in this country yet.</p>
      <p class="muted">Push from Extracting Results to fill site names here.</p>
    <?php elseif ($isAdminAll): ?>
      <p>No mirrored sites in this country yet.</p>
      <p class="muted">They sync here from Sites with emails - Admin. Final stays a neutral backup (no emailed marks).</p>
    <?php else: ?>
      <p>No sites in this country yet.</p>
      <p class="muted">Waiting for Team to Push from Sites with emails - Team.</p>
    <?php endif; ?>
  </div>
  <?php elseif (!$rows && ($q !== '' || $sentFilter !== '')): ?>
  <div class="empty-state">
    <?php if ($sentFilter === '0'): ?>
      <p>No unmarked sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
      <p class="muted">New Team pushes appear here until you mark them emailed.</p>
    <?php elseif ($sentFilter === '1'): ?>
      <p>No emailed sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
      <p class="muted">Use “Mark emailed” or “Mark up to here” while working the campaign.</p>
    <?php else: ?>
      <p>No matching sites.</p>
      <p class="muted">Try a different search, or clear the filter.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="actions" style="margin-top:0.85rem;justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
    <div class="actions" style="margin:0;gap:0.65rem;flex-wrap:wrap;align-items:center">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <?php if ($rows || $q !== ''): ?>
        <span class="muted">Page <?= $pageNum ?> / <?= $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?></span>
      <?php endif; ?>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
      <?php
      render_sheet_per_page_filter([
          'page' => $isAdmin ? 'admin_emails_data' : 'team_sites_emails',
          'folder' => $sweFolder,
          'country' => $countryName,
          'q' => $q,
          'sent' => $sentFilter,
      ], $perPage);
      ?>
    </div>
    <?php if ($countryTotal > 0): ?>
    <form method="post" action="<?= h($listBase) ?>"
          data-show-processing="Removing all sites…"
          onsubmit="return confirm('Remove ALL <?= (int) $countryTotal ?> sites from <?= h($countryName) ?>?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove_all">
      <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
      <button class="btn secondary small danger" type="submit">Remove all</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($isTeam): ?>
<div class="card" style="margin-top:1rem">
  <h2><?= label_with_info('Add site row', 'Optional manual add. Most site names arrive from Extracting Results → Push.') ?></h2>
  <p class="help">Optional manual add. Most sites arrive from Extracting Results → Push.</p>
  <form method="post" action="<?= h($listBase) ?>" class="swe-add-form"
        data-show-processing="Adding site…">
    <?= csrf_field() ?>
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
          <?= render_clearable_email_input('email1', '', ['id' => 'swe_add_e1', 'swe' => true, 'placeholder' => 'email 1 · or paste up to 4', 'aria_label' => 'Clear email 1']) ?>
          <?= render_clearable_email_input('email2', '', ['id' => 'swe_add_e2', 'swe' => true, 'placeholder' => 'email 2', 'aria_label' => 'Clear email 2']) ?>
          <?= render_clearable_email_input('email3', '', ['id' => 'swe_add_e3', 'swe' => true, 'placeholder' => 'email 3', 'aria_label' => 'Clear email 3']) ?>
          <?= render_clearable_email_input('email4', '', ['id' => 'swe_add_e4', 'swe' => true, 'placeholder' => 'email 4', 'aria_label' => 'Clear email 4']) ?>
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
        data-show-processing="Removing listed sites…"
        onsubmit="return confirm('Remove matching sites from <?= h($countryName) ?>?');">
    <?= csrf_field() ?>
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

<?= email_field_clear_script_tag() ?>
<script src="<?= h(script_asset_url('js/sites-with-emails.js')) ?>" defer></script>
<?php
render_footer($swePanel);
return;
