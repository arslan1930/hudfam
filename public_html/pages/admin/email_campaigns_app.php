<?php
/**
 * Admin · Emails data · Email campaign data
 * One editable sheet per country (site + up to 4 emails — same workflow as Sites with emails).
 * Always connected to Communication Team super search.
 *
 * Expects: $user, $base (Emails data hub URL)
 */
ensure_email_campaign_schema();
ensure_sites_with_emails_schema();
seed_countries_if_empty(db());

$campBase = $base . '&folder=email_campaigns';
$sheetId = isset($sheetId) ? (int) $sheetId : (int) get('sheet');
$countryParam = (string) get('country');

// Open by country name shortcut
if ($sheetId < 1 && $countryParam !== '') {
    $byCountry = get_email_campaign_sheet_by_country($countryParam);
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $back = $campBase . '&sheet=' . $sheetId;
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
                redirect($back);
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
                redirect($back);
            }
            if ($action === 'import') {
                $source = (string) post('source') === 'admin' ? 'admin' : 'admin_all';
                $result = import_email_campaign_sheet_from_swe($sheetId, $source, $sheetCountry);
                $label = $source === 'admin' ? 'Sites with emails - Admin' : 'All sites with emails - Final';
                flash(
                    'ok',
                    'Imported ' . $sheetCountry . ' from ' . $label . ': '
                    . (int) $result['imported'] . ' new, '
                    . (int) $result['updated'] . ' updated'
                    . ((int) ($result['skipped'] ?? 0) > 0
                        ? ', ' . (int) $result['skipped'] . ' skipped (no emails)'
                        : '')
                    . '.'
                );
                redirect($back);
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
                delete_email_campaign_sheet($sheetId);
                flash('ok', 'Deleted email sheet for ' . $sheetCountry . '.');
                redirect($campBase);
            }
        } catch (Throwable $e) {
            if ($wantsJson) {
                $jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            flash('error', $e->getMessage());
            redirect($back);
        }
    }

    purge_blank_email_campaign_rows($sheetId);
    $rows = list_email_campaign_rows($sheetId);
    $filledCount = count($rows);
    $formAction = $campBase . '&sheet=' . $sheetId;
    $q = trim((string) get('q'));
    $sheet = get_email_campaign_sheet($sheetId) ?: $sheet;
    $projectName = email_campaign_sheet_project_name($sheet);
    $teamVisible = email_campaign_sheet_team_visible($sheet);

    render_header('Email sheet · ' . $projectName, 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails data', 'href' => $base],
        ['label' => 'Email campaign data', 'href' => $campBase],
        ['label' => $projectName],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info($projectName, 'Project Email Sheet for ' . $sheetCountry . '. Admin adds all data: (+) one row, paste many sites + emails, or import CSV / Excel / TXT. When shown to Communication Team, they get a search bar named with this project.') ?></h1>
        <p class="muted">
          <?= h($sheetCountry) ?> ·
          <span id="swe_total_label"><?= (int) $filledCount ?></span> site<?= (int) $filledCount === 1 ? '' : 's' ?>
          · Admin fills site + up to 4 emails · autosave ·
          Communication Team search: <strong><?= $teamVisible ? 'shown' : 'hidden' ?></strong>
        </p>
      </div>
      <div class="actions">
        <button type="button" class="btn" id="camp-add-toggle" data-camp-add-toggle title="Add one site + up to 4 emails">+ Add site</button>
        <a class="btn secondary" href="#camp-bulk-add">Paste / import</a>
        <a class="btn secondary" href="<?= h($campBase) ?>">All projects</a>
      </div>
    </div>
    <p class="help">
      Data on this sheet is added by <strong>Admin</strong>: use <strong>+ Add site</strong> for one row,
      or paste / import hundreds or thousands of sites with emails below.
    </p>

    <div class="card" style="margin-bottom:1rem">
      <h2 style="margin-top:0"><?= label_with_info('Project & Communication Team search', 'Project name labels the Communication Team search bar. Turn the search bar on or off for that team without deleting the sheet.') ?></h2>
      <form method="post" action="<?= h($formAction) ?>" autocomplete="off">
        <input type="hidden" name="action" value="save_settings">
        <label for="camp_project_name">Project name</label>
        <input id="camp_project_name" name="project_name" required maxlength="180"
               value="<?= h($projectName) ?>" placeholder="e.g. Spring outreach DE">
        <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.85rem">
          <input type="checkbox" name="team_search_visible" value="1" <?= $teamVisible ? 'checked' : '' ?>>
          Show search bar to Communication Team
        </label>
        <p class="help" style="margin-top:0.35rem">
          When on, Communication Team sees a search bar titled <strong><?= h($projectName) ?></strong>
          to find site + emails and delete (same actions as before).
        </p>
        <p class="actions" style="margin-top:0.85rem">
          <button class="btn" type="submit">Save project settings</button>
        </p>
      </form>
    </div>

    <div class="card">
      <div class="invoice-list-toolbar swe-list-toolbar" style="margin-bottom:0.75rem">
        <div>
          <h2 style="margin:0"><?= label_with_info('Sites with emails', 'Each row is one site with up to 4 emails. Use + Add site for a single row. Clearing the last email removes the site.') ?></h2>
          <p class="help" style="margin:0.25rem 0 0">
            Paste up to 4 emails into any email box. Edits <strong>autosave</strong>.
            Every site must keep at least one email.
          </p>
        </div>
        <div class="actions" style="align-items:center;gap:0.5rem;flex-wrap:wrap">
          <button type="button" class="btn small" data-camp-add-toggle title="Add one site + up to 4 emails">+ Add site</button>
          <label class="sheet-search swe-row-search-wrap" for="swe-row-search">
            <span class="visually-hidden">Search sites and emails</span>
            <input id="swe-row-search" type="search" placeholder="Search site or email…"
                   value="<?= h($q) ?>" autocomplete="off" spellcheck="false" data-no-draft
                   <?= $filledCount < 1 && $q === '' ? 'disabled' : '' ?>
                   title="Filter · Enter = next match">
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
              <form method="post" action="<?= h($formAction) ?>" class="swe-row-form swe-add-form" id="camp-add-form" autocomplete="off">
                <input type="hidden" name="action" value="save_row">
                <input type="hidden" name="site_id" value="0">
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
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="help sheet-search-empty" data-swe-row-search-empty hidden>
        No matching <strong>site + emails</strong> rows.
      </p>
      <?php if ($rows === []): ?>
      <div class="empty-state" id="camp-empty-state">
        <p>No sites in this sheet yet.</p>
        <p class="muted">Admin adds data here: press <strong>+ Add site</strong>, paste a list, or import a CSV / Excel / TXT file.</p>
        <p class="actions" style="justify-content:center;margin-top:0.75rem">
          <button type="button" class="btn" data-camp-add-toggle>+ Add site</button>
          <a class="btn secondary" href="#camp-bulk-add">Paste / import file</a>
        </p>
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

      <form method="post" action="<?= h($formAction) ?>" style="margin-top:0.85rem">
        <input type="hidden" name="action" value="paste">
        <label for="camp_paste_text">Paste sites + emails</label>
        <textarea id="camp_paste_text" name="paste_text" class="inventory-box camp-bulk-paste" rows="14"
                  placeholder="Site name, Email 1, Email 2, Email 3, Email 4&#10;example.com, hello@example.com, sales@example.com&#10;other.org, contact@other.org&#10;shop.de info@shop.de support@shop.de"></textarea>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Add pasted rows</button>
        </p>
      </form>

      <hr class="camp-bulk-divider">

      <form method="post" action="<?= h($formAction) ?>" enctype="multipart/form-data">
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
      <h2><?= label_with_info('Import ' . $sheetCountry . ' from archive (optional)', 'Optional shortcut: copy this country’s sites that already have emails from Final or Admin. Empty-email sites are skipped. Primary entry is still Admin paste / file / + Add site.') ?></h2>
      <p class="help">Copies only sites that have at least one email. Archives are not changed.</p>
      <form method="post" action="<?= h($formAction) ?>"
            onsubmit="return confirm('Import <?= h($sheetCountry) ?> into this sheet?');">
        <input type="hidden" name="action" value="import">
        <label for="camp_import_source">Source</label>
        <select id="camp_import_source" name="source">
          <option value="admin_all">All sites with emails - Final</option>
          <option value="admin">Sites with emails - Admin</option>
        </select>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn secondary" type="submit">Import country into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Communication Team', 'When “Show search bar” is on, Communication Team gets a project search bar titled with this project name. Deletes there update this sheet.') ?></h2>
      <p class="help">
        <?php if ($teamVisible): ?>
          Communication Team sees a search bar named <strong><?= h($projectName) ?></strong>
          (site + emails · delete both or remove only email).
        <?php else: ?>
          Communication Team search bar is <strong>hidden</strong> for this project.
          Turn it on under Project &amp; Communication Team search above.
        <?php endif; ?>
      </p>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Danger zone</h2>
      <form method="post" action="<?= h($formAction) ?>"
            onsubmit="return confirm('Delete the <?= h($sheetCountry) ?> email sheet and all its rows?');">
        <input type="hidden" name="action" value="delete_sheet">
        <button class="btn danger" type="submit">Delete country sheet</button>
      </form>
    </div>
    <?= email_field_clear_script_tag() ?>
    <script src="<?= h(script_asset_url('js/email-campaign-sheet.js')) ?>" defer></script>
    <?php
    render_footer('admin');
    return;
}

