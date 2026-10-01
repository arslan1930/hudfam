<?php
/**
 * One-time installer for Hostinger shared hosting.
 * Open https://yourdomain.com/install.php then delete this file.
 */
require_once __DIR__ . '/includes/helpers.php';
txf_secure_session_start();
txf_send_security_headers();

$error = '';
$done = false;
$createdPasswords = [];
$justInstalled = false;

// Already installed — refuse to run again (do not expose credentials).
if (file_exists(__DIR__ . '/config.php')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en" class="ui-v2"><head><meta charset="utf-8"><title>Install locked</title>';
    echo '<link rel="stylesheet" href="asset.php?f=css/app.css">';
    echo '<link rel="stylesheet" href="asset.php?f=css/style-new.css"></head><body><div class="login-wrap"><div class="login-card">';
    echo '<h1>Install locked</h1>';
    echo '<p>This site already has <code>config.php</code>. Installer will not run again.</p>';
    echo '<p class="help"><strong>Delete <code>install.php</code></strong> from the server now.</p>';
    echo '<p><a href="index.php">Go to login</a></p>';
    echo '</div></div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        $adminPass = bin2hex(random_bytes(5));
        $admin2Pass = bin2hex(random_bytes(5));
        $teamPass = bin2hex(random_bytes(5));
        $createdPasswords = [
            'admin' => $adminPass,
            'admin2' => $admin2Pass,
            'teammate' => $teamPass,
        ];

        $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
        $admin2Hash = password_hash($admin2Pass, PASSWORD_DEFAULT);
        $teamHash = password_hash($teamPass, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role),
               phone=VALUES(phone), contact_details=VALUES(contact_details), full_name=VALUES(full_name),
               must_change_password=1'
        )->execute(['admin', $adminHash, 'Sara Khan', 'sara@hudfam.local', '+49 30 111111', 'Primary EU admin · Slack @sara', 'admin']);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, phone, contact_details, role, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role),
               phone=VALUES(phone), contact_details=VALUES(contact_details), full_name=VALUES(full_name),
               must_change_password=1'
        )->execute(['admin2', $admin2Hash, 'Marcus Lee', 'marcus@hudfam.local', '+1 212 555 0100', 'NA admin · Slack @marcus', 'admin']);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role, must_change_password)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), must_change_password=1'
        )->execute(['teammate', $teamHash, 'Alex', 'team@hudfam.local', 'team']);

        $teamId = (int) $pdo->query("SELECT id FROM users WHERE username='teammate'")->fetchColumn();

        // Sample URLs in Our database for Team Filter & add demos
        $prospectIns = $pdo->prepare(
            'INSERT INTO prospect_sites (domain, country, language, region, niche, notes, status, created_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE country=VALUES(country), language=VALUES(language)'
        );
        $demoProspects = [
            ['prospect-blog-de.com', 'Germany', 'German', 'europe', 'Finance', 'Demo site in Our database', 'new'],
            ['outreach-target-us.com', 'United States', 'English', 'north_america', 'Business', 'Demo site in Our database', 'contacting'],
        ];
        foreach ($demoProspects as $pr) {
            $prospectIns->execute([$pr[0], $pr[1], $pr[2], $pr[3], $pr[4], $pr[5], $pr[6], $teamId]);
        }
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

        $configPhp = "<?php\nreturn [\n"
            . "    'db_host' => " . var_export($host, true) . ",\n"
            . "    'db_name' => " . var_export($name, true) . ",\n"
            . "    'db_user' => " . var_export($user, true) . ",\n"
            . "    'db_pass' => " . var_export($pass, true) . ",\n"
            . "    'app_name' => " . var_export($app, true) . ",\n"
            . "    'app_url' => '',\n"
            . "    'mail_from' => '',\n"
            . "    'mail_from_name' => " . var_export($app, true) . ",\n"
            . "    'smtp_host' => '',\n"
            . "    'smtp_port' => 465,\n"
            . "    'smtp_user' => '',\n"
            . "    'smtp_pass' => '',\n"
            . "    'smtp_secure' => 'ssl',\n"
            . "];\n";
        if (file_put_contents(__DIR__ . '/config.php', $configPhp) === false) {
            throw new RuntimeException('Could not write config.php. Create it manually from config.sample.php.');
        }
        @file_put_contents(__DIR__ . '/install.lock', date('c') . " installed\n");
        $done = true;
        $justInstalled = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="ui-v2">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install TechxForm</title>
  <link rel="stylesheet" href="asset.php?f=css/app.css">
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="asset.php?f=css/style-new.css">
  <link rel="stylesheet" href="assets/css/style-new.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1>TechxForm install</h1>
    <?php if ($done && $justInstalled): ?>
      <p>Installed. <a href="index.php">Go to login</a></p>
      <p class="help"><strong>Copy these one-time passwords now</strong> (they are not shown again):</p>
      <ul class="help">
        <?php foreach ($createdPasswords as $uname => $pw): ?>
          <li><code><?= h($uname) ?></code> / <code><?= h($pw) ?></code></li>
        <?php endforeach; ?>
      </ul>
      <p class="help">Each user must change their password on first login.</p>
      <p class="help"><strong>Delete install.php now</strong> for security.</p>
    <?php else: ?>
      <p class="muted">Enter MySQL details from Hostinger hPanel → Databases.</p>
      <?php if ($error): render_alert_box('error', $error); endif; ?>
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
