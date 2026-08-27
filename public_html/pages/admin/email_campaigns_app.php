<?php
/**
 * Admin · Emails data · Email campaign data
 * Project → many country sheets (Admin adds only the countries they need).
 * Communication Team gets one search bar per project across all its countries.
 *
 * Expects: $user, $base (Emails data hub URL)
 */
ensure_email_campaign_schema();
ensure_sites_with_emails_schema();
seed_countries_if_empty(db());

$campBase = $base . '&folder=email_campaigns';
$sheetId = isset($sheetId) ? (int) $sheetId : (int) get('sheet');
$projectIdParam = (int) get('project');
$countryParam = (string) get('country');

// Open by country name shortcut (optionally scoped to a project)
if ($sheetId < 1 && $countryParam !== '') {
    $byCountry = get_email_campaign_sheet_by_country(
        $countryParam,
        $projectIdParam > 0 ? $projectIdParam : null
    );
    if ($byCountry) {
        redirect($campBase . '&sheet=' . (int) $byCountry['id']);
    }
}

// --- Sheet detail (site + emails, same workflow as Sites with emails) ---
if ($sheetId > 0) {
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        flash('error', 'Email sheet not found.');
        redirect($campBase);
    }
    $sheetCountry = email_campaign_sheet_country($sheet);
    $canon = resolve_canonical_country($sheetCountry);
    if ($canon) {
        $sheetCountry = $canon['name'];
    }

    $q = trim((string) get('q'));
    $sentFilter = (string) get('sent'); // '', '0' (not emailed), '1' (emailed)
    if ($sentFilter !== '0' && $sentFilter !== '1') {
        $sentFilter = '';
    }
    $batchFilter = max(0, (int) get('batch'));
    $pageNum = max(1, (int) get('p', 1));
    $perPage = resolve_sheet_per_page();

    if ((string) get('export') === 'domains') {
        $sentExport = (string) get('sent');
        if ($sentExport !== '0' && $sentExport !== '1') {
            $sentExport = null;
        }
        stream_email_campaign_domains_plain($sheetId, $sentExport);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $returnQ = trim((string) post('q'));
        $returnP = max(1, (int) post('p', 1));
        $returnSent = (string) post('sent');
        if ($returnSent !== '0' && $returnSent !== '1') {
            $returnSent = '';
        }
        $returnPerPage = resolve_sheet_per_page();
        $back = $campBase . '&sheet=' . $sheetId;
        if ($returnQ !== '') {
            $back .= '&q=' . rawurlencode($returnQ);
        }
        if ($returnSent !== '') {
            $back .= '&sent=' . $returnSent;
        }
        $returnBatch = max(0, (int) post('batch'));
        if ($returnBatch > 0) {
            $back .= '&batch=' . $returnBatch;
        }
        $back = append_sheet_per_page_query($back, $returnPerPage);
        if ($returnP > 1) {
            $back .= '&p=' . $returnP;
        }
        $wantsJson = (string) post('ajax') === '1'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $histKey = function_exists('sheet_history_key')
            ? sheet_history_key('campaign', (string) $sheetId)
            : ('campaign:' . $sheetId);
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
        try {
            if ($action === 'save_row') {
                $rowId = (int) post('site_id');
                $emails = [
                    (string) post('email1'),
                    (string) post('email2'),
                    (string) post('email3'),
                    (string) post('email4'),
                ];
                if ($rowId > 0) {
                    $result = save_email_campaign_row($sheetId, $rowId, (string) post('domain'), $emails, $user);
                } else {
                    $result = upsert_email_campaign_row($sheetId, (string) post('domain'), $emails);
                }
                if ($wantsJson) {
                    $jsonOut($result + [
                        'site_count' => count_email_campaign_rows($sheetId),
                    ], !empty($result['ok']) ? 200 : 400);
                }
                if (empty($result['ok'])) {
                    flash('error', (string) ($result['error'] ?? 'Could not save.'));
                } else {
                    flash('ok', !empty($result['row_deleted'])
                        ? 'Removed ' . (string) ($result['domain'] ?? 'site') . ' (no emails left).'
                        : 'Saved ' . (string) ($result['domain'] ?? 'site') . '.');
                }
                redirect($back);
            }
            if ($action === 'remove_site') {
                $rowId = (int) post('site_id');
                $del = delete_email_campaign_row($sheetId, $rowId, true, $user);
                if ($wantsJson) {
                    $jsonOut([
                        'ok' => !empty($del['ok']),
                        'error' => $del['error'] ?? null,
                        'domain' => $del['domain'] ?? '',
                        'site_count' => count_email_campaign_rows($sheetId),
                    ] + count_email_campaign_sent_stats($sheetId), !empty($del['ok']) ? 200 : 404);
                }
                flash($del['ok'] ? 'ok' : 'error', $del['ok']
                    ? 'Removed ' . (string) ($del['domain'] ?? 'site') . '.'
                    : (string) ($del['error'] ?? 'Could not remove row.'));
                redirect($back);
            }
            if ($action === 'remove_selected') {
                $ids = function_exists('parse_posted_id_list')
                    ? parse_posted_id_list(post('site_ids'))
                    : [];
                $del = delete_email_campaign_rows_by_ids($sheetId, $ids, $user);
                $left = count_email_campaign_rows($sheetId);
                if ($wantsJson) {
                    $jsonOut(
                        $del + [
                            'site_count' => $left,
                        ] + count_email_campaign_sent_stats($sheetId),
                        !empty($del['ok']) ? 200 : 400
                    );
                }
                flash($del['ok'] ? 'ok' : 'error', $del['ok']
                    ? 'Removed ' . (int) $del['count'] . ' selected site' . ((int) $del['count'] === 1 ? '' : 's') . '.'
                    : (string) ($del['error'] ?? 'Could not remove selected rows.'));
                redirect($back);
            }
            if ($action === 'undo_last' || $action === 'redo_last') {
                $result = $action === 'redo_last'
                    ? sheet_history_apply_redo($histKey)
                    : sheet_history_apply_undo($histKey);
                if ($wantsJson) {
                    $jsonOut($result + count_email_campaign_sent_stats($sheetId), !empty($result['ok']) ? 200 : 400);
                }
                flash($result['ok'] ? 'ok' : 'error', $result['ok']
                    ? ($action === 'redo_last' ? 'Redid last change.' : 'Undid last change.')
                    : (string) ($result['error'] ?? 'Could not undo/redo.'));
                redirect($back);
            }
            // Campaign emailed progress — same rule as Sites with emails - Admin, per sheet.
            if ($action === 'mark_email_sent') {
                $rowId = (int) post('site_id');
                $sent = (string) post('email_sent') === '1';
                $result = set_email_campaign_row_email_sent($sheetId, $rowId, $sent, $user);
                if ($wantsJson) {
                    $jsonOut(
                        $result + count_email_campaign_sent_stats($sheetId),
                        !empty($result['ok']) ? 200 : 400
                    );
                }
                if (empty($result['ok'])) {
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
            if ($action === 'mark_emailed_up_to') {
                $rowId = (int) post('site_id');
                $result = mark_email_campaign_emailed_up_to(
                    $sheetId,
                    $rowId,
                    (string) post('batch_name'),
                    $user
                );
                if ($wantsJson) {
                    $jsonOut(
                        $result + count_email_campaign_sent_stats($sheetId),
                        !empty($result['ok']) ? 200 : 400
                    );
                }
                if (empty($result['ok'])) {
                    flash('error', (string) ($result['error'] ?? 'Could not mark checkpoint.'));
                } else {
                    flash(
                        'ok',
                        'Marked emailed up to ' . (string) ($result['domain'] ?? 'site')
                        . ' · ' . (int) ($result['marked'] ?? 0) . ' newly marked'
                        . ((string) ($result['batch_name'] ?? '') !== ''
                            ? ' · batch “' . (string) $result['batch_name'] . '”'
                            : '')
                        . '.'
                    );
                }
                redirect($back);
            }
            if ($action === 'clear_emailed_up_to') {
                $rowId = (int) post('site_id');
                $result = clear_email_campaign_emailed_up_to($sheetId, $rowId);
                if ($wantsJson) {
                    $jsonOut(
                        $result + count_email_campaign_sent_stats($sheetId),
                        !empty($result['ok']) ? 200 : 400
                    );
                }
                if (empty($result['ok'])) {
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
            if ($action === 'clear_all_emailed') {
                $result = clear_all_email_campaign_emailed($sheetId);
                if ($wantsJson) {
                    $jsonOut(
                        $result + count_email_campaign_sent_stats($sheetId),
                        !empty($result['ok']) ? 200 : 400
                    );
                }
                if (empty($result['ok'])) {
                    flash('error', (string) ($result['error'] ?? 'Could not clear emailed marks.'));
                } else {
                    flash(
                        'ok',
                        'Cleared all emailed marks on ' . $sheetCountry
                        . ' · ' . (int) ($result['cleared'] ?? 0) . ' sites.'
                        . ' Ready to resend and track again.'
                    );
                }
                redirect($back);
            }
            if ($action === 'paste') {
                $result = paste_email_campaign_rows($sheetId, (string) post('paste_text'));
                $msg = 'Added to sheet: '
                    . (int) $result['added'] . ' new, ' . (int) $result['updated'] . ' updated';
                if ((int) ($result['skipped_duplicate'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_duplicate'] . ' duplicate domain(s) skipped';
                }
                if ((int) ($result['skipped_excluded'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_excluded'] . ' previously removed (not re-added)';
                }
                if ((int) ($result['skipped_emails'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_emails'] . ' previously removed email'
                        . ((int) $result['skipped_emails'] === 1 ? '' : 's') . ' stripped';
                }
                if ((int) ($result['skipped'] ?? 0) > 0
                    && (int) ($result['skipped_excluded'] ?? 0) < 1
                    && (int) ($result['skipped_emails'] ?? 0) < 1
                    && (int) ($result['skipped_duplicate'] ?? 0) < 1) {
                    $msg .= ', ' . (int) $result['skipped'] . ' skipped';
                } elseif ((int) ($result['skipped'] ?? 0)
                    > ((int) ($result['skipped_excluded'] ?? 0) + (int) ($result['skipped_duplicate'] ?? 0))) {
                    $otherSkip = (int) $result['skipped']
                        - (int) ($result['skipped_excluded'] ?? 0)
                        - (int) ($result['skipped_duplicate'] ?? 0);
                    if ($otherSkip > 0) {
                        $msg .= ', ' . $otherSkip . ' other skipped';
                    }
                }
                $msg .= '.';
                if ($result['errors'] !== []) {
                    $msg .= ' Issues: ' . implode('; ', array_slice($result['errors'], 0, 8));
                    flash('error', $msg);
                } else {
                    flash('ok', $msg);
                }
                // After bulk add, jump to last page so new rows are visible.
                $totalAfter = count_email_campaign_rows($sheetId);
                $lastPage = max(1, (int) ceil($totalAfter / $perPage));
                redirect($campBase . '&sheet=' . $sheetId . '&p=' . $lastPage);
            }
            if ($action === 'import_file') {
                $result = import_email_campaign_rows_from_upload($sheetId, $_FILES['import_file'] ?? null);
                $msg = 'Imported file into sheet: '
                    . (int) $result['added'] . ' new, ' . (int) $result['updated'] . ' updated';
                if ((int) ($result['skipped_duplicate'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_duplicate'] . ' duplicate domain(s) skipped';
                }
                if ((int) ($result['skipped_excluded'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_excluded'] . ' previously removed (not re-added)';
                }
                if ((int) ($result['skipped_emails'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_emails'] . ' previously removed email'
                        . ((int) $result['skipped_emails'] === 1 ? '' : 's') . ' stripped';
                }
                if ((int) ($result['skipped'] ?? 0) > 0
                    && (int) ($result['skipped_excluded'] ?? 0) < 1
                    && (int) ($result['skipped_duplicate'] ?? 0) < 1) {
                    $msg .= ', ' . (int) $result['skipped'] . ' skipped';
                }
                $msg .= ' · ' . (int) ($result['lines'] ?? 0) . ' data line(s).';
                if ($result['errors'] !== []) {
                    $msg .= ' Issues: ' . implode('; ', array_slice($result['errors'], 0, 8));
                    flash('error', $msg);
                } else {
                    flash('ok', $msg);
                }
                $totalAfter = count_email_campaign_rows($sheetId);
                $lastPage = max(1, (int) ceil($totalAfter / $perPage));
                redirect($campBase . '&sheet=' . $sheetId . '&p=' . $lastPage);
            }
            if ($action === 'import') {
                $sourceRaw = (string) post('source');
                $source = match ($sourceRaw) {
                    'admin' => 'admin',
                    'team' => 'team',
                    default => 'admin_all',
                };
                // Skip identical domain+emails; replace when emails differ; add new domains.
                // Team/Admin/Final source rows are never deleted.
                $result = import_email_campaign_sheet_from_swe($sheetId, $source, $sheetCountry, 'replace');
                $label = match ($source) {
                    'team' => 'Team',
                    'admin' => 'Admin',
                    default => 'Final',
                };
                $msg = 'Imported into ' . $sheetCountry . ' from ' . $label . ': '
                    . (int) $result['imported'] . ' new, ' . (int) $result['updated'] . ' updated';
                if ((int) ($result['skipped_duplicate'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_duplicate'] . ' duplicate(s) skipped';
                }
                if ((int) ($result['skipped_existing'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_existing'] . ' already on sheet';
                }
                if ((int) ($result['skipped_excluded'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_excluded'] . ' previously removed (not re-added)';
                }
                if ((int) ($result['skipped_empty'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_empty'] . ' skipped (no emails)';
                }
                $msg .= '.';
                if ($source === 'team') {
                    $msg .= ' Team sheet marked fetched to '
                        . email_campaign_sheet_project_name($sheet)
                        . ' (Team data stayed).';
                }
                flash('ok', $msg);
                $totalAfter = count_email_campaign_rows($sheetId);
                $lastPage = max(1, (int) ceil($totalAfter / $perPage));
                redirect($campBase . '&sheet=' . $sheetId . '&p=' . $lastPage);
            }
            if ($action === 'fill_gaps') {
                $result = fill_email_campaign_gaps_from_archives($sheetId, $sheetCountry);
                $n = (int) ($result['would_add'] ?? $result['imported'] ?? 0);
                $u = (int) ($result['would_update'] ?? $result['updated'] ?? 0);
                $msg = 'Filled gaps from Final + Admin into ' . $sheetCountry . ': '
                    . $n . ' new, ' . $u . ' updated';
                if ((int) ($result['skipped_excluded'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_excluded'] . ' previously removed (not re-added)';
                }
                if ((int) ($result['skipped_empty'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped_empty'] . ' skipped (no emails)';
                }
                $msg .= '. Admin and Final were not changed. Campaign emailed marks stayed on this sheet.';
                flash('ok', $msg);
                $totalAfter = count_email_campaign_rows($sheetId);
                $lastPage = max(1, (int) ceil($totalAfter / $perPage));
                redirect($campBase . '&sheet=' . $sheetId . '&p=' . $lastPage);
            }
            if ($action === 'allow_excluded_domain') {
                $domain = (string) post('domain');
                $ok = clear_email_campaign_domain_exclusion($sheetId, $domain);
                if ($wantsJson) {
                    $jsonOut([
                        'ok' => $ok,
                        'domain' => normalize_email_campaign_domain($domain),
                        'error' => $ok ? null : 'That site was not on the excluded list.',
                        'message' => $ok
                            ? ('Allowed “' . normalize_email_campaign_domain($domain) . '” again.')
                            : 'That site was not on the excluded list.',
                    ], $ok ? 200 : 404);
                }
                if ($ok) {
                    flash(
                        'ok',
                        'Allowed “' . normalize_email_campaign_domain($domain) . '” again '
                        . '(including its previously removed emails). '
                        . 'Import, paste, and + Add can add it if it has emails.'
                    );
                } else {
                    flash('error', 'That site was not on the excluded list.');
                }
                redirect($back . '#camp-excluded');
            }
            if ($action === 'allow_excluded_email') {
                $domain = (string) post('domain');
                $email = (string) post('email');
                $ok = clear_email_campaign_email_exclusion($sheetId, $domain, $email);
                $domLabel = normalize_email_campaign_domain($domain);
                $emLabel = function_exists('normalize_email_value')
                    ? normalize_email_value($email)
                    : strtolower(trim($email));
                if ($wantsJson) {
                    $jsonOut([
                        'ok' => $ok,
                        'domain' => $domLabel,
                        'email' => $emLabel,
                        'error' => $ok ? null : 'That email was not on the excluded list.',
                        'message' => $ok
                            ? ('Allowed “' . $emLabel . '” on ' . $domLabel . ' again.')
                            : 'That email was not on the excluded list.',
                    ], $ok ? 200 : 404);
                }
                if ($ok) {
                    flash(
                        'ok',
                        'Allowed “' . $emLabel . '” on ' . $domLabel . ' again. '
                        . 'Import, paste, and + Add can include that email.'
                    );
                } else {
                    flash('error', 'That email was not on the excluded list.');
                }
                redirect($back . '#camp-excluded');
            }
            if ($action === 'allow_excluded_emails_for_domain') {
                $domain = (string) post('domain');
                $domLabel = normalize_email_campaign_domain($domain);
                $n = clear_email_campaign_email_exclusions_for_domain($sheetId, $domain);
                $ok = $n > 0;
                if ($wantsJson) {
                    $jsonOut([
                        'ok' => $ok,
                        'domain' => $domLabel,
                        'cleared' => $n,
                        'error' => $ok ? null : 'No excluded emails for that site.',
                        'message' => $ok
                            ? ('Allowed ' . $n . ' email' . ($n === 1 ? '' : 's') . ' on ' . $domLabel . ' again.')
                            : 'No excluded emails for that site.',
                    ], $ok ? 200 : 404);
                }
                if ($ok) {
                    flash(
                        'ok',
                        'Allowed ' . $n . ' previously removed email' . ($n === 1 ? '' : 's')
                        . ' on “' . $domLabel . '” again.'
                    );
                } else {
                    flash('error', 'No excluded emails for that site.');
                }
                redirect($back . '#camp-excluded');
            }
            if ($action === 'save_settings') {
                $result = update_email_campaign_sheet_settings(
                    $sheetId,
                    (string) post('project_name'),
                    (string) post('team_search_visible') === '1'
                );
                if (empty($result['ok'])) {
                    flash('error', (string) ($result['error'] ?? 'Could not save settings.'));
                } else {
                    $vis = (string) post('team_search_visible') === '1'
                        ? 'Communication Team search bar is ON'
                        : 'Communication Team search bar is OFF';
                    flash('ok', 'Saved project settings · ' . $vis . '.');
                }
                redirect($back);
            }
            if ($action === 'delete_sheet') {
                $backProjectId = (int) ($sheet['project_id'] ?? 0);
                delete_email_campaign_sheet($sheetId);
                flash('ok', 'Deleted ' . $sheetCountry . ' from the project.');
                redirect($backProjectId > 0
                    ? ($campBase . '&project=' . $backProjectId)
                    : $campBase);
            }
        } catch (Throwable $e) {
            if ($wantsJson) {
                $jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            flash('error', $e->getMessage());
            redirect($back);
        }
    }

    $openBatch = $batchFilter > 0 ? get_email_campaign_send_batch($batchFilter, $sheetId) : null;
    if ($batchFilter > 0 && !$openBatch) {
        $batchFilter = 0;
    }
    $inv = email_campaign_rows_inventory_query(
        $sheetId,
        ['q' => $q, 'sent' => $sentFilter, 'batch' => $batchFilter],
        $pageNum,
        $perPage
    );
    $rows = $inv['rows'];
    $total = (int) $inv['total'];
    $pages = (int) $inv['pages'];
    $pageNum = (int) $inv['page'];
    $sheetTotal = ($q !== '' || $sentFilter !== '' || $batchFilter > 0)
        ? count_email_campaign_rows($sheetId)
        : $total;
    $filledCount = $sheetTotal;
    $sentStats = count_email_campaign_sent_stats($sheetId);
    $excludedCount = count_email_campaign_excluded_domains($sheetId);
    $excludedDomains = list_email_campaign_excluded_domains($sheetId, 200);
    $excludedEmailCount = count_email_campaign_excluded_emails($sheetId);
    $excludedEmails = list_email_campaign_excluded_emails($sheetId, 200);
    $excludedDomainSet = [];
    foreach ($excludedDomains as $exDom) {
        $d = (string) ($exDom['domain'] ?? '');
        if ($d !== '') {
            $excludedDomainSet[$d] = true;
        }
    }
    $whoMap = map_email_campaign_latest_event_who($sheetId);
    $sendBatches = list_email_campaign_send_batches($sheetId);
    $sendBatchMap = [];
    foreach ($sendBatches as $b) {
        $sendBatchMap[(int) $b['id']] = $b;
    }
    if ($openBatch && isset($sendBatchMap[(int) ($openBatch['id'] ?? 0)])) {
        $openBatch = $sendBatchMap[(int) $openBatch['id']];
    }
    $batchSuggest = trim((string) ($user['username'] ?? '')) !== ''
        ? trim((string) $user['username']) . ' · ' . date('Y-m-d')
        : 'Admin · ' . date('Y-m-d');
    $formAction = append_sheet_per_page_query($campBase . '&sheet=' . $sheetId, $perPage);
    $domainsExportUrl = $campBase . '&sheet=' . $sheetId . '&export=domains';
    $domainsExportUnsentUrl = $domainsExportUrl . '&sent=0';
    $domainsExportSentUrl = $domainsExportUrl . '&sent=1';
    $qs = http_build_query(array_filter([
        'page' => 'admin_emails_data',
        'folder' => 'email_campaigns',
        'sheet' => $sheetId,
        'q' => $q,
        'sent' => $sentFilter,
        'batch' => $batchFilter > 0 ? $batchFilter : null,
        'per_page' => $perPage,
    ], static fn ($v) => $v !== '' && $v !== null));
    $sheet = get_email_campaign_sheet($sheetId) ?: $sheet;
    $projectName = email_campaign_sheet_project_name($sheet);
    $teamVisible = email_campaign_sheet_team_visible($sheet);
    $sheetProjectId = (int) ($sheet['project_id'] ?? 0);
    $projectHref = $sheetProjectId > 0
        ? ($campBase . '&project=' . $sheetProjectId)
        : $campBase;
    $gapDiff = diff_email_campaign_vs_archives($sheetId, $sheetCountry, ['sample' => 20]);
    $gapCounts = is_array($gapDiff['counts'] ?? null) ? $gapDiff['counts'] : [];
    $gapSamples = is_array($gapDiff['samples'] ?? null) ? $gapDiff['samples'] : [];
    $gapFillable = (int) ($gapCounts['fillable'] ?? 0);
    $adminCountryUrl = $base . '&folder=sites_with_emails&country=' . rawurlencode($sheetCountry);
    $finalCountryUrl = $base . '&folder=all_sites_with_emails&country=' . rawurlencode($sheetCountry);

    render_header($projectName . ' · ' . $sheetCountry, 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails data', 'href' => $base],
        ['label' => 'Email campaign data', 'href' => $campBase],
        ['label' => $projectName, 'href' => $projectHref],
        ['label' => $sheetCountry],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info($sheetCountry, 'Country sheet inside project “' . $projectName . '”. Data here is only for this country. Communication Team search covers the whole project and updates this sheet when they delete a hit from ' . $sheetCountry . '.') ?></h1>
        <p class="muted">
          Project <strong><?= h($projectName) ?></strong> ·
          <span id="swe_total_label"><?= (int) $filledCount ?></span> site<?= (int) $filledCount === 1 ? '' : 's' ?>
          <?= $q !== '' || $sentFilter !== '' || $batchFilter > 0 ? ' · ' . (int) $total . ' matching this filter' : '' ?>
          · <span id="swe_unsent_label"><?= (int) $sentStats['unsent'] ?></span> not emailed
          · <span id="swe_sent_label"><?= (int) $sentStats['sent'] ?></span> emailed
          <?php if ($sendBatches !== []): ?>
            · <a href="#camp-batches"><?= count($sendBatches) ?> send batch<?= count($sendBatches) === 1 ? '' : 'es' ?></a>
          <?php endif; ?>
          · <?= (int) $perPage ?> per page · autosave ·
          Team search: <strong><?= $teamVisible ? 'shown' : 'hidden' ?></strong>
        </p>
      </div>
      <div class="actions">
        <?php render_task_presence('camp:' . $sheetId, 'Others on Email Sheet · ' . $sheetCountry); ?>
        <button type="button" class="btn" id="camp-add-toggle" data-camp-add-toggle title="Add one site + up to 4 emails">+ Add site</button>
        <a class="btn secondary" href="#camp-bulk-add">Paste / import</a>
        <a class="btn secondary" href="<?= h($projectHref) ?>">Project countries</a>
      </div>
    </div>
    <p class="help">
      Admin fills <strong><?= h($sheetCountry) ?></strong> data for project <strong><?= h($projectName) ?></strong>.
      Use <strong>+ Add site</strong>, paste, file import, Fill gaps from Admin + Final, or import from Team / Admin / Final.
    </p>

    <?php
    render_sheet_checkpoint_compact(
        'How Mark emailed / Mark up to here / Clear up to here work on this Email campaign country sheet. '
        . 'Order: oldest sites at the top, newest adds at the bottom. '
        . 'Mark emailed: marks only that one site as done. '
        . 'Mark up to here: marks this site and every site above it as emailed (checkpoint). '
        . 'Clear up to here: clears emailed marks from the top through this site (redo that stretch). '
        . 'Clear all emailed: resets this country sheet for a full resend. '
        . 'Mark up to here names a send batch (Batch A, Batch B, …) and records who marked it. '
        . 'The next stretch is a new batch. Status shows the batch name; Batches lists who emailed whom. '
        . 'Highlighted rows = already emailed. Filters: All / Not emailed / Emailed. '
        . 'Marks stay on this sheet only (other projects / countries are separate).'
    );
    ?>

    <div class="card">
      <div class="invoice-list-toolbar swe-list-toolbar" style="margin-bottom:0.75rem">
        <div>
          <h2 style="margin:0"><?= label_with_info('Sites with emails', 'Same model as Our database: one country sheet, paginated — choose how many rows per page with the Per page filter (sheets can reach ~100K). Use + Add site for a single row. Clearing the last email removes the site. Use Status and Actions for emailed / up to here. Paste up to 4 emails into any email box. Edits autosave.') ?></h2>
          <p class="swe-sent-filters">
            <?php
            $sentLinks = [
                '' => 'All',
                '0' => 'Not emailed',
                '1' => 'Emailed',
            ];
            foreach ($sentLinks as $val => $label):
                $href = append_sheet_per_page_query($campBase . '&sheet=' . $sheetId, $perPage);
                if ($q !== '') {
                    $href .= '&q=' . rawurlencode($q);
                }
                if ($batchFilter > 0) {
                    $href .= '&batch=' . $batchFilter;
                }
                if ($val !== '') {
                    $href .= '&sent=' . $val;
                }
                $active = $sentFilter === (string) $val;
                ?>
              <a class="btn small <?= $active ? '' : 'secondary' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
            <?php if ((int) $sentStats['sent'] > 0): ?>
            <form method="post" action="<?= h($formAction) ?>" class="swe-clear-all-emailed"
                  data-swe-clear-all-emailed
                  onsubmit="return confirm(<?= h(json_encode('Clear ALL emailed marks on ' . $sheetCountry . " in this project?\n\nYou can resend and track this sheet from scratch.", JSON_UNESCAPED_UNICODE)) ?>);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear_all_emailed">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
              <?php if ($batchFilter > 0): ?>
              <input type="hidden" name="batch" value="<?= (int) $batchFilter ?>">
              <?php endif; ?>
              <button class="btn secondary small" type="submit" title="Clear every emailed mark on this Email campaign sheet">
                Clear all emailed
              </button>
            </form>
            <?php endif; ?>
          </p>
          <?php render_sheet_tool_menu_open('Copy', 'Copy domains by emailed status'); ?>
          <div class="swe-copy-group" role="group" aria-label="Copy domains by sent status">
            <button type="button" class="btn secondary small" data-camp-copy-domains
                    data-export-url="<?= h($domainsExportUnsentUrl) ?>"
                    data-copy-label="not emailed"
                    title="Copy site names that are not marked emailed yet"
                    <?= ((int) $sentStats['unsent'] > 0) ? '' : 'disabled' ?>>
              Copy not emailed domains
            </button>
            <button type="button" class="btn secondary small" data-camp-copy-domains
                    data-export-url="<?= h($domainsExportSentUrl) ?>"
                    data-copy-label="emailed"
                    title="Copy site names already marked emailed"
                    <?= ((int) $sentStats['sent'] > 0) ? '' : 'disabled' ?>>
              Copy emailed domains
            </button>
            <button type="button" class="btn secondary small" data-camp-copy-domains
                    data-export-url="<?= h($domainsExportUrl) ?>"
                    data-copy-label="all"
                    title="Copy every site name on this campaign country sheet"
                    <?= ((int) $filledCount > 0) ? '' : 'disabled' ?>>
              Copy all domains
            </button>
          </div>
          <?php render_sheet_tool_menu_close(); ?>
          <?php render_sheet_tool_menu_open('Batches', 'Named send batches — who emailed which stretch'); ?>
          <div id="camp-batches" class="swe-copy-group" role="group" aria-label="Send batches on this country sheet">
            <?php if ($sendBatches === []): ?>
              <p class="muted" style="margin:0">No send batches yet. Mark up to here names a batch (Batch A, then Batch B).</p>
            <?php else: ?>
              <?php foreach ($sendBatches as $b):
                  $bid = (int) ($b['id'] ?? 0);
                  $bName = (string) ($b['name'] ?? '');
                  $bWho = email_campaign_send_batch_who_label($b);
                  $bLive = (int) ($b['live_count'] ?? 0);
                  $bOrig = (int) ($b['site_count'] ?? 0);
                  $bWhen = (string) ($b['created_at'] ?? '');
                  $bHref = append_sheet_per_page_query($campBase . '&sheet=' . $sheetId . '&batch=' . $bid, $perPage);
                  $bTitle = $bName . ' · ' . $bWho
                      . ($bWhen !== '' ? ' · ' . $bWhen : '')
                      . ' · ' . $bLive . ' still marked'
                      . ($bOrig > 0 ? ' / ' . $bOrig . ' originally' : '');
                  ?>
                <a class="btn small <?= $batchFilter === $bid ? '' : 'secondary' ?>"
                   href="<?= h($bHref) ?>"
                   title="<?= h($bTitle) ?>">
                  <?= h($bName !== '' ? $bName : 'Untitled') ?>
                  · <?= h($bWho !== '' && $bWho !== '—' ? $bWho : 'unknown') ?>
                  · <?= (int) $bLive ?><?= $bOrig > 0 && $bOrig !== $bLive ? '/' . $bOrig : '' ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php render_sheet_tool_menu_close(); ?>
        </div>
        <div class="actions">
          <button type="button" class="btn small" data-camp-add-toggle title="Add one site + up to 4 emails">+ Add site</button>
          <?php
          render_sheet_edit_toolbar($formAction, sheet_history_key('campaign', (string) $sheetId), [
              'q' => $q,
              'p' => $pageNum,
              'sent' => $sentFilter,
              'batch' => $batchFilter,
          ]);
          ?>
          <label class="sheet-search swe-row-search-wrap" for="swe-row-search">
            <span class="visually-hidden">Search sites and emails</span>
            <input id="swe-row-search" type="search" placeholder="Search site or email…"
                   value="<?= h($q) ?>" autocomplete="off" spellcheck="false" data-no-draft
                   <?= $filledCount < 1 && $q === '' && $sentFilter === '' && $batchFilter < 1 ? 'disabled' : '' ?>
                   title="Filters this page after you pause typing · Enter = next match · Ctrl/Cmd+Enter = search all pages">
            <span class="sheet-search-meta muted" data-swe-row-search-meta hidden></span>
          </label>
        </div>
      </div>
      <p class="help" id="swe_status" role="status" aria-live="polite" hidden></p>

      <?php if ($openBatch):
          $openWho = email_campaign_send_batch_who_label($openBatch);
          $openLive = (int) ($openBatch['live_count'] ?? 0);
          $openOrig = (int) ($openBatch['site_count'] ?? 0);
          $openWhen = (string) ($openBatch['created_at'] ?? '');
          $sheetAllHref = append_sheet_per_page_query($campBase . '&sheet=' . $sheetId, $perPage);
          ?>
      <div class="card" id="camp-batch-open" style="margin:0 0 0.75rem">
        <h2 style="margin:0"><?= h((string) ($openBatch['name'] ?? 'Send batch')) ?></h2>
        <p class="muted" style="margin:0.35rem 0 0">
          Sent by <strong><?= h($openWho !== '' && $openWho !== '—' ? $openWho : 'unknown') ?></strong>
          <?php if ($openWhen !== ''): ?> · <?= h($openWhen) ?><?php endif; ?>
          · <?= (int) $openOrig ?> originally
          · <?= (int) $openLive ?> still marked
        </p>
        <p class="help" style="margin:0.35rem 0 0">
          Sites and emails for this stretch are in the table<?= $openLive < 1 ? ' (none still marked — Clear unlinked them; this batch stays as history)' : '' ?>.
        </p>
        <p class="actions" style="margin:0.5rem 0 0">
          <a class="btn secondary small" href="<?= h($sheetAllHref) ?>">Show all sites</a>
        </p>
      </div>
      <?php endif; ?>

      <div class="table-wrap swe-sheet-wrap">
        <table class="swe-table swe-sheet-table is-admin-checkpoint is-dense sheet-cards-mobile" id="camp-sheet-table"
               data-camp-batch-suggest="<?= h($batchSuggest) ?>">
          <thead>
            <tr>
              <?php render_sheet_select_th(); ?>
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
          <tbody id="camp-sheet-tbody">
          <tr id="camp-add-row" class="camp-add-row" hidden data-swe-emails>
            <td class="swe-td-check sheet-td-check" data-label="Select"></td>
            <td class="swe-td-site" data-label="Site">
              <form method="post" action="<?= h($formAction) ?>" class="swe-row-form swe-add-form" id="camp-add-form"
                    autocomplete="off" data-show-processing="Adding site…">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_row">
                <input type="hidden" name="site_id" value="0">
                <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                <?php if ($sentFilter !== ''): ?>
                <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                <?php endif; ?>
                <?php if ($batchFilter > 0): ?>
                <input type="hidden" name="batch" value="<?= (int) $batchFilter ?>">
                <?php endif; ?>
              </form>
              <label class="visually-hidden" for="camp_add_domain">Site</label>
              <input id="camp_add_domain" class="swe-domain" form="camp-add-form" name="domain" required
                     placeholder="example.com" spellcheck="false" autocomplete="off" aria-label="Site">
            </td>
            <td class="swe-td-lang" data-label="Language"><span class="swe-cell-text muted">—</span></td>
            <td class="swe-td-email" data-label="Email 1">
              <?= render_clearable_email_input('email1', '', ['id' => 'camp_add_e1', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 1']) ?>
            </td>
            <td class="swe-td-email" data-label="Email 2">
              <?= render_clearable_email_input('email2', '', ['id' => 'camp_add_e2', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 2']) ?>
            </td>
            <td class="swe-td-email" data-label="Email 3">
              <?= render_clearable_email_input('email3', '', ['id' => 'camp_add_e3', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 3']) ?>
            </td>
            <td class="swe-td-email" data-label="Email 4">
              <?= render_clearable_email_input('email4', '', ['id' => 'camp_add_e4', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => '+', 'aria_label' => 'Clear email 4']) ?>
            </td>
            <td class="swe-td-status" data-label="Status"><span class="swe-status-badge is-open" data-swe-status>New</span></td>
            <td class="swe-td-actions" data-label="Actions">
              <div class="swe-row-actions">
                <button class="btn small" type="submit" form="camp-add-form">Add row</button>
                <button class="btn secondary small" type="button" id="camp-add-cancel" data-camp-add-cancel>Cancel</button>
              </div>
            </td>
          </tr>
          <?php foreach ($rows as $r):
              $rid = (int) $r['id'];
              $formId = 'camp-save-' . $rid;
              $domain = (string) $r['domain'];
              $lang = trim((string) ($r['language'] ?? ''));
              if ($lang === '') {
                  $lang = '—';
              }
              $e1 = (string) $r['email1'];
              $e2 = (string) $r['email2'];
              $e3 = (string) $r['email3'];
              $e4 = (string) $r['email4'];
              $hasEmail = $e1 !== '' || $e2 !== '' || $e3 !== '' || $e4 !== '';
              $isEmailed = (int) ($r['email_sent'] ?? 0) === 1;
              $rowBatchId = (int) ($r['send_batch_id'] ?? 0);
              $rowBatch = ($isEmailed && $rowBatchId > 0) ? ($sendBatchMap[$rowBatchId] ?? null) : null;
              $emailedStatus = $isEmailed ? email_campaign_row_emailed_status($rowBatch) : null;
              $statusLabel = $isEmailed ? (string) $emailedStatus['label'] : 'Not emailed';
              $statusTitle = $isEmailed ? (string) $emailedStatus['title'] : '';
              $statusClass = $isEmailed ? 'is-emailed' : 'is-open';
              $hay = mb_strtolower($domain . ' ' . $lang . ' ' . $e1 . ' ' . $e2 . ' ' . $e3 . ' ' . $e4);
              ?>
            <tr data-swe-row data-swe-emails data-search="<?= h($hay) ?>" data-site-id="<?= $rid ?>"
                data-has-email="<?= $hasEmail ? '1' : '0' ?>"
                data-email-sent="<?= $isEmailed ? '1' : '0' ?>"
                class="<?= $isEmailed ? 'swe-row-emailed' : '' ?>">
              <?php render_sheet_select_td($rid, $domain); ?>
              <td class="swe-td-site" data-label="Site">
                <form id="<?= h($formId) ?>" method="post" action="<?= h($formAction) ?>" class="swe-row-form" data-swe-save>
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save_row">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
                  <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                  <?php if ($sentFilter !== ''): ?>
                  <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                  <?php endif; ?>
                  <?php if ($batchFilter > 0): ?>
                  <input type="hidden" name="batch" value="<?= (int) $batchFilter ?>">
                  <?php endif; ?>
                </form>
                <div class="swe-site-cell open-site-cell" data-open-site-cell>
                  <label class="visually-hidden" for="camp-domain-<?= $rid ?>">Site</label>
                  <input id="camp-domain-<?= $rid ?>" class="swe-domain" form="<?= h($formId) ?>" name="domain"
                         value="<?= h($domain) ?>" required spellcheck="false" autocomplete="off" aria-label="Site"
                         title="<?= h($domain) ?>"
                         data-open-site-host>
                  <?= render_open_site_anchor($domain) ?>
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
                <span class="swe-status-badge <?= h($statusClass) ?>" data-swe-status
                      <?= $statusTitle !== '' ? 'title="' . h($statusTitle) . '"' : '' ?>><?= h($statusLabel) ?></span>
              </td>
              <td class="swe-td-actions" data-label="Actions">
                <div class="swe-row-actions">
                  <button class="btn small <?= $isEmailed ? 'secondary' : '' ?>" type="button"
                          data-sheet-action="mark" data-site-id="<?= $rid ?>"
                          data-email-sent="<?= $isEmailed ? '0' : '1' ?>" data-domain="<?= h($domain) ?>"
                          title="<?= $isEmailed ? 'Clear emailed mark on this site only' : 'Mark this site as emailed' ?>"
                          aria-label="<?= $isEmailed ? 'Clear emailed mark on this site only' : 'Mark this site as emailed' ?>">
                    <?= $isEmailed ? 'Undo' : 'Emailed' ?>
                  </button>
                  <?php render_sheet_row_more_open(); ?>
                  <button class="btn secondary small" type="button"
                          data-sheet-action="upto" data-site-id="<?= $rid ?>" data-domain="<?= h($domain) ?>"
                          title="Mark this site and every older site above it as emailed"
                          data-confirm="Mark emailed UP TO <?= h($domain) ?>?&#10;&#10;Every older site from the top through this row will be marked emailed on this sheet.">
                    Up to here
                  </button>
                  <button class="btn secondary small" type="button"
                          data-sheet-action="clear-upto" data-site-id="<?= $rid ?>" data-domain="<?= h($domain) ?>"
                          title="Clear emailed marks from the top through this site"
                          data-confirm="Clear emailed UP TO <?= h($domain) ?>?&#10;&#10;Every older emailed site from the top through this row will be unmarked on this sheet.">
                    Clear up to
                  </button>
                  <button class="btn secondary small" type="button"
                          data-sheet-action="remove" data-site-id="<?= $rid ?>" data-domain="<?= h($domain) ?>"
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
      render_sheet_shared_row_action_forms($formAction, 'camp', [
          'q' => $q,
          'p' => $pageNum,
          'sent' => $sentFilter,
          'batch' => $batchFilter,
          'mark' => true,
          'push' => false,
          'remove' => true,
      ]);
      ?>
      <p class="help sheet-search-empty" data-swe-row-search-empty hidden>
        No search matches on this page. Try Ctrl/Cmd+Enter to search all pages.
      </p>
      <?php if ($rows === [] && $q === '' && $sentFilter === '' && $batchFilter < 1): ?>
      <div class="empty-state" id="camp-empty-state">
        <p>No sites in this sheet yet.</p>
        <p class="muted">Admin adds data here: <strong>+ Add site</strong>, paste, file import, <strong>Fill gaps from Admin + Final</strong>, or <strong>Import from Team / Admin / Final</strong>.</p>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <button type="button" class="btn" data-camp-add-toggle>+ Add site</button>
          <a class="btn secondary" href="#camp-fill-gaps">Fill gaps</a>
          <a class="btn secondary" href="#camp-bulk-add">Paste / import file</a>
        </p>
      </div>
      <?php elseif ($rows === [] && ($q !== '' || $sentFilter !== '' || $batchFilter > 0)): ?>
      <div class="empty-state">
        <?php if ($openBatch): ?>
          <p>No sites still marked in this batch<?= $q !== '' ? ' matching this search' : '' ?>.</p>
          <p class="muted">Clear up to here unlinks rows from the batch; the batch stays so you can see who sent it.</p>
        <?php elseif ($sentFilter === '0'): ?>
          <p>No unmarked sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
          <?php if ($q === '' && (int) $sentStats['sent'] > 0): ?>
            <p class="muted">
              <?= (int) $sentStats['sent'] ?> site<?= (int) $sentStats['sent'] === 1 ? ' is' : 's are' ?> emailed.
              Open
              <a href="<?= h(append_sheet_per_page_query($campBase . '&sheet=' . $sheetId . '&sent=1', $perPage)) ?>">Emailed</a>
              or
              <a href="<?= h(append_sheet_per_page_query($campBase . '&sheet=' . $sheetId, $perPage)) ?>">All</a>
              to see them.
            </p>
          <?php else: ?>
            <p class="muted">New imports and adds appear here until you mark them emailed.</p>
          <?php endif; ?>
        <?php elseif ($sentFilter === '1'): ?>
          <p>No emailed sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
          <p class="muted">Use “Mark emailed” or “Mark up to here” while working the campaign.</p>
        <?php else: ?>
          <p>No search matches<?= $q !== '' ? ' for “' . h($q) . '”' : '' ?>.</p>
        <?php endif; ?>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <a class="btn secondary" href="<?= h($formAction) ?>">Clear filters</a>
        </p>
      </div>
      <?php endif; ?>
      <div class="pagination" style="margin-top:0.85rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
        <?php if ($pageNum > 1): ?>
          <a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a>
        <?php endif; ?>
        <?php if ($pages > 1 || $total > 0): ?>
        <span class="muted" data-sheet-page-status
              data-page="<?= (int) $pageNum ?>"
              data-pages="<?= (int) $pages ?>"
              data-on-page="<?= (int) count($rows) ?>"
              data-total="<?= (int) $total ?>">Page <?= (int) $pageNum ?> / <?= (int) $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?><?= $q !== '' ? ' matches' : '' ?></span>
        <?php endif; ?>
        <?php if ($pageNum < $pages): ?>
          <a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a>
        <?php endif; ?>
        <?php
        render_sheet_per_page_filter([
            'page' => 'admin_emails_data',
            'folder' => 'email_campaigns',
            'sheet' => $sheetId,
            'q' => $q,
            'sent' => $sentFilter,
        ], $perPage);
        ?>
      </div>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-bulk-add">
      <h2><?= label_with_info('Add many sites (paste or file)', 'Admin bulk entry. Paste 1000+ lines, or import CSV / Excel (.xlsx) / TXT. One line or row per site: site + up to 4 emails. Each site needs at least one email. Previously removed sites/emails on this sheet are skipped (use Allow again to restore).') ?></h2>
      <p class="help">
        Columns: <strong>Site name, Email 1, Email 2, Email 3, Email 4</strong>
        (comma, tab, or semicolon). Header row is optional and skipped.
        Built for large lists — paste or upload thousands of rows at once.
        Sites or emails previously removed from this sheet are not re-added.
      </p>

      <form method="post" action="<?= h($formAction) ?>" style="margin-top:0.85rem"
            data-show-processing="Adding pasted sites…">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="paste">
        <label for="camp_paste_text">Paste sites + emails</label>
        <textarea id="camp_paste_text" name="paste_text" class="inventory-box camp-bulk-paste" rows="14"
                  placeholder="Site name, Email 1, Email 2, Email 3, Email 4&#10;example.com, hello@example.com, sales@example.com&#10;other.org, contact@other.org&#10;shop.de info@shop.de support@shop.de"></textarea>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Add pasted rows</button>
        </p>
      </form>

      <hr class="camp-bulk-divider">

      <form method="post" action="<?= h($formAction) ?>" enctype="multipart/form-data"
            data-show-processing="Importing file…">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_file">
        <label for="camp_import_file">Import from CSV, Excel, or TXT</label>
        <input id="camp_import_file" type="file" name="import_file" required
               accept=".csv,.txt,.tsv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        <p class="help" style="margin-top:0.35rem">
          Accepts <strong>.csv</strong>, <strong>.xlsx</strong> (Excel), and <strong>.txt</strong> / <strong>.tsv</strong>.
          First columns = site + up to 4 emails. Old <code>.xls</code> → save as CSV or <code>.xlsx</code> first.
        </p>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Import file into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-fill-gaps">
      <h2><?= label_with_info('Fill gaps from Admin + Final', 'Copies missing and different-email sites from Final then Admin into this country campaign sheet only. Admin emails win when both have the domain. Previously removed sites stay blocked unless you Allow again. Campaign emailed marks stay; new rows start unmarked. Admin, Final, and Team are not edited.') ?></h2>
      <p class="help">
        Copies into this <strong><?= h($sheetCountry) ?></strong> campaign sheet only.
        Admin, Final, and Team are not edited.
        Previously removed domains stay blocked unless you Allow again.
        Campaign emailed marks stay on this sheet; new rows start unmarked.
      </p>
      <p>
        <strong><?= (int) ($gapCounts['add'] ?? 0) ?></strong> not on this sheet
        · <strong><?= (int) ($gapCounts['update'] ?? 0) ?></strong> different emails
        · <strong><?= (int) ($gapCounts['same'] ?? 0) ?></strong> already the same
        · <strong><?= (int) ($gapCounts['empty'] ?? 0) ?></strong> no emails
        · <strong><?= (int) ($gapCounts['excluded'] ?? 0) ?></strong> previously removed
      </p>
      <?php
      $addSample = is_array($gapSamples['add'] ?? null) ? $gapSamples['add'] : [];
      $updSample = is_array($gapSamples['update'] ?? null) ? $gapSamples['update'] : [];
      ?>
      <?php if ($addSample !== []): ?>
      <p class="muted" style="margin:.4rem 0 0">Would add (sample): <?= h(implode(', ', $addSample)) ?></p>
      <?php endif; ?>
      <?php if ($updSample !== []): ?>
      <p class="muted" style="margin:.4rem 0 0">Would update (sample): <?= h(implode(', ', $updSample)) ?></p>
      <?php endif; ?>
      <?php if ((int) ($gapCounts['empty'] ?? 0) > 0): ?>
      <p class="muted" style="margin:.4rem 0 0">
        Source rows with no emails cannot be imported.
        Add emails on <a href="<?= h($adminCountryUrl) ?>">Admin <?= h($sheetCountry) ?></a> first
        (Final: <a href="<?= h($finalCountryUrl) ?>"><?= h($sheetCountry) ?></a>).
      </p>
      <?php endif; ?>
      <form method="post" action="<?= h($formAction) ?>" style="margin-top:.85rem"
            data-show-processing="Filling gaps…"
            onsubmit="return confirm(<?= h(json_encode(
                'Fill gaps from Final then Admin into ' . $sheetCountry . "?\n\nCampaign emailed marks stay on this sheet.\nAdmin and Final are not changed.",
                JSON_UNESCAPED_UNICODE
            )) ?>);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fill_gaps">
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit"<?= $gapFillable === 0 ? ' disabled' : '' ?>>Fill gaps</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Import ' . $sheetCountry . ' from archive', 'Adds new sites from Team, Final, or Admin. Same domain with the same emails is skipped; different emails replace the sheet row. Source sheets are never deleted. Team sheets are stamped as fetched to this campaign. Sites and emails removed from this sheet are never re-added unless you Allow again.') ?></h2>
      <p class="help">
        Imports from Team, Final, or Admin — <strong>new sites</strong> are added, <strong>duplicate domains with the same emails</strong> are skipped,
        and <strong>same domain with different emails</strong> replaces the sheet row.
        <strong>Team data stays</strong> — importing only copies into this campaign and marks the Team country as fetched to <strong><?= h($projectName) ?></strong>.
        This campaign’s emailed marks stay on this sheet only — other campaigns are not changed.
        Paste / + Add also respect previously removed sites and emails.
        Previously removed sites and emails are never re-added (use <strong>Allow again</strong> below if a removal was a mistake).
      </p>
      <form method="post" action="<?= h($formAction) ?>"
            data-show-processing="Importing sites…"
            onsubmit="return confirm(<?= h(json_encode(
                'Import into ' . $sheetCountry . "?\n\nNew sites are added.\nSame domain + same emails → skipped.\nSame domain + different emails → replaced.\nThis campaign’s emailed marks stay on this sheet only.\nOther campaigns are not changed.\nTeam/Admin/Final source rows are not deleted.\nPreviously removed sites and emails are not re-added.",
                JSON_UNESCAPED_UNICODE
            )) ?>);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <label for="camp_import_source">Source</label>
        <select id="camp_import_source" name="source">
          <option value="team">Team</option>
          <option value="admin_all">Final</option>
          <option value="admin">Admin</option>
        </select>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Import into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-excluded">
      <h2><?= label_with_info('Previously removed', 'Sites and emails deleted from this Email Sheet (by Admin or Communication Team) stay blocked from import, paste, and + Add. Who is the person who last deleted that site or email. Allow again does not erase the name — it only lets the site/email be added again.') ?></h2>

      <h3 style="margin:0.85rem 0 0.45rem;font-size:1rem">Sites</h3>
      <?php if ($excludedCount < 1): ?>
        <p class="muted" style="margin:0">No excluded sites yet. When a site is removed from this sheet, it appears here.</p>
      <?php else: ?>
        <p class="help" style="margin-top:0">
          <?= (int) $excludedCount ?> site<?= $excludedCount === 1 ? '' : 's' ?> blocked from re-add (import, paste, and + Add).
          Allow again also clears that site’s previously removed emails.
          <?php if ($excludedCount > count($excludedDomains)): ?>
            Showing first <?= count($excludedDomains) ?>.
          <?php endif; ?>
        </p>
        <div class="table-wrap">
          <table class="extracted-country-table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Who</th>
                <th>Removed</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($excludedDomains as $ex):
                $exDomain = (string) $ex['domain'];
                $exWho = email_campaign_who_for_exclusion($whoMap, 'delete_site', $exDomain);
                ?>
              <tr>
                <td><code><?= h($exDomain) ?></code></td>
                <td><?= h($exWho) ?></td>
                <td class="muted"><?= h((string) $ex['excluded_at']) ?></td>
                <td class="num">
                  <form method="post" action="<?= h($formAction) ?>" style="display:inline"
                        data-stay-ajax data-stay-remove-row>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="allow_excluded_domain">
                    <input type="hidden" name="domain" value="<?= h((string) $ex['domain']) ?>">
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                    <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                    <button class="btn secondary small" type="submit"
                            title="Let import, paste, and + Add add this site again">Allow again</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <h3 style="margin:1.15rem 0 0.45rem;font-size:1rem">Emails</h3>
      <?php if ($excludedEmailCount < 1): ?>
        <p class="muted" style="margin:0">No excluded emails yet. When a single email is removed (and the site stays), it appears here.</p>
      <?php else: ?>
        <p class="help" style="margin-top:0">
          <?= (int) $excludedEmailCount ?> email<?= $excludedEmailCount === 1 ? '' : 's' ?> blocked from re-add on this sheet.
          <?php if ($excludedEmailCount > count($excludedEmails)): ?>
            Showing first <?= count($excludedEmails) ?>.
          <?php endif; ?>
        </p>
        <div class="table-wrap">
          <table class="extracted-country-table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Email</th>
                <th>Who</th>
                <th>Removed</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php
            $emailsPerDomain = [];
            foreach ($excludedEmails as $exCountRow) {
                $dKey = (string) ($exCountRow['domain'] ?? '');
                if ($dKey === '') {
                    continue;
                }
                $emailsPerDomain[$dKey] = ($emailsPerDomain[$dKey] ?? 0) + 1;
            }
            $emailAllowAllShown = [];
            foreach ($excludedEmails as $exEm):
                $emDomain = (string) ($exEm['domain'] ?? '');
                $emAddr = (string) ($exEm['email'] ?? '');
                $siteAlsoBlocked = isset($excludedDomainSet[$emDomain]);
                $emWho = email_campaign_who_for_exclusion($whoMap, 'remove_email', $emDomain, $emAddr);
                ?>
              <tr>
                <td>
                  <code><?= h($emDomain) ?></code>
                  <?php if ($siteAlsoBlocked): ?>
                    <span class="muted" style="display:block;font-size:0.85em">Site also blocked above</span>
                  <?php endif; ?>
                </td>
                <td><code><?= h($emAddr) ?></code></td>
                <td><?= h($emWho) ?></td>
                <td class="muted"><?= h((string) ($exEm['excluded_at'] ?? '')) ?></td>
                <td class="num">
                  <form method="post" action="<?= h($formAction) ?>" style="display:inline"
                        data-stay-ajax data-stay-remove-row>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="allow_excluded_email">
                    <input type="hidden" name="domain" value="<?= h($emDomain) ?>">
                    <input type="hidden" name="email" value="<?= h($emAddr) ?>">
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                    <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                    <button class="btn secondary small" type="submit"
                            title="Let this email be added again on this site">Allow again</button>
                  </form>
                  <?php if ($emDomain !== ''
                      && ($emailsPerDomain[$emDomain] ?? 0) > 1
                      && empty($emailAllowAllShown[$emDomain])):
                      $emailAllowAllShown[$emDomain] = true;
                      ?>
                    <form method="post" action="<?= h($formAction) ?>" style="display:inline;margin-left:0.35rem">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="allow_excluded_emails_for_domain">
                      <input type="hidden" name="domain" value="<?= h($emDomain) ?>">
                      <input type="hidden" name="q" value="<?= h($q) ?>">
                      <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                      <button class="btn secondary small" type="submit"
                              title="Allow all previously removed emails on this site">Allow all for site</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Communication Team', 'When the project search bar is on, Communication Team searches the whole project. Deleting a hit from this country updates this sheet only.') ?></h2>
      <p class="help">
        <?php if ($teamVisible): ?>
          Communication Team sees one search bar for project <strong><?= h($projectName) ?></strong>
          covering every country in it. Deletes that match <strong><?= h($sheetCountry) ?></strong> update this sheet
          and show under <a href="#camp-excluded">Previously removed</a> with who deleted them.
        <?php else: ?>
          Communication Team search is <strong>hidden</strong> for this project.
          Turn it on from
          <a href="<?= h($projectHref) ?>">Project countries</a>.
        <?php endif; ?>
      </p>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Danger zone</h2>
      <form method="post" action="<?= h($formAction) ?>"
            data-show-processing="Deleting country sheet…"
            onsubmit="return confirm(<?= h(json_encode(
                'Remove ' . $sheetCountry . ' from project “' . $projectName . "”?\n\n"
                . "This deletes this country’s campaign rows and the “fetched to " . $projectName . "” stamp on Team.\n"
                . "Other campaigns are not affected.\nTeam sites stay.",
                JSON_UNESCAPED_UNICODE
            )) ?>);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_sheet">
        <button class="btn danger" type="submit">Remove country from project</button>
      </form>
    </div>
    <?= email_field_clear_script_tag() ?>
    <script src="<?= h(script_asset_url('js/sheet-select-undo.js')) ?>" defer></script>
    <script src="<?= h(script_asset_url('js/email-campaign-sheet.js')) ?>" defer></script>
    <?= open_site_script_tag() ?>
    <?php
    render_footer('admin');
    return;
}

// --- Project detail (countries inside one project) + hub ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $returnProjectId = (int) post('project_id');
    $backProject = $returnProjectId > 0
        ? ($campBase . '&project=' . $returnProjectId)
        : $campBase;
    $hubWantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $hubJson = static function (array $payload, int $code = 200) use ($hubWantsJson): void {
        if (!$hubWantsJson) {
            return;
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    };
    try {
        if ($action === 'create_project') {
            $projectName = trim((string) post('project_name'));
            if ($projectName === '') {
                flash('error', 'Project name is required.');
                redirect($campBase . '#create-project');
            }
            if (get_email_campaign_project_by_name($projectName)) {
                flash('error', 'A project named “' . $projectName . '” already exists. Open it to add countries.');
                redirect($campBase . '#create-project');
            }
            $teamVisible = (string) post('team_search_visible') === '1';
            $pid = create_email_campaign_project(
                $projectName,
                (int) ($user['id'] ?? 0),
                $teamVisible
            );
            $firstCountry = trim((string) post('country'));
            if ($firstCountry !== '') {
                $sid = add_email_campaign_country_to_project(
                    $pid,
                    $firstCountry,
                    (int) ($user['id'] ?? 0)
                );
                purge_blank_email_campaign_rows($sid);
            }
            $msg = 'Project “' . $projectName . '” ready.';
            if ($firstCountry !== '') {
                $msg .= ' Added ' . $firstCountry . '.';
            }
            $msg .= $teamVisible
                ? ' Communication Team search bar is on (covers all countries in this project).'
                : ' Communication Team search bar is hidden (turn it on when ready).';
            flash('ok', $msg);
            redirect($campBase . '&project=' . $pid);
        }
        if ($action === 'add_country') {
            $pid = (int) post('project_id');
            $project = get_email_campaign_project($pid);
            if (!$project) {
                flash('error', 'Project not found.');
                redirect($campBase);
            }
            $sid = add_email_campaign_country_to_project(
                $pid,
                (string) post('country'),
                (int) ($user['id'] ?? 0)
            );
            purge_blank_email_campaign_rows($sid);
            $sheet = get_email_campaign_sheet($sid);
            $countryName = $sheet ? email_campaign_sheet_country($sheet) : (string) post('country');
            flash('ok', 'Added ' . $countryName . ' to “' . (string) $project['name'] . '”. Open it to add site + emails.');
            redirect($campBase . '&project=' . $pid);
        }
        if ($action === 'save_project_settings') {
            $pid = (int) post('project_id');
            $result = update_email_campaign_project_settings(
                $pid,
                (string) post('project_name'),
                (string) post('team_search_visible') === '1'
            );
            if (empty($result['ok'])) {
                flash('error', (string) ($result['error'] ?? 'Could not save project settings.'));
            } else {
                $vis = (string) post('team_search_visible') === '1'
                    ? 'Communication Team search is ON for the whole project'
                    : 'Communication Team search is OFF';
                flash('ok', 'Saved project settings · ' . $vis . '.');
            }
            redirect($campBase . '&project=' . $pid);
        }
        if ($action === 'save_draft') {
            $pid = (int) post('project_id');
            $draftId = (int) post('draft_id');
            $result = save_email_campaign_draft(
                $pid,
                (string) post('title'),
                (string) post('body'),
                (string) post('category'),
                $draftId,
                (int) ($user['id'] ?? 0),
                (string) post('subject')
            );
            if (empty($result['ok'])) {
                flash('error', (string) ($result['error'] ?? 'Could not save draft.'));
            } else {
                flash('ok', $draftId > 0 ? 'Updated Communication draft.' : 'Saved Communication draft for this project.');
            }
            redirect($campBase . '&project=' . $pid . '#project-drafts');
        }
        if ($action === 'delete_draft') {
            $pid = (int) post('project_id');
            $result = delete_email_campaign_draft($pid, (int) post('draft_id'), $user);
            flash(
                !empty($result['ok']) ? 'ok' : 'error',
                !empty($result['ok'])
                    ? ('Deleted draft “' . (string) ($result['title'] ?? 'draft') . '”.')
                    : (string) ($result['error'] ?? 'Could not delete draft.')
            );
            redirect($campBase . '&project=' . $pid . '#project-drafts');
        }
        if ($action === 'move_draft') {
            $pid = (int) post('project_id');
            $result = move_email_campaign_draft(
                $pid,
                (int) post('draft_id'),
                (string) post('direction'),
                $user
            );
            flash(
                !empty($result['ok']) ? 'ok' : 'error',
                !empty($result['ok'])
                    ? 'Draft order updated.'
                    : (string) ($result['error'] ?? 'Could not move draft.')
            );
            redirect($campBase . '&project=' . $pid . '#project-drafts');
        }
        if ($action === 'toggle_project_team_search') {
            $pid = (int) post('project_id');
            $visible = (string) post('team_search_visible') === '1';
            $result = set_email_campaign_project_team_visible($pid, $visible);
            $project = get_email_campaign_project($pid);
            $label = $project ? (string) $project['name'] : 'project';
            if (empty($result['ok'])) {
                $hubJson([
                    'ok' => false,
                    'error' => (string) ($result['error'] ?? 'Could not update visibility.'),
                ], 400);
                flash('error', (string) ($result['error'] ?? 'Could not update visibility.'));
            } else {
                $hubJson([
                    'ok' => true,
                    'team_search_visible' => $visible,
                    'project_id' => $pid,
                    'project_name' => $label,
                    'message' => $visible
                        ? ('Showing “' . $label . '” search bar to Communication Team (all countries).')
                        : ('Hid “' . $label . '” search bar from Communication Team.'),
                ]);
                flash('ok', $visible
                    ? 'Showing “' . $label . '” search bar to Communication Team (all countries).'
                    : 'Hid “' . $label . '” search bar from Communication Team.');
            }
            // Optional stay=1 keeps Admin on the project page after toggle.
            redirect((string) post('stay') === '1' && $returnProjectId > 0
                ? $backProject
                : $campBase);
        }
        // Legacy sheet-level toggle from older bookmarks.
        if ($action === 'toggle_team_search') {
            $id = (int) post('id');
            $visible = (string) post('team_search_visible') === '1';
            $result = set_email_campaign_sheet_team_visible($id, $visible);
            $sheet = get_email_campaign_sheet($id);
            $label = $sheet ? email_campaign_sheet_project_name($sheet) : 'project';
            $pid = $sheet ? (int) ($sheet['project_id'] ?? 0) : 0;
            if (empty($result['ok'])) {
                flash('error', (string) ($result['error'] ?? 'Could not update visibility.'));
            } else {
                flash('ok', $visible
                    ? 'Showing “' . $label . '” search bar to Communication Team.'
                    : 'Hid “' . $label . '” search bar from Communication Team.');
            }
            redirect($pid > 0 ? ($campBase . '&project=' . $pid) : $campBase);
        }
        if ($action === 'delete_country') {
            $id = (int) post('id');
            $sheet = get_email_campaign_sheet($id);
            if (!$sheet) {
                flash('error', 'Country sheet not found.');
                redirect($backProject);
            }
            $pid = (int) ($sheet['project_id'] ?? 0);
            $label = email_campaign_sheet_country($sheet);
            delete_email_campaign_sheet($id);
            flash('ok', 'Removed ' . $label . ' from the project.');
            redirect($pid > 0 ? ($campBase . '&project=' . $pid) : $campBase);
        }
        if ($action === 'delete_project') {
            $pid = (int) post('project_id');
            $project = get_email_campaign_project($pid);
            if (!$project) {
                flash('error', 'Project not found.');
            } else {
                $label = (string) $project['name'];
                delete_email_campaign_project($pid);
                flash('ok', 'Deleted project “' . $label . '” and all its country sheets.');
            }
            redirect($campBase);
        }
        // Legacy create (country + project in one step) → project then country.
        if ($action === 'create') {
            $projectName = trim((string) post('project_name'));
            if ($projectName === '') {
                flash('error', 'Project name is required.');
                redirect($campBase . '#create-project');
            }
            $teamVisible = (string) post('team_search_visible') === '1';
            $pid = create_email_campaign_project(
                $projectName,
                (int) ($user['id'] ?? 0),
                $teamVisible
            );
            update_email_campaign_project_settings($pid, $projectName, $teamVisible);
            $sid = add_email_campaign_country_to_project(
                $pid,
                (string) post('country'),
                (int) ($user['id'] ?? 0)
            );
            purge_blank_email_campaign_rows($sid);
            flash('ok', 'Project “' . $projectName . '” ready with '
                . email_campaign_sheet_country(get_email_campaign_sheet($sid) ?: ['name' => (string) post('country')])
                . '.');
            redirect($campBase . '&sheet=' . $sid);
        }
        if ($action === 'delete') {
            $id = (int) post('id');
            $sheet = get_email_campaign_sheet($id);
            if (!$sheet) {
                flash('error', 'Sheet not found.');
                redirect($campBase);
            }
            $pid = (int) ($sheet['project_id'] ?? 0);
            $label = email_campaign_sheet_country($sheet);
            delete_email_campaign_sheet($id);
            flash('ok', 'Removed ' . $label . '.');
            redirect($pid > 0 ? ($campBase . '&project=' . $pid) : $campBase);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($backProject);
    }
}

// --- Project page: countries Admin added to this project ---
if ($projectIdParam > 0) {
    $project = get_email_campaign_project($projectIdParam);
    if (!$project) {
        flash('error', 'Project not found.');
        redirect($campBase);
    }
    $projectName = (string) $project['name'];
    $teamVisible = email_campaign_project_team_visible($project);
    $countrySheets = list_email_campaign_sheets_for_project($projectIdParam);
    $inProject = [];
    foreach ($countrySheets as $s) {
        $inProject[mb_strtolower((string) $s['country'])] = true;
    }
    $allCountries = list_countries(null, true);
    $availableCountries = [];
    foreach ($allCountries as $c) {
        $name = (string) $c['name'];
        if (!isset($inProject[mb_strtolower($name)])) {
            $availableCountries[] = $c;
        }
    }
    $projectForm = $campBase . '&project=' . $projectIdParam;
    $countryCount = count($countrySheets);
    $siteTotal = 0;
    foreach ($countrySheets as $s) {
        $siteTotal += (int) $s['row_count'];
    }
    $draftCategories = email_campaign_draft_categories();
    $projectDrafts = list_email_campaign_drafts($projectIdParam);
    $editDraftId = (int) get('edit_draft');
    $editDraft = $editDraftId > 0 ? get_email_campaign_draft($editDraftId) : null;
    if ($editDraft && (int) ($editDraft['project_id'] ?? 0) !== $projectIdParam) {
        $editDraft = null;
        $editDraftId = 0;
    }
    $removedEvents = list_email_campaign_row_events(null, $projectIdParam, 200);
    $removedCount = count_email_campaign_row_events(null, $projectIdParam);
    $projectBatches = list_email_campaign_send_batches(null, $projectIdParam, 200);
    $projectBatchCount = count($projectBatches);

    render_header($projectName, 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails data', 'href' => $base],
        ['label' => 'Email campaign data', 'href' => $campBase],
        ['label' => $projectName],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info($projectName, 'This project holds only the countries you add. Each country has its own site + email data. Communication Team gets one search bar for the whole project.') ?></h1>
        <p class="muted">
          <?= (int) $countryCount ?> countr<?= $countryCount === 1 ? 'y' : 'ies' ?>
          · <?= (int) $siteTotal ?> site<?= (int) $siteTotal === 1 ? '' : 's' ?>
          · Team search: <strong><?= $teamVisible ? 'shown' : 'hidden' ?></strong>
          <?php if ($projectBatchCount > 0): ?>
            · <a href="#camp-batches"><?= (int) $projectBatchCount ?> send batch<?= $projectBatchCount === 1 ? '' : 'es' ?></a>
          <?php endif; ?>
          <?php if ($removedCount > 0): ?>
            · <a href="#camp-removed"><?= (int) $removedCount ?> removed</a>
          <?php endif; ?>
        </p>
      </div>
      <div class="actions">
        <?php if ($availableCountries): ?>
          <a class="btn secondary" href="#add-country">Add country</a>
        <?php endif; ?>
        <a class="btn secondary" href="<?= h($campBase) ?>">All projects</a>
      </div>
    </div>

    <div class="orders-layout camp-hub-layout">
      <section class="card camp-hub-list">
        <div class="camp-hub-section-head">
          <div>
            <h2 style="margin:0"><?= label_with_info('Countries in this project', 'Open a country to edit its site + email rows. Same country can exist in another project with different data.') ?></h2>
            <p class="help" style="margin:0.3rem 0 0">
              Only countries you add appear here — not every country in the system.
            </p>
          </div>
        </div>
        <?php if (!$countrySheets): ?>
          <div class="empty-state camp-hub-empty">
            <p>No countries in this project yet.</p>
            <p class="muted">Add a country on the right, then open it to fill site + emails.</p>
            <?php if ($availableCountries): ?>
            <p class="actions" style="justify-content:center;margin-top:0.75rem">
              <a class="btn" href="#add-country">Add first country</a>
            </p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="invoice-list-toolbar camp-hub-toolbar">
            <label class="sheet-search" for="camp-project-country-search">
              <span class="visually-hidden">Search countries</span>
              <input id="camp-project-country-search" type="search" placeholder="Find a country…"
                     autocomplete="off" spellcheck="false" data-no-draft>
            </label>
          </div>
          <div class="table-wrap">
            <table class="extracted-country-table camp-hub-table" id="camp-project-country-table">
              <thead>
                <tr>
                  <th>Country</th>
                  <th class="num">Sites</th>
                  <th class="num">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($countrySheets as $s):
                  $cName = (string) $s['country'];
                  $rowCount = (int) $s['row_count'];
                  $hay = mb_strtolower($cName . ' ' . $rowCount . ' sites');
                  ?>
                <tr data-camp-country-row data-search="<?= h($hay) ?>">
                  <td>
                    <a class="extracted-country-link camp-hub-project"
                       href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">
                      <?= h($cName) ?>
                    </a>
                  </td>
                  <td class="num">
                    <span class="camp-hub-count" title="Sites with emails in this country sheet"><?= $rowCount ?></span>
                  </td>
                  <td class="num">
                    <div class="camp-hub-row-actions">
                      <a class="btn secondary small" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">Open</a>
                      <form method="post" action="<?= h($projectForm) ?>"
                            onsubmit="return confirm(<?= h(json_encode('Remove “' . $cName . '” from this project?', JSON_UNESCAPED_UNICODE)) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_country">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
                        <button class="btn secondary small" type="submit">Remove</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <script>
          (function () {
            var input = document.getElementById('camp-project-country-search');
            if (!input) return;
            input.addEventListener('input', function () {
              var q = String(input.value || '').trim().toLowerCase();
              document.querySelectorAll('[data-camp-country-row]').forEach(function (row) {
                row.hidden = !(!q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1);
              });
            });
          })();
          </script>
        <?php endif; ?>
      </section>

      <aside class="camp-hub-create">
        <section class="card" id="add-country">
          <div class="camp-hub-section-head">
            <h2 style="margin:0"><?= label_with_info('Add a country', 'Creates an empty country sheet inside this project. Fill site + emails after opening it. The same country can live in another project with different data.') ?></h2>
          </div>
          <?php if (!$availableCountries): ?>
            <div class="empty-state camp-hub-empty">
              <p>Every country is already in this project.</p>
            </div>
          <?php else: ?>
          <form method="post" action="<?= h($projectForm) ?>" class="camp-hub-create-form" autocomplete="off"
                data-show-processing="Adding country…">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_country">
            <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
            <div class="camp-hub-field">
              <label for="add_camp_country">Country</label>
              <p class="camp-hub-field-hint">Only countries not already in this project.</p>
              <select id="add_camp_country" name="country" required>
                <option value="">Select country…</option>
                <?php foreach ($availableCountries as $c): ?>
                  <option value="<?= h((string) $c['name']) ?>"><?= h((string) $c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <p class="actions camp-hub-create-actions">
              <button class="btn" type="submit">Add country</button>
            </p>
          </form>
          <?php endif; ?>
        </section>

        <section class="card" style="margin-top:1rem" id="project-settings">
          <div class="camp-hub-section-head">
            <h2 style="margin:0"><?= label_with_info('Project & Communication Team', 'One search bar for Communication Team covers every country in this project. Deletes update the matching country sheet. Drafts below are the reusable outreach texts for this project only.') ?></h2>
          </div>
          <form method="post" action="<?= h($projectForm) ?>" class="camp-hub-create-form" autocomplete="off"
                data-show-processing="Saving project…">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_project_settings">
            <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
            <div class="camp-hub-field">
              <label for="edit_camp_project">Project name</label>
              <input id="edit_camp_project" name="project_name" required maxlength="180"
                     value="<?= h($projectName) ?>">
            </div>
            <div class="camp-hub-field camp-hub-field-check">
              <label class="camp-hub-check">
                <input type="checkbox" name="team_search_visible" value="1" <?= $teamVisible ? 'checked' : '' ?>>
                <span>
                  <strong>Show search bar to Communication Team</strong>
                  <span class="camp-hub-field-hint">They search the whole project; deletes update the country sheet that matched. Drafts stay available when the project is shown.</span>
                </span>
              </label>
            </div>
            <p class="actions camp-hub-create-actions">
              <button class="btn" type="submit">Save project settings</button>
            </p>
          </form>
        </section>

        <section class="card" style="margin-top:1rem" id="project-drafts">
          <div class="camp-hub-section-head">
            <h2 style="margin:0"><?= label_with_info('Communication drafts', 'Reusable outreach / offer / reply text for this project only. Communication Team copies these with one click when the project is shown to them.') ?></h2>
            <p class="help" style="margin:0.3rem 0 0">
              <?= count($projectDrafts) ?> draft<?= count($projectDrafts) === 1 ? '' : 's' ?> · seed starter text here or let Communication add their own
            </p>
          </div>
          <?php if ($projectDrafts): ?>
          <ul class="camp-admin-drafts-list">
            <?php
            $adminDraftTotal = count($projectDrafts);
            foreach ($projectDrafts as $adi => $d):
                $did = (int) $d['id'];
                $adminCat = (string) ($d['category'] ?? '');
                $adminCanUp = $adi > 0
                    && (string) ($projectDrafts[$adi - 1]['category'] ?? '') === $adminCat;
                $adminCanDown = $adi < ($adminDraftTotal - 1)
                    && (string) ($projectDrafts[$adi + 1]['category'] ?? '') === $adminCat;
                $adminSizeWarn = email_campaign_draft_size_warning((string) ($d['body'] ?? ''));
                ?>
              <li>
                <div class="camp-admin-draft-meta">
                  <strong><?= h((string) $d['title']) ?></strong>
                  <span class="muted" style="font-size:0.82rem">
                    <?= h(email_campaign_draft_category_label($adminCat)) ?>
                  </span>
                  <?php if (trim((string) ($d['subject'] ?? '')) !== ''): ?>
                  <span class="help" style="display:block;margin-top:0.15rem">
                    Subject: <?= h((string) $d['subject']) ?>
                  </span>
                  <?php endif; ?>
                  <?php
                    $adminAttr = email_campaign_draft_attribution($d);
                    if ($adminAttr !== ''):
                  ?>
                  <span class="help" style="display:block;margin-top:0.2rem"><?= h($adminAttr) ?></span>
                  <?php endif; ?>
                  <?php if ($adminSizeWarn !== ''): ?>
                  <span class="help camp-draft-size-warn" style="display:block;margin-top:0.2rem"><?= h($adminSizeWarn) ?></span>
                  <?php endif; ?>
                </div>
                <div class="actions">
                  <?php if ($adminCanUp): ?>
                  <form method="post" action="<?= h($projectForm) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="move_draft">
                    <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
                    <input type="hidden" name="draft_id" value="<?= $did ?>">
                    <input type="hidden" name="direction" value="up">
                    <button class="btn secondary small" type="submit" title="Move up">↑</button>
                  </form>
                  <?php endif; ?>
                  <?php if ($adminCanDown): ?>
                  <form method="post" action="<?= h($projectForm) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="move_draft">
                    <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
                    <input type="hidden" name="draft_id" value="<?= $did ?>">
                    <input type="hidden" name="direction" value="down">
                    <button class="btn secondary small" type="submit" title="Move down">↓</button>
                  </form>
                  <?php endif; ?>
                  <a class="btn secondary small" href="<?= h($projectForm) ?>&amp;edit_draft=<?= $did ?>#project-drafts">Edit</a>
                  <?php if (email_campaign_user_can_delete_draft($user, $d)): ?>
                  <form method="post" action="<?= h($projectForm) ?>"
                        onsubmit="return confirm(<?= h(json_encode('Delete draft “' . (string) $d['title'] . '”?', JSON_UNESCAPED_UNICODE)) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_draft">
                    <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
                    <input type="hidden" name="draft_id" value="<?= $did ?>">
                    <button class="btn danger small" type="submit">Delete</button>
                  </form>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <p class="muted" style="margin:0.65rem 0 0">No drafts yet for this project.</p>
          <?php endif; ?>

          <form method="post" action="<?= h($projectForm) ?>" class="camp-hub-create-form" style="margin-top:1rem"
                autocomplete="off" data-show-processing="Saving draft…">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_draft">
            <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
            <input type="hidden" name="draft_id" value="<?= $editDraft ? (int) $editDraft['id'] : 0 ?>">
            <h3 style="margin:0 0 0.55rem;font-size:0.98rem">
              <?= $editDraft ? 'Edit draft' : 'Add draft' ?>
            </h3>
            <div class="camp-hub-field">
              <label for="admin_draft_title">Title</label>
              <input id="admin_draft_title" name="title" required maxlength="180"
                     value="<?= h((string) ($editDraft['title'] ?? '')) ?>"
                     placeholder="e.g. First outreach">
            </div>
            <div class="camp-hub-field">
              <label for="admin_draft_subject">Subject <span class="muted">(optional)</span></label>
              <input id="admin_draft_subject" name="subject" maxlength="255"
                     value="<?= h((string) ($editDraft['subject'] ?? '')) ?>"
                     placeholder="e.g. Idea for {domain}"
                     data-camp-draft-subject-input>
              <p class="help" style="margin:0.3rem 0 0">
                Tokens:
                <?php foreach (email_campaign_draft_token_defs() as $tok => $tokLabel): ?>
                  <button type="button" class="btn secondary small" data-camp-draft-token="{<?= h($tok) ?>}"
                          data-camp-draft-token-target="admin_draft_subject"
                          title="<?= h($tokLabel) ?>">{<?= h($tok) ?></button>
                <?php endforeach; ?>
              </p>
            </div>
            <div class="camp-hub-field">
              <label for="admin_draft_category">Category</label>
              <select id="admin_draft_category" name="category" required>
                <?php
                $adminSelCat = normalize_email_campaign_draft_category((string) ($editDraft['category'] ?? 'first_outreach'));
                foreach ($draftCategories as $slug => $label):
                    ?>
                  <option value="<?= h($slug) ?>" <?= $adminSelCat === $slug ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="camp-hub-field">
              <label for="admin_draft_body">Draft text</label>
              <p class="help" style="margin:0 0 0.45rem">
                Bold / italic / underline / headings / lists / http(s) links / images are kept when Communication copies into email.
                Optional subject + tokens ({domain}, {country}, {language}, {name}, {site}).
                Paste a screenshot or use Image (auto-compressed).
              </p>
              <p class="help" style="margin:0 0 0.45rem">
                Insert into body:
                <?php foreach (email_campaign_draft_token_defs() as $tok => $tokLabel): ?>
                  <button type="button" class="btn secondary small" data-camp-draft-token="{<?= h($tok) ?>}"
                          data-camp-draft-token-target="body"
                          title="<?= h($tokLabel) ?>">{<?= h($tok) ?></button>
                <?php endforeach; ?>
              </p>
              <?php
              render_email_campaign_draft_editor(
                  'admin_draft_body',
                  'body',
                  (string) ($editDraft['body'] ?? ''),
                  ['placeholder' => "Hi,\n\n…"]
              );
              ?>
            </div>
            <p class="actions camp-hub-create-actions">
              <button class="btn" type="submit"><?= $editDraft ? 'Update draft' : 'Save draft' ?></button>
              <?php if ($editDraft): ?>
                <a class="btn secondary" href="<?= h($projectForm) ?>#project-drafts">Cancel</a>
              <?php endif; ?>
            </p>
          </form>
        </section>

        <section class="card" style="margin-top:1rem">
          <h2>Danger zone</h2>
          <p class="muted">Deletes this project and all of its country sheets, contacts, drafts, and Team “fetched to this campaign” stamps. Team sites stay. Other campaigns are not affected.</p>
          <form method="post" action="<?= h($projectForm) ?>"
                data-show-processing="Deleting project…"
                onsubmit="return confirm(<?= h(json_encode(
                    'Delete project “' . $projectName . '” and all country sheets and drafts inside it?'
                    . "\n\nTeam “fetched to " . $projectName . '” stamps for those sheets are removed. Team sites stay. Other campaigns are not affected.',
                    JSON_UNESCAPED_UNICODE
                )) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_project">
            <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
            <button class="btn danger" type="submit">Delete whole project</button>
          </form>
        </section>
      </aside>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-batches">
      <h2><?= label_with_info('Send batches', 'Who marked emailed on a country sheet, and which named stretch (Batch A, Batch B). Mark up to here names a batch. Open shows the sites and emails still tagged to that stretch.') ?></h2>
      <?php if ($projectBatchCount < 1): ?>
        <p class="muted" style="margin:0">No send batches yet. On a country sheet, Mark up to here names a batch and records who marked it.</p>
      <?php else: ?>
        <p class="help" style="margin-top:0">
          <?= (int) $projectBatchCount ?> batch<?= $projectBatchCount === 1 ? '' : 'es' ?>
          on this project.
          <?php if ($projectBatchCount > count($projectBatches)): ?>
            Showing the <?= count($projectBatches) ?> most recent.
          <?php endif; ?>
        </p>
        <div class="invoice-list-toolbar camp-hub-toolbar">
          <label class="sheet-search" for="camp-project-batch-search">
            <span class="visually-hidden">Search send batches</span>
            <input id="camp-project-batch-search" type="search" placeholder="Find a batch, person, or country…"
                   autocomplete="off" spellcheck="false" data-no-draft>
          </label>
        </div>
        <div class="table-wrap">
          <table class="extracted-country-table camp-hub-table" id="camp-project-batch-table">
            <thead>
              <tr>
                <th>Batch</th>
                <th>Country</th>
                <th>Who</th>
                <th class="num">Sites</th>
                <th>When</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($projectBatches as $b):
                $bName = (string) ($b['name'] ?? '');
                $bCountry = (string) ($b['country'] ?? '');
                $bWho = email_campaign_send_batch_who_label($b);
                $bLive = (int) ($b['live_count'] ?? 0);
                $bOrig = (int) ($b['site_count'] ?? 0);
                $bWhen = (string) ($b['created_at'] ?? '');
                $bSheet = (int) ($b['sheet_id'] ?? 0);
                $bId = (int) ($b['id'] ?? 0);
                $bHref = $bSheet > 0 && $bId > 0
                    ? ($campBase . '&sheet=' . $bSheet . '&batch=' . $bId)
                    : $projectForm;
                $bCount = (string) $bLive . ($bOrig > 0 && $bOrig !== $bLive ? '/' . $bOrig : '');
                $bHay = mb_strtolower($bName . ' ' . $bCountry . ' ' . $bWho);
                ?>
              <tr data-camp-batch-row data-search="<?= h($bHay) ?>">
                <td><?= h($bName !== '' ? $bName : 'Untitled') ?></td>
                <td><?= h($bCountry !== '' ? $bCountry : '—') ?></td>
                <td><?= h($bWho !== '' && $bWho !== '—' ? $bWho : '—') ?></td>
                <td class="num" title="Still marked / originally"><?= h($bCount) ?></td>
                <td class="muted"><?= h($bWhen !== '' ? $bWhen : '—') ?></td>
                <td class="num">
                  <a class="btn secondary small" href="<?= h($bHref) ?>">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <script>
        (function () {
          var input = document.getElementById('camp-project-batch-search');
          if (!input) return;
          input.addEventListener('input', function () {
            var q = String(input.value || '').trim().toLowerCase();
            document.querySelectorAll('[data-camp-batch-row]').forEach(function (row) {
              row.hidden = !(!q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1);
            });
          });
        })();
        </script>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-removed">
      <h2><?= label_with_info('Removed', 'Who deleted a site or email on any country sheet in this project. Communication Team share one search bar — this list is how Admin sees who removed what. Allow again on a country sheet does not erase these rows.') ?></h2>
      <?php if ($removedCount < 1): ?>
        <p class="muted" style="margin:0">Nothing removed yet. Team or Admin deletes from a country sheet appear here with the site name and who did it.</p>
      <?php else: ?>
        <p class="help" style="margin-top:0">
          <?= (int) $removedCount ?> removal<?= $removedCount === 1 ? '' : 's' ?>
          recorded on this project.
          <?php if ($removedCount > count($removedEvents)): ?>
            Showing the <?= count($removedEvents) ?> most recent.
          <?php endif; ?>
        </p>
        <div class="invoice-list-toolbar camp-hub-toolbar">
          <label class="sheet-search" for="camp-project-removed-search">
            <span class="visually-hidden">Search removed sites</span>
            <input id="camp-project-removed-search" type="search" placeholder="Find a site, email, or person…"
                   autocomplete="off" spellcheck="false" data-no-draft>
          </label>
        </div>
        <div class="table-wrap">
          <table class="extracted-country-table camp-hub-table" id="camp-project-removed-table">
            <thead>
              <tr>
                <th>Country</th>
                <th>Site</th>
                <th>What</th>
                <th>Who</th>
                <th>When</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($removedEvents as $ev):
                $evCountry = (string) ($ev['country'] ?? '');
                $evDomain = (string) ($ev['domain'] ?? '');
                $evEmail = (string) ($ev['email'] ?? '');
                $evAction = (string) ($ev['action'] ?? '');
                $evWhat = $evAction === 'remove_email'
                    ? ('Email · ' . ($evEmail !== '' ? $evEmail : '—'))
                    : 'Site';
                $evWho = email_campaign_event_who_label($ev);
                $evWhen = (string) ($ev['created_at'] ?? '');
                $evSheet = (int) ($ev['sheet_id'] ?? 0);
                $evHref = $evSheet > 0
                    ? ($campBase . '&sheet=' . $evSheet . '#camp-excluded')
                    : $projectForm;
                $evHay = mb_strtolower($evCountry . ' ' . $evDomain . ' ' . $evEmail . ' ' . $evWhat . ' ' . $evWho);
                ?>
              <tr data-camp-removed-row data-search="<?= h($evHay) ?>">
                <td><?= h($evCountry !== '' ? $evCountry : '—') ?></td>
                <td><code><?= h($evDomain !== '' ? $evDomain : '—') ?></code></td>
                <td><?= h($evWhat) ?></td>
                <td><?= h($evWho) ?></td>
                <td class="muted"><?= h($evWhen !== '' ? $evWhen : '—') ?></td>
                <td class="num">
                  <a class="btn secondary small" href="<?= h($evHref) ?>">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <script>
        (function () {
          var input = document.getElementById('camp-project-removed-search');
          if (!input) return;
          input.addEventListener('input', function () {
            var q = String(input.value || '').trim().toLowerCase();
            document.querySelectorAll('[data-camp-removed-row]').forEach(function (row) {
              row.hidden = !(!q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1);
            });
          });
        })();
        </script>
      <?php endif; ?>
    </div>

    <script src="<?= h(script_asset_url('js/email-campaign-drafts.js')) ?>" defer></script>
    <?php
    render_footer('admin');
    return;
}

// --- Hub: list / create projects ---
$projects = list_email_campaign_projects();
$allCountries = list_countries(null, true);

render_header('Email campaign data', 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Emails data', 'href' => $base],
    ['label' => 'Email campaign data'],
]);
$projectCount = count($projects);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Email campaign data', 'Create a project, then add only the countries you need. Each country keeps its own site + email data. Share the project with Communication Team as one search bar across all its countries.') ?></h1>
    <p class="muted">
      Projects hold country sheets — Admin chooses which countries go in each project.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="#create-project">Create project</a>
    <a class="btn secondary" href="<?= h($base) ?>">All folders</a>
  </div>
</div>

<ol class="camp-hub-steps" aria-label="How Email campaign projects work">
  <li>
    <span class="camp-hub-step-num">1</span>
    <div>
      <strong>Create a project</strong>
      <span>Name it, optionally add a first country.</span>
    </div>
  </li>
  <li>
    <span class="camp-hub-step-num">2</span>
    <div>
      <strong>Add countries</strong>
      <span>Only countries you add get a sheet — each with its own data.</span>
    </div>
  </li>
  <li>
    <span class="camp-hub-step-num">3</span>
    <div>
      <strong>Share with Communication</strong>
      <span>One project search bar; deletes update the matching country sheet.</span>
    </div>
  </li>
</ol>

<div class="orders-layout camp-hub-layout">
  <section class="card camp-hub-list">
    <div class="camp-hub-section-head">
      <div>
        <h2 style="margin:0"><?= label_with_info('Your projects', 'Open a project to manage its countries. Team search shows or hides the Communication Team bar for the whole project.') ?></h2>
        <p class="help" style="margin:0.3rem 0 0">
          <?= (int) $projectCount ?> project<?= $projectCount === 1 ? '' : 's' ?>
          · click a name or <strong>Open</strong>
        </p>
      </div>
    </div>
    <?php if (!$projects): ?>
      <div class="empty-state camp-hub-empty">
        <p>No projects yet.</p>
        <p class="muted">Create a project on the right, then add the countries you need.</p>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <a class="btn" href="#create-project">Create your first project</a>
        </p>
      </div>
    <?php else: ?>
      <div class="invoice-list-toolbar camp-hub-toolbar">
        <label class="sheet-search" for="camp-country-search">
          <span class="visually-hidden">Search projects</span>
          <input id="camp-country-search" type="search" placeholder="Find a project or country…"
                 autocomplete="off" spellcheck="false" data-no-draft>
        </label>
      </div>
      <div class="table-wrap">
        <table class="extracted-country-table camp-hub-table" id="camp-country-table">
          <thead>
            <tr>
              <th>Project</th>
              <th>Countries</th>
              <th class="num">Sites</th>
              <th>Team search</th>
              <th class="num">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($projects as $p):
              $pName = (string) $p['name'];
              $visible = !empty($p['team_search_visible']);
              $rowCount = (int) $p['row_count'];
              $cCount = (int) $p['country_count'];
              $countries = $p['countries'] ?? [];
              $countryPreview = $countries !== []
                  ? implode(', ', array_slice($countries, 0, 4)) . (count($countries) > 4 ? '…' : '')
                  : '—';
              $hay = mb_strtolower($pName . ' ' . implode(' ', $countries) . ' ' . $rowCount . ' sites');
              ?>
            <tr data-camp-country-row data-search="<?= h($hay) ?>">
              <td>
                <a class="extracted-country-link camp-hub-project"
                   href="<?= h($campBase) ?>&amp;project=<?= (int) $p['id'] ?>">
                  <?= h($pName) ?>
                </a>
              </td>
              <td>
                <span class="camp-hub-country" title="<?= h(implode(', ', $countries)) ?>">
                  <?= (int) $cCount ?> · <?= h($countryPreview) ?>
                </span>
              </td>
              <td class="num">
                <span class="camp-hub-count" title="Sites across all countries in this project"><?= $rowCount ?></span>
              </td>
              <td>
                <form method="post" action="<?= h($campBase) ?>" class="camp-hub-team-form"
                      data-stay-ajax data-stay-team-toggle>
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_project_team_search">
                  <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="team_search_visible" value="<?= $visible ? '0' : '1' ?>">
                  <button class="camp-hub-team-btn <?= $visible ? 'is-on' : 'is-off' ?>" type="submit"
                          title="<?= $visible ? 'Hide from Communication Team' : 'Show to Communication Team' ?>">
                    <span class="camp-hub-team-dot" aria-hidden="true"></span>
                    <?= $visible ? 'Shown to team' : 'Hidden from team' ?>
                  </button>
                </form>
              </td>
              <td class="num">
                <div class="camp-hub-row-actions">
                  <a class="btn secondary small" href="<?= h($campBase) ?>&amp;project=<?= (int) $p['id'] ?>">Open</a>
                  <form method="post" action="<?= h($campBase) ?>"
                        onsubmit="return confirm(<?= h(json_encode('Delete project “' . $pName . '” and all its countries?', JSON_UNESCAPED_UNICODE)) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_project">
                    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
                    <button class="btn secondary small" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <script>
      (function () {
        var input = document.getElementById('camp-country-search');
        if (!input) return;
        input.addEventListener('input', function () {
          var q = String(input.value || '').trim().toLowerCase();
          document.querySelectorAll('[data-camp-country-row]').forEach(function (row) {
            row.hidden = !(!q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1);
          });
        });
      })();
      </script>
    <?php endif; ?>
  </section>

  <section class="card camp-hub-create" id="create-project">
    <div class="camp-hub-section-head">
      <h2 style="margin:0"><?= label_with_info('Create a project', 'Name the project, optionally pick a first country, then add more countries inside the project. Communication Team gets one search bar for the whole project when enabled.') ?></h2>
      <p class="help" style="margin:0.3rem 0 0">You can add more countries after creating.</p>
    </div>
    <form method="post" action="<?= h($campBase) ?>" class="camp-hub-create-form" autocomplete="off"
          data-show-processing="Creating project…">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_project">

      <div class="camp-hub-field">
        <label for="new_camp_project">
          <span class="camp-hub-field-step">1</span>
          Project name
        </label>
        <p class="camp-hub-field-hint">Title of the Communication Team search bar when shared.</p>
        <input id="new_camp_project" name="project_name" required maxlength="180"
               placeholder="e.g. Q2 EU outreach">
      </div>

      <div class="camp-hub-field">
        <label for="new_camp_country">
          <span class="camp-hub-field-step">2</span>
          First country <span class="muted">(optional)</span>
        </label>
        <p class="camp-hub-field-hint">Starts an empty country sheet. Add more countries inside the project.</p>
        <select id="new_camp_country" name="country">
          <option value="">Add later…</option>
          <?php foreach ($allCountries as $c): ?>
            <option value="<?= h((string) $c['name']) ?>">
              <?= h((string) $c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="camp-hub-field camp-hub-field-check">
        <label class="camp-hub-check">
          <input type="checkbox" name="team_search_visible" value="1" checked>
          <span>
            <strong>Show search bar to Communication Team</strong>
            <span class="camp-hub-field-hint">One bar for the whole project · uncheck to keep Admin-only for now.</span>
          </span>
        </label>
      </div>

      <p class="actions camp-hub-create-actions">
        <button class="btn" type="submit">Create project</button>
      </p>
    </form>
  </section>
</div>
<?php
render_footer('admin');
