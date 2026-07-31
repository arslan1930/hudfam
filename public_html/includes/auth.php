<?php

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
        http_response_code(403);
        echo 'Admin access required.';
        exit;
    }
    return $user;
}

function require_team(): array
{
    $user = require_login();
    if (!in_array($user['role'], ['admin', 'team'], true)) {
        http_response_code(403);
        echo 'Team access required.';
        exit;
    }
    return $user;
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ];
    return true;
}

function logout_user(): void
{
    unset($_SESSION['user']);
}

function is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && $user['role'] === 'admin';
}
