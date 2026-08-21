<?php
/**
 * One-time upgrade for existing Hostinger installs.
 * Requires a logged-in Admin session. Delete this file after running.
 */
require __DIR__ . '/includes/helpers.php';
txf_secure_session_start();
txf_send_security_headers();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$error = '';
$done = false;
$notes = [];
$locked = false;

if (!file_exists(__DIR__ . '/config.php')) {
    $error = 'config.php missing. Run install.php first.';
    $locked = true;
} else {
    // Admin-only: log in via the app first, then open this page.
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        $locked = true;
        http_response_code(403);
    }
}

if (!$locked && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require __DIR__ . '/includes/geo.php';
        require __DIR__ . '/includes/prospects.php';
        require __DIR__ . '/includes/extracting.php';
        require __DIR__ . '/includes/extracted.php';
        require __DIR__ . '/includes/sites_with_emails.php';
        require __DIR__ . '/includes/email_campaigns.php';
        require __DIR__ . '/includes/admin_new_data.php';
        require __DIR__ . '/includes/departments.php';

        $pdo = db();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS countries (
              id INT AUTO_INCREMENT PRIMARY KEY,
              region VARCHAR(40) NOT NULL DEFAULT 'other',
              code VARCHAR(10) NOT NULL DEFAULT '',
              name VARCHAR(100) NOT NULL,
              default_language VARCHAR(50) NOT NULL DEFAULT '',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              UNIQUE KEY uniq_country_name (name),
              INDEX (region),
              INDEX (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        seed_countries_if_empty($pdo);
        if (function_exists('dedupe_countries_catalog')) {
            $n = dedupe_countries_catalog();
            $notes[] = $n > 0
                ? 'countries OK · removed ' . $n . ' duplicate country name(s)'
                : 'countries OK';
        } else {
            $notes[] = 'countries OK';
        }
        if (function_exists('repair_country_alias_folders')) {
            $repair = repair_country_alias_folders(true);
            if (($repair['removed_catalog'] ?? 0) > 0 || ($repair['merged'] ?? 0) > 0) {
                $notes[] = 'merged demonym folders (e.g. German → Germany) · '
                    . (int) ($repair['merged'] ?? 0) . ' row(s) · removed '
                    . (int) ($repair['removed_catalog'] ?? 0) . ' fake country name(s)';
            } else {
                $notes[] = 'country aliases OK (no German/Spanish-style folders)';
            }
        }

        ensure_prospect_schema();
        $notes[] = 'prospect_sites (Our database) OK — unique per country + domain';
        $notes[] = 'prospect_batches (Site adding history) OK';

        ensure_extract_schema();
        $notes[] = 'extract_batches / extract_batch_sites (Extracting sites) OK';

        require_once __DIR__ . '/includes/semrush_research.php';
        ensure_semrush_research_schema();
        $notes[] = 'semrush_sites / semrush_sheet_comments (Semrush Research) OK';

        ensure_extracted_schema();
        $notes[] = 'extracted_sites (Extracted URLs) OK';

        ensure_sites_with_emails_schema();
        $notes[] = 'sites_with_emails_team / sites_with_emails_admin / sites_with_emails_admin_all OK';

        ensure_email_campaign_schema();
        $notes[] = 'email_campaign_sheets / email_campaign_rows (Email campaign data) OK';

        ensure_admin_new_data_schema();
        $notes[] = 'admin_data_signals / admin_data_seen (New data reminders) OK';

        ensure_departments_schema();
        $notes[] = 'departments / department_members / department_tasks OK';

        require_once __DIR__ . '/includes/orders.php';
        ensure_order_schema();
        $notes[] = 'order_clients / order_items (Order management) OK';

        require_once __DIR__ . '/includes/invoices.php';
        ensure_invoice_schema();
        $notes[] = 'invoices / invoice_items (Invoices panel) OK';

        ensure_users_auth_schema();
        $notes[] = 'users.must_change_password OK';

        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
            $notes[] = 'added users.phone';
        }
        if (!in_array('contact_details', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN contact_details TEXT NULL AFTER phone");
            $notes[] = 'added users.contact_details';
        }

        $weak = flag_users_with_weak_passwords();
        $notes[] = $weak > 0
            ? 'flagged ' . $weak . ' user(s) still on demo passwords (must change on login)'
            : 'no demo passwords detected';

        // Remove Catalog / Emails / Orders / Published / Projects from the database
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $legacy = [
            'publication_orders',
            'clients',
            'published_placements',
            'pitch_items',
            'pitches',
            'email_campaign_contacts',
            'country_catalog_sites',
            'sites',
            'project_admins',
            'project_members',
            'projects',
        ];
        foreach ($legacy as $table) {
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
            if ($exists) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
                $notes[] = 'dropped ' . $table;
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $notes[] = 'legacy Catalog/Emails/Orders/Published/Projects removed from DB';

        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Upgrade TechxForm</title>
  <link rel="stylesheet" href="asset.php?f=css/app.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1>Upgrade</h1>
    <p class="muted">
      Admin-only schema upgrade. Keeps live data tables and removes legacy Catalog tables.
      Delete <code>upgrade.php</code> when finished.
    </p>
    <?php if ($error): render_alert_box('error', $error); endif; ?>
    <?php if ($locked && file_exists(__DIR__ . '/config.php')): ?>
      <?php render_alert_box('error', 'Admin login required. Sign in as Admin in the app, then open upgrade.php again.'); ?>
      <p><a class="btn" href="index.php?page=login">Sign in as Admin</a></p>
    <?php elseif ($done): ?>
      <?php render_alert_box('ok', 'Upgrade complete.'); ?>
      <ul class="help"><?php foreach ($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
      <p><a href="index.php?page=admin_dashboard">Open Admin dashboard</a></p>
      <p class="help"><strong>Delete upgrade.php now.</strong></p>
    <?php elseif (!$locked): ?>
      <form method="post">
        <button class="btn" type="submit">Run upgrade</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
