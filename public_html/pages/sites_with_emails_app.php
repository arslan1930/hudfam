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
    $returnFilter = (string) post('filter');
    $returnPerPage = resolve_sheet_per_page();
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $histKey = function_exists('sheet_history_key')
        ? sheet_history_key('swe', $sweScope . ':' . $countryName)
        : ('swe:' . $sweScope . ':' . $countryName);
    $jsonOut = static function (array $payload, int $code = 200) use ($wantsJson, $histKey): void {
        if (!$wantsJson) {
            return;
        }
        if (function_exists('sheet_history_state')) {
            $payload += sheet_history_state($histKey);
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    };
    $back = $sweBase . '&country=' . rawurlencode($countryName);
    if ($returnQ !== '') {
        $back .= '&q=' . rawurlencode($returnQ);
    }
    if ($returnSent === '0' || $returnSent === '1') {
        $back .= '&sent=' . $returnSent;
    }
    if ($returnFilter === 'new' || $returnFilter === 'updated') {
        $back .= '&filter=' . rawurlencode($returnFilter);
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
        } elseif (!empty($result['row_deleted'])) {
            flash('ok', 'Removed ' . (string) ($result['domain'] ?? 'site') . ' (no emails left).');
        } else {
            flash('ok', $id > 0 ? 'Updated row.' : 'Added site row.');
        }
        redirect($back);
    }

    if ($action === 'remove_site') {
        $siteId = (int) post('site_id');
        $result = remove_sites_with_emails_by_ids($countryName, [$siteId], $sweScope);
        $ok = !empty($result['ok']);
        $domain = (string) (($result['removed'][0]['domain'] ?? '') ?: '');
        $left = count_sites_with_emails_for_country($countryName, $sweScope);
        if ($wantsJson) {
            $jsonOut([
                'ok' => $ok,
                'error' => $ok ? null : (string) ($result['error'] ?? 'Row not found.'),
                'domain' => $domain,
                'site_count' => $left,
                'redirect' => $left < 1 ? $sweBase : null,
            ] + (function_exists('count_sites_with_emails_sent_stats') && $sweScope === 'admin'
                ? count_sites_with_emails_sent_stats($countryName)
                : []), $ok ? 200 : 404);
        }
        if (!$ok) {
            flash('error', (string) ($result['error'] ?? 'Row not found.'));
            redirect($back);
        }
        flash('ok', 'Removed ' . ($domain !== '' ? $domain : 'site') . '.');
        if ($left < 1) {
            redirect($sweBase);
        }
        redirect($back);
    }

    if ($action === 'remove_selected') {
        $ids = function_exists('parse_posted_id_list') ? parse_posted_id_list(post('site_ids')) : [];
        $result = remove_sites_with_emails_by_ids($countryName, $ids, $sweScope);
        $left = count_sites_with_emails_for_country($countryName, $sweScope);
        if ($wantsJson) {
            $jsonOut(
                $result + [
                    'site_count' => $left,
                ],
                !empty($result['ok']) ? 200 : 400
            );
        }
        flash($result['ok'] ? 'ok' : 'error', $result['ok']
            ? 'Removed ' . (int) $result['count'] . ' selected site' . ((int) $result['count'] === 1 ? '' : 's') . '.'
            : (string) ($result['error'] ?? 'Could not remove selected rows.'));
        redirect($back);
    }

    if ($action === 'undo_last' || $action === 'redo_last') {
        $result = $action === 'redo_last'
            ? sheet_history_apply_redo($histKey)
            : sheet_history_apply_undo($histKey);
        if ($wantsJson) {
            $jsonOut($result, !empty($result['ok']) ? 200 : 400);
        }
        flash($result['ok'] ? 'ok' : 'error', $result['ok']
            ? ($action === 'redo_last' ? 'Redid last change.' : 'Undid last change.')
            : (string) ($result['error'] ?? 'Could not undo/redo.'));
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

    // Final only: Campaign-style paste + CSV/xlsx/txt import (also writes Admin).
    if ($isAdminAll && ($action === 'paste' || $action === 'import_file')) {
        $finishBulk = static function (array $result, string $prefix) use (
            $countryName,
            $sweScope,
            $sweBase,
            $returnPerPage,
            $back
        ): void {
            $msg = sites_with_emails_bulk_result_message($prefix, $result);
            $hasErrors = ($result['errors'] ?? []) !== [];
            flash($hasErrors ? 'error' : 'ok', $msg);
            if ((int) ($result['added'] ?? 0) < 1 && (int) ($result['updated'] ?? 0) < 1) {
                redirect($back . '#swe-bulk-add');
            }
            $totalAfter = count_sites_with_emails_for_country($countryName, $sweScope);
            $lastPage = max(1, (int) ceil($totalAfter / max(1, $returnPerPage)));
            $jump = $sweBase . '&country=' . rawurlencode($countryName);
            $jump = append_sheet_per_page_query($jump, $returnPerPage);
            if ($lastPage > 1) {
                $jump .= '&p=' . $lastPage;
            }
            redirect($jump);
        };
        try {
            if ($action === 'paste') {
                $pasteText = (string) post('paste_text');
                if (trim($pasteText) === '') {
                    flash('error', 'Paste at least one site + email line.');
                    redirect($back . '#swe-bulk-add');
                }
                $finishBulk(
                    paste_sites_with_emails_rows($countryName, $pasteText, $sweUser, 'admin_all'),
                    'Added to Final'
                );
            }
            $finishBulk(
                import_sites_with_emails_rows_from_upload(
                    $countryName,
                    $_FILES['import_file'] ?? null,
                    $sweUser,
                    'admin_all'
                ),
                'Imported file into Final'
            );
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect($back . '#swe-bulk-add');
        }
    }

    // Campaign progress — Admin only (Final stays a neutral duplicate archive).
    if ($action === 'mark_email_sent' && $sweScope === 'admin') {
        $siteId = (int) post('site_id');
        $sent = (string) post('email_sent') === '1';
        $result = set_site_with_emails_admin_email_sent($siteId, $sent);
        if ($wantsJson) {
            $jsonOut(
                $result + count_sites_with_emails_sent_stats($countryName),
                !empty($result['ok']) ? 200 : 400
            );
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not update sent mark.'));
        } elseif ($sent && !empty($result['row_deleted'])) {
            flash(
                'ok',
                'Marked emailed · removed ' . (string) ($result['domain'] ?? 'site')
                . ' from Admin. Final archive kept the copy.'
            );
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
            $jsonOut(
                $result + count_sites_with_emails_sent_stats($countryName),
                !empty($result['ok']) ? 200 : 400
            );
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not mark checkpoint.'));
        } else {
            flash(
                'ok',
                'Marked emailed up to ' . (string) ($result['domain'] ?? 'site')
                . ' · removed ' . (int) ($result['marked'] ?? 0)
                . ' from Admin. Final archive kept those copies.'
            );
        }
        redirect($back);
    }

    if ($action === 'clear_emailed_up_to' && $sweScope === 'admin') {
        $siteId = (int) post('site_id');
        $result = clear_sites_with_emails_admin_emailed_up_to($siteId);
        if ($wantsJson) {
            $jsonOut(
                $result + count_sites_with_emails_sent_stats($countryName),
                !empty($result['ok']) ? 200 : 400
            );
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
            $jsonOut(
                $result + count_sites_with_emails_sent_stats($countryName),
                !empty($result['ok']) ? 200 : 400
            );
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
        $confirmOverwrite = post('confirm_overwrite') === '1';
        $result = push_one_site_with_emails_team_to_admin(
            $siteId,
            $sweUser,
            $countryName,
            $confirmOverwrite
        );
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(!empty($result['needs_confirm']) ? 409 : 400);
            }
            $left = (int) ($result['site_count'] ?? count_sites_with_emails_for_country($countryName, 'team'));
            echo json_encode($result + [
                'ready_count' => count_sites_with_emails_ready_to_push($countryName),
                'conflict_count' => count_sites_with_emails_push_conflicts($countryName),
                'redirect' => ($result['ok'] ?? false) && $left < 1 ? $sweBase : null,
            ]);
            exit;
        }
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not push this site.'));
            redirect($back);
        }
        $oneMsg = ((!empty($result['updated'])) ? 'Merged Team emails into Admin for ' : 'Pushed ')
            . (string) ($result['domain'] ?? 'site')
            . ' · cleared from Team';
        if ((int) ($result['skipped_full_slots'] ?? 0) > 0) {
            $oneMsg .= ' · ' . (int) $result['skipped_full_slots']
                . ' Team email(s) not applied (Admin already had 4)';
        }
        if ((int) ($result['emailed_cleared'] ?? 0) > 0) {
            $oneMsg .= ' · Admin emailed mark cleared (emails changed)';
        }
        flash('ok', $oneMsg . '.');
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
        $confirmOverwrite = post('confirm_overwrite') === '1';
        $pushed = push_sites_with_emails_team_to_admin($countryName, $sweUser, $confirmOverwrite);
        if (empty($pushed['ok'])) {
            flash('error', (string) ($pushed['error'] ?? 'Could not push to Admin.'));
            redirect($back);
        }
        $msg = 'Pushed all ' . ((int) $pushed['pushed'] + (int) $pushed['updated'])
            . ' site(s) with emails to Sites with emails - Admin · ' . $pushed['country'];
        if ((int) $pushed['pushed'] > 0 || (int) $pushed['updated'] > 0) {
            $msg .= ' (' . (int) $pushed['pushed'] . ' new';
            if ((int) $pushed['updated'] > 0) {
                $msg .= ', ' . (int) $pushed['updated'] . ' merged';
            }
            $msg .= ')';
        }
        if ((int) ($pushed['cleared'] ?? 0) > 0) {
            $msg .= ' · cleared from Team working copy';
        }
        if ((int) $pushed['skipped_empty'] > 0) {
            $msg .= ' · ' . (int) $pushed['skipped_empty'] . ' without emails left here';
        }
        if ((int) ($pushed['skipped_full_slots'] ?? 0) > 0) {
            $msg .= ' · ' . (int) $pushed['skipped_full_slots']
                . ' Team email(s) not applied (Admin already had 4)';
            $dropDom = $pushed['dropped_domains'] ?? [];
            if (is_array($dropDom) && $dropDom !== []) {
                $msg .= ' on ' . implode(', ', array_slice($dropDom, 0, 5));
                if (count($dropDom) > 5) {
                    $msg .= '…';
                }
            }
        }
        if ((int) ($pushed['emailed_cleared'] ?? 0) > 0) {
            $msg .= ' · cleared emailed on ' . (int) $pushed['emailed_cleared']
                . ' Admin site(s) (emails changed)';
        }
        flash('ok', $msg . '.');
        // After push, stay on country if unfinished rows remain; else country list.
        $remaining = count_sites_with_emails_for_country($countryName, 'team');
        redirect($remaining > 0 ? $back : $sweBase);
    }
}

