<?php
/**
 * Lightweight “who else is on this task” presence (warning only — no locks).
 */

function ensure_task_presence_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS task_presence (
          id INT AUTO_INCREMENT PRIMARY KEY,
          task_key VARCHAR(190) NOT NULL,
          user_id INT NOT NULL,
          display_name VARCHAR(120) NOT NULL DEFAULT '',
          last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_task_presence_user (task_key, user_id),
          INDEX (task_key),
          INDEX (last_seen_at),
          CONSTRAINT fk_task_presence_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function normalize_task_presence_key(string $taskKey): string
{
    $taskKey = trim($taskKey);
    $taskKey = preg_replace('/\s+/', ' ', $taskKey) ?? $taskKey;
    if (mb_strlen($taskKey) > 180) {
        $taskKey = mb_substr($taskKey, 0, 180);
    }
    return $taskKey;
}

function task_presence_display_name(array $user): string
{
    $name = trim((string) ($user['full_name'] ?? ''));
    if ($name !== '') {
        return mb_strlen($name) > 40 ? mb_substr($name, 0, 40) : $name;
    }
    $username = trim((string) ($user['username'] ?? 'User'));
    return $username !== '' ? $username : 'User';
}

/**
 * Refresh current user presence and return others still active on the same task.
 *
 * @return array{ok:bool,task_key:string,others:list<array{id:int,name:string}>,count:int,error?:string}
 */
function ping_task_presence(string $taskKey, array $user, int $ttlSeconds = 45): array
{
    ensure_task_presence_schema();
    $taskKey = normalize_task_presence_key($taskKey);
    $uid = (int) ($user['id'] ?? 0);
    if ($taskKey === '' || $uid < 1) {
        return ['ok' => false, 'task_key' => $taskKey, 'others' => [], 'count' => 0, 'error' => 'Missing task or user.'];
    }
    $ttlSeconds = max(20, min(180, $ttlSeconds));
    $name = task_presence_display_name($user);

    db()->prepare(
        'INSERT INTO task_presence (task_key, user_id, display_name, last_seen_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           display_name = VALUES(display_name),
           last_seen_at = NOW()'
    )->execute([$taskKey, $uid, $name]);

    // Drop stale rows (any task) so the table stays small.
    db()->prepare(
        'DELETE FROM task_presence
         WHERE last_seen_at < (NOW() - INTERVAL ? SECOND)'
    )->execute([$ttlSeconds]);

    $st = db()->prepare(
        'SELECT user_id, display_name
         FROM task_presence
         WHERE task_key = ?
           AND user_id <> ?
           AND last_seen_at >= (NOW() - INTERVAL ? SECOND)
         ORDER BY display_name ASC
         LIMIT 20'
    );
    $st->execute([$taskKey, $uid, $ttlSeconds]);
    $others = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $others[] = [
            'id' => (int) ($row['user_id'] ?? 0),
            'name' => (string) ($row['display_name'] ?? 'User'),
        ];
    }
    return [
        'ok' => true,
        'task_key' => $taskKey,
        'others' => $others,
        'count' => count($others),
    ];
}

/**
 * Compact presence chip. Shows only when someone else is on the same task.
 */
function render_task_presence(string $taskKey, string $ariaLabel = 'Others on this task'): void
{
    static $scriptPrinted = false;
    $user = current_user();
    if (!$user) {
        return;
    }
    $taskKey = normalize_task_presence_key($taskKey);
    if ($taskKey === '') {
        return;
    }
    echo '<div class="task-presence" data-task-presence'
        . ' data-task-key="' . h($taskKey) . '"'
        . ' data-ping-url="index.php?page=presence_ping"'
        . ' hidden'
        . ' title="Other teammates currently on this task"'
        . ' aria-live="polite"'
        . ' aria-label="' . h($ariaLabel) . '">';
    echo '<span class="task-presence-dot" aria-hidden="true"></span>';
    echo '<span class="task-presence-label">Also here</span>';
    echo '<span class="task-presence-names" data-presence-names></span>';
    echo '</div>';
    if (!$scriptPrinted) {
        $scriptPrinted = true;
        echo '<script src="' . h(script_asset_url('js/task-presence.js')) . '" defer></script>';
    }
}
