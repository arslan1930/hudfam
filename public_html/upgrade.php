<?php
/**
 * One-time upgrade: adds clients + publication_orders tables.
 * Open once, then delete this file.
 */
session_start();
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';

$error = '';
$done = false;
$seeded = false;

if (!file_exists(__DIR__ . '/config.php')) {
    $error = 'config.php missing. Run install.php first.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
        $pdo = db();
        $sql = file_get_contents(__DIR__ . '/sql/upgrade_clients_orders.sql');
        $pdo->exec($sql);

        // Seed demo client/order if missing
        $rexboId = (int) $pdo->query("SELECT id FROM projects WHERE name='rexbo.de' LIMIT 1")->fetchColumn();
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($rexboId && $adminId) {
            $pdo->prepare(
                'INSERT INTO clients (project_id, name, email, notes, created_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name)'
            )->execute([$rexboId, 'Hans Mueller', 'hans@rexbo.de', 'Main contact for Rexbo deals.', $adminId]);
            $clientId = (int) $pdo->query(
                "SELECT id FROM clients WHERE project_id={$rexboId} AND email='hans@rexbo.de'"
            )->fetchColumn();
            $count = (int) $pdo->query(
                "SELECT COUNT(*) FROM publication_orders WHERE client_id={$clientId}"
            )->fetchColumn();
            if ($clientId && $count === 0) {
                $siteId = (int) $pdo->query(
                    "SELECT id FROM sites WHERE domain='de-finance-news.example'"
                )->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO publication_orders
                    (client_id, site_id, site_domain, article_url, sent_for_publication_at, client_price, currency, live_url, status, admin_notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $clientId,
                    $siteId ?: null,
                    'de-finance-news.example',
                    'https://docs.example.com/rexbo-article-1.docx',
                    date('Y-m-d'),
                    120,
                    'EUR',
                    '',
                    'processing',
                    'Demo order — waiting for live URL.',
                    $adminId,
                ]);
                $seeded = true;
            }
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
    <p class="muted">Adds client email folders + publication orders tables.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= htmlspecialchars($error) ?></li></ul><?php endif; ?>
    <?php if ($done): ?>
      <p>Upgrade complete<?= $seeded ? ' (demo client/order seeded)' : '' ?>.</p>
      <p><a href="index.php?page=admin_clients">Open Clients</a></p>
      <p class="help"><strong>Delete upgrade.php now.</strong></p>
    <?php else: ?>
      <form method="post"><button class="btn" type="submit">Run upgrade</button></form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
