<?php
/**
 * ONE-TIME Admin password reset → admin / admin123
 *
 * 1. Upload this file to your Hostinger public_html (same folder as index.php).
 * 2. Open: https://YOUR-DOMAIN/reset_admin_once.php?confirm=RESET
 * 3. Sign in with admin / admin123
 * 4. Delete this file if it is still on the server (it tries to delete itself).
 *
 * Do NOT leave this file on the server.
 */
require_once __DIR__ . '/includes/helpers.php';
txf_secure_session_start();
txf_send_security_headers();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/includes/db.php';

$confirm = (string) ($_GET['confirm'] ?? '');
$defaultUser = 'admin';
$defaultPass = 'admin123';

function h_reset(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Reset admin password</title>';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:2rem auto;padding:0 1rem;line-height:1.45}';
echo 'code{background:#f3f4f6;padding:0.1rem 0.35rem;border-radius:4px}.ok{color:#065f46}.err{color:#991b1b}</style></head><body>';
echo '<h1>Reset admin password</h1>';

if ($confirm !== 'RESET') {
    echo '<p>This will set username <code>' . h_reset($defaultUser) . '</code> password to <code>' . h_reset($defaultPass) . '</code>.</p>';
    echo '<p><a href="?confirm=RESET"><strong>Confirm reset</strong></a></p>';
    echo '<p>After success, delete <code>reset_admin_once.php</code> from the server.</p>';
    echo '</body></html>';
    exit;
}

try {
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new RuntimeException('Missing config.php — site is not installed yet. Run install.php first.');
    }
    $pdo = db();

    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cols = [];
    }

    $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
    $st = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE username=? LIMIT 1');
    $st->execute([$defaultUser]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Create admin if missing
        if (in_array('must_change_password', $cols, true)) {
            $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, email, role, is_active, must_change_password)
                 VALUES (?,?,?,?,\'admin\',1,1)'
            )->execute([$defaultUser, $hash, 'Administrator', '']);
        } else {
            $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, email, role, is_active)
                 VALUES (?,?,?,?,\'admin\',1)'
            )->execute([$defaultUser, $hash, 'Administrator', '']);
        }
        echo '<p class="ok">Created user <code>admin</code> with password <code>admin123</code>.</p>';
    } else {
        if (in_array('must_change_password', $cols, true)) {
            $pdo->prepare(
                'UPDATE users SET password_hash=?, is_active=1, role=\'admin\', must_change_password=1 WHERE id=?'
            )->execute([$hash, (int) $row['id']]);
        } else {
            $pdo->prepare(
                'UPDATE users SET password_hash=?, is_active=1, role=\'admin\' WHERE id=?'
            )->execute([$hash, (int) $row['id']]);
        }
        echo '<p class="ok">Password for <code>admin</code> is now <code>admin123</code>.</p>';
    }

    echo '<p>Sign in at <a href="index.php?page=login">login</a> with:</p>';
    echo '<ul><li>Username: <code>admin</code></li><li>Password: <code>admin123</code></li></ul>';
    echo '<p><strong>Change the password after login</strong>, then delete this file.</p>';

    $self = __FILE__;
    if (@unlink($self)) {
        echo '<p class="ok">This reset script deleted itself.</p>';
    } else {
        echo '<p class="err">Could not auto-delete. Remove <code>reset_admin_once.php</code> from Hostinger File Manager now.</p>';
    }
} catch (Throwable $e) {
    echo '<p class="err">Reset failed: ' . h_reset($e->getMessage()) . '</p>';
    echo '<p>Check that <code>config.php</code> has the correct MySQL details.</p>';
}

echo '</body></html>';
