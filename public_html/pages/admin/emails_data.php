<?php
/**
 * Admin · Emails data — email archives + Email campaign data sheets.
 */
$user = require_admin();
ensure_sites_with_emails_schema();
ensure_email_campaign_schema();
seed_countries_if_empty(db());

$base = 'index.php?page=admin_emails_data';
$folder = (string) get('folder');
$allowedFolders = ['sites_with_emails', 'all_sites_with_emails', 'email_campaigns'];
if ($folder !== '' && !in_array($folder, $allowedFolders, true)) {
    flash('error', 'Unknown folder.');
    redirect($base);
}
// New reminder for Emails Admin is cleared when a country sheet is opened (not hub-only).
// See sites_with_emails_app.php (admin scope country view).

// Hub-only: Admin super-search (reuse Team Admin emails delete helpers).
if ($folder === '' && (string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = (string) get('q');
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
    try {
        echo json_encode([
            'ok' => true,
            'q' => $q,
            'suggestions' => search_sites_with_emails_admin_suggestions($q, 25),
        ], $flags);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'q' => $q, 'suggestions' => [], 'error' => 'Search failed.'], $flags);
    }
    exit;
}

if ($folder === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    $json = static function (array $payload, int $code = 200) use ($wantsJson, $base): void {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode($payload);
            exit;
        }
        if (!empty($payload['ok'])) {
            flash('ok', (string) ($payload['message'] ?? 'Done.'));
        } else {
            flash('error', (string) ($payload['error'] ?? 'Could not complete.'));
        }
        redirect($base);
    };

    if ($action === 'repair_final_archive') {
        require_csrf();
        $result = sync_sites_with_emails_admin_to_all();
        $added = (int) ($result['added'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $unchanged = (int) ($result['unchanged'] ?? 0);
        $msg = 'Final archive repaired · added ' . $added
            . ' · updated ' . $updated
            . ' · unchanged ' . $unchanged
            . ' · archive copies kept';
        $bits = [];
        foreach (['added_samples' => 'Added', 'updated_samples' => 'Updated'] as $key => $label) {
            $samples = $result[$key] ?? [];
            if (is_array($samples) && $samples !== []) {
                $bits[] = $label . ': ' . implode('; ', array_slice($samples, 0, 5))
                    . (count($samples) > 5 ? '…' : '');
            }
        }
        if ($bits !== []) {
            $msg .= ' · ' . implode(' · ', $bits);
        }
        flash('ok', $msg . '.');
        redirect($base);
    }

    if ($action === 'delete_row') {
        require_csrf();
        $siteId = (int) post('site_id');
        $before = get_site_with_emails($siteId, 'admin');
        $result = delete_sites_with_emails_admin_row($siteId);
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Delete failed.')], 404);
        }
        $country = $before ? (string) $before['country'] : '';
        $json([
            'ok' => true,
            'message' => 'Deleted ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . ' from Sites with emails - Admin.',
            'domain' => (string) $result['domain'],
            'country' => $country,
            'mode' => 'row',
        ]);
    }

    if ($action === 'delete_email') {
        require_csrf();
        $siteId = (int) post('site_id');
        $before = get_site_with_emails($siteId, 'admin');
        $result = remove_email_from_sites_with_emails_admin($siteId, (string) post('email'));
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not remove email.')], 400);
        }
        $country = $before ? (string) $before['country'] : '';
        $rowDeleted = !empty($result['row_deleted']);
        $msg = $rowDeleted
            ? 'Removed last email from ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . ' · Admin working-list row deleted · Final keeps its archive copy.'
            : 'Removed ' . (string) ($result['removed'] ?? '') . ' from ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . '. Site name kept in Admin.';
        $json([
            'ok' => true,
            'message' => $msg,
            'domain' => (string) $result['domain'],
            'country' => $country,
            'emails' => $result['emails'] ?? [],
            'removed' => (string) ($result['removed'] ?? ''),
            'site_id' => $siteId,
            'mode' => $rowDeleted ? 'row' : 'email',
            'row_deleted' => $rowDeleted,
        ]);
    }

    if ($action !== '') {
        $json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
}