// --- Country list ---
if (!$inCountry) {
    if ($sweScope === 'admin' && function_exists('swe_admin_clear_visit_since')) {
        swe_admin_clear_visit_since($sweUser);
    }
    if ($sweScope === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST'
        && (string) post('action') === 'mark_all_countries_seen') {
        require_csrf();
        if (function_exists('swe_admin_mark_all_countries_seen')) {
            swe_admin_mark_all_countries_seen($sweUser);
        }
        flash('ok', 'Marked all countries as seen.');
        redirect($sweBase);
    }

    $countryRows = list_sites_with_emails_country_rows($sweScope);
    $teamFetchesByCountry = ($isTeam && function_exists('email_campaign_fetches_grouped_by_country'))
        ? email_campaign_fetches_grouped_by_country('team')
        : [];
    $grandTotal = 0;
    $emailSites = 0;
    foreach ($countryRows as $r) {
        $grandTotal += (int) $r['total'];
        $emailSites += (int) $r['with_emails'];
    }
    $adminNewByCountry = ($sweScope === 'admin' && function_exists('swe_admin_new_counts_by_country'))
        ? swe_admin_new_counts_by_country($sweUser)
        : [];
    $adminNewCountryTotal = 0;
    foreach ($adminNewByCountry as $n) {
        $adminNewCountryTotal += (int) $n;
    }
    $existingCountryKeys = [];
    foreach ($countryRows as $r) {
        $existingCountryKeys[mb_strtolower((string) ($r['country'] ?? ''))] = true;
    }
    $emptyCatalogCountries = [];
    if ($isAdminAll && function_exists('list_countries')) {
        foreach (list_countries() as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            if ($name === '' || isset($existingCountryKeys[mb_strtolower($name)])) {
                continue;
            }
            $emptyCatalogCountries[] = $name;
        }
    }
    $showWithEmailsCol = !$isAdminAll;
    $countryTableCols = 3; // Country · Sites · Open
    if ($showWithEmailsCol) {
        $countryTableCols++;
    }
    if ($isTeam) {
        $countryTableCols++;
    }

    render_header($sweLabel, $swePanel);
    $crumbs = $isAdmin
        ? [
            ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
            ['label' => $sweAdminHubLabel, 'href' => $sweAdminHub],
            ['label' => $sweLabel],
        ]
        : [
            ['label' => 'Your work', 'href' => 'index.php?page=team_dashboard'],
            ['label' => $sweLabel],
        ];
    render_breadcrumbs($crumbs);
    if ($isAdminAll && function_exists('guide_emails_data')) {
        echo guide_emails_data();
    }
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info(
            $sweLabel,
            $isTeam
                ? 'Working copy: site names arrive from Extracting Results Push. Add emails, then Push to Admin — pushed rows leave this list. Sites without emails stay here.'
                : ($isAdminAll
                    ? 'Final keeps a copy after Mark emailed or Remove on Admin. Not linked to Team. Open a folder in the list; paste and import are on that country sheet (and also create the Admin working-list row).'
                    : 'Working list from Team Push. Mark emailed removes the site from this list after Final has a copy. Communication Team can super-search this data.')
        ) ?></h1>
        <p class="muted">
          <?php if ($isTeam): ?>
            Site names arrive from Extracting Results → Push.
            Add emails, then Push again to Sites with emails - Admin ·
          <?php elseif ($isAdminAll): ?>
            Final keeps a copy after Mark emailed or Remove on Admin.
            Open a folder in the list — paste and import are on that sheet.
          <?php else: ?>
            Working list from Team Push · emailed checkpoint here · also synced to Final ·
          <?php endif; ?>
        </p>
        <p class="muted">
          <strong><?= count($countryRows) ?></strong>
          countr<?= count($countryRows) === 1 ? 'y' : 'ies' ?>
          · <strong><?= (int) $grandTotal ?></strong>
          site<?= (int) $grandTotal === 1 ? '' : 's' ?>
          <?php if (!$isAdminAll || (int) $emailSites !== (int) $grandTotal): ?>
            · <strong><?= (int) $emailSites ?></strong>
            with email<?= (int) $emailSites === 1 ? '' : 's' ?>
          <?php endif; ?>
          <?php if ($sweScope === 'admin' && $adminNewCountryTotal > 0): ?>
            · <span class="swe-country-new">+<?= (int) $adminNewCountryTotal ?> new</span>
          <?php endif; ?>
        </p>
      </div>
      <div class="actions">
        <?php if ($isAdmin): ?>
          <?php if ($sweScope === 'admin' && $adminNewCountryTotal > 0): ?>
            <form method="post" action="<?= h($sweBase) ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_all_countries_seen">
              <button class="btn secondary" type="submit">Mark all countries seen</button>
            </form>
          <?php endif; ?>
          <a class="btn secondary" href="<?= h($sweAdminHub) ?>">All folders</a>
        <?php else: ?>
          <?php if (team_page_unlocked($sweUser, 'team_admin_emails_search')): ?>
            <a class="btn secondary" href="index.php?page=team_admin_emails_search">Admin emails search</a>
          <?php endif; ?>
          <?php if (team_page_unlocked($sweUser, 'team_extracting')): ?>
            <a class="btn secondary" href="index.php?page=team_extracting">Extracting sites</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php
    $finalOpenerHtml = '';
    if ($isAdminAll && function_exists('list_countries')) {
        ob_start();
        ?>
    <div class="card" style="margin-bottom:1rem" id="swe-open-country">
      <h2><?= label_with_info(
          'Open an empty country',
          'Countries already in the list open from the table. Use this only to start a country that has no Final folder yet. After it opens, paste or import CSV / Excel / TXT like Campaign. Each site needs at least one email and also creates the Admin working-list row.'
      ) ?></h2>
      <?php if ($emptyCatalogCountries === []): ?>
        <p class="help">
          Every country in the catalog already has a Final folder.
          Open one in the list<?= $countryRows ? ' above' : '' ?> to paste or import on that sheet.
        </p>
      <?php else: ?>
      <p class="help">
        For a country that is not in the list yet. After it opens, paste or import
        <strong>site + emails</strong> (same formats as Campaign). Each site needs at least one email.
      </p>
      <form method="get" action="index.php" class="camp-hub-create-form" autocomplete="off" data-no-draft style="margin-top:0.65rem">
        <input type="hidden" name="page" value="admin_emails_data">
        <input type="hidden" name="folder" value="all_sites_with_emails">
        <div class="camp-hub-field">
          <label for="swe_open_country">Country</label>
          <select id="swe_open_country" name="country" required>
            <option value="">Select country…</option>
            <?php foreach ($emptyCatalogCountries as $emptyName): ?>
              <option value="<?= h($emptyName) ?>"><?= h($emptyName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Open country</button>
        </p>
      </form>
      <?php endif; ?>
    </div>
        <?php
        $finalOpenerHtml = (string) ob_get_clean();
    }
    if ($finalOpenerHtml !== '' && $countryRows === []) {
        echo $finalOpenerHtml;
    }
    ?>

    <?php if ($isTeam && team_page_unlocked($sweUser, 'team_admin_emails_search')): ?>
    <div class="card" style="margin-bottom:1rem">
      <h2><?= label_with_info('Admin emails search', 'Live search across Sites with emails - Admin. Delete the whole site row, or remove one email only and keep the site name.') ?></h2>
      <p class="help">
        Type a site or email — live suggestions come from
        <strong>Sites with emails - Admin</strong>.
        Select a match, then delete the whole row or remove one email only (site name stays).
      </p>
      <p class="actions" style="margin-top:0.65rem">
        <a class="btn secondary" href="index.php?page=team_admin_emails_search">Open Admin super search</a>
      </p>
    </div>
    <?php endif; ?>

    <div class="card">
      <?php if ($countryRows): ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <h2 style="margin:0"><?= label_with_info(
            'By country',
            $isAdminAll
                ? 'Open a folder to see its archive and paste or import. Each country name or Open goes to that sheet.'
                : 'Open a country to see its sites and emails. Counts show how many sites have at least one email.'
        ) ?></h2>
        <label class="sheet-search swe-country-search" for="swe-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="swe-country-search" type="search" placeholder="Search country name…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type a country name · Enter = next match">
          <span class="sheet-search-meta muted" data-swe-country-search-meta hidden></span>
        </label>
      </div>
      <?php if ($isAdminAll): ?>
      <p class="help" style="margin:0 0 0.65rem">Open a folder. Adding a site on that sheet also creates the Admin working-list row.</p>
      <?php endif; ?>
      <div class="table-wrap">
      <table class="extracted-country-table" id="swe-country-table">
        <thead>
          <tr>
            <th>Country</th>
            <th class="num">Sites</th>
            <?php if ($showWithEmailsCol): ?>
            <th class="num">With emails</th>
            <?php endif; ?>
            <?php if ($isTeam): ?>
            <th>Last Push</th>
            <?php endif; ?>
            <th><span class="visually-hidden">Open</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($countryRows as $r):
            $cName = (string) $r['country'];
            $openHref = $sweBase . '&country=' . rawurlencode($cName);
            $hay = mb_strtolower($cName . ' ' . (int) $r['total'] . ' sites');
            $newN = (int) ($adminNewByCountry[$cName] ?? 0);
            ?>
          <tr data-swe-country-row data-search="<?= h($hay) ?>">
            <td>
              <a class="extracted-country-link" href="<?= h($openHref) ?>">
                <?= h($cName) ?>
              </a>
              <?php if ($newN > 0): ?>
                <span class="swe-country-new" title="New sites since your last visit">+<?= $newN ?> new</span>
              <?php endif; ?>
              <?php
              $countryFetches = $teamFetchesByCountry[$cName] ?? [];
              if ($countryFetches !== [] && function_exists('render_email_campaign_fetch_stamps')):
                  ?>
                <div class="swe-fetch-stamps-inline">
                  <?php render_email_campaign_fetch_stamps($countryFetches); ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="num">
              <a class="extracted-country-count" href="<?= h($openHref) ?>" title="Open <?= h($cName) ?>">
                <?= (int) $r['total'] ?>
              </a>
            </td>
            <?php if ($showWithEmailsCol): ?>
            <td class="num muted"><?= (int) $r['with_emails'] ?></td>
            <?php endif; ?>
            <?php if ($isTeam):
                $lp = trim((string) ($r['last_pushed_at'] ?? ''));
                $recentPush = false;
                if ($lp !== '') {
                    $ts = strtotime($lp);
                    $recentPush = $ts !== false && $ts >= (time() - 48 * 3600);
                }
                ?>
            <td class="muted">
              <?= $lp !== '' ? h(substr($lp, 0, 16)) : '—' ?>
              <?php if ($recentPush): ?>
                <span class="badge" title="Recently updated on the Team sheet (from Extracting Push or email edits)">new from Push</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="num">
              <a class="btn secondary small" href="<?= h($openHref) ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
          <tr class="sheet-search-empty" data-swe-country-search-empty hidden>
            <td colspan="<?= (int) $countryTableCols ?>" class="muted">No countries match your search.</td>
          </tr>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <?php if ($isTeam): ?>
          <p>No sites yet.</p>
          <p class="muted">They appear here when you click Push in Extracting Results.</p>
        <?php elseif ($isAdminAll): ?>
          <p>No mirrored sites yet.</p>
          <p class="muted">They sync from Admin, or open an empty country above and paste / import CSV like Campaign.</p>
        <?php else: ?>
          <p>No sites yet.</p>
          <p class="muted">They appear when Team pushes from Sites with emails - Team (after adding emails).</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    if ($finalOpenerHtml !== '' && $countryRows !== []) {
        echo $finalOpenerHtml;
    }
    ?>
    <script>
    (function () {
      document.querySelectorAll('[data-swe-country-row]').forEach(function (row) {
        row.addEventListener('click', function (e) {
          if (e.target.closest('a, button, input, label, select, textarea')) return;
          var a = row.querySelector('a.extracted-country-link');
          if (a && a.href) window.location.href = a.href;
        });
      });
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
$rowFilter = (string) get('filter'); // Admin only: '', 'new', 'updated'
if ($sweScope !== 'admin' || ($rowFilter !== 'new' && $rowFilter !== 'updated')) {
    $rowFilter = '';
}

// Capture New/Updated signals before marking this country seen (GET open only).
$adminSeenSince = null;
$adminNewOpenCount = 0;
$adminUpdatedOpenCount = 0;
$adminVisitStarted = false;
if ($sweScope === 'admin' && function_exists('swe_admin_visit_since')) {
    $uidVisit = (int) ($sweUser['id'] ?? 0);
    $visitMap = $_SESSION['swe_admin_visit_since'][$uidVisit] ?? null;
    $hadVisit = is_array($visitMap) && array_key_exists($countryName, $visitMap);
    $adminSeenSince = swe_admin_visit_since($sweUser, $countryName, !$hadVisit);
    $adminVisitStarted = !$hadVisit;
    $adminNewOpenCount = swe_admin_count_new_since($countryName, $adminSeenSince);
    $adminUpdatedOpenCount = swe_admin_count_updated_since($countryName, $adminSeenSince);
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && (string) get('export') === ''
        && $adminVisitStarted
        && $adminNewOpenCount > 0) {
        $flashMsg = $adminNewOpenCount . ' new site' . ($adminNewOpenCount === 1 ? '' : 's')
            . ' since your last visit';
        if ($adminUpdatedOpenCount > 0) {
            $flashMsg .= ' · ' . $adminUpdatedOpenCount . ' updated';
        }
        flash('ok', $flashMsg . '.');
    }
    // Mark seen once at visit start (not on every pagination/filter GET).
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && $adminVisitStarted
        && function_exists('swe_admin_mark_country_seen')) {
        swe_admin_mark_country_seen($sweUser, $countryName);
    }
}

$pageNum = max(1, (int) get('p', 1));
$perPage = resolve_sheet_per_page();
$inv = sites_with_emails_inventory_query([
    'country' => $countryName,
    'q' => $q,
    'sent' => $sentFilter,
    'filter' => $rowFilter,
    'since' => ($sweScope === 'admin' && $adminSeenSince !== null) ? $adminSeenSince : '',
], $pageNum, $perPage, $sweScope);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryTotal = count_sites_with_emails_for_country($countryName, $sweScope);
$sentStats = ($sweScope === 'admin') ? count_sites_with_emails_sent_stats($countryName) : null;
$readyToPush = $isTeam ? count_sites_with_emails_ready_to_push($countryName) : 0;
$pushConflicts = $isTeam ? list_sites_with_emails_push_conflict_domains($countryName) : [];
$pushConflictSet = $pushConflicts !== [] ? array_fill_keys($pushConflicts, true) : [];
$pushConflictCount = count($pushConflicts);
$teamCountryFetches = ($isTeam && function_exists('list_email_campaign_fetches_for_source'))
    ? list_email_campaign_fetches_for_source('team', $countryName)
    : [];
$listBase = $sweBase . '&country=' . rawurlencode($countryName);
$listBase = append_sheet_per_page_query($listBase, $perPage);
if ($rowFilter !== '') {
    $listBase .= '&filter=' . rawurlencode($rowFilter);
}
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
    'filter' => $rowFilter,
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
        ['label' => 'Your work', 'href' => 'index.php?page=team_dashboard'],
        ['label' => $sweLabel, 'href' => $sweBase],
        ['label' => $countryName],
    ];
render_breadcrumbs($crumbs);
?>
<div class="topbar">
  <div>
    <?php
    $sweJumpTip = $isTeam
        ? 'Add emails (autosave). Pick another country from this list — you do not need to go back to All countries. Push one site with its row button, or Push all sites that have at least one email.'
        : ($isAdminAll
            ? 'Final archive for this country. Pick another country from this list — you do not need to go back to All countries. Search finds site + emails together.'
            : 'Admin working list for this country. Pick another country from this list — you do not need to go back to All countries. Search finds site + emails together. Clear an email with Backspace (autosave). Remove deletes the whole row.');
    $sweJumpHidden = ['per_page' => $perPage];
    if ($isTeam) {
        $sweJumpHidden['page'] = 'team_sites_emails';
    } else {
        $sweJumpHidden['page'] = 'admin_emails_data';
        $sweJumpHidden['folder'] = (string) $sweFolder;
    }
    render_sheet_country_jump(
        'country',
        $countryName,
        list_sites_with_emails_country_nav($sweScope),
        $sweJumpHidden,
        $sweJumpTip,
        'swe-country-jump',
        $sweLabel . ' country'
    );
    ?>
    <p class="muted">
      <span id="swe_total_label"><?= (int) $countryTotal ?></span> site<?= (int) $countryTotal === 1 ? '' : 's' ?>
      <?= $q !== '' || $sentFilter !== '' || $rowFilter !== '' ? ' · ' . (int) $total . ' shown' : '' ?>
      · <?= (int) $perPage ?> per page
      · up to 4 emails each
      <?php if ($sweScope === 'admin' && ($adminNewOpenCount > 0 || $adminUpdatedOpenCount > 0)): ?>
        · <span class="swe-country-new">+<?= (int) $adminNewOpenCount ?> new</span>
        <?php if ($adminUpdatedOpenCount > 0): ?>
          · <span class="swe-row-chip is-updated"><?= (int) $adminUpdatedOpenCount ?> updated</span>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($sentStats): ?>
        · <span id="swe_unsent_label"><?= (int) $sentStats['unsent'] ?></span> not emailed
        · <span id="swe_sent_label"><?= (int) $sentStats['sent'] ?></span> emailed
      <?php endif; ?>
      <?php if ($isTeam): ?>
        · <span id="swe_ready_label"><?= (int) $readyToPush ?></span> ready to Push
      <?php endif; ?>
    </p>
    <?php
    if ($isTeam && $teamCountryFetches !== [] && function_exists('render_email_campaign_fetch_stamps')) {
        render_email_campaign_fetch_stamps($teamCountryFetches);
    }
    ?>
  </div>
  <div class="actions">
    <?php
    if ($isTeam) {
        render_task_presence('swe_team:' . $countryName, 'Others on Sites with emails · ' . $countryName);
    } elseif ($sweScope === 'admin') {
        render_task_presence('swe_admin:' . $countryName, 'Others on Admin emails · ' . $countryName);
    }
    ?>
    <?php if ($isTeam || $isAdminAll): ?>
    <button type="button" class="btn" data-swe-add-toggle
            title="<?= $isAdminAll
                ? 'Add one site + at least one email · also creates the Admin working-list row'
                : 'Add one site + up to 4 emails (emails optional)' ?>">+ Add site</button>
    <?php endif; ?>
    <?php if ($isAdminAll): ?>
    <a class="btn secondary" href="#swe-bulk-add">Paste / import</a>
    <?php endif; ?>
    <?php if ($isTeam): ?>
    <form method="post" action="<?= h($listBase) ?>" style="display:inline" id="swe-push-form"
          data-show-processing="Pushing sites to Admin…"
          data-conflict-count="<?= (int) $pushConflictCount ?>"
          data-confirm-push-all="<?php
            $pushAllMsg = 'Push ' . countable_label((int) $readyToPush, 'site', 'sites')
                . ' with emails to Sites with emails - Admin?'
                . "\n\nThose rows will leave this Team working copy.";
            if ($pushConflictCount > 0) {
                $pushAllMsg .= "\n\n" . countable_label((int) $pushConflictCount, 'site', 'sites')
                    . ' already exist in Admin — Push will MERGE Team emails into empty Admin slots '
                    . '(existing Admin emails are kept; merge fills blanks only).';
            }
            echo h($pushAllMsg);
          ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="push_to_admin">
      <input type="hidden" name="confirm_overwrite" value="0" id="swe-push-confirm-overwrite">
      <button class="btn" type="submit" id="swe-push-btn" <?= $readyToPush > 0 ? '' : 'disabled' ?>
              title="<?= $readyToPush > 0
                  ? ($pushConflictCount > 0
                      ? 'Push every ready site · ' . (int) $pushConflictCount . ' will merge into Admin'
                      : 'Push every site on this country that has at least one email')
                  : 'Add at least one email on a site first' ?>">
        Push all to Admin
      </button>
    </form>
    <?php endif; ?>
    <?php if ($sweScope === 'admin'): ?>
    <?php render_sheet_tool_menu_open('Copy / Open', 'Copy emails or open sites'); ?>
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
    <?php render_sheet_tool_menu_open('Copy / Open', 'Copy emails or open sites'); ?>
    <button type="button" class="btn secondary" id="swe_copy_emails" data-swe-copy-emails
            data-export-url="<?= h($emailsExportUrl) ?>"
            data-copy-label="all"
            <?= $countryTotal > 0 ? '' : 'disabled' ?>>Copy all emails</button>
    <?php endif; ?>
    <div class="swe-open-group" data-swe-open-group role="group" aria-label="Open sites in new tabs">
      <label class="visually-hidden" for="swe-open-count">How many sites to open</label>
      <select id="swe-open-count" class="swe-open-count" data-swe-open-count title="Open sites on this page (after search)">
        <option value="10" selected>First 10</option>
        <option value="20">First 20</option>
        <option value="30">First 30</option>
        <option value="40">First 40</option>
        <option value="50">First 50</option>
      </select>
      <button type="button" class="btn secondary" data-swe-open-bulk
              title="Open sites from the top of this page in new tabs">
        Open first 10
      </button>
      <button type="button" class="btn secondary" data-swe-open-continue hidden
              title="Open the next batch of sites">
        Open next 10
      </button>
    </div>
    <?php render_sheet_tool_menu_close(); ?>
    <a class="btn secondary" href="<?= h($csvUrl) ?>">Download CSV / Excel</a>
    <a class="btn secondary" href="<?= h($sweBase) ?>">All countries</a>
  </div>
</div>
<p class="help" id="swe_status" role="status" aria-live="polite" hidden></p>
<?php if ($isTeam): ?>
<?= guide_sites_with_emails_team() ?>
<p class="help">
  Edits <strong>autosave</strong>.
  <strong>Push</strong> when a site has at least one email (same as Push all to Admin).
  <?php if ($pushConflictCount > 0): ?>
    <?= h(countable_label((int) $pushConflictCount, 'site', 'sites')) ?> already exist in Admin — Push asks before merging into empty Admin slots.
  <?php endif; ?>
</p>
<?php elseif ($isAdminAll): ?>
<p class="help">
  Neutral duplicate archive (mirror of Admin). No campaign “emailed” marks here.
  Search finds a <strong>site + its emails</strong> together.
  Use <strong>+ Add site</strong>, or paste / import CSV / Excel / TXT below — same as Campaign.
  Each site needs at least one email and also creates the Admin working-list row.
</p>
<?php endif; ?>

<?php if ($sweScope === 'admin'): ?>
<?php
render_sheet_checkpoint_compact(
    'Mark emailed removes the site from this Admin working list after Final has a copy. Final never loses those rows. '
    . 'Order: oldest sites at the top, newest Team pushes at the bottom. '
    . 'Mark emailed: removes that one site from Admin; Final keeps the copy. '
    . 'Mark up to here: removes this site and every site above it from Admin; Final keeps those copies. '
    . 'Remove: also removes from Admin only; Final keeps the archive copy. '
    . 'Admin is the working list from Team Push. Final is the lasting archive.'
);
?>
<?php endif; ?>

<div class="card">
  <div class="invoice-list-toolbar swe-list-toolbar">
    <div>
      <h2 style="margin:0"><?= label_with_info('Sites · Emails', 'Each row is one site with up to 4 emails. Sheets can reach ~100K sites — choose how many rows per page with the Per page filter. Search matches site name or any email on that row. Search filters this page after you pause typing (default 100 rows). Ctrl/Cmd+Enter searches all pages.' . ($sweScope === 'admin' ? ' Use Status and the Actions buttons on each row for emailed / up to here.' : ($isAdmin ? ' Edit or Backspace to clear an email · Remove deletes the complete row.' : ' Paste up to 4 emails at once · autosave · row # shows position · Open highlights until an email is entered · Remove deletes the row.'))) ?></h2>
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
            if ($rowFilter !== '') {
                $href .= '&filter=' . rawurlencode($rowFilter);
            }
            if ($val !== '') {
                $href .= '&sent=' . $val;
            }
            $active = $sentFilter === (string) $val;
            ?>
          <a class="btn small <?= $active ? '' : 'secondary' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
        <span class="swe-filter-sep muted" aria-hidden="true">·</span>
        <?php
        $signalLinks = [
            '' => 'All changes',
            'new' => 'New' . ($adminNewOpenCount > 0 ? ' (' . $adminNewOpenCount . ')' : ''),
            'updated' => 'Updated' . ($adminUpdatedOpenCount > 0 ? ' (' . $adminUpdatedOpenCount . ')' : ''),
        ];
        foreach ($signalLinks as $val => $label):
            $href = $sweBase . '&country=' . rawurlencode($countryName);
            $href = append_sheet_per_page_query($href, $perPage);
            if ($q !== '') {
                $href .= '&q=' . rawurlencode($q);
            }
            if ($sentFilter !== '') {
                $href .= '&sent=' . $sentFilter;
            }
            if ($val !== '') {
                $href .= '&filter=' . rawurlencode($val);
            }
            $active = $rowFilter === (string) $val;
            ?>
          <a class="btn small <?= $active ? '' : 'secondary' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
        <?php if ($sentStats && (int) $sentStats['sent'] > 0): ?>
        <form method="post" action="<?= h($listBase) ?>" class="swe-clear-all-emailed"
              data-swe-clear-all-emailed
              <?= confirm_attrs(
                  'Clear all emailed marks on ' . $countryName . '?'
                  . "\n\nYou can resend and track this Admin sheet from scratch.\n\nFinal archive stays unchanged.",
                  ['title' => 'Clear emailed marks?', 'confirm_label' => 'Clear', 'danger' => true]
              ) ?>>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_all_emailed">
          <input type="hidden" name="q" value="<?= h($q) ?>">
          <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
          <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
          <?php if ($sentFilter !== ''): ?>
          <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
          <?php endif; ?>
          <?php if ($rowFilter !== ''): ?>
          <input type="hidden" name="filter" value="<?= h($rowFilter) ?>">
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
             title="Filters this page after you pause typing · Enter = next match · Ctrl/Cmd+Enter = search all pages">
      <span class="sheet-search-meta muted" data-swe-row-search-meta hidden></span>
    </label>
    <?php if ($isTeam || $isAdminAll): ?>
    <button type="button" class="btn small" data-swe-add-toggle
            title="<?= $isAdminAll
                ? 'Add one site + at least one email · also creates the Admin working-list row'
                : 'Add one site + up to 4 emails (emails optional)' ?>">+ Add site</button>
    <?php endif; ?>
    <?php
    render_sheet_edit_toolbar($listBase, sheet_history_key('swe', $sweScope . ':' . $countryName), [
        'q' => $q,
        'p' => $pageNum,
        'sent' => $sentFilter,
        'filter' => $rowFilter,
    ]);
    ?>
  </div>

  <div class="table-wrap swe-sheet-wrap">
    <table class="swe-table swe-sheet-table is-dense sheet-cards-mobile<?= $sweScope === 'admin' ? ' is-admin-checkpoint' : '' ?>"
           id="swe-table"
           data-swe-country="<?= h($countryName) ?>"
           <?= $isTeam ? 'data-swe-open-track="1"' : '' ?>>
      <thead>
        <tr>
          <?php render_sheet_select_th(); ?>
          <?php if ($isTeam): ?>
          <th class="swe-col-num" scope="col" title="Row number on this page">#</th>
          <?php endif; ?>
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
      <?php if ($isTeam || $isAdminAll): ?>
          <tr id="swe-add-row" class="sheet-add-row" hidden data-swe-emails>
            <td class="swe-td-check sheet-td-check" data-label="Select"></td>
            <?php if ($isTeam): ?>
            <td class="swe-td-num swe-col-num" data-label="Row"><span class="muted">—</span></td>
            <?php endif; ?>
            <td class="swe-td-site" data-label="Site">
              <form method="post" action="<?= h($listBase) ?>" class="swe-row-form swe-add-form" id="swe-add-form"
                    autocomplete="off" data-show-processing="Adding site…">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_row">
                <input type="hidden" name="site_id" value="0">
                <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
              </form>
              <label class="visually-hidden" for="swe_add_domain">Site</label>
              <input id="swe_add_domain" class="swe-domain" form="swe-add-form" name="domain" required
                     placeholder="example.com" spellcheck="false" autocomplete="off" aria-label="Site">
            </td>
            <td class="swe-td-lang" data-label="Language"><span class="swe-cell-text muted">—</span></td>
            <td class="swe-td-email" data-label="Email 1">
              <?= render_clearable_email_input('email1', '', ['id' => 'swe_add_e1', 'swe' => true, 'form' => 'swe-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 1']) ?>
            </td>
            <td class="swe-td-email" data-label="Email 2">
              <?= render_clearable_email_input('email2', '', ['id' => 'swe_add_e2', 'swe' => true, 'form' => 'swe-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 2']) ?>
            </td>
            <td class="swe-td-email" data-label="Email 3">
              <?= render_clearable_email_input('email3', '', ['id' => 'swe_add_e3', 'swe' => true, 'form' => 'swe-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 3']) ?>
            </td>
            <td class="swe-td-email" data-label="Email 4">
              <?= render_clearable_email_input('email4', '', ['id' => 'swe_add_e4', 'swe' => true, 'form' => 'swe-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 4']) ?>
            </td>
            <td class="swe-td-status" data-label="Status"><span class="swe-status-badge is-open">New</span></td>
            <td class="swe-td-actions" data-label="Actions">
              <div class="swe-row-actions">
                <button class="btn small" type="submit" form="swe-add-form">Add row</button>
                <button class="btn secondary small" type="button" data-swe-add-cancel>Cancel</button>
              </div>
            </td>
          </tr>
      <?php endif; ?>
      <?php
      $rowNumBase = max(0, ($pageNum - 1) * $perPage);
      $rowLoop = 0;
      foreach ($rows as $s):
          $rowLoop++;
          $rowNum = $rowNumBase + $rowLoop;
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
          $willOverwrite = $isTeam && isset($pushConflictSet[$domain]);
          $isEmailed = $sweScope === 'admin' && (int) ($s['email_sent'] ?? 0) === 1;
          $openHost = function_exists('extract_host_candidate')
              ? extract_host_candidate($domain)
              : strtolower(trim($domain));
          $openRoot = function_exists('to_root_domain') ? to_root_domain($openHost) : $openHost;
          $siteOpenable = $openRoot !== ''
              && (!function_exists('is_root_domain') || is_root_domain($openRoot));
          $siteOpenUrl = $siteOpenable ? ('https://' . $openRoot) : '';
          $hay = mb_strtolower($domain . ' ' . $lang . ' ' . $e1 . ' ' . $e2 . ' ' . $e3 . ' ' . $e4);
          $rowSignal = ($sweScope === 'admin' && function_exists('swe_admin_row_signal'))
              ? swe_admin_row_signal($s, $adminSeenSince)
              : '';
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
            data-row-num="<?= (int) $rowNum ?>"
            data-row-signal="<?= h($rowSignal) ?>"
            class="<?= $isEmailed ? 'swe-row-emailed' : '' ?><?= $rowSignal !== '' ? ' swe-row-' . h($rowSignal) : '' ?>">
          <?php render_sheet_select_td($sid, $domain); ?>
          <?php if ($isTeam): ?>
          <td class="swe-td-num" data-label="#">
            <span class="swe-row-num" title="Site #<?= (int) $rowNum ?>"><?= (int) $rowNum ?></span>
          </td>
          <?php endif; ?>
          <td class="swe-td-site" data-label="Site">
            <form id="<?= h($formId) ?>" method="post" action="<?= h($listBase) ?>" class="swe-row-form" data-swe-save>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_row">
              <input type="hidden" name="site_id" value="<?= $sid ?>">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
              <?php if ($rowFilter !== ''): ?>
              <input type="hidden" name="filter" value="<?= h($rowFilter) ?>">
              <?php endif; ?>
            </form>
            <div class="swe-site-cell<?= $siteOpenable ? '' : ' is-invalid-site' ?>">
              <label class="visually-hidden" for="swe-domain-<?= $sid ?>">Site</label>
              <input id="swe-domain-<?= $sid ?>" class="swe-domain" form="<?= h($formId) ?>" name="domain"
                     value="<?= h($domain) ?>" required spellcheck="false" autocomplete="off" aria-label="Site"
                     title="<?= h($domain) ?>"
                     data-swe-domain>
              <?php if ($rowSignal === 'new'): ?>
              <span class="swe-row-chip is-new" title="Added since your last visit">New</span>
              <?php elseif ($rowSignal === 'updated'): ?>
              <span class="swe-row-chip is-updated" title="Emails updated since your last visit">Updated</span>
              <?php endif; ?>
              <?php if ($siteOpenable): ?>
              <a class="swe-open-site" data-swe-open-site
                 href="<?= h($siteOpenUrl) ?>" target="_blank" rel="noopener noreferrer"
                 title="Open <?= h($openRoot) ?> in a new tab"
                 aria-label="Open <?= h($openRoot) ?> in a new tab">Open</a>
              <?php else: ?>
              <a class="swe-open-site is-disabled" data-swe-open-site href="#" tabindex="-1" aria-disabled="true"
                 title="Fix the site name (needs a valid domain) before opening"
                 aria-label="Site name invalid — cannot open">Open</a>
              <?php endif; ?>
            </div>
          </td>
          <td class="swe-td-lang" data-label="Language"><span class="swe-cell-text"><?= h($lang) ?></span></td>
          <td class="swe-td-email" data-label="Email 1">
            <?= render_clearable_email_input('email1', $e1, ['swe' => true, 'form' => $formId, 'placeholder' => '+', 'aria_label' => 'Clear email 1']) ?>
          </td>
          <td class="swe-td-email" data-label="Email 2">
            <?= render_clearable_email_input('email2', $e2, ['swe' => true, 'form' => $formId, 'placeholder' => '+', 'aria_label' => 'Clear email 2']) ?>
          </td>
          <td class="swe-td-email" data-label="Email 3">
            <?= render_clearable_email_input('email3', $e3, ['swe' => true, 'form' => $formId, 'placeholder' => '+', 'aria_label' => 'Clear email 3']) ?>
          </td>
          <td class="swe-td-email" data-label="Email 4">
            <?= render_clearable_email_input('email4', $e4, ['swe' => true, 'form' => $formId, 'placeholder' => '+', 'aria_label' => 'Clear email 4']) ?>
          </td>
          <td class="swe-td-status" data-label="Status">
            <span class="swe-status-badge <?= h($statusClass) ?>" data-swe-status><?= h($statusLabel) ?></span>
          </td>
          <td class="swe-td-actions" data-label="Actions">
            <div class="swe-row-actions">
              <?php if ($sweScope === 'admin'): ?>
              <button class="btn small <?= $isEmailed ? 'secondary' : '' ?>" type="button"
                      data-sheet-action="mark" data-site-id="<?= $sid ?>"
                      data-email-sent="<?= $isEmailed ? '0' : '1' ?>" data-domain="<?= h($domain) ?>"
                      title="<?= $isEmailed ? 'Clear emailed mark on this site only' : 'Mark emailed · remove from Admin (Final keeps a copy)' ?>"
                      aria-label="<?= $isEmailed ? 'Clear emailed mark on this site only' : 'Mark emailed · remove from Admin (Final keeps a copy)' ?>">
                <?= $isEmailed ? 'Undo' : 'Emailed' ?>
              </button>
              <?php render_sheet_row_more_open(); ?>
              <button class="btn secondary small" type="button"
                      data-sheet-action="upto" data-site-id="<?= $sid ?>" data-domain="<?= h($domain) ?>"
                      title="Mark emailed up to here · remove those rows from Admin (Final keeps copies)"
                      data-confirm="Mark emailed UP TO <?= h($domain) ?>?&#10;&#10;Every older site from the top through this row will be REMOVED from Admin.&#10;&#10;Final archive keeps those copies.">
                Up to here
              </button>
              <button class="btn secondary small" type="button"
                      data-sheet-action="clear-upto" data-site-id="<?= $sid ?>" data-domain="<?= h($domain) ?>"
                      title="Clear emailed marks from the top through this site"
                      data-confirm="Clear emailed UP TO <?= h($domain) ?>?&#10;&#10;Every older emailed site from the top through this row will be unmarked.&#10;&#10;Final archive stays unchanged.">
                Clear up to
              </button>
              <?php endif; ?>
              <?php if ($isTeam):
                  $pushConfirm = $willOverwrite
                      ? 'Push ' . $domain . ' to Admin?' . "\n\n"
                        . 'This site ALREADY EXISTS in Admin. Push will MERGE Team emails into empty Admin slots '
                        . '(existing Admin emails are kept).' . "\n\nThis row will leave the Team working copy."
                      : 'Push ' . $domain . ' to Admin?' . "\n\nThis row will leave the Team working copy.";
                  ?>
              <button class="btn small" type="button"
                      data-sheet-action="push" data-site-id="<?= $sid ?>" data-domain="<?= h($domain) ?>"
                      data-swe-push-btn <?= $hasEmail ? '' : 'disabled' ?>
                      data-admin-conflict="<?= $willOverwrite ? '1' : '0' ?>"
                      data-confirm="<?= h($pushConfirm) ?>"
                      data-confirm-title="Push to Admin?"
                      data-confirm-label="Push"
                      title="<?= $hasEmail
                          ? ($willOverwrite ? 'Merge Team emails into empty Admin slots for this site' : 'Push this site to Admin')
                          : 'Add at least one email first' ?>">Push</button>
              <?php render_sheet_row_more_open(); ?>
              <?php endif; ?>
              <?php if ($sweScope !== 'admin' && !$isTeam) {
                  render_sheet_row_more_open();
              } ?>
              <button class="btn secondary small" type="button"
                      data-sheet-action="remove" data-site-id="<?= $sid ?>" data-domain="<?= h($domain) ?>"
                      data-confirm="Remove complete row for <?= h($domain) ?>?">Remove</button>
              <?php render_sheet_row_more_close(); ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
  render_sheet_shared_row_action_forms($listBase, 'swe', [
      'q' => $q,
      'p' => $pageNum,
      'sent' => $sentFilter,
      'filter' => $rowFilter,
      'mark' => $sweScope === 'admin',
      'push' => $isTeam,
      'remove' => true,
  ]);
  ?>
  <p class="help sheet-search-empty" data-swe-row-search-empty hidden>
    No search matches on this page. Try Ctrl/Cmd+Enter to search all pages.
  </p>

  <?php if (!$rows && $q === '' && $sentFilter === '' && $rowFilter === ''): ?>
  <div class="empty-state" id="swe-empty-state">
    <?php if ($isTeam): ?>
      <p>No sites in this country yet.</p>
      <p class="muted">Push from Extracting Results, or add one site here. Emails are optional until you Push.</p>
      <p class="actions" style="justify-content:center;margin-top:0.75rem">
        <button type="button" class="btn" data-swe-add-toggle>+ Add site</button>
      </p>
    <?php elseif ($isAdminAll): ?>
      <p>No mirrored sites in this country yet.</p>
      <p class="muted">They sync here from Admin, or add a site / paste / import a CSV like Campaign. Each site needs at least one email (also creates the Admin working-list row).</p>
      <p class="actions" style="justify-content:center;margin-top:0.75rem">
        <button type="button" class="btn" data-swe-add-toggle>+ Add site</button>
        <a class="btn secondary" href="#swe-bulk-add">Paste / import file</a>
      </p>
    <?php else: ?>
      <p>No sites in this country yet.</p>
      <p class="muted">Waiting for Team to Push.</p>
    <?php endif; ?>
  </div>
  <?php elseif (!$rows && ($q !== '' || $sentFilter !== '' || $rowFilter !== '')): ?>
  <div class="empty-state">
    <?php if ($rowFilter === 'new'): ?>
      <p>No new sites<?= $q !== '' ? ' matching this search' : '' ?> since your last visit.</p>
    <?php elseif ($rowFilter === 'updated'): ?>
      <p>No updated sites<?= $q !== '' ? ' matching this search' : '' ?> since your last visit.</p>
    <?php elseif ($sentFilter === '0'): ?>
      <p>No unmarked sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
      <p class="muted">New Team pushes appear here until you mark them emailed.</p>
    <?php elseif ($sentFilter === '1'): ?>
      <p>No emailed sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
      <p class="muted">Use “Mark emailed” or “Mark up to here” while working the campaign.</p>
    <?php else: ?>
      <p>No search matches.</p>
      <p class="muted">Try a different search, or clear the filter.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="actions is-spread">
    <div class="actions actions-compact">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <?php if ($rows || $q !== ''): ?>
        <span class="muted" data-sheet-page-status
              data-page="<?= (int) $pageNum ?>"
              data-pages="<?= (int) $pages ?>"
              data-on-page="<?= (int) count($rows) ?>"
              data-total="<?= (int) $total ?>">Page <?= $pageNum ?> / <?= $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?></span>
      <?php endif; ?>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
      <?php
      render_sheet_per_page_filter([
          'page' => $isAdmin ? 'admin_emails_data' : 'team_sites_emails',
          'folder' => $sweFolder,
          'country' => $countryName,
          'q' => $q,
          'sent' => $sentFilter,
          'filter' => $rowFilter,
      ], $perPage);
      ?>
    </div>
    <?php if ($countryTotal > 0): ?>
    <?php
    $sweRemoveAllBody = 'This removes ' . countable_label((int) $countryTotal, 'site', 'sites')
        . ' from ' . $countryName . '.';
    if ($isTeam) {
        $sweRemoveAllBody .= "\n\nSites with emails – Team only.\nExtracting sites are not removed.";
    } elseif ($sweScope === 'admin') {
        $sweRemoveAllBody .= "\n\nAdmin working list only. Final archive stays.";
    } else {
        $sweRemoveAllBody .= "\n\nFinal archive for this country.";
    }
    $sweRemoveAllBody .= "\n\nThis cannot be undone.";
    ?>
    <form method="post" action="<?= h($listBase) ?>"
          data-show-processing="Removing all sites…"
          <?= confirm_attrs($sweRemoveAllBody, [
              'title' => 'Remove all sites?',
              'confirm_label' => 'Remove',
              'danger' => true,
          ]) ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove_all">
      <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
      <button class="btn danger" type="submit">Remove all</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAdminAll): ?>
