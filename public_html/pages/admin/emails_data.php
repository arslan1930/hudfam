<?php
/**
 * Admin · Emails DATA — panel for email archives (Sites with emails - Admin).
 */
$user = require_admin();
ensure_sites_with_emails_schema();
seed_countries_if_empty(db());

$base = 'index.php?page=admin_emails_data';
$folder = (string) get('folder');
$allowedFolders = ['sites_with_emails'];
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

    render_header('Emails DATA', 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails DATA'],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1>Emails DATA</h1>
        <p class="muted">Final email archives from Team Push. Open a folder to work by country.</p>
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
    $sweBase = $base . '&folder=sites_with_emails';
    $sweAdminHub = $base;
    $sweAdminHubLabel = 'Emails DATA';
    require __DIR__ . '/../sites_with_emails_app.php';
    return;
}
