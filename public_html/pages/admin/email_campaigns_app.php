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
    $pageNum = max(1, (int) get('p', 1));
    $perPage = 1000;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $returnQ = trim((string) post('q'));
        $returnP = max(1, (int) post('p', 1));
        $returnSent = (string) post('sent');
        if ($returnSent !== '0' && $returnSent !== '1') {
            $returnSent = '';
        }
        $back = $campBase . '&sheet=' . $sheetId;
        if ($returnQ !== '') {
            $back .= '&q=' . rawurlencode($returnQ);
        }
        if ($returnSent !== '') {
            $back .= '&sent=' . $returnSent;
        }
        if ($returnP > 1) {
            $back .= '&p=' . $returnP;
        }
        $wantsJson = (string) post('ajax') === '1'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $jsonOut = static function (array $payload, int $code = 200) use ($wantsJson): void {
            if (!$wantsJson) {
                return;
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
                    $result = save_email_campaign_row($sheetId, $rowId, (string) post('domain'), $emails);
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
                $del = delete_email_campaign_row($sheetId, $rowId);
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
            // Campaign emailed progress — same rule as Sites with emails - Admin, per sheet.
            if ($action === 'mark_email_sent') {
                $rowId = (int) post('site_id');
                $sent = (string) post('email_sent') === '1';
                $result = set_email_campaign_row_email_sent($sheetId, $rowId, $sent);
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
                $result = mark_email_campaign_emailed_up_to($sheetId, $rowId);
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
                        . ' · ' . (int) ($result['marked'] ?? 0) . ' newly marked.'
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
                if ((int) ($result['skipped'] ?? 0) > 0) {
                    $msg .= ', ' . (int) $result['skipped'] . ' skipped';
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
                if ((int) ($result['skipped'] ?? 0) > 0) {
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
                $source = (string) post('source') === 'admin' ? 'admin' : 'admin_all';
                // Always new sites only — never update rows already on the sheet.
                $result = import_email_campaign_sheet_from_swe($sheetId, $source, $sheetCountry, 'new_only');
                $label = $source === 'admin' ? 'Sites with emails - Admin' : 'All sites with emails - Final';
                $msg = 'Imported new sites into ' . $sheetCountry . ' from ' . $label . ': '
                    . (int) $result['imported'] . ' new';
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
                        'Allowed “' . normalize_email_campaign_domain($domain) . '” again. '
                        . 'Next Final/Admin import can add it if it still has emails.'
                    );
                } else {
                    flash('error', 'That site was not on the excluded list.');
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

    $inv = email_campaign_rows_inventory_query(
        $sheetId,
        ['q' => $q, 'sent' => $sentFilter],
        $pageNum,
        $perPage
    );
    $rows = $inv['rows'];
    $total = (int) $inv['total'];
    $pages = (int) $inv['pages'];
    $pageNum = (int) $inv['page'];
    $sheetTotal = ($q !== '' || $sentFilter !== '')
        ? count_email_campaign_rows($sheetId)
        : $total;
    $filledCount = $sheetTotal;
    $sentStats = count_email_campaign_sent_stats($sheetId);
    $excludedCount = count_email_campaign_excluded_domains($sheetId);
    $excludedDomains = list_email_campaign_excluded_domains($sheetId, 200);
    $formAction = $campBase . '&sheet=' . $sheetId;
    $qs = http_build_query(array_filter([
        'page' => 'admin_emails_data',
        'folder' => 'email_campaigns',
        'sheet' => $sheetId,
        'q' => $q,
        'sent' => $sentFilter,
    ], static fn ($v) => $v !== '' && $v !== null));
    $sheet = get_email_campaign_sheet($sheetId) ?: $sheet;
    $projectName = email_campaign_sheet_project_name($sheet);
    $teamVisible = email_campaign_sheet_team_visible($sheet);
    $sheetProjectId = (int) ($sheet['project_id'] ?? 0);
    $projectHref = $sheetProjectId > 0
        ? ($campBase . '&project=' . $sheetProjectId)
        : $campBase;

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
          <?= $q !== '' || $sentFilter !== '' ? ' · ' . (int) $total . ' shown' : '' ?>
          · <span id="swe_unsent_label"><?= (int) $sentStats['unsent'] ?></span> not emailed
          · <span id="swe_sent_label"><?= (int) $sentStats['sent'] ?></span> emailed
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
      Use <strong>+ Add site</strong>, paste, file import, or import from Final.
      Track send progress with the same <strong>emailed</strong> rule as Sites with emails - Admin (this sheet only).
    </p>

    <div class="card swe-checkpoint-rule" style="margin-bottom:1rem">
      <h2 style="margin:0 0 0.45rem"><?= label_with_info('Emailed selection rule', 'How Mark emailed / Mark up to here / Clear up to here work on this Email campaign country sheet.') ?></h2>
      <ol class="swe-checkpoint-steps">
        <li><strong>Order:</strong> oldest sites at the top · newest adds at the bottom.</li>
        <li><strong>Mark emailed:</strong> marks only that one site as done.</li>
        <li><strong>Mark up to here:</strong> marks this site <em>and every site above it</em> as emailed (checkpoint).</li>
        <li><strong>Clear up to here:</strong> clears emailed marks from the top through this site (redo that stretch).</li>
        <li><strong>Clear all emailed:</strong> resets this country sheet for a full resend.</li>
      </ol>
      <p class="help" style="margin:0.55rem 0 0">
        Highlighted rows = already emailed. Filters: All / Not emailed / Emailed.
        Marks stay on this sheet only (other projects / countries are separate).
      </p>
    </div>

    <div class="card">
      <div class="invoice-list-toolbar swe-list-toolbar" style="margin-bottom:0.75rem">
        <div>
          <h2 style="margin:0"><?= label_with_info('Sites with emails', 'Same model as Our database: one country sheet, paginated (1,000 sites per page — sheets can reach ~100K). Use + Add site for a single row. Clearing the last email removes the site. Use Status and Actions for emailed / up to here.') ?></h2>
          <p class="help" style="margin:0.25rem 0 0">
            Paste up to 4 emails into any email box. Edits <strong>autosave</strong>.
            Browse page by page — large sheets stay fast at <?= (int) $perPage ?> per page.
          </p>
          <p class="swe-sent-filters">
            <?php
            $sentLinks = [
                '' => 'All',
                '0' => 'Not emailed',
                '1' => 'Emailed',
            ];
            foreach ($sentLinks as $val => $label):
                $href = $formAction;
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
            <?php if ((int) $sentStats['sent'] > 0): ?>
            <form method="post" action="<?= h($formAction) ?>" class="swe-clear-all-emailed"
                  data-swe-clear-all-emailed
                  onsubmit="return confirm('Clear ALL emailed marks on <?= h($sheetCountry) ?> in this project?\n\nYou can resend and track this sheet from scratch.');">
              <input type="hidden" name="action" value="clear_all_emailed">
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
              <?php if ($sentFilter !== ''): ?>
              <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
              <?php endif; ?>
              <button class="btn secondary small" type="submit" title="Clear every emailed mark on this Email campaign sheet">
                Clear all emailed
              </button>
            </form>
            <?php endif; ?>
          </p>
        </div>
        <div class="actions" style="align-items:center;gap:0.5rem;flex-wrap:wrap">
          <button type="button" class="btn small" data-camp-add-toggle title="Add one site + up to 4 emails">+ Add site</button>
          <label class="sheet-search swe-row-search-wrap" for="swe-row-search">
            <span class="visually-hidden">Search sites and emails</span>
            <input id="swe-row-search" type="search" placeholder="Search site or email…"
                   value="<?= h($q) ?>" autocomplete="off" spellcheck="false" data-no-draft
                   <?= $filledCount < 1 && $q === '' && $sentFilter === '' ? 'disabled' : '' ?>
                   title="Filter this page · Enter = next match · Ctrl/Cmd+Enter = search all pages">
            <span class="sheet-search-meta muted" data-swe-row-search-meta hidden></span>
          </label>
        </div>
      </div>
      <p class="help" id="swe_status" role="status" aria-live="polite" hidden></p>

      <div class="table-wrap swe-sheet-wrap">
        <table class="swe-table swe-sheet-table is-admin-checkpoint" id="camp-sheet-table">
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
          <tbody id="camp-sheet-tbody">
          <tr id="camp-add-row" class="camp-add-row" hidden data-swe-emails>
            <td class="swe-td-site">
              <form method="post" action="<?= h($formAction) ?>" class="swe-row-form swe-add-form" id="camp-add-form"
                    autocomplete="off" data-show-processing="Adding site…">
                <input type="hidden" name="action" value="save_row">
                <input type="hidden" name="site_id" value="0">
                <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                <?php if ($sentFilter !== ''): ?>
                <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                <?php endif; ?>
              </form>
              <label class="visually-hidden" for="camp_add_domain">Site</label>
              <input id="camp_add_domain" class="swe-domain" form="camp-add-form" name="domain" required
                     placeholder="example.com" spellcheck="false" autocomplete="off" aria-label="Site">
            </td>
            <td class="swe-td-lang"><span class="swe-cell-text muted">—</span></td>
            <td class="swe-td-email">
              <?= render_clearable_email_input('email1', '', ['id' => 'camp_add_e1', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => 'email 1', 'aria_label' => 'Clear email 1']) ?>
            </td>
            <td class="swe-td-email">
              <?= render_clearable_email_input('email2', '', ['id' => 'camp_add_e2', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => 'email 2', 'aria_label' => 'Clear email 2']) ?>
            </td>
            <td class="swe-td-email">
              <?= render_clearable_email_input('email3', '', ['id' => 'camp_add_e3', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => 'email 3', 'aria_label' => 'Clear email 3']) ?>
            </td>
            <td class="swe-td-email">
              <?= render_clearable_email_input('email4', '', ['id' => 'camp_add_e4', 'swe' => true, 'form' => 'camp-add-form', 'placeholder' => 'email 4', 'aria_label' => 'Clear email 4']) ?>
            </td>
            <td class="swe-td-status"><span class="swe-status-badge is-open" data-swe-status>New</span></td>
            <td class="swe-td-actions">
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
              $statusLabel = $isEmailed ? 'Emailed' : 'Not emailed';
              $statusClass = $isEmailed ? 'is-emailed' : 'is-open';
              $hay = mb_strtolower($domain . ' ' . $lang . ' ' . $e1 . ' ' . $e2 . ' ' . $e3 . ' ' . $e4);
              ?>
            <tr data-swe-row data-swe-emails data-search="<?= h($hay) ?>" data-site-id="<?= $rid ?>"
                data-has-email="<?= $hasEmail ? '1' : '0' ?>"
                data-email-sent="<?= $isEmailed ? '1' : '0' ?>"
                class="<?= $isEmailed ? 'swe-row-emailed' : '' ?>">
              <td class="swe-td-site">
                <form id="<?= h($formId) ?>" method="post" action="<?= h($formAction) ?>" class="swe-row-form" data-swe-save>
                  <input type="hidden" name="action" value="save_row">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
                  <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                  <?php if ($sentFilter !== ''): ?>
                  <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                  <?php endif; ?>
                </form>
                <label class="visually-hidden" for="camp-domain-<?= $rid ?>">Site</label>
                <input id="camp-domain-<?= $rid ?>" class="swe-domain" form="<?= h($formId) ?>" name="domain"
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
                  <button class="btn small <?= $isEmailed ? 'secondary' : '' ?>" type="submit"
                          form="camp-mark-<?= $rid ?>"
                          title="<?= $isEmailed ? 'Clear emailed mark on this site only' : 'Mark this site as emailed' ?>">
                    <?= $isEmailed ? 'Clear emailed' : 'Mark emailed' ?>
                  </button>
                  <button class="btn secondary small" type="submit" form="camp-upto-<?= $rid ?>"
                          title="Mark this site and every older site above it as emailed"
                          onclick="return confirm('Mark emailed UP TO <?= h($domain) ?>?\n\nEvery older site from the top through this row will be marked emailed on this sheet.');">
                    Up to here
                  </button>
                  <button class="btn secondary small" type="submit" form="camp-clear-upto-<?= $rid ?>"
                          title="Clear emailed marks from the top through this site"
                          onclick="return confirm('Clear emailed UP TO <?= h($domain) ?>?\n\nEvery older emailed site from the top through this row will be unmarked on this sheet.');">
                    Clear up to
                  </button>
                  <button class="btn secondary small" type="submit" form="camp-remove-<?= $rid ?>"
                          onclick="return confirm('Remove complete row for <?= h($domain) ?>?');">Remove</button>
                </div>
                <form id="camp-mark-<?= $rid ?>" method="post" action="<?= h($formAction) ?>" data-swe-mark hidden>
                  <input type="hidden" name="action" value="mark_email_sent">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
                  <input type="hidden" name="email_sent" value="<?= $isEmailed ? '0' : '1' ?>">
                  <input type="hidden" name="q" value="<?= h($q) ?>">
                  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                  <?php if ($sentFilter !== ''): ?>
                  <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                  <?php endif; ?>
                </form>
                <form id="camp-upto-<?= $rid ?>" method="post" action="<?= h($formAction) ?>" data-swe-mark-upto hidden>
                  <input type="hidden" name="action" value="mark_emailed_up_to">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
                  <input type="hidden" name="q" value="<?= h($q) ?>">
                  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                  <?php if ($sentFilter !== ''): ?>
                  <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                  <?php endif; ?>
                </form>
                <form id="camp-clear-upto-<?= $rid ?>" method="post" action="<?= h($formAction) ?>" data-swe-clear-upto hidden>
                  <input type="hidden" name="action" value="clear_emailed_up_to">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
                  <input type="hidden" name="q" value="<?= h($q) ?>">
                  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                  <?php if ($sentFilter !== ''): ?>
                  <input type="hidden" name="sent" value="<?= h($sentFilter) ?>">
                  <?php endif; ?>
                </form>
                <form id="camp-remove-<?= $rid ?>" method="post" action="<?= h($formAction) ?>" data-swe-remove hidden>
                  <input type="hidden" name="action" value="remove_site">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
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
      <?php if ($rows === [] && $q === '' && $sentFilter === ''): ?>
      <div class="empty-state" id="camp-empty-state">
        <p>No sites in this sheet yet.</p>
        <p class="muted">Admin adds data here: <strong>+ Add site</strong>, paste, file import, or <strong>Import from Final (new sites only)</strong>.</p>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <button type="button" class="btn" data-camp-add-toggle>+ Add site</button>
          <a class="btn secondary" href="#camp-bulk-add">Paste / import file</a>
        </p>
      </div>
      <?php elseif ($rows === [] && ($q !== '' || $sentFilter !== '')): ?>
      <div class="empty-state">
        <?php if ($sentFilter === '0'): ?>
          <p>No unmarked sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
          <p class="muted">New imports and adds appear here until you mark them emailed.</p>
        <?php elseif ($sentFilter === '1'): ?>
          <p>No emailed sites<?= $q !== '' ? ' matching this search' : '' ?>.</p>
          <p class="muted">Use “Mark emailed” or “Mark up to here” while working the campaign.</p>
        <?php else: ?>
          <p>No sites match “<?= h($q) ?>”.</p>
        <?php endif; ?>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <a class="btn secondary" href="<?= h($formAction) ?>">Clear filters</a>
        </p>
      </div>
      <?php endif; ?>
      <?php if ($pages > 1 || $total > 0): ?>
      <div class="pagination" style="margin-top:0.85rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
        <?php if ($pageNum > 1): ?>
          <a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a>
        <?php endif; ?>
        <span class="muted">Page <?= (int) $pageNum ?> / <?= (int) $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?><?= $q !== '' ? ' matches' : '' ?></span>
        <?php if ($pageNum < $pages): ?>
          <a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-bulk-add">
      <h2><?= label_with_info('Add many sites (paste or file)', 'Admin bulk entry. Paste 1000+ lines, or import CSV / Excel (.xlsx) / TXT. One line or row per site: site + up to 4 emails. Each site needs at least one email.') ?></h2>
      <p class="help">
        Columns: <strong>Site name, Email 1, Email 2, Email 3, Email 4</strong>
        (comma, tab, or semicolon). Header row is optional and skipped.
        Built for large lists — paste or upload thousands of rows at once.
      </p>

      <form method="post" action="<?= h($formAction) ?>" style="margin-top:0.85rem"
            data-show-processing="Adding pasted sites…">
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

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Import ' . $sheetCountry . ' from archive', 'Adds only new sites from Final or Admin. Sites already on this sheet are left unchanged. Sites removed from this sheet are never re-added. Archives are not changed.') ?></h2>
      <p class="help">
        Imports <strong>new sites only</strong> — skips anything already on the sheet, and never re-adds sites
        that were removed (unless you Allow again below, or paste/+ Add them yourself).
      </p>
      <form method="post" action="<?= h($formAction) ?>"
            data-show-processing="Importing new sites…"
            onsubmit="return confirm('Import NEW sites into <?= h($sheetCountry) ?>?\n\nSites already on this sheet stay unchanged.\nPreviously removed sites are not re-added.\n\nFinal/Admin archives are not changed.');">
        <input type="hidden" name="action" value="import">
        <label for="camp_import_source">Source</label>
        <select id="camp_import_source" name="source">
          <option value="admin_all">All sites with emails - Final</option>
          <option value="admin">Sites with emails - Admin</option>
        </select>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Import new sites into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem" id="camp-excluded">
      <h2><?= label_with_info('Previously removed sites', 'Sites deleted from this Email Sheet (by Admin or Communication Team) are listed here so Final/Admin import never re-adds them. Allow again if a removal was a mistake.') ?></h2>
      <?php if ($excludedCount < 1): ?>
        <p class="muted" style="margin:0">No excluded sites yet. When a site is removed from this sheet, it appears here.</p>
      <?php else: ?>
        <p class="help" style="margin-top:0">
          <?= (int) $excludedCount ?> site<?= $excludedCount === 1 ? '' : 's' ?> blocked from archive import.
          <?php if ($excludedCount > count($excludedDomains)): ?>
            Showing first <?= count($excludedDomains) ?>.
          <?php endif; ?>
        </p>
        <div class="table-wrap">
          <table class="extracted-country-table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Removed</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($excludedDomains as $ex): ?>
              <tr>
                <td><code><?= h((string) $ex['domain']) ?></code></td>
                <td class="muted"><?= h((string) $ex['excluded_at']) ?></td>
                <td class="num">
                  <form method="post" action="<?= h($formAction) ?>" style="display:inline"
                        data-stay-ajax data-stay-remove-row>
                    <input type="hidden" name="action" value="allow_excluded_domain">
                    <input type="hidden" name="domain" value="<?= h((string) $ex['domain']) ?>">
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                    <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                    <button class="btn secondary small" type="submit"
                            title="Let the next Final/Admin import add this site again">Allow again</button>
                  </form>
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
          covering every country in it. Deletes that match <strong><?= h($sheetCountry) ?></strong> update this sheet.
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
            onsubmit="return confirm('Remove <?= h($sheetCountry) ?> from project “<?= h($projectName) ?>” and delete all its rows?');">
        <input type="hidden" name="action" value="delete_sheet">
        <button class="btn danger" type="submit">Remove country from project</button>
      </form>
    </div>
    <?= email_field_clear_script_tag() ?>
    <script src="<?= h(script_asset_url('js/email-campaign-sheet.js')) ?>" defer></script>
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
        </p>
      </div>
      <div class="actions">
        <?php if ($availableCountries): ?>
          <a class="btn" href="#add-country">Add country</a>
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
                      <a class="btn small" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">Open</a>
                      <form method="post" action="<?= h($projectForm) ?>"
                            onsubmit="return confirm(<?= h(json_encode('Remove “' . $cName . '” from this project?', JSON_UNESCAPED_UNICODE)) ?>);">
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
            <h2 style="margin:0"><?= label_with_info('Project & Communication Team', 'One search bar for Communication Team covers every country in this project. Deletes update the matching country sheet.') ?></h2>
          </div>
          <form method="post" action="<?= h($projectForm) ?>" class="camp-hub-create-form" autocomplete="off"
                data-show-processing="Saving project…">
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
                  <span class="camp-hub-field-hint">They search the whole project; deletes update the country sheet that matched.</span>
                </span>
              </label>
            </div>
            <p class="actions camp-hub-create-actions">
              <button class="btn" type="submit">Save project settings</button>
            </p>
          </form>
        </section>

        <section class="card" style="margin-top:1rem">
          <h2>Danger zone</h2>
          <form method="post" action="<?= h($projectForm) ?>"
                data-show-processing="Deleting project…"
                onsubmit="return confirm(<?= h(json_encode('Delete project “' . $projectName . '” and all country sheets inside it?', JSON_UNESCAPED_UNICODE)) ?>);">
            <input type="hidden" name="action" value="delete_project">
            <input type="hidden" name="project_id" value="<?= (int) $projectIdParam ?>">
            <button class="btn danger" type="submit">Delete whole project</button>
          </form>
        </section>
      </aside>
    </div>
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
    <a class="btn" href="#create-project">Create project</a>
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
                  <a class="btn small" href="<?= h($campBase) ?>&amp;project=<?= (int) $p['id'] ?>">Open</a>
                  <form method="post" action="<?= h($campBase) ?>"
                        onsubmit="return confirm(<?= h(json_encode('Delete project “' . $pName . '” and all its countries?', JSON_UNESCAPED_UNICODE)) ?>);">
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
