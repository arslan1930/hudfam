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
// Clear New reminder when Admin opens Emails data.
if (function_exists('clear_admin_new_data')) {
    clear_admin_new_data('emails_admin', $user);
}

// Optional repair: Admin→Final archive sync (not automatic on every hub GET).
if ($folder === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'repair_final_archive') {
    $result = sync_sites_with_emails_admin_to_all();
    $up = (int) ($result['upserted'] ?? 0);
    $rm = (int) ($result['removed'] ?? 0);
    flash('ok', 'Final archive repaired · synced ' . $up . ' · removed ' . $rm . ' stale.');
    redirect($base);
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
    $archiveDrift = $allTotal !== $sweTotal;

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
        <h1><?= label_with_info('Emails data', 'Final email archives from Team Push, plus country Email Sheets for Communication Team search.') ?></h1>
        <p class="muted">Email archives and campaign sheets for Communication Team.</p>
      </div>
    </div>

    <?php if ($sweTotal < 1 && $allTotal < 1 && $campaignSheetCount < 1): ?>
    <div class="card" style="margin-bottom:1rem">
      <div class="empty-state">
        <p>No email data yet.</p>
        <p class="muted">
          Team fills <strong>Sites with emails - Admin</strong> via Push.
          You create country sheets under <strong>Email campaign data</strong>.
        </p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($archiveDrift): ?>
    <div class="card" style="margin-bottom:1rem">
      <p style="margin:0 0 0.65rem">
        Final archive count (<strong><?= (int) $allTotal ?></strong>) differs from Sites with emails - Admin
        (<strong><?= (int) $sweTotal ?></strong>).
      </p>
      <form method="post" action="<?= h($base) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="repair_final_archive">
        <button class="btn secondary" type="submit">Repair Final archive</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="folders emails-data-folders">
        <div class="folder-with-info">
          <a class="folder" href="<?= h($base) ?>&amp;folder=sites_with_emails">
            <h3>Sites with emails - Admin</h3>
            <p class="muted">
              Final list from Team Push ·
              <?= (int) $sweCountryCount ?> countr<?= $sweCountryCount === 1 ? 'y' : 'ies' ?>
              · <?= (int) $sweTotal ?> site<?= (int) $sweTotal === 1 ? '' : 's' ?>
              · <?= (int) $sweWithEmails ?> with email<?= (int) $sweWithEmails === 1 ? '' : 's' ?>
            </p>
          </a>
          <?= info_icon('Final site + email archive filled when Team pushes from Sites with emails - Team. Communication Team can super-search this across all countries.', 'About Sites with emails - Admin') ?>
        </div>
        <div class="folder-with-info">
          <a class="folder" href="<?= h($base) ?>&amp;folder=all_sites_with_emails">
            <h3>All sites with emails - Final</h3>
            <p class="muted">
              Admin-only mirror of Sites with emails - Admin (not linked to Team) ·
              <?= (int) $allCountryCount ?> countr<?= $allCountryCount === 1 ? 'y' : 'ies' ?>
              · <?= (int) $allTotal ?> site<?= (int) $allTotal === 1 ? '' : 's' ?>
              · <?= (int) $allWithEmails ?> with email<?= (int) $allWithEmails === 1 ? '' : 's' ?>
            </p>
          </a>
          <?= info_icon('Admin-only mirror of Sites with emails - Admin. Stays in sync automatically. Not linked to Team.', 'About All sites with emails - Final') ?>
        </div>
        <div class="folder-with-info">
          <a class="folder" href="<?= h($base) ?>&amp;folder=email_campaigns">
            <h3>Email campaign data</h3>
            <p class="muted">
              One sheet per country · Communication Team super search ·
              <?= (int) $campaignSheetCount ?> countr<?= (int) $campaignSheetCount === 1 ? 'y' : 'ies' ?>
              · <?= (int) $campaignRowTotal ?> site<?= (int) $campaignRowTotal === 1 ? '' : 's' ?>
            </p>
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
