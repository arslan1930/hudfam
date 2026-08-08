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
    $pageNum = max(1, (int) get('p', 1));
    $perPage = 100;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $returnQ = trim((string) post('q'));
        $returnP = max(1, (int) post('p', 1));
        $back = $campBase . '&sheet=' . $sheetId;
        if ($returnQ !== '') {
            $back .= '&q=' . rawurlencode($returnQ);
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
                    ], !empty($del['ok']) ? 200 : 404);
                }
                flash($del['ok'] ? 'ok' : 'error', $del['ok']
                    ? 'Removed ' . (string) ($del['domain'] ?? 'site') . '.'
                    : (string) ($del['error'] ?? 'Could not remove row.'));
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
                if (clear_email_campaign_domain_exclusion($sheetId, $domain)) {
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

    $inv = email_campaign_rows_inventory_query($sheetId, ['q' => $q], $pageNum, $perPage);
    $rows = $inv['rows'];
    $total = (int) $inv['total'];
    $pages = (int) $inv['pages'];
    $pageNum = (int) $inv['page'];
    $sheetTotal = $q !== '' ? count_email_campaign_rows($sheetId) : $total;
    $filledCount = $sheetTotal;
    $excludedCount = count_email_campaign_excluded_domains($sheetId);
    $excludedDomains = list_email_campaign_excluded_domains($sheetId, 200);
    $formAction = $campBase . '&sheet=' . $sheetId;
    $qs = http_build_query(array_filter([
        'page' => 'admin_emails_data',
        'folder' => 'email_campaigns',
        'sheet' => $sheetId,
        'q' => $q,
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
          <?= $q !== '' ? ' · ' . (int) $total . ' match' . ((int) $total === 1 ? '' : 'es') : '' ?>
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
    </p>

    <div class="card">
      <div class="invoice-list-toolbar swe-list-toolbar" style="margin-bottom:0.75rem">
        <div>
          <h2 style="margin:0"><?= label_with_info('Sites with emails', 'Same model as Our database: one country sheet, paginated rows (not all 100K at once). Use + Add site for a single row. Clearing the last email removes the site.') ?></h2>
          <p class="help" style="margin:0.25rem 0 0">
            Paste up to 4 emails into any email box. Edits <strong>autosave</strong>.
            Browse page by page — large sheets stay fast.
          </p>
        </div>
        <div class="actions" style="align-items:center;gap:0.5rem;flex-wrap:wrap">
          <button type="button" class="btn small" data-camp-add-toggle title="Add one site + up to 4 emails">+ Add site</button>
          <label class="sheet-search swe-row-search-wrap" for="swe-row-search">
            <span class="visually-hidden">Search sites and emails</span>
            <input id="swe-row-search" type="search" placeholder="Search site or email…"
                   value="<?= h($q) ?>" autocomplete="off" spellcheck="false" data-no-draft
                   <?= $filledCount < 1 && $q === '' ? 'disabled' : '' ?>
                   title="Filter this page · Enter = next match · Ctrl/Cmd+Enter = search all pages">
            <span class="sheet-search-meta muted" data-swe-row-search-meta hidden></span>
          </label>
        </div>
      </div>
      <p class="help" id="swe_status" role="status" aria-live="polite" hidden></p>

      <div class="table-wrap">
        <table class="swe-table" id="camp-sheet-table">
          <thead>
            <tr>
              <th class="swe-col-site">Site name</th>
              <th class="swe-col-emails">Emails (up to 4)</th>
              <th class="swe-col-actions">Actions</th>
            </tr>
          </thead>
          <tbody id="camp-sheet-tbody">
          <tr id="camp-add-row" class="camp-add-row" hidden>
            <td colspan="3">
              <form method="post" action="<?= h($formAction) ?>" class="swe-row-form swe-add-form" id="camp-add-form"
                    autocomplete="off" data-show-processing="Adding site…">
                <input type="hidden" name="action" value="save_row">
                <input type="hidden" name="site_id" value="0">
                <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                <div class="swe-row-grid">
                  <div class="swe-site-block">
                    <label class="visually-hidden" for="camp_add_domain">Site name</label>
                    <input id="camp_add_domain" class="swe-domain" name="domain" required
                           placeholder="example.com" spellcheck="false" autocomplete="off"
                           aria-label="Site name">
                  </div>
                  <div class="swe-emails" aria-label="Emails" data-swe-emails>
                    <?= render_clearable_email_input('email1', '', ['id' => 'camp_add_e1', 'swe' => true, 'placeholder' => 'email 1 · or paste up to 4', 'aria_label' => 'Clear email 1']) ?>
                    <?= render_clearable_email_input('email2', '', ['id' => 'camp_add_e2', 'swe' => true, 'placeholder' => 'email 2', 'aria_label' => 'Clear email 2']) ?>
                    <?= render_clearable_email_input('email3', '', ['id' => 'camp_add_e3', 'swe' => true, 'placeholder' => 'email 3', 'aria_label' => 'Clear email 3']) ?>
                    <?= render_clearable_email_input('email4', '', ['id' => 'camp_add_e4', 'swe' => true, 'placeholder' => 'email 4', 'aria_label' => 'Clear email 4']) ?>
                  </div>
                  <div class="swe-row-actions">
                    <button class="btn small" type="submit">Add row</button>
                    <button class="btn secondary small" type="button" id="camp-add-cancel" data-camp-add-cancel>Cancel</button>
                  </div>
                </div>
              </form>
            </td>
          </tr>
          <?php foreach ($rows as $r):
              $rid = (int) $r['id'];
              $domain = (string) $r['domain'];
              $e1 = (string) $r['email1'];
              $e2 = (string) $r['email2'];
              $e3 = (string) $r['email3'];
              $e4 = (string) $r['email4'];
              $hasEmail = $e1 !== '' || $e2 !== '' || $e3 !== '' || $e4 !== '';
              $hay = mb_strtolower($domain . ' ' . $e1 . ' ' . $e2 . ' ' . $e3 . ' ' . $e4);
              ?>
            <tr data-swe-row data-search="<?= h($hay) ?>" data-site-id="<?= $rid ?>"
                data-has-email="<?= $hasEmail ? '1' : '0' ?>">
              <td colspan="3">
                <form method="post" action="<?= h($formAction) ?>" class="swe-row-form" data-swe-save>
                  <input type="hidden" name="action" value="save_row">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
                  <input type="hidden" name="q" value="<?= h($q) ?>" data-swe-q>
                  <input type="hidden" name="p" value="<?= (int) $pageNum ?>">
                  <div class="swe-row-grid">
                    <div class="swe-site-block">
                      <label class="visually-hidden">Site name</label>
                      <input class="swe-domain" name="domain" value="<?= h($domain) ?>" required
                             spellcheck="false" autocomplete="off" aria-label="Site name">
                    </div>
                    <div class="swe-emails" aria-label="Emails" data-swe-emails>
                      <?= render_clearable_email_input('email1', $e1, ['swe' => true, 'placeholder' => 'email 1 · or paste up to 4', 'aria_label' => 'Clear email 1']) ?>
                      <?= render_clearable_email_input('email2', $e2, ['swe' => true, 'placeholder' => 'email 2', 'aria_label' => 'Clear email 2']) ?>
                      <?= render_clearable_email_input('email3', $e3, ['swe' => true, 'placeholder' => 'email 3', 'aria_label' => 'Clear email 3']) ?>
                      <?= render_clearable_email_input('email4', $e4, ['swe' => true, 'placeholder' => 'email 4', 'aria_label' => 'Clear email 4']) ?>
                    </div>
                    <div class="swe-row-actions">
                      <button class="btn secondary small" type="submit" form="camp-remove-<?= $rid ?>"
                              onclick="return confirm('Remove complete row for <?= h($domain) ?>?');">Remove row</button>
                    </div>
                  </div>
                </form>
                <form id="camp-remove-<?= $rid ?>" method="post" action="<?= h($formAction) ?>" data-swe-remove hidden>
                  <input type="hidden" name="action" value="remove_site">
                  <input type="hidden" name="site_id" value="<?= $rid ?>">
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
      <?php if ($rows === [] && $q === ''): ?>
      <div class="empty-state" id="camp-empty-state">
        <p>No sites in this sheet yet.</p>
        <p class="muted">Admin adds data here: <strong>+ Add site</strong>, paste, file import, or <strong>Import from Final (new sites only)</strong>.</p>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <button type="button" class="btn" data-camp-add-toggle>+ Add site</button>
          <a class="btn secondary" href="#camp-bulk-add">Paste / import file</a>
        </p>
      </div>
      <?php elseif ($rows === [] && $q !== ''): ?>
      <div class="empty-state">
        <p>No sites match “<?= h($q) ?>”.</p>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <a class="btn secondary" href="<?= h($formAction) ?>">Clear search</a>
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
                        data-show-processing="Allowing site again…">
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
                flash('error', (string) ($result['error'] ?? 'Could not update visibility.'));
            } else {
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
                <form method="post" action="<?= h($campBase) ?>" class="camp-hub-team-form">
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
