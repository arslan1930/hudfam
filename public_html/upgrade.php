<?php
/**
 * One-time upgrade runner for existing Hostinger installs.
 * Open once after uploading new files, then delete this file.
 */
session_start();
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/geo.php';

$error = '';
$done = false;
$notes = [];

if (!file_exists(__DIR__ . '/config.php')) {
    $error = 'config.php missing. Run install.php first.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
        $pdo = db();

        // Clients + publication orders
        $pdo->exec(file_get_contents(__DIR__ . '/sql/upgrade_clients_orders.sql'));
        $notes[] = 'clients / publication_orders OK';

        // Countries table
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

        // Publisher quote columns (idempotent)
        $cols = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('publisher_quote_price', $cols, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN publisher_quote_price DECIMAL(12,2) NULL AFTER traffic');
            $notes[] = 'added publisher_quote_price';
        }
        if (!in_array('publisher_quote_date', $cols, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN publisher_quote_date DATE NULL AFTER publisher_quote_price');
            $notes[] = 'added publisher_quote_date';
        }

        // Demo client/order if missing
        $rexboId = (int) $pdo->query("SELECT id FROM projects WHERE name='rexbo.de' LIMIT 1")->fetchColumn();
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($rexboId && $adminId) {
            $pdo->prepare(
                'INSERT INTO clients (project_id, name, email, notes, created_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name)'
            )->execute([$rexboId, 'Hans Mueller', 'hans@rexbo.de', 'Main contact for Rexbo deals.', $adminId]);
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
  <title>Upgrade Hudfam</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1>Upgrade</h1>
    <p class="muted">Adds clients/orders, countries, and publisher quote fields.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= htmlspecialchars($error) ?></li></ul><?php endif; ?>
    <?php if ($done): ?>
      <p>Upgrade complete.</p>
      <ul class="help"><?php foreach ($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
      <p><a href="index.php?page=team_countries">Open country folders</a> · <a href="index.php?page=admin_clients">Clients</a></p>
      <p class="help"><strong>Delete upgrade.php now.</strong></p>
    <?php else: ?>
      <form method="post"><button class="btn" type="submit">Run upgrade</button></form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
