<?php
/**
 * One-time upgrade for existing Hostinger installs.
 * 1) Ensures Our database + Add history tables
 * 2) DROPS Catalog / Emails / Orders / Published / Projects tables
 * Open once after uploading new files, then delete this file.
 */
session_start();
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/extracting.php';
require __DIR__ . '/includes/extracted.php';
require __DIR__ . '/includes/sites_with_emails.php';
require __DIR__ . '/includes/departments.php';

$error = '';
$done = false;
$notes = [];

if (!file_exists(__DIR__ . '/config.php')) {
    $error = 'config.php missing. Run install.php first.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
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
        $notes[] = 'countries OK';

        ensure_prospect_schema();
        $notes[] = 'prospect_sites (Our database) OK — unique per country + domain';
        $notes[] = 'prospect_batches (Add history) OK';

        ensure_extract_schema();
        $notes[] = 'extract_batches / extract_batch_sites (Extracting sites) OK';

        ensure_extracted_schema();
        $notes[] = 'extracted_sites (Extracted URLs) OK';

        ensure_sites_with_emails_schema();
        $notes[] = 'sites_with_emails_team / sites_with_emails_admin OK';

        ensure_departments_schema();
        $notes[] = 'departments / department_members / department_tasks OK';

        require_once __DIR__ . '/includes/orders.php';
        ensure_order_schema();
        $notes[] = 'order_clients / order_items (Order management) OK';

        require_once __DIR__ . '/includes/invoices.php';
        ensure_invoice_schema();
        $notes[] = 'invoices / invoice_items (Invoices panel) OK';

        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
            $notes[] = 'added users.phone';
        }
        if (!in_array('contact_details', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN contact_details TEXT NULL AFTER phone");
            $notes[] = 'added users.contact_details';
        }

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
      Keeps <strong>Our database</strong> (one URL list per country) + <strong>Add history</strong>.
      Permanently removes Catalog, Emails, Orders, Published, and Projects tables.
    </p>
    <?php if ($error): render_alert_box('error', $error); endif; ?>
    <?php if ($done): ?>
      <?php render_alert_box('ok', 'Upgrade complete.'); ?>
      <ul class="help"><?php foreach ($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
      <p><a href="index.php?page=admin_dashboard">Open Admin dashboard</a></p>
      <p class="help"><strong>Delete upgrade.php now.</strong> Also delete any old folders:
        <code>pages/admin/sites.php</code>, email/project pages if still on the server.</p>
    <?php else: ?>
      <form method="post">
        <button class="btn" type="submit">Run upgrade (drop Catalog/Emails/Orders)</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
