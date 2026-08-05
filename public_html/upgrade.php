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

        // Site columns (idempotent)
        $cols = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('publisher_quote_price', $cols, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN publisher_quote_price DECIMAL(12,2) NULL AFTER traffic');
            $notes[] = 'added publisher_quote_price';
        }
        if (!in_array('publisher_quote_date', $cols, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN publisher_quote_date DATE NULL AFTER publisher_quote_price');
            $notes[] = 'added publisher_quote_date';
        }
        if (!in_array('our_mailbox', $cols, true)) {
            $pdo->exec("ALTER TABLE sites ADD COLUMN our_mailbox VARCHAR(190) NOT NULL DEFAULT '' AFTER publisher_email");
            $notes[] = 'added our_mailbox';
        }
        if (!in_array('our_contact_name', $cols, true)) {
            $pdo->exec("ALTER TABLE sites ADD COLUMN our_contact_name VARCHAR(150) NOT NULL DEFAULT '' AFTER our_mailbox");
            $notes[] = 'added our_contact_name';
        }
        if (!in_array('inventory_client_name', $cols, true)) {
            $pdo->exec("ALTER TABLE sites ADD COLUMN inventory_client_name VARCHAR(255) NOT NULL DEFAULT '' AFTER our_contact_name");
            $notes[] = 'added inventory_client_name';
        }
        if (!in_array('order_status', $cols, true)) {
            $pdo->exec("ALTER TABLE sites ADD COLUMN order_status VARCHAR(40) NOT NULL DEFAULT '' AFTER inventory_client_name");
            $notes[] = 'added order_status';
        }
        if (!in_array('admin_comments', $cols, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN admin_comments TEXT NULL AFTER order_status');
            $notes[] = 'added admin_comments';
        }

        // Assign orphan sites to first project so project_id can be required
        $firstProject = (int) $pdo->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
        if ($firstProject) {
            $orphans = (int) $pdo->query(
                'SELECT COUNT(*) FROM sites WHERE primary_project_id IS NULL'
            )->fetchColumn();
            if ($orphans > 0) {
                $pdo->prepare(
                    'UPDATE sites SET primary_project_id = ? WHERE primary_project_id IS NULL'
                )->execute([$firstProject]);
                $notes[] = "assigned {$orphans} orphan site(s) to project #{$firstProject}";
            }
        }

        // Switch unique constraint: domain → (project, domain)
        $indexes = $pdo->query('SHOW INDEX FROM sites')->fetchAll(PDO::FETCH_ASSOC);
        $hasDomainUnique = false;
        $hasProjectDomainUnique = false;
        foreach ($indexes as $idx) {
            if ((int) $idx['Non_unique'] === 0 && $idx['Column_name'] === 'domain' && $idx['Key_name'] !== 'PRIMARY') {
                // Could be single-column unique on domain
                $key = $idx['Key_name'];
                $colsInKey = array_values(array_filter($indexes, fn($i) => $i['Key_name'] === $key));
                if (count($colsInKey) === 1) {
                    $hasDomainUnique = $key;
                }
            }
            if ($idx['Key_name'] === 'uniq_project_domain') {
                $hasProjectDomainUnique = true;
            }
        }
        if ($hasDomainUnique) {
            $pdo->exec('ALTER TABLE sites DROP INDEX `' . str_replace('`', '``', $hasDomainUnique) . '`');
            $notes[] = 'dropped global unique on domain';
        }
        if (!$hasProjectDomainUnique) {
            // Resolve duplicate domains within same project before adding unique
            $dups = $pdo->query(
                'SELECT primary_project_id, domain, COUNT(*) c FROM sites
                 WHERE primary_project_id IS NOT NULL
                 GROUP BY primary_project_id, domain HAVING c > 1'
            )->fetchAll();
            foreach ($dups as $d) {
                $rows = $pdo->prepare(
                    'SELECT id FROM sites WHERE primary_project_id=? AND domain=? ORDER BY id'
                );
                $rows->execute([$d['primary_project_id'], $d['domain']]);
                $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
                array_shift($ids); // keep first
                foreach ($ids as $dupId) {
                    $pdo->prepare('UPDATE sites SET domain = CONCAT(domain, "-dup-", id) WHERE id=?')
                        ->execute([$dupId]);
                }
            }
            $pdo->exec('ALTER TABLE sites ADD UNIQUE KEY uniq_project_domain (primary_project_id, domain)');
            $notes[] = 'added uniq_project_domain';
        }

        // Index mailbox
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        if (!in_array('our_mailbox', $indexNames, true)) {
            try {
                $pdo->exec('ALTER TABLE sites ADD INDEX (our_mailbox)');
                $notes[] = 'indexed our_mailbox';
            } catch (Throwable $e) {
                // already exists or unsupported — ignore
            }
        }

        // Admin contact fields
        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
            $notes[] = 'added users.phone';
        }
        if (!in_array('contact_details', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN contact_details TEXT NULL AFTER phone');
            $notes[] = 'added users.contact_details';
        }

        // Project admin collaborators
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS project_admins (
              project_id INT NOT NULL,
              user_id INT NOT NULL,
              PRIMARY KEY (project_id, user_id),
              CONSTRAINT fk_pa_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
              CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $notes[] = 'project_admins OK';

        // Prospect inventory
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS prospect_sites (
              id INT AUTO_INCREMENT PRIMARY KEY,
              domain VARCHAR(255) NOT NULL,
              url VARCHAR(500) NOT NULL DEFAULT '',
              country VARCHAR(100) NOT NULL DEFAULT '',
              language VARCHAR(50) NOT NULL DEFAULT '',
              region VARCHAR(40) NOT NULL DEFAULT '',
              niche VARCHAR(255) NOT NULL DEFAULT '',
              notes TEXT,
              status ENUM('new','contacting','replied','skipped') NOT NULL DEFAULT 'new',
              created_by INT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_prospect_domain (domain),
              INDEX (country),
              INDEX (language),
              INDEX (region),
              INDEX (status),
              CONSTRAINT fk_prospect_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $notes[] = 'prospect_sites OK';

        // Dated prospect batches
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
        $notes[] = 'prospect_batches OK';

        // Email campaign inventory (URL + email per country)
        $pdo->exec(file_get_contents(__DIR__ . '/sql/upgrade_email_campaigns.sql'));
        $notes[] = 'email_campaign_contacts OK';

        // Demo client if missing
        $rexboId = (int) $pdo->query("SELECT id FROM projects WHERE name='rexbo.de' LIMIT 1")->fetchColumn();
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($rexboId && $adminId) {
            $pdo->prepare(
                'INSERT INTO clients (project_id, name, email, notes, created_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name)'
            )->execute([$rexboId, 'Hans Mueller', 'hans@rexbo.de', 'Main contact for Rexbo deals.', $adminId]);
            // Ensure creating admin is a collaborator
            $pdo->prepare('INSERT IGNORE INTO project_admins (project_id, user_id) VALUES (?,?)')
                ->execute([$rexboId, $adminId]);
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
    <p class="muted">Adds email campaign sheets, prospect inventory, multi-admin contacts, catalog fields, and prior upgrades.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= htmlspecialchars($error) ?></li></ul><?php endif; ?>
    <?php if ($done): ?>
      <p>Upgrade complete.</p>
      <ul class="help"><?php foreach ($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
      <p><a href="index.php?page=admin_projects">Open projects</a></p>
      <p class="help"><strong>Delete upgrade.php now.</strong></p>
    <?php else: ?>
      <form method="post"><button class="btn" type="submit">Run upgrade</button></form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
