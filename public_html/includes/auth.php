<?php

function ensure_users_auth_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('must_change_password', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
                 AFTER is_active"
            );
        }
    } catch (Throwable $e) {
        // Table may not exist during very early install.
    }
}

/** Well-known demo passwords from older installs — never leave these live. */
function known_weak_passwords(): array
{
    return ['admin123', 'team123'];
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('index.php?page=login');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        flash('error', 'Admin access required. You were sent to the Team panel.');
        redirect('index.php?page=team_dashboard');
    }
    return $user;
}

function require_team(): array
{
    $user = require_login();
    if (!in_array($user['role'], ['admin', 'team'], true)) {
        flash('error', 'Please sign in with a Team or Admin account.');
        redirect('index.php?page=login');
    }
    return $user;
}

function user_must_change_password(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if (!empty($_SESSION['must_change_password'])) {
        return true;
    }
    if (array_key_exists('must_change_password', $user)) {
        return (int) $user['must_change_password'] === 1;
    }
    try {
        ensure_users_auth_schema();
        $stmt = db()->prepare('SELECT must_change_password FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $flag = (int) $stmt->fetchColumn();
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['must_change_password'] = $flag;
        }
        return $flag === 1;
    } catch (Throwable $e) {
        return !empty($_SESSION['must_change_password']);
    }
}

function attempt_login(string $username, string $password): bool
{
    ensure_users_auth_schema();
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $mustChange = (int) ($user['must_change_password'] ?? 0) === 1;
    if (!$mustChange && in_array($password, known_weak_passwords(), true)) {
        $mustChange = true;
        try {
            db()->prepare('UPDATE users SET must_change_password=1 WHERE id=?')->execute([(int) $user['id']]);
        } catch (Throwable $e) {
            // Still force via session even if column update fails.
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'must_change_password' => $mustChange ? 1 : 0,
    ];
    $_SESSION['must_change_password'] = $mustChange;
    return true;
}

function clear_must_change_password_flag(int $userId): void
{
    ensure_users_auth_schema();
    db()->prepare('UPDATE users SET must_change_password=0 WHERE id=?')->execute([$userId]);
    if (isset($_SESSION['user']) && (int) ($_SESSION['user']['id'] ?? 0) === $userId) {
        $_SESSION['user']['must_change_password'] = 0;
    }
    unset($_SESSION['must_change_password']);
}

function change_user_password(int $userId, string $currentPassword, string $newPassword): string
{
    ensure_users_auth_schema();
    $newPassword = trim($newPassword);
    if (strlen($newPassword) < 8) {
        return 'New password must be at least 8 characters.';
    }
    if (in_array($newPassword, known_weak_passwords(), true)) {
        return 'Choose a stronger password (not a demo default).';
    }
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($currentPassword, (string) $hash)) {
        return 'Current password is incorrect.';
    }
    if (password_verify($newPassword, (string) $hash)) {
        return 'New password must be different from the current one.';
    }
    db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    clear_must_change_password_flag($userId);
    return '';
}

/**
 * Mark accounts still using known demo passwords so they must change on next login.
 * @return int number of users flagged
 */
function flag_users_with_weak_passwords(): int
{
    ensure_users_auth_schema();
    $users = db()->query('SELECT id, password_hash FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $upd = db()->prepare('UPDATE users SET must_change_password=1 WHERE id=?');
    $n = 0;
    foreach ($users as $u) {
        $hash = (string) ($u['password_hash'] ?? '');
        foreach (known_weak_passwords() as $weak) {
            if ($hash !== '' && password_verify($weak, $hash)) {
                $upd->execute([(int) $u['id']]);
                $n++;
                break;
            }
        }
    }
    return $n;
}

function logout_user(): void
{
    unset($_SESSION['user'], $_SESSION['must_change_password']);
}

function is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && $user['role'] === 'admin';
}
