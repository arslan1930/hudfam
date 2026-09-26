<?php
/**
 * Admin "New data" reminders — section badges until Admin opens that area.
 *
 * Sections:
 *   our_database     → sites added to Our database
 *   extracted_sites  → Extracted Sites from Team Push
 *   emails_admin     → Sites with emails - Admin (Emails DATA)
 */

function ensure_admin_new_data_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('txf_schema_is_current') && txf_schema_is_current(__FUNCTION__, __FILE__)) {
        return;
    }
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_data_signals (
          section VARCHAR(60) NOT NULL PRIMARY KEY,
          last_new_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_count INT NOT NULL DEFAULT 0,
          note VARCHAR(255) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_data_seen (
          user_id INT NOT NULL,
          section VARCHAR(60) NOT NULL,
          last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (user_id, section),
          CONSTRAINT fk_admin_data_seen_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (function_exists('txf_schema_mark_current')) {
        txf_schema_mark_current(__FUNCTION__);
    }
}

/**
 * @return list<string>
 */
function admin_new_data_sections(): array
{
    return ['our_database', 'extracted_sites', 'emails_admin'];
}

function admin_new_data_normalize_section(string $section): string
{
    $section = trim($section);
    if (!in_array($section, admin_new_data_sections(), true)) {
        return '';
    }
    return $section;
}

/**
 * Mark a section as having new data (call when Team adds/pushes).
 */
function mark_admin_new_data(string $section, int $count = 0, string $note = ''): void
{
    $section = admin_new_data_normalize_section($section);
    if ($section === '') {
        return;
    }
    try {
        ensure_admin_new_data_schema();
        db()->prepare(
            'INSERT INTO admin_data_signals (section, last_new_at, last_count, note)
             VALUES (?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
               last_new_at = NOW(),
               last_count = VALUES(last_count),
               note = VALUES(note)'
        )->execute([$section, max(0, $count), mb_substr(trim($note), 0, 255)]);
    } catch (Throwable $e) {
        // never break main flows
    }
}

/**
 * Admin opened this section — clear the New reminder for this admin.
 */
function clear_admin_new_data(string $section, ?array $user = null): void
{
    $section = admin_new_data_normalize_section($section);
    if ($section === '') {
        return;
    }
    try {
        ensure_admin_new_data_schema();
        $user = $user ?? (function_exists('current_user') ? current_user() : null);
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            return;
        }
        $uid = (int) ($user['id'] ?? 0);
        if ($uid < 1) {
            return;
        }
        db()->prepare(
            'INSERT INTO admin_data_seen (user_id, section, last_seen_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
        )->execute([$uid, $section]);
    } catch (Throwable $e) {
        // ignore
    }
}

function admin_has_new_data(string $section, ?array $user = null): bool
{
    $section = admin_new_data_normalize_section($section);
    if ($section === '') {
        return false;
    }
    try {
        ensure_admin_new_data_schema();
        $user = $user ?? (function_exists('current_user') ? current_user() : null);
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            return false;
        }
        $uid = (int) ($user['id'] ?? 0);
        if ($uid < 1) {
            return false;
        }
        $sig = db()->prepare('SELECT last_new_at FROM admin_data_signals WHERE section=? LIMIT 1');
        $sig->execute([$section]);
        $lastNew = $sig->fetchColumn();
        if ($lastNew === false || $lastNew === null || $lastNew === '') {
            return false;
        }
        $seen = db()->prepare(
            'SELECT last_seen_at FROM admin_data_seen WHERE user_id=? AND section=? LIMIT 1'
        );
        $seen->execute([$uid, $section]);
        $lastSeen = $seen->fetchColumn();
        if ($lastSeen === false || $lastSeen === null || $lastSeen === '') {
            return true;
        }
        return strtotime((string) $lastNew) > strtotime((string) $lastSeen);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<string,bool>
 */
function admin_new_data_flags(?array $user = null): array
{
    $flags = [];
    foreach (admin_new_data_sections() as $section) {
        $flags[$section] = admin_has_new_data($section, $user);
    }
    return $flags;
}

/**
 * New badge HTML. Re-enabled only for emails_admin (Sites with emails - Admin).
 * Our database / Extracted stay off until a later decision.
 */
function admin_new_badge_html(string $section, ?array $user = null): string
{
    $section = admin_new_data_normalize_section($section);
    if ($section !== 'emails_admin') {
        return '';
    }
    if (!admin_has_new_data($section, $user)) {
        return '';
    }
    return ' <span class="admin-new-badge" title="New data — open a country to clear">New</span>';
}

/**
 * Map admin page keys to reminder sections.
 */
function admin_new_data_section_for_page(string $page): string
{
    return match ($page) {
        'admin_prospects', 'admin_prospect_batches', 'admin_prospect_batch', 'admin_prospect_add' => 'our_database',
        'admin_extracted' => 'extracted_sites',
        'admin_emails_data' => 'emails_admin',
        default => '',
    };
}
