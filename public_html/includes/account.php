<?php
/**
 * Admin account: email verification + password reset (admin-only).
 */

function ensure_account_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('email_verified_at', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER email');
        }
    } catch (Throwable $e) {
        // ignore
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS auth_tokens (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          purpose ENUM('email_verify','password_reset') NOT NULL,
          token_hash CHAR(64) NOT NULL,
          expires_at DATETIME NOT NULL,
          used_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_token_hash (token_hash),
          INDEX (user_id, purpose),
          INDEX (expires_at),
          CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_tasks_schema(): void
{
    // Retired: Assign tasks UI redirects to Departments. Do not CREATE/ALTER
    // team_tasks on each request. Existing installs keep the table; old rows
    // are not migrated.
}

function load_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function admin_email_is_verified(array $user): bool
{
    return ($user['role'] ?? '') === 'admin'
        && trim((string) ($user['email'] ?? '')) !== ''
        && !empty($user['email_verified_at']);
}

function create_auth_token(int $userId, string $purpose, int $ttlHours = 24): string
{
    ensure_account_schema();
    if (!in_array($purpose, ['email_verify', 'password_reset'], true)) {
        throw new InvalidArgumentException('Invalid token purpose.');
    }
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    // Invalidate older unused tokens of same purpose
    db()->prepare(
        'UPDATE auth_tokens SET used_at=NOW() WHERE user_id=? AND purpose=? AND used_at IS NULL'
    )->execute([$userId, $purpose]);
    db()->prepare(
        'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
    )->execute([$userId, $purpose, $hash, $ttlHours]);
    return $raw;
}

/**
 * @return array{user:array,token:array}|null
 */
function consume_auth_token(string $rawToken, string $purpose, bool $markUsed = true): ?array
{
    ensure_account_schema();
    $rawToken = trim($rawToken);
    if ($rawToken === '' || strlen($rawToken) < 32) {
        return null;
    }
    $hash = hash('sha256', $rawToken);
    $stmt = db()->prepare(
        'SELECT t.id AS token_id, t.purpose, t.expires_at,
                u.id AS user_id, u.username, u.full_name, u.email, u.role, u.is_active,
                u.email_verified_at, u.password_hash
         FROM auth_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash=? AND t.purpose=? AND t.used_at IS NULL AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash, $purpose]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($markUsed) {
        db()->prepare('UPDATE auth_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['token_id']]);
    }
    $user = [
        'id' => (int) $row['user_id'],
        'username' => $row['username'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'is_active' => $row['is_active'],
        'email_verified_at' => $row['email_verified_at'] ?? null,
        'password_hash' => $row['password_hash'],
    ];
    $token = [
        'id' => (int) $row['token_id'],
        'purpose' => $row['purpose'],
        'expires_at' => $row['expires_at'],
    ];
    return ['user' => $user, 'token' => $token];
}

function send_admin_email_verification(array $user): array
{
    ensure_account_schema();
    if (($user['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'error' => 'Only Admin accounts can verify email.'];
    }
    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Add a valid email on your account first.'];
    }
    $token = create_auth_token((int) $user['id'], 'email_verify', 48);
    $link = public_page_url('verify_email', ['token' => $token]);
    $app = app_config()['app_name'] ?? 'TechxForm';
    $body = "Hi " . ($user['full_name'] ?: $user['username']) . ",\n\n"
        . "Verify your Admin email for {$app}:\n\n"
        . $link . "\n\n"
        . "This link expires in 48 hours. If you did not request this, ignore this email.\n";
    if (!send_app_mail($email, "Verify your {$app} Admin email", $body)) {
        return ['ok' => false, 'error' => 'Could not send email. Check mail settings in config.php (mail_from / SMTP).'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Admin-only password reset request by email.
 */
function request_admin_password_reset(string $email): array
{
    ensure_account_schema();
    $email = trim($email);
    // Always return generic success to callers for privacy; still try to send when valid admin.
    $generic = ['ok' => true, 'sent' => false, 'error' => ''];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'sent' => false, 'error' => 'Enter a valid email address.'];
    }
    $stmt = db()->prepare(
        "SELECT * FROM users WHERE role='admin' AND is_active=1 AND LOWER(TRIM(email))=LOWER(?) LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        // Do not reveal whether email exists
        return $generic;
    }
    if (empty($user['email_verified_at'])) {
        return [
            'ok' => false,
            'sent' => false,
            'error' => 'Verify your Admin email first (Account → Verify email), then try again.',
        ];
    }
    $token = create_auth_token((int) $user['id'], 'password_reset', 2);
    $link = public_page_url('reset_password', ['token' => $token]);
    $app = app_config()['app_name'] ?? 'TechxForm';
    $body = "Hi " . ($user['full_name'] ?: $user['username']) . ",\n\n"
        . "Reset your Admin password for {$app}:\n\n"
        . $link . "\n\n"
        . "This link expires in 2 hours. Only Admin accounts can reset via email.\n"
        . "If you did not request this, ignore this email.\n";
    if (!send_app_mail($email, "Reset your {$app} Admin password", $body)) {
        return ['ok' => false, 'sent' => false, 'error' => 'Could not send email. Check mail settings in config.php.'];
    }
    return ['ok' => true, 'sent' => true, 'error' => ''];
}

function mark_admin_email_verified(int $userId): void
{
    ensure_account_schema();
    db()->prepare(
        "UPDATE users SET email_verified_at=NOW() WHERE id=? AND role='admin'"
    )->execute([$userId]);
}

/**
 * Another active admin already uses this email (case-insensitive).
 * Used by Admin → Users so email login / password reset stay unambiguous.
 */
function admin_email_taken_by_other(string $email, int $excludeId = 0): bool
{
    ensure_account_schema();
    $email = trim($email);
    if ($email === '') {
        return false;
    }
    $stmt = db()->prepare(
        "SELECT id FROM users
         WHERE role='admin' AND is_active=1
           AND email <> ''
           AND LOWER(TRIM(email)) = LOWER(?)
           AND id <> ?
         LIMIT 1"
    );
    $stmt->execute([$email, $excludeId]);
    return (bool) $stmt->fetchColumn();
}

function set_user_password(int $userId, string $password): void
{
    db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
}

/* -------------------- Tasks -------------------- */

/**
 * @return list<array<string,mixed>>
 */
function list_team_tasks(?int $assignedTo = null, string $status = '', int $limit = 200): array
{
    ensure_tasks_schema();
    $sql = "SELECT t.*,
                   a.username AS assignee_username, a.full_name AS assignee_name,
                   c.username AS creator_username, c.full_name AS creator_name
            FROM team_tasks t
            INNER JOIN users a ON a.id = t.assigned_to
            INNER JOIN users c ON c.id = t.created_by
            WHERE 1=1";
    $params = [];
    if ($assignedTo) {
        $sql .= ' AND t.assigned_to = ?';
        $params[] = $assignedTo;
    }
    if ($status !== '' && in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) {
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY FIELD(t.status,\'open\',\'in_progress\',\'done\',\'cancelled\'), t.due_date IS NULL, t.due_date ASC, t.id DESC LIMIT '
        . (int) $limit;
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function get_team_task(int $id): ?array
{
    ensure_tasks_schema();
    try {
        $stmt = db()->prepare(
            "SELECT t.*,
                    a.username AS assignee_username, a.full_name AS assignee_name,
                    c.username AS creator_username, c.full_name AS creator_name
             FROM team_tasks t
             INNER JOIN users a ON a.id = t.assigned_to
             INNER JOIN users c ON c.id = t.created_by
             WHERE t.id=? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{id:int}
 */
function create_team_task(array $data, int $createdBy): array
{
    ensure_tasks_schema();
    $title = trim((string) ($data['title'] ?? ''));
    $assignedTo = (int) ($data['assigned_to'] ?? 0);
    if ($title === '') {
        throw new InvalidArgumentException('Title is required.');
    }
    if ($assignedTo <= 0) {
        throw new InvalidArgumentException('Assign a teammate.');
    }
    $check = db()->prepare("SELECT id FROM users WHERE id=? AND role='team' AND is_active=1");
    $check->execute([$assignedTo]);
    if (!$check->fetchColumn()) {
        throw new InvalidArgumentException('Choose an active team user.');
    }
    $status = (string) ($data['status'] ?? 'open');
    if (!in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) {
        $status = 'open';
    }
    $targetRaw = $data['target_count'] ?? null;
    $target = ($targetRaw !== '' && $targetRaw !== null) ? (int) $targetRaw : null;
    if ($target !== null && $target <= 0) {
        $target = null;
    }
    $due = trim((string) ($data['due_date'] ?? ''));
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $due = '';
    }
    $workType = trim((string) ($data['work_type'] ?? 'sites'));
    $allowedTypes = ['sites', 'extract_submit', 'extract_claim', 'extract_final', 'extract_emails'];
    if (!in_array($workType, $allowedTypes, true)) {
        $workType = 'sites';
    }
    db()->prepare(
        'INSERT INTO team_tasks (title, notes, country, language, niche, work_type, target_count, status, assigned_to, created_by, due_date, completed_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $title,
        trim((string) ($data['notes'] ?? '')),
        function_exists('canonicalize_country_name')
            ? canonicalize_country_name(trim((string) ($data['country'] ?? '')))
            : trim((string) ($data['country'] ?? '')),
        trim((string) ($data['language'] ?? '')),
        trim((string) ($data['niche'] ?? '')),
        $workType,
        $target,
        $status,
        $assignedTo,
        $createdBy,
        $due !== '' ? $due : null,
        $status === 'done' ? date('Y-m-d H:i:s') : null,
    ]);
    return ['id' => (int) db()->lastInsertId()];
}

function update_team_task(int $id, array $data): void
{
    ensure_tasks_schema();
    $task = get_team_task($id);
    if (!$task) {
        throw new InvalidArgumentException('Task not found.');
    }
    $title = trim((string) ($data['title'] ?? $task['title']));
    $assignedTo = (int) ($data['assigned_to'] ?? $task['assigned_to']);
    if ($title === '') {
        throw new InvalidArgumentException('Title is required.');
    }
    $check = db()->prepare("SELECT id FROM users WHERE id=? AND role='team' AND is_active=1");
    $check->execute([$assignedTo]);
    if (!$check->fetchColumn() && $assignedTo !== (int) $task['assigned_to']) {
        throw new InvalidArgumentException('Choose an active team user.');
    }
    $status = (string) ($data['status'] ?? $task['status']);
    if (!in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) {
        $status = (string) $task['status'];
    }
    $target = array_key_exists('target_count', $data)
        ? (($data['target_count'] === '' || $data['target_count'] === null) ? null : (int) $data['target_count'])
        : ($task['target_count'] !== null ? (int) $task['target_count'] : null);
    if ($target !== null && $target <= 0) {
        $target = null;
    }
    $due = array_key_exists('due_date', $data) ? trim((string) $data['due_date']) : (string) ($task['due_date'] ?? '');
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $due = '';
    }
    $completedAt = $task['completed_at'];
    if ($status === 'done' && empty($completedAt)) {
        $completedAt = date('Y-m-d H:i:s');
    }
    if ($status !== 'done') {
        $completedAt = null;
    }
    db()->prepare(
        'UPDATE team_tasks SET title=?, notes=?, country=?, language=?, niche=?, work_type=?, target_count=?, status=?,
         assigned_to=?, due_date=?, completed_at=? WHERE id=?'
    )->execute([
        $title,
        trim((string) ($data['notes'] ?? $task['notes'] ?? '')),
        function_exists('canonicalize_country_name')
            ? canonicalize_country_name(trim((string) ($data['country'] ?? $task['country'] ?? '')))
            : trim((string) ($data['country'] ?? $task['country'] ?? '')),
        trim((string) ($data['language'] ?? $task['language'] ?? '')),
        trim((string) ($data['niche'] ?? $task['niche'] ?? '')),
        (static function () use ($data, $task) {
            $workType = trim((string) ($data['work_type'] ?? $task['work_type'] ?? 'sites'));
            $allowed = ['sites', 'extract_submit', 'extract_claim', 'extract_final', 'extract_emails'];
            return in_array($workType, $allowed, true) ? $workType : 'sites';
        })(),
        $target,
        $status,
        $assignedTo,
        $due !== '' ? $due : null,
        $completedAt,
        $id,
    ]);
}

function task_status_label(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
        default => $status,
    };
}

function count_open_tasks_for_user(int $userId): int
{
    ensure_tasks_schema();
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM team_tasks WHERE assigned_to=? AND status IN ('open','in_progress')"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