// --- Hub ---
if ($folder === '') {
    $sweCountryRows = list_sites_with_emails_country_rows('admin');
    $sweTotal = 0;
    $sweWithEmails = 0;
    foreach ($sweCountryRows as $r) {
        $sweTotal += (int) $r['total'];
        $sweWithEmails += (int) $r['with_emails'];
    }
    $sweCountryCount = count($sweCountryRows);

    $allCountryRows = list_sites_with_emails_country_rows('admin_all');
    $allTotal = 0;
    $allWithEmails = 0;
    foreach ($allCountryRows as $r) {
        $allTotal += (int) $r['total'];
        $allWithEmails += (int) $r['with_emails'];
    }
    $allCountryCount = count($allCountryRows);
    $archiveDrift = function_exists('sites_with_emails_final_needs_repair')
        ? sites_with_emails_final_needs_repair()
        : ($allTotal < $sweTotal);

    $adminEmailsNew = function_exists('admin_has_new_data') && admin_has_new_data('emails_admin', $user);
    $adminNewByCountry = function_exists('swe_admin_new_counts_by_country')
        ? swe_admin_new_counts_by_country($user)
        : [];
    $adminNewTotal = 0;
    foreach ($adminNewByCountry as $n) {
        $adminNewTotal += (int) $n;
    }

    $campaignSheets = list_email_campaign_sheets();
    $campaignSheetCount = count($campaignSheets);
    $campaignRowTotal = 0;
    foreach ($campaignSheets as $cs) {
        $campaignRowTotal += (int) $cs['row_count'];
    }

    render_header('Emails data', 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails data'],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info('Emails data', 'Three folders: Admin is the working list from Team Push (emailed checkpoint here). Final keeps a copy after you mark emailed or remove on Admin. Campaign is separate country sheets with their own emailed marks.') ?></h1>
        <p class="muted">Admin working list · Final archive · Campaign country sheets.</p>
      </div>
    </div>

    <?= guide_emails_data() ?>

    <?php if ($sweTotal < 1 && $allTotal < 1 && $campaignSheetCount < 1): ?>
    <div class="card" style="margin-bottom:1rem">
      <div class="empty-state">
        <p>No email data yet.</p>
        <p class="muted">
          Team fills <strong>Admin</strong> via Push.
          You create country sheets under <strong>Campaign</strong>.
        </p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($archiveDrift): ?>
    <div class="card" style="margin-bottom:1rem">
      <p style="margin:0 0 0.65rem">
        Final is missing some Admin sites or has older emails
        (Admin <strong><?= (int) $sweTotal ?></strong> · Final <strong><?= (int) $allTotal ?></strong>).
        Repair copies Admin into Final. Rows already removed from Admin stay in Final.
      </p>
      <form method="post" action="<?= h($base) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="repair_final_archive">
        <button class="btn secondary" type="submit">Repair Final archive</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($sweTotal > 0): ?>
    <?php render_sites_with_emails_admin_super_search($base); ?>
    <?php endif; ?>

    <div class="card">
      <div class="folders emails-data-folders">
        <div class="folder-with-info">
          <a class="folder<?= $adminEmailsNew ? ' has-admin-new' : '' ?>" href="<?= h($base) ?>&amp;folder=sites_with_emails">
            <h3>Admin<?= function_exists('admin_new_badge_html') ? admin_new_badge_html('emails_admin', $user) : '' ?></h3>
            <p class="muted">
              Working list from Team Push · emailed checkpoint here ·
              <?= (int) $sweCountryCount ?> countr<?= $sweCountryCount === 1 ? 'y' : 'ies' ?>
              · <?= (int) $sweTotal ?> site<?= (int) $sweTotal === 1 ? '' : 's' ?>
              · <?= (int) $sweWithEmails ?> with email<?= (int) $sweWithEmails === 1 ? '' : 's' ?>
              <?php if ($adminNewTotal > 0): ?>
                · <span class="swe-country-new">+<?= (int) $adminNewTotal ?> new</span>
              <?php endif; ?>
            </p>
            <?php folder_open_cue(); ?>
          </a>
          <?= info_icon('Working site + email list filled when Team pushes from Sites with emails - Team. Emailed progress is tracked here only. Final is a separate mirror without emailed marks. Communication Team can super-search Admin data across countries. Open a country to clear New for that folder.', 'About Sites with emails - Admin') ?>
        </div>
        <div class="folder-with-info">
          <a class="folder" href="<?= h($base) ?>&amp;folder=all_sites_with_emails">
            <h3>Final</h3>
            <p class="muted">
              Keeps a copy after Mark emailed or Remove on Admin ·
              <?= (int) $allCountryCount ?> countr<?= $allCountryCount === 1 ? 'y' : 'ies' ?>
              · <?= (int) $allTotal ?> site<?= (int) $allTotal === 1 ? '' : 's' ?>
            </p>
            <?php folder_open_cue(); ?>
          </a>
          <?= info_icon('Final keeps a copy after Mark emailed or Remove on Admin. Repair copies Admin → Final and never deletes archive rows. Open a country folder to paste or import CSV / Excel / TXT like Campaign (also creates the Admin working-list row). Not linked to Team.', 'About All sites with emails - Final') ?>
        </div>
        <div class="folder-with-info">
          <a class="folder" href="<?= h($base) ?>&amp;folder=email_campaigns">
            <h3>Campaign</h3>
            <p class="muted">
              One sheet per country · Communication Team super search ·
              <?= (int) $campaignSheetCount ?> countr<?= $campaignSheetCount === 1 ? 'y' : 'ies' ?>
              · <?= (int) $campaignRowTotal ?> site<?= (int) $campaignRowTotal === 1 ? '' : 's' ?>
            </p>
            <?php folder_open_cue(); ?>
          </a>
          <?= info_icon('Create one Email Sheet per country with site names + emails. Communication Team searches all country sheets in one super search bar.', 'About Email campaign data') ?>
        </div>
      </div>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- Folder: Sites with emails - Admin ---
if ($folder === 'sites_with_emails') {
    $sweUser = $user;
    $swePanel = 'admin';
    $sweScope = 'admin';
    $sweBase = $base . '&folder=sites_with_emails';
    $sweAdminHub = $base;
    $sweAdminHubLabel = 'Emails data';
    require __DIR__ . '/../sites_with_emails_app.php';
    return;
}

// --- Folder: All sites with emails - Final ---
if ($folder === 'all_sites_with_emails') {
    $sweUser = $user;
    $swePanel = 'admin';
    $sweScope = 'admin_all';
    $sweBase = $base . '&folder=all_sites_with_emails';
    $sweAdminHub = $base;
    $sweAdminHubLabel = 'Emails data';
    require __DIR__ . '/../sites_with_emails_app.php';
    return;
}

// --- Folder: Email campaign data ---
if ($folder === 'email_campaigns') {
    require __DIR__ . '/email_campaigns_app.php';
    return;
}
