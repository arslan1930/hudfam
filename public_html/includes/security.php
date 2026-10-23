<?php
/**
 * Session hardening, CSRF, login throttle, and response security headers.
 */

function client_ip_address(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return '0.0.0.0';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '0.0.0.0';
}

/**
 * Start the app session with HttpOnly / SameSite / Secure cookies.
 * Safe to call more than once.
 */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        @session_start();
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    $path = '/';
    if (function_exists('app_base_path')) {
        $base = app_base_path();
        if ($base !== '') {
            $path = $base . '/';
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Send browser security headers (safe to call more than once). */
function send_security_headers(): void
{
    static $done = false;
    if ($done || headers_sent()) {
        return;
    }
    $done = true;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_app_session();
    }
    $token = (string) ($_SESSION['_csrf'] ?? '');
    if ($token === '' || strlen($token) < 32) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf'] = $token;
    }
    return $token;
}

/** Hidden input for classic HTML forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function request_csrf_token(): string
{
    $fromPost = (string) ($_POST['_csrf'] ?? '');
    if ($fromPost !== '') {
        return $fromPost;
    }
    $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }
    return '';
}

function csrf_token_valid(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = (string) ($_SESSION['_csrf'] ?? '');
    $token = $token ?? request_csrf_token();
    if ($expected === '' || $token === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

function request_wants_json(): bool
{
    if ((string) ($_POST['ajax'] ?? '') === '1' || (string) ($_GET['ajax'] ?? '') !== '') {
        return true;
    }
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json');
}

/**
 * Reject state-changing requests that lack a valid CSRF token.
 * Call once near the front controller for POST (and other unsafe methods).
 */
function require_csrf(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    if (csrf_token_valid()) {
        return;
    }

    if (request_wants_json()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token. Reload the page and try again.']);
        exit;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">';
    echo '<title>Request blocked</title></head><body>';
    echo '<h1>Request blocked</h1>';
    echo '<p>Invalid or missing security token. Go back, reload the page, and try again.</p>';
    echo '<p><a href="index.php">Home</a></p>';
    echo '</body></html>';
    exit;
}

function ensure_login_throttle_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS login_throttle (
              id INT AUTO_INCREMENT PRIMARY KEY,
              throttle_key VARCHAR(190) NOT NULL,
              attempts INT NOT NULL DEFAULT 0,
              window_started_at DATETIME NOT NULL,
              locked_until DATETIME NULL,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_login_throttle_key (throttle_key),
              INDEX (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // Table may be unavailable during very early install.
    }
}

function login_throttle_key(string $username): string
{
    $user = strtolower(trim($username));
    if ($user === '') {
        $user = '(empty)';
    }
    return hash('sha256', $user . '|' . client_ip_address());
}

/**
 * @return array{ok:bool,error:string,retry_after?:int}
 */
function login_throttle_check(string $username): array
{
    ensure_login_throttle_schema();
    $key = login_throttle_key($username);
    try {
        $stmt = db()->prepare(
            'SELECT attempts, window_started_at, locked_until FROM login_throttle WHERE throttle_key=? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => true, 'error' => ''];
        }
        $lockedUntil = (string) ($row['locked_until'] ?? '');
        if ($lockedUntil !== '') {
            $untilTs = strtotime($lockedUntil);
            if ($untilTs !== false && $untilTs > time()) {
                $retry = $untilTs - time();
                return [
                    'ok' => false,
                    'error' => 'Too many failed sign-in attempts. Try again in about '
                        . max(1, (int) ceil($retry / 60)) . ' minute(s).',
                    'retry_after' => $retry,
                ];
            }
        }
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => true, 'error' => ''];
    }
}

function login_throttle_register_failure(string $username): void
{
    ensure_login_throttle_schema();
    $key = login_throttle_key($username);
    $maxAttempts = 8;
    $windowSeconds = 900; // 15 minutes
    $lockSeconds = 900;

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT id, attempts, window_started_at, locked_until FROM login_throttle WHERE throttle_key=? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->prepare(
                'INSERT INTO login_throttle (throttle_key, attempts, window_started_at, locked_until)
                 VALUES (?, 1, NOW(), NULL)'
            )->execute([$key]);
            return;
        }

        $windowStart = strtotime((string) ($row['window_started_at'] ?? '')) ?: time();
        $attempts = (int) ($row['attempts'] ?? 0);
        $resetWindow = (time() - $windowStart) > $windowSeconds;
        if ($resetWindow) {
            $attempts = 0;
        }
        $attempts++;
        $lockedUntil = null;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockSeconds);
            $attempts = $maxAttempts;
        }

        if ($resetWindow) {
            $pdo->prepare(
                'UPDATE login_throttle
                 SET attempts=?, window_started_at=NOW(), locked_until=?
                 WHERE id=?'
            )->execute([$attempts, $lockedUntil, (int) $row['id']]);
        } else {
            $pdo->prepare(
                'UPDATE login_throttle
                 SET attempts=?, locked_until=?
                 WHERE id=?'
            )->execute([$attempts, $lockedUntil, (int) $row['id']]);
        }
    } catch (Throwable $e) {
        // Fail open for availability; headers/CSRF still protect mutations.
    }
}

function login_throttle_clear(string $username): void
{
    ensure_login_throttle_schema();
    try {
        db()->prepare('DELETE FROM login_throttle WHERE throttle_key=?')
            ->execute([login_throttle_key($username)]);
    } catch (Throwable $e) {
        // ignore
    }
}