<div class="card" style="margin-top:1rem" id="swe-bulk-add">
  <h2><?= label_with_info('Add many sites (paste or file)', 'Admin bulk entry on Final. Paste 1000+ lines, or import CSV / Excel (.xlsx) / TXT. One line or row per site: site + up to 4 emails. Each site needs at least one email and also creates the Admin working-list row. Identical emails are skipped; different emails replace the existing row.') ?></h2>
  <p class="help">
    Columns: <strong>Site name, Email 1, Email 2, Email 3, Email 4</strong>
    (comma, tab, or semicolon). Header row is optional and skipped.
    Extra columns after email 4 are ignored. Each site needs at least one email.
    Built for large lists — paste or upload thousands of rows at once.
    Same formats as Campaign. Lines with no email are skipped.
  </p>

  <form method="post" action="<?= h($listBase) ?>" style="margin-top:0.85rem"
        data-show-processing="Adding pasted sites…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="paste">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
    <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
    <label for="swe_paste_text">Paste sites + emails</label>
    <textarea id="swe_paste_text" name="paste_text" class="inventory-box camp-bulk-paste" rows="14"
              placeholder="Site name, Email 1, Email 2, Email 3, Email 4&#10;example.com, hello@example.com, sales@example.com&#10;other.org, contact@other.org&#10;shop.de info@shop.de support@shop.de"></textarea>
    <p class="actions" style="margin-top:0.75rem">
      <button class="btn" type="submit">Add pasted rows</button>
    </p>
  </form>

  <hr class="camp-bulk-divider">

  <form method="post" action="<?= h($listBase) ?>" enctype="multipart/form-data"
        data-show-processing="Importing file…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_file">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
    <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
    <label for="swe_import_file">Import from CSV, Excel, or TXT</label>
    <input id="swe_import_file" type="file" name="import_file" required
           accept=".csv,.txt,.tsv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
    <p class="help" style="margin-top:0.35rem">
      Accepts <strong>.csv</strong>, <strong>.xlsx</strong> (Excel), and <strong>.txt</strong> / <strong>.tsv</strong>.
      First columns = site + up to 4 emails (extra columns ignored). Old <code>.xls</code> → save as CSV or <code>.xlsx</code> first.
    </p>
    <p class="actions" style="margin-top:0.75rem">
      <button class="btn" type="submit">Import file into sheet</button>
    </p>
  </form>
