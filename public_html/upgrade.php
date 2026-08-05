<?php
/**
 * One-time upgrade runner for existing Hostinger installs.
 * Open once after uploading new files, then delete this file.
 *
 * Ensures Our database (prospect_sites) + add history tables exist.
 * Catalog / Email campaigns / Orders / Published are removed from the app.
 */
session_start();
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';

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
        $notes[] = 'prospect_sites (Our database) OK';

        // Batches / add history (also created inside ensure_prospect_schema when present)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS prospect_batches (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NOT NULL,
              batch_date DATE NOT NULL,
              site_count INT NOT NULL DEFAULT 0,
              country VARCHAR(100) NOT NULL DEFAULT '',
              language VARCHAR(50) NOT NULL DEFAULT '',
              region VARCHAR(40) NOT NULL DEFAULT '',
              niche VARCHAR(255) NOT NULL DEFAULT '',
              notes TEXT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_user_batch_date (user_id, batch_date),
              INDEX (batch_date),
              INDEX (user_id),
              CONSTRAINT fk_pbatch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS prospect_batch_items (
              id INT AUTO_INCREMENT PRIMARY KEY,
              batch_id INT NOT NULL,
              domain VARCHAR(255) NOT NULL,
              prospect_site_id INT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_batch_domain (batch_id, domain),
              INDEX (domain),
              CONSTRAINT fk_pbi_batch FOREIGN KEY (batch_id) REFERENCES prospect_batches(id) ON DELETE CASCADE,
              CONSTRAINT fk_pbi_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $notes[] = 'prospect_batches (Add history) OK';

        // Optional user contact columns used by Admin → Users
        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
            $notes[] = 'added users.phone';
        }
        if (!in_array('contact_details', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN contact_details TEXT NULL AFTER phone");
            $notes[] = 'added users.contact_details';
        }

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
    <p class="muted">Ensures Our database + Add history tables. Catalog, Emails, Orders, and Published are removed from the app.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= htmlspecialchars($error) ?></li></ul><?php endif; ?>
    <?php if ($done): ?>
      <p>Upgrade complete.</p>
      <ul class="help"><?php foreach ($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
      <p><a href="index.php?page=admin_dashboard">Open Admin dashboard</a></p>
      <p class="help"><strong>Delete upgrade.php now.</strong></p>
    <?php else: ?>
      <form method="post"><button class="btn" type="submit">Run upgrade</button></form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