// --- List + create by country / project ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'create') {
            $projectName = trim((string) post('project_name'));
            if ($projectName === '') {
                flash('error', 'Project name is required.');
                redirect($campBase . '#create-email-sheet');
            }
            $teamVisible = (string) post('team_search_visible') === '1';
            $id = create_email_campaign_sheet(
                (string) post('country'),
                (int) ($user['id'] ?? 0),
                $projectName,
                $teamVisible
            );
            $sheet = get_email_campaign_sheet($id);
            $countryName = $sheet ? email_campaign_sheet_country($sheet) : (string) post('country');
            $projectLabel = $sheet ? email_campaign_sheet_project_name($sheet) : $projectName;
            purge_blank_email_campaign_rows($id);
            $msg = 'Email sheet ready for ' . $countryName . ' · project “' . $projectLabel . '”.';
            $msg .= $teamVisible
                ? ' Communication Team search bar created.'
                : ' Communication Team search bar is hidden (you can turn it on later).';
            flash('ok', $msg);
            redirect($campBase . '&sheet=' . $id);
        }
        if ($action === 'toggle_team_search') {
            $id = (int) post('id');
            $visible = (string) post('team_search_visible') === '1';
            $result = set_email_campaign_sheet_team_visible($id, $visible);
            $sheet = get_email_campaign_sheet($id);
            $label = $sheet ? email_campaign_sheet_project_name($sheet) : 'sheet';
            if (empty($result['ok'])) {
                flash('error', (string) ($result['error'] ?? 'Could not update visibility.'));
            } else {
                flash('ok', $visible
                    ? 'Showing “' . $label . '” search bar to Communication Team.'
                    : 'Hid “' . $label . '” search bar from Communication Team.');
            }
            redirect($campBase);
        }
        if ($action === 'delete') {
            $id = (int) post('id');
            $sheet = get_email_campaign_sheet($id);
            if (!$sheet) {
                flash('error', 'Sheet not found.');
            } else {
                $label = email_campaign_sheet_project_name($sheet);
                delete_email_campaign_sheet($id);
                flash('ok', 'Deleted email sheet “' . $label . '”.');
            }
            redirect($campBase);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($campBase);
    }
}