</div>
<?php endif; ?>

<?php if ($countryTotal > 0): ?>
<div class="card" id="remove-by-list" style="margin-top:1rem">
  <h2><?= label_with_info('Remove by list', 'Paste site names or upload a 1-column CSV. Matching rows in this country are removed.') ?></h2>
  <p class="help">Paste site names (or 1-column CSV) to remove those rows from <?= h($countryName) ?>.</p>
  <?php
    $sweListedBody = 'Remove matching sites from ' . $countryName . '?';
    if ($isTeam) {
        $sweListedBody .= "\n\nSites with emails – Team only. Extracting sites are not removed.";
    }
    $sweListedBody .= "\n\nThis cannot be undone.";
    ?>
  <form method="post" action="<?= h($listBase) ?>#remove-by-list" enctype="multipart/form-data"
        data-show-processing="Removing listed sites…"
        <?= confirm_attrs($sweListedBody, [
            'title' => 'Remove listed sites?',
            'confirm_label' => 'Remove',
            'danger' => true,
        ]) ?>>
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
<script src="<?= h(script_asset_url('js/sheet-select-undo.js')) ?>" defer></script>
<script src="<?= h(script_asset_url('js/sites-with-emails.js')) ?>" defer></script>
<?php
render_footer($swePanel);
return;
