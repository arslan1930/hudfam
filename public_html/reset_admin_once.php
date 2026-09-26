<?php
/**
 * ONE-TIME Admin password reset → admin / admin123
 *
 * CLI only. Do not upload this file to Hostinger. If it is already there,
 * a web hit returns 404 (this script also refuses anything except `cli`).
 *
 * Recovery (on the server shell, in the same folder as config.php):
 *   php reset_admin_once.php RESET
 * Then sign in as admin / admin123, change the password, and delete this file.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$confirm = strtoupper(trim((string) ($argv[1] ?? '')));
$defaultUser = 'admin';
$defaultPass = 'admin123';

if ($confirm !== 'RESET') {
    fwrite(STDERR, "Usage: php reset_admin_once.php RESET\n");
    fwrite(STDERR, "Sets username {$defaultUser} password to {$defaultPass}, then deletes this file.\n");
    exit(1);
}

require_once __DIR__ . '/includes/helpers.php';
txf_secure_session_start();
require_once __DIR__ . '/includes/db.php';

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
        echo "Created user admin with password admin123.\n";
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
        echo "Password for admin is now admin123.\n";
    }

    echo "Sign in at index.php?page=login with admin / admin123.\n";
    echo "Change the password after login, then delete this file.\n";

    $self = __FILE__;
    if (@unlink($self)) {
        echo "This reset script deleted itself.\n";
    } else {
        echo "Could not auto-delete. Remove reset_admin_once.php from the server now.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Check that config.php has the correct MySQL details.\n");
    exit(1);
}
