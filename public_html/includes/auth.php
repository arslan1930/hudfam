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

/**
 * One-time temporary password for Admin → Users (create / generate-on-edit).
 * Mixed alphabet, no ambiguous 0/O/I/l/1; never a known demo default.
 */
function generate_temp_password(int $length = 14): string
{
    $length = max(12, min(64, $length));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $n = strlen($alphabet);
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $n - 1)];
    }
    if (in_array($out, known_weak_passwords(), true)) {
        return generate_temp_password($length);
    }
    return $out;
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

/**
 * Sign in with username (any role), or with email for Admin accounts only.
 * Email match is case-insensitive; ignored if more than one admin shares it.
 */
function attempt_login(string $username, string $password): bool
{
    ensure_users_auth_schema();
    $login = trim($username);
    if ($login === '' || $password === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Admin-only: allow signing in with the email on the Admin user profile.
    if (!$user && str_contains($login, '@')) {
        $byEmail = db()->prepare(
            "SELECT * FROM users
             WHERE role = 'admin'
               AND email <> ''
               AND LOWER(email) = LOWER(?)
               AND is_active = 1
             LIMIT 2"
        );
        $byEmail->execute([$login]);
        $matches = $byEmail->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($matches) === 1) {
            $user = $matches[0];
        }
    }

    if (!$user || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
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

const LOGIN_THROTTLE_MAX = 12;
const LOGIN_THROTTLE_WINDOW = 600;

function login_throttle_key(string $login): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0';
    return hash('sha256', $ip . '|' . mb_strtolower(trim($login)));
}

function login_throttle_file(string $login): string
{
    return sys_get_temp_dir() . '/txf_login_' . login_throttle_key($login);
}

function login_throttle_blocked(string $login): bool
{
    $path = login_throttle_file($login);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    $pack = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($pack)) {
        return false;
    }
    $start = (int) ($pack['start'] ?? 0);
    $n = (int) ($pack['n'] ?? 0);
    if ($start < 1 || (time() - $start) > LOGIN_THROTTLE_WINDOW) {
        @unlink($path);
        return false;
    }
    return $n >= LOGIN_THROTTLE_MAX;
}

function login_throttle_note_failure(string $login): void
{
    $path = login_throttle_file($login);
    $now = time();
    $n = 1;
    $start = $now;
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $pack = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($pack) && (int) ($pack['start'] ?? 0) > 0
            && ($now - (int) $pack['start']) <= LOGIN_THROTTLE_WINDOW) {
            $start = (int) $pack['start'];
            $n = (int) ($pack['n'] ?? 0) + 1;
        }
    }
    @file_put_contents($path, json_encode(['start' => $start, 'n' => $n]), LOCK_EX);
}

function login_throttle_clear(string $login): void
{
    $path = login_throttle_file($login);
    if (is_file($path)) {
        @unlink($path);
    }
}

function is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && $user['role'] === 'admin';
}
