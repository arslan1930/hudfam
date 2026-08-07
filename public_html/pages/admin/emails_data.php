<?php
/**
 * Admin · Emails DATA — email archives + Email campaign data sheets.
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

    if ($sweTotal > 0) {
        $allCount = count_sites_with_emails('admin_all');
        if ($allCount !== $sweTotal) {
            sync_sites_with_emails_admin_to_all();
        }
    }
    $allCountryRows = list_sites_with_emails_country_rows('admin_all');
    $allTotal = 0;
    $allWithEmails = 0;
    foreach ($allCountryRows as $r) {
        $allTotal += (int) $r['total'];
        $allWithEmails += (int) $r['with_emails'];
    }
    $allCountryCount = count($allCountryRows);

    $campaignSheets = list_email_campaign_sheets();
    $campaignSheetCount = count($campaignSheets);
    $campaignRowTotal = 0;
    foreach ($campaignSheets as $cs) {
        $campaignRowTotal += (int) $cs['row_count'];
    }

    render_header('Emails DATA', 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails DATA'],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1>Emails DATA</h1>
        <p class="muted">Email archives and campaign sheets for Communication Team.</p>
      </div>
    </div>

    <div class="card">
      <div class="folders">
        <a class="folder" href="<?= h($base) ?>&amp;folder=sites_with_emails">
          <h3>Sites with emails - Admin</h3>
          <p class="muted">
            Final list from Team Push ·
            <?= (int) $sweCountryCount ?> countr<?= $sweCountryCount === 1 ? 'y' : 'ies' ?>
            · <?= (int) $sweTotal ?> site<?= (int) $sweTotal === 1 ? '' : 's' ?>
            · <?= (int) $sweWithEmails ?> with email<?= (int) $sweWithEmails === 1 ? '' : 's' ?>
          </p>
        </a>
        <a class="folder" href="<?= h($base) ?>&amp;folder=all_sites_with_emails">
          <h3>All sites with emails - Final</h3>
          <p class="muted">
            Admin-only mirror of Sites with emails - Admin (not linked to Team) ·
            <?= (int) $allCountryCount ?> countr<?= $allCountryCount === 1 ? 'y' : 'ies' ?>
            · <?= (int) $allTotal ?> site<?= (int) $allTotal === 1 ? '' : 's' ?>
            · <?= (int) $allWithEmails ?> with email<?= (int) $allWithEmails === 1 ? '' : 's' ?>
          </p>
        </a>
        <a class="folder" href="<?= h($base) ?>&amp;folder=email_campaigns">
          <h3>Email campaign data</h3>
          <p class="muted">
            Create Email Sheets for Communication Team search ·
            <?= (int) $campaignSheetCount ?> sheet<?= (int) $campaignSheetCount === 1 ? '' : 's' ?>
            · <?= (int) $campaignRowTotal ?> site<?= (int) $campaignRowTotal === 1 ? '' : 's' ?>
          </p>
        </a>
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
    $sweAdminHubLabel = 'Emails DATA';
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
    $sweAdminHubLabel = 'Emails DATA';
    require __DIR__ . '/../sites_with_emails_app.php';
    return;
}

// --- Folder: Email campaign data ---
if ($folder === 'email_campaigns') {
    require __DIR__ . '/email_campaigns_app.php';
    return;
}