$sheets = list_email_campaign_sheets();
$existingCountries = [];
foreach ($sheets as $s) {
    $existingCountries[mb_strtolower((string) $s['country'])] = true;
}
$allCountries = list_countries(null, true);
$availableCountries = [];
foreach ($allCountries as $c) {
    $name = (string) $c['name'];
    if (!isset($existingCountries[mb_strtolower($name)])) {
        $availableCountries[] = $c;
    }
}

render_header('Email campaign data', 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Emails data', 'href' => $base],
    ['label' => 'Email campaign data'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Email campaign data', 'Create an Email Sheet per country. Admin adds all site + email data (+ Add site, paste, or CSV/Excel/TXT import). Optionally expose a Communication Team search bar.') ?></h1>
    <p class="muted">
      One sheet per country — Admin fills site + up to 4 emails.
      Assign a <strong>project name</strong> and choose whether Communication Team gets that search bar.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="<?= h($base) ?>">All folders</a>
  </div>
</div>

<div class="orders-layout">
  <section class="card">
    <h2><?= label_with_info('Project sheets', 'Open a project to edit site + email rows. Toggle Communication Team search per sheet.') ?></h2>
    <?php if (!$sheets): ?>
      <div class="empty-state">
        <p>No project sheets yet.</p>
        <p class="muted">Create an Email Sheet on the right with a project name, then add site + emails.</p>
      </div>
    <?php else: ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <label class="sheet-search" for="camp-country-search">
          <span class="visually-hidden">Search projects</span>
          <input id="camp-country-search" type="search" placeholder="Search project or country…"
                 autocomplete="off" spellcheck="false" data-no-draft>
        </label>
      </div>
      <table class="extracted-country-table" id="camp-country-table">
        <thead>
          <tr>
            <th>Project</th>
            <th>Country</th>
            <th class="num">Sites</th>
            <th>Team search</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sheets as $s):
            $cName = (string) $s['country'];
            $pName = (string) $s['project_name'];
            $visible = !empty($s['team_search_visible']);
            $hay = mb_strtolower($pName . ' ' . $cName . ' ' . (int) $s['row_count'] . ' sites');
            ?>
          <tr data-camp-country-row data-search="<?= h($hay) ?>">
            <td>
              <a class="extracted-country-link" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">
                <?= h($pName) ?>
              </a>
            </td>
            <td class="muted"><?= h($cName) ?></td>
            <td class="num"><?= (int) $s['row_count'] ?></td>
            <td>
              <form method="post" action="<?= h($campBase) ?>" style="display:inline">
                <input type="hidden" name="action" value="toggle_team_search">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="team_search_visible" value="<?= $visible ? '0' : '1' ?>">
                <button class="btn secondary small" type="submit"
                        title="<?= $visible ? 'Hide from Communication Team' : 'Show to Communication Team' ?>">
                  <?= $visible ? 'Shown' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td class="num">
              <a class="btn small" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">Open</a>
              <form method="post" action="<?= h($campBase) ?>" style="display:inline"
                    onsubmit="return confirm(<?= h(json_encode('Delete sheet “' . $pName . '”?', JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn secondary small" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
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

  <section class="card" id="create-email-sheet">
    <h2><?= label_with_info('Create an Email Sheet', 'Pick a country and project name. After create, Admin adds all rows: + Add site, paste thousands of lines, or import CSV / Excel / TXT.') ?></h2>
    <p class="muted" style="margin-top:0">
      The sheet starts empty — Admin adds the data.
      You can also turn on a Communication Team search bar titled with your project name.
    </p>
    <?php if (!$availableCountries): ?>
      <div class="empty-state">
        <p>Every country already has a sheet.</p>
      </div>
    <?php else: ?>
    <form method="post" action="<?= h($campBase) ?>" autocomplete="off">
      <input type="hidden" name="action" value="create">
      <label for="new_camp_country">Country</label>
      <select id="new_camp_country" name="country" required>
        <option value="">Select country…</option>
        <?php foreach ($availableCountries as $c): ?>
          <option value="<?= h((string) $c['name']) ?>">
            <?= h((string) $c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label for="new_camp_project" style="margin-top:0.85rem;display:block">Project name</label>
      <input id="new_camp_project" name="project_name" required maxlength="180"
             placeholder="e.g. Q2 Germany outreach">
      <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.85rem">
        <input type="checkbox" name="team_search_visible" value="1" checked>
        Show search bar to Communication Team
      </label>
      <p class="help" style="margin-top:0.35rem">
        Uncheck to keep the sheet Admin-only until you’re ready.
      </p>
      <p class="actions" style="margin-top:1rem">
        <button class="btn" type="submit">Create an Email Sheet</button>
      </p>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php
render_footer('admin');
