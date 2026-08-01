<?php
/**
 * One-time installer for Hostinger shared hosting.
 * Open https://yourdomain.com/install.php then delete this file.
 */
session_start();
$error = '';
$done = false;

if (file_exists(__DIR__ . '/config.php')) {
    $done = true;
}

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = (string) ($_POST['db_pass'] ?? '');
    $app = trim($_POST['app_name'] ?? 'TechxForm');

    try {
        if ($name === '' || $user === '') {
            throw new RuntimeException('Database name and user are required.');
        }
        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        $pdo->exec($sql);

        require_once __DIR__ . '/includes/geo.php';
        seed_countries_if_empty($pdo);

        $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
        $admin2Hash = password_hash('admin123', PASSWORD_DEFAULT);
        $teamHash = password_hash('team123', PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role),
               phone=VALUES(phone), contact_details=VALUES(contact_details), full_name=VALUES(full_name)'
        )->execute(['admin', $adminHash, 'Sara Khan', 'sara@hudfam.local', '+49 30 111111', 'Primary EU admin · Slack @sara', 'admin']);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role),
               phone=VALUES(phone), contact_details=VALUES(contact_details), full_name=VALUES(full_name)'
        )->execute(['admin2', $admin2Hash, 'Marcus Lee', 'marcus@hudfam.local', '+1 212 555 0100', 'NA admin · Slack @marcus', 'admin']);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
        )->execute(['teammate', $teamHash, 'Alex', 'team@hudfam.local', 'team']);

        $adminId = (int) $pdo->query("SELECT id FROM users WHERE username='admin'")->fetchColumn();
        $admin2Id = (int) $pdo->query("SELECT id FROM users WHERE username='admin2'")->fetchColumn();
        $teamId = (int) $pdo->query("SELECT id FROM users WHERE username='teammate'")->fetchColumn();

        $pdo->prepare(
            'INSERT INTO projects
            (name, client_name, status, niche, countries, region_focus, budget, price_min, price_max, currency, min_dr, min_da, min_traffic, avoid_notes, workflow_notes, requirements_brief, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE niche=VALUES(niche)'
        )->execute([
            'rexbo.de', 'Rexbo', 'active', 'Gambling / Casino', 'DE, AT, CH', 'Europe',
            '€5,000 / month', 50, 150, 'EUR', 30, 25, 5000,
            'MFA, weak sites, casino links',
            'Prefer guest posts on DE sites.',
            'Build a clean DE/AT/CH pack. Avoid MFA.',
            $adminId,
        ]);
        $pdo->prepare(
            'INSERT INTO projects
            (name, client_name, status, niche, countries, region_focus, budget, price_min, price_max, currency, min_dr, min_da, min_traffic, avoid_notes, workflow_notes, requirements_brief, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE niche=VALUES(niche)'
        )->execute([
            'xyw.com', 'XYW', 'active', 'Finance', 'US, UK, CA', 'North America + English',
            '$8,000 / month', 80, 200, 'USD', 40, 35, 20000,
            'Adult, spam, MFA',
            'Prefer homepage + banner.',
            'Finance niche, English markets, stronger metrics.',
            $adminId,
        ]);

        $rexboId = (int) $pdo->query("SELECT id FROM projects WHERE name='rexbo.de'")->fetchColumn();
        $xywId = (int) $pdo->query("SELECT id FROM projects WHERE name='xyw.com'")->fetchColumn();
        $pdo->prepare('INSERT IGNORE INTO project_members (project_id, user_id) VALUES (?, ?)')->execute([$rexboId, $teamId]);
        $pdo->prepare('INSERT IGNORE INTO project_members (project_id, user_id) VALUES (?, ?)')->execute([$xywId, $teamId]);
        // Multi-admin collaboration on each project
        foreach ([$rexboId, $xywId] as $pid) {
            $pdo->prepare('INSERT IGNORE INTO project_admins (project_id, user_id) VALUES (?, ?)')->execute([$pid, $adminId]);
            $pdo->prepare('INSERT IGNORE INTO project_admins (project_id, user_id) VALUES (?, ?)')->execute([$pid, $admin2Id]);
        }

        // Sample prospect sites (no prices) for Team uniqueness checks
        $prospectIns = $pdo->prepare(
            'INSERT INTO prospect_sites (domain, country, language, region, niche, notes, status, created_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE country=VALUES(country), language=VALUES(language)'
        );
        $demoProspects = [
            ['prospect-blog-de.example', 'Germany', 'German', 'europe', 'Finance', 'Need to email for guest post', 'new'],
            ['outreach-target-us.example', 'United States', 'English', 'north_america', 'Business', 'Cold outreach', 'contacting'],
        ];
        foreach ($demoProspects as $pr) {
            $prospectIns->execute([$pr[0], $pr[1], $pr[2], $pr[3], $pr[4], $pr[5], $pr[6], $teamId]);
        }
        // Demo dated batch (yesterday) so Dated batches page has an example
        $pdo->prepare(
            'INSERT INTO prospect_batches (user_id, batch_date, site_count, country, language, region, niche, notes)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE site_count=VALUES(site_count)'
        )->execute([
            $teamId,
            date('Y-m-d', strtotime('-1 day')),
            2,
            'Germany',
            'German',
            'europe',
            'Finance',
            'Demo batch from install',
        ]);
        $demoBatchId = (int) $pdo->query(
            'SELECT id FROM prospect_batches WHERE user_id=' . (int) $teamId . ' ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        if ($demoBatchId) {
            $itemIns = $pdo->prepare(
                'INSERT IGNORE INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)'
            );
            foreach ($demoProspects as $pr) {
                $sid = (int) $pdo->query(
                    "SELECT id FROM prospect_sites WHERE domain=" . $pdo->quote($pr[0])
                )->fetchColumn();
                $itemIns->execute([$demoBatchId, $pr[0], $sid ?: null]);
            }
        }

        // Per-project inventory demos (same domain can exist under another project)
        $sites = [
            // domain, projectId, country, region, language, niche, dr, da, traffic, quote, quote_date, agreed, status, currency, mailbox, contact
            ['de-finance-news.example', $rexboId, 'Germany', 'europe', 'German', 'Finance', 42, 38, 22000, 140, date('Y-m-d', strtotime('-14 days')), 120, 'agreed', 'EUR', 'outreach.de@gmail.com', 'Alex DE'],
            ['berlin-biz-daily.example', $rexboId, 'Germany', 'europe', 'German', 'Business', 35, 30, 9000, 100, date('Y-m-d', strtotime('-7 days')), 90, 'agreed', 'EUR', 'outreach.de@gmail.com', 'Alex DE'],
            ['us-money-wire.example', $xywId, 'United States', 'north_america', 'English', 'Finance', 55, 48, 80000, 200, date('Y-m-d', strtotime('-21 days')), 180, 'agreed', 'USD', 'finance.outreach@gmail.com', 'Alex US'],
            // Same domain copied into another project with different mailbox/price
            ['us-money-wire.example', $rexboId, 'United States', 'north_america', 'English', 'Finance', 55, 48, 80000, 190, date('Y-m-d', strtotime('-10 days')), 150, 'negotiating', 'EUR', 'outreach.de@gmail.com', 'Alex DE'],
        ];
        $ins = $pdo->prepare(
            'INSERT INTO sites (domain, primary_project_id, country, region, language, niche, dr, da, traffic,
             publisher_quote_price, publisher_quote_date, backlink_price, status, assigned_to, created_by, currency,
             our_mailbox, our_contact_name, inventory_client_name, order_status, admin_comments)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status=VALUES(status),
               publisher_quote_price=VALUES(publisher_quote_price),
               publisher_quote_date=VALUES(publisher_quote_date),
               backlink_price=VALUES(backlink_price),
               our_mailbox=VALUES(our_mailbox),
               our_contact_name=VALUES(our_contact_name),
               inventory_client_name=VALUES(inventory_client_name),
               order_status=VALUES(order_status),
               admin_comments=VALUES(admin_comments)'
        );
        foreach ($sites as $s) {
            $clientName = ((int) $s[1] === (int) $rexboId) ? 'Rexbo' : 'XYW';
            $orderSt = $s[12] === 'agreed' ? 'pending' : '';
            $ins->execute([
                $s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $s[8],
                $s[9], $s[10], $s[11], $s[12], $teamId, $teamId, $s[13], $s[14], $s[15],
                $clientName, $orderSt, 'Demo admin comment',
            ]);
        }

        $pdo->prepare(
            'INSERT INTO clients (project_id, name, email, notes, created_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE name=VALUES(name)'
        )->execute([
            $rexboId,
            'Hans Mueller',
            'hans@rexbo.de',
            'Main contact for Rexbo deals.',
            $adminId,
        ]);
        $clientId = (int) $pdo->query(
            "SELECT id FROM clients WHERE project_id={$rexboId} AND email='hans@rexbo.de'"
        )->fetchColumn();
        $siteId = (int) $pdo->query(
            "SELECT id FROM sites WHERE domain='de-finance-news.example' AND primary_project_id={$rexboId}"
        )->fetchColumn();
        $existsOrder = (int) $pdo->query(
            "SELECT COUNT(*) FROM publication_orders WHERE client_id={$clientId}"
        )->fetchColumn();
        if ($clientId && !$existsOrder) {
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
        }

        $configPhp = "<?php\nreturn [\n"
            . "    'db_host' => " . var_export($host, true) . ",\n"
            . "    'db_name' => " . var_export($name, true) . ",\n"
            . "    'db_user' => " . var_export($user, true) . ",\n"
            . "    'db_pass' => " . var_export($pass, true) . ",\n"
            . "    'app_name' => " . var_export($app, true) . ",\n"
            . "];\n";
        if (file_put_contents(__DIR__ . '/config.php', $configPhp) === false) {
            throw new RuntimeException('Could not write config.php. Create it manually from config.sample.php.');
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
  <title>Install TechxForm</title>
  <link rel="stylesheet" href="asset.php?f=css/app.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1>TechxForm install</h1>
    <?php if ($done): ?>
      <p>Installed. <a href="index.php">Go to login</a></p>
      <p class="help">Demo: admin / admin123 · admin2 / admin123 · teammate / team123</p>
      <p class="help"><strong>Delete install.php now</strong> for security.</p>
    <?php else: ?>
      <p class="muted">Enter MySQL details from Hostinger hPanel → Databases.</p>
      <?php if ($error): ?><ul class="messages"><li class="error"><?= htmlspecialchars($error) ?></li></ul><?php endif; ?>
      <form method="post">
        <label>App name</label>
        <input name="app_name" value="TechxForm">
        <label>DB host</label>
        <input name="db_host" value="localhost" required>
        <label>DB name</label>
        <input name="db_name" required>
        <label>DB user</label>
        <input name="db_user" required>
        <label>DB password</label>
        <input name="db_pass" type="password">
        <p style="margin-top:1rem"><button class="btn" type="submit">Install</button></p>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
