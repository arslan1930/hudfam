<?php
/**
 * Sites with emails stores:
 *   team       → working copy from Extracting Results Push; team adds emails, then Push to admin
 *   admin      → Sites with emails - Admin (from Team Push)
 *   admin_all  → All sites with emails - Final (Admin-only mirror of admin; no Team access)
 */

function swe_normalize_scope(string $scope): string
{
    if ($scope === 'admin') {
        return 'admin';
    }
    if ($scope === 'admin_all') {
        return 'admin_all';
    }
    return 'team';
}

function swe_table(string $scope): string
{
    return match (swe_normalize_scope($scope)) {
        'admin' => 'sites_with_emails_admin',
        'admin_all' => 'sites_with_emails_admin_all',
        default => 'sites_with_emails_team',
    };
}

function swe_label(string $scope): string
{
    return match (swe_normalize_scope($scope)) {
        'admin' => 'Admin',
        'admin_all' => 'Final',
        default => 'Team',
    };
}

function swe_create_table_sql(string $table): string
{
    $map = [
        'sites_with_emails_admin' => ['fk_swe_admin_pushed_by', 'uniq_swe_admin_country_domain'],
        'sites_with_emails_admin_all' => ['fk_swe_admin_all_pushed_by', 'uniq_swe_admin_all_country_domain'],
        'sites_with_emails_team' => ['fk_swe_team_pushed_by', 'uniq_swe_team_country_domain'],
    ];
    [$fk, $uniq] = $map[$table] ?? ['fk_swe_team_pushed_by', 'uniq_swe_team_country_domain'];
    return "CREATE TABLE IF NOT EXISTS {$table} (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          country VARCHAR(100) NOT NULL,
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          email1 VARCHAR(255) NOT NULL DEFAULT '',
          email2 VARCHAR(255) NOT NULL DEFAULT '',
          email3 VARCHAR(255) NOT NULL DEFAULT '',
          email4 VARCHAR(255) NOT NULL DEFAULT '',
          extract_batch_id INT NULL,
          pushed_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY {$uniq} (country, domain),
          INDEX (country),
          INDEX (domain),
          INDEX (pushed_by),
          CONSTRAINT {$fk} FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function ensure_sites_with_emails_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(swe_create_table_sql('sites_with_emails_team'));
    $pdo->exec(swe_create_table_sql('sites_with_emails_admin'));
    $pdo->exec(swe_create_table_sql('sites_with_emails_admin_all'));

    // Campaign progress lives only on Admin — Final stays a neutral duplicate archive.
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM sites_with_emails_admin')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $have = array_fill_keys(array_map('strval', $cols), true);
        if (!isset($have['email_sent'])) {
            $pdo->exec(
                'ALTER TABLE sites_with_emails_admin
                 ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER email4,
                 ADD INDEX idx_swe_admin_email_sent (country, email_sent)'
            );
        }
        if (!isset($have['email_sent_at'])) {
            $pdo->exec(
                'ALTER TABLE sites_with_emails_admin
                 ADD COLUMN email_sent_at TIMESTAMP NULL DEFAULT NULL AFTER email_sent'
            );
        }
    } catch (Throwable $e) {
        // ignore migration hiccups
    }

    // Per-admin “seen” watermark for Sites with emails - Admin country folders.
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS swe_admin_country_seen (
              user_id INT NOT NULL,
              country VARCHAR(100) NOT NULL,
              last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (user_id, country),
              CONSTRAINT fk_swe_admin_country_seen_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }

    // Legacy single table → migrate into Team working copy once.
    try {
        $legacy = $pdo->query("SHOW TABLES LIKE 'sites_with_emails'")->fetchColumn();
        if ($legacy) {
            $countLegacy = table_has_any_row($pdo, 'sites_with_emails');
            $countTeam = table_has_any_row($pdo, 'sites_with_emails_team');
            if ($countLegacy && !$countTeam) {
                $pdo->exec(
                    'INSERT IGNORE INTO sites_with_emails_team
                       (domain, country, language, region, email1, email2, email3, email4,
                        extract_batch_id, pushed_by, created_at, updated_at)
                     SELECT domain, country, language, region, email1, email2, email3, email4,
                            extract_batch_id, pushed_by, created_at, updated_at
                     FROM sites_with_emails'
                );
            }
        }
    } catch (Throwable $e) {
        // ignore migration hiccups; tables above are enough
    }

    // First-time / catch-up: mirror Admin → All if All is empty but Admin has data.
    try {
        if (table_has_any_row($pdo, 'sites_with_emails_admin')
            && !table_has_any_row($pdo, 'sites_with_emails_admin_all')) {
            sync_sites_with_emails_admin_to_all();
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Country + id for paginated country sheets (up to ~100K rows per country).
    foreach (['sites_with_emails_team', 'sites_with_emails_admin', 'sites_with_emails_admin_all'] as $tbl) {
        try {
            $idxName = 'idx_' . $tbl . '_country_id';
            if (!table_has_index($pdo, $tbl, $idxName)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD INDEX {$idxName} (country, id)");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * Upsert one Admin row into All sites with emails - Final (by country + domain).
 */
function sync_sites_with_emails_admin_row_to_all(array $row): void
{
    ensure_sites_with_emails_schema();
    $domain = trim((string) ($row['domain'] ?? ''));
    $country = trim((string) ($row['country'] ?? ''));
    if ($domain === '' || $country === '') {
        return;
    }
    db()->prepare(
        'INSERT INTO sites_with_emails_admin_all
           (domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           email1 = VALUES(email1),
           email2 = VALUES(email2),
           email3 = VALUES(email3),
           email4 = VALUES(email4),
           language = VALUES(language),
           region = VALUES(region),
           extract_batch_id = VALUES(extract_batch_id),
           pushed_by = VALUES(pushed_by),
           updated_at = NOW()'
    )->execute([
        $domain,
        $country,
        (string) ($row['language'] ?? ''),
        (string) ($row['region'] ?? ''),
        (string) ($row['email1'] ?? ''),
        (string) ($row['email2'] ?? ''),
        (string) ($row['email3'] ?? ''),
        (string) ($row['email4'] ?? ''),
        $row['extract_batch_id'] !== null && $row['extract_batch_id'] !== ''
            ? (int) $row['extract_batch_id']
            : null,
        $row['pushed_by'] !== null && $row['pushed_by'] !== ''
            ? (int) $row['pushed_by']
            : null,
    ]);
}

/**
 * Full mirror: Sites with emails - Admin → All sites with emails - Final.
 * Adds/updates Final from current Admin rows. Does NOT delete Final-only rows —
 * those are archive copies (marked emailed / removed from Admin).
 *
 * @return array{
 *   upserted:int,
 *   added:int,
 *   updated:int,
 *   unchanged:int,
 *   removed:int,
 *   added_samples:list<string>,
 *   updated_samples:list<string>,
 *   removed_samples:list<string>
 * }
 */
function sync_sites_with_emails_admin_to_all(?string $country = null): array
{
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $pdo = db();
    $upserted = 0;
    $added = 0;
    $updated = 0;
    $unchanged = 0;
    /** @var list<string> $addedSamples */
    $addedSamples = [];
    /** @var list<string> $updatedSamples */
    $updatedSamples = [];

    if ($country !== null && trim($country) !== '') {
        $sel = $pdo->prepare('SELECT * FROM sites_with_emails_admin WHERE country=?');
        $sel->execute([trim($country)]);
    } else {
        $sel = $pdo->query('SELECT * FROM sites_with_emails_admin');
    }
    $exist = $pdo->prepare(
        'SELECT email1, email2, email3, email4, language, region
         FROM sites_with_emails_admin_all WHERE country=? AND domain=? LIMIT 1'
    );
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $cName = (string) ($row['country'] ?? '');
        $domain = (string) ($row['domain'] ?? '');
        $label = $cName . ' · ' . $domain;
        $exist->execute([$cName, $domain]);
        $prior = $exist->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($prior === null) {
            $added++;
            if (count($addedSamples) < 10) {
                $addedSamples[] = $label;
            }
        } else {
            $beforeSlots = email_slots_from_row($prior);
            $afterSlots = email_slots_from_row($row);
            $metaChanged = trim((string) ($prior['language'] ?? '')) !== trim((string) ($row['language'] ?? ''))
                || trim((string) ($prior['region'] ?? '')) !== trim((string) ($row['region'] ?? ''));
            if (!swe_email_slots_equal($beforeSlots, $afterSlots) || $metaChanged) {
                $updated++;
                if (count($updatedSamples) < 10) {
                    $updatedSamples[] = $label;
                }
            } else {
                $unchanged++;
            }
        }
        sync_sites_with_emails_admin_row_to_all($row);
        $upserted++;
    }

    return [
        'upserted' => $upserted,
        'added' => $added,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'removed' => 0,
        'added_samples' => $addedSamples,
        'updated_samples' => $updatedSamples,
        'removed_samples' => [],
    ];
}

/**
 * True when Admin has rows missing from Final or emails/meta that differ.
 * Extra Final-only rows (archive copies) are expected and are not drift.
 */
function sites_with_emails_final_needs_repair(): bool
{
    ensure_sites_with_emails_schema();
    // Stop at the first mismatch — COUNT(*) over the full Admin↔Final join froze the hub.
    $hit = db()->query(
        "SELECT 1 FROM sites_with_emails_admin a
         LEFT JOIN sites_with_emails_admin_all f
           ON f.country = a.country AND f.domain = a.domain
         WHERE f.id IS NULL
            OR a.email1 <> f.email1 OR a.email2 <> f.email2
            OR a.email3 <> f.email3 OR a.email4 <> f.email4
            OR a.language <> f.language OR a.region <> f.region
         LIMIT 1"
    )->fetchColumn();
    return (int) $hit === 1;
}

function delete_sites_with_emails_admin_all_by_domain(string $country, string $domain): void
{
    ensure_sites_with_emails_schema();
    db()->prepare(
        'DELETE FROM sites_with_emails_admin_all WHERE country=? AND domain=?'
    )->execute([$country, $domain]);
}

function normalize_email_value(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }
    // Strip wrapping <angle brackets> / quotes from pasted values.
    $email = trim($email, " \t\n\r\0\x0B\"'<>");
    if ($email === '') {
        return '';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    if (strlen($email) > 255) {
        return '';
    }
    return $email;
}

/**
 * Split a pasted/packed email cell into individual addresses
 * (commas, semicolons, whitespace, or newlines).
 *
 * @return list<string>
 */
function split_email_cell(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    // Already a single address — keep as one token.
    if (normalize_email_value($raw) !== '') {
        return [$raw];
    }
    $parts = preg_split('/[\s,;]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Flatten email1…email4 (and any packed multi-email cells) into up to 4 slots.
 *
 * @param array<int|string,mixed> $emails
 * @return list<string>
 */
function flatten_email_inputs(array $emails): array
{
    $flat = [];
    foreach ($emails as $e) {
        foreach (split_email_cell((string) $e) as $part) {
            $flat[] = $part;
            if (count($flat) >= 16) { // hard cap before normalize
                break 2;
            }
        }
    }
    return $flat;
}

/**
 * Compact up to 4 unique valid emails. Packed multi-email cells are split first.
 * Invalid tokens are skipped by default so one bad address does not wipe / block the rest
 * (Copy all emails, expand, paste). Pass $strict=true to reject the whole set.
 *
 * @param array{0?:string,1?:string,2?:string,3?:string}|list<string> $emails
 * @return array{ok:bool,slots?:array{0:string,1:string,2:string,3:string},error?:string,skipped_invalid?:list<string>}
 */
function normalize_email_slots(array $emails, bool $strict = false): array
{
    $out = ['', '', '', ''];
    $i = 0;
    $seen = [];
    /** @var list<string> $skippedInvalid */
    $skippedInvalid = [];
    foreach (flatten_email_inputs($emails) as $raw) {
        if ($i >= 4) {
            break;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        $n = normalize_email_value($raw);
        if ($n === '') {
            $skippedInvalid[] = $raw;
            if ($strict) {
                return [
                    'ok' => false,
                    'error' => 'Invalid email: ' . $raw,
                    'skipped_invalid' => $skippedInvalid,
                ];
            }
            continue;
        }
        if (isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $out[$i] = $n;
        $i++;
    }
    return ['ok' => true, 'slots' => $out, 'skipped_invalid' => $skippedInvalid];
}

/**
 * Read email1–4 from a Team/Admin row and expand packed cells into 4 slots.
 *
 * @param array<string,mixed> $row
 * @return array{0:string,1:string,2:string,3:string}
 */
function email_slots_from_row(array $row): array
{
    $norm = normalize_email_slots([
        (string) ($row['email1'] ?? ''),
        (string) ($row['email2'] ?? ''),
        (string) ($row['email3'] ?? ''),
        (string) ($row['email4'] ?? ''),
    ]);
    if (!empty($norm['ok']) && isset($norm['slots']) && is_array($norm['slots'])) {
        return [
            (string) ($norm['slots'][0] ?? ''),
            (string) ($norm['slots'][1] ?? ''),
            (string) ($norm['slots'][2] ?? ''),
            (string) ($norm['slots'][3] ?? ''),
        ];
    }
    // Fallback: keep email1 only if packed junk failed validation
    return [
        normalize_email_value((string) ($row['email1'] ?? '')),
        normalize_email_value((string) ($row['email2'] ?? '')),
        normalize_email_value((string) ($row['email3'] ?? '')),
        normalize_email_value((string) ($row['email4'] ?? '')),
    ];
}

/**
 * Insert site names into Team working copy (from Extracting Results Push).
 *
 * @param list<string> $domains
 * @return array{inserted:int,skipped:int,country:string}
 */
function add_sites_with_emails_domains(
    array $domains,
    string $country,
    array $user,
    string $language = '',
    string $region = '',
    ?int $extractBatchId = null
): array {
    return add_sites_with_emails_domains_to_scope(
        'team',
        $domains,
        $country,
        $user,
        $language,
        $region,
        $extractBatchId
    );
}

/**
 * @param list<string> $domains
 * @return array{inserted:int,skipped:int,country:string}
 */
function add_sites_with_emails_domains_to_scope(
    string $scope,
    array $domains,
    string $country,
    array $user,
    string $language = '',
    string $region = '',
    ?int $extractBatchId = null
): array {
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $table = swe_table($scope);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    if ($region === '') {
        $region = $canon['region'];
    }
    if ($language === '') {
        $language = $canon['language'];
    }

    $unique = [];
    foreach ($domains as $d) {
        $host = function_exists('extract_host_candidate') ? extract_host_candidate((string) $d) : normalize_domain((string) $d);
        $root = function_exists('to_root_domain') ? to_root_domain($host) : normalize_domain($host);
        if ($root !== '' && function_exists('is_root_domain') && is_root_domain($root)) {
            $unique[$root] = true;
        }
    }
    $list = array_keys($unique);
    if ($list === []) {
        return ['inserted' => 0, 'skipped' => 0, 'country' => $country];
    }

    $ins = db()->prepare(
        "INSERT INTO {$table}
           (domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by)
         VALUES (?,?,?,?, '', '', '', '', ?,?)
         ON DUPLICATE KEY UPDATE
           updated_at = NOW(),
           language = IF(VALUES(language) <> '', VALUES(language), language),
           region = IF(VALUES(region) <> '', VALUES(region), region)"
    );
    $find = db()->prepare(
        "SELECT id FROM {$table} WHERE country=? AND domain=? LIMIT 1"
    );
    $inserted = 0;
    $skipped = 0;
    $uid = (int) ($user['id'] ?? 0) ?: null;
    foreach ($list as $domain) {
        $find->execute([$country, $domain]);
        $exists = (int) $find->fetchColumn() > 0;
        try {
            $ins->execute([$domain, $country, $language, $region, $extractBatchId, $uid]);
            if ($exists) {
                $skipped++;
            } else {
                $inserted++;
            }
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    return ['inserted' => $inserted, 'skipped' => $skipped, 'country' => $country];
}

/**
 * Domains already in Admin that a Team push for this country would merge into.
 *
 * @return list<string>
 */
function list_sites_with_emails_push_conflict_domains(string $country): array
{
    ensure_sites_with_emails_schema();
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $team = swe_table('team');
    $admin = swe_table('admin');
    $stmt = db()->prepare(
        "SELECT t.domain
         FROM {$team} t
         INNER JOIN {$admin} a ON a.country = t.country AND a.domain = t.domain
         WHERE t.country=?
           AND (t.email1<>'' OR t.email2<>'' OR t.email3<>'' OR t.email4<>'')
         ORDER BY t.domain ASC"
    );
    $stmt->execute([$country]);
    $out = [];
    while ($domain = $stmt->fetchColumn()) {
        $out[] = (string) $domain;
    }
    return $out;
}

function count_sites_with_emails_push_conflicts(string $country): int
{
    return count(list_sites_with_emails_push_conflict_domains($country));
}

/**
 * Merge Team emails into Admin slots (option B):
 * keep every existing Admin email in place; fill empty slots with Team emails
 * that are not already present. Never wipe a filled Admin slot.
 *
 * @param array{0:string,1:string,2:string,3:string}|list<string> $adminSlots
 * @param array{0:string,1:string,2:string,3:string}|list<string> $teamSlots
 * @return array{0:string,1:string,2:string,3:string}
 */
function merge_swe_email_slots_prefer_admin(array $adminSlots, array $teamSlots): array
{
    return merge_swe_email_slots_prefer_admin_stats($adminSlots, $teamSlots)['slots'];
}

/**
 * Compare normalized email slot lists (order-sensitive; empty trailing ignored via normalize from email_slots_from_row).
 *
 * @param list<string> $a
 * @param list<string> $b
 */
function swe_email_slots_equal(array $a, array $b): bool
{
    $norm = static function (array $slots): array {
        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $out[] = strtolower(trim((string) ($slots[$i] ?? '')));
        }
        return $out;
    };
    return $norm($a) === $norm($b);
}

/**
 * When a re-push changes Admin email slots on an emailed row, clear the emailed checkpoint.
 *
 * @param list<string> $beforeSlots
 * @param list<string> $afterSlots
 */
function swe_admin_clear_emailed_if_slots_changed(
    string $country,
    string $domain,
    array $beforeSlots,
    array $afterSlots,
    bool $wasEmailed
): bool {
    if (!$wasEmailed || swe_email_slots_equal($beforeSlots, $afterSlots)) {
        return false;
    }
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare(
        'UPDATE sites_with_emails_admin
         SET email_sent=0, email_sent_at=NULL
         WHERE country=? AND domain=? AND email_sent=1'
    );
    $stmt->execute([trim($country), trim($domain)]);
    return $stmt->rowCount() > 0;
}

/**
 * Same merge as prefer_admin, plus how many Team emails could not fit (Admin already full).
 *
 * @param array{0:string,1:string,2:string,3:string}|list<string> $adminSlots
 * @param array{0:string,1:string,2:string,3:string}|list<string> $teamSlots
 * @return array{slots: array{0:string,1:string,2:string,3:string}, dropped:int, dropped_emails:list<string>}
 */
function merge_swe_email_slots_prefer_admin_stats(array $adminSlots, array $teamSlots): array
{
    $out = ['', '', '', ''];
    $seen = [];
    for ($i = 0; $i < 4; $i++) {
        $e = trim((string) ($adminSlots[$i] ?? ''));
        $out[$i] = $e;
        if ($e !== '') {
            $seen[strtolower($e)] = true;
        }
    }
    $droppedEmails = [];
    foreach ($teamSlots as $raw) {
        $e = trim((string) $raw);
        if ($e === '') {
            continue;
        }
        $key = strtolower($e);
        if (isset($seen[$key])) {
            continue;
        }
        $placed = false;
        for ($i = 0; $i < 4; $i++) {
            if ($out[$i] === '') {
                $out[$i] = $e;
                $seen[$key] = true;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $droppedEmails[] = $e;
        }
    }
    return [
        'slots' => $out,
        'dropped' => count($droppedEmails),
        'dropped_emails' => $droppedEmails,
    ];
}

/**
 * Team → Admin: push one site row (must have at least one email), then remove it from Team.
 * When Admin already has the domain, require $confirmOverwrite (UI confirm) then merge
 * Team emails into empty Admin slots only (never wipe filled Admin emails).
 *
 * @return array{ok:bool,error?:string,needs_confirm?:bool,pushed?:int,updated?:int,cleared?:int,skipped_full_slots?:int,dropped_emails?:list<string>,domain?:string,country?:string,site_count?:int}
 */
function push_one_site_with_emails_team_to_admin(
    int $siteId,
    array $user,
    ?string $expectCountry = null,
    bool $confirmOverwrite = false
): array {
    ensure_sites_with_emails_schema();
    $team = swe_table('team');
    $admin = swe_table('admin');
    $uid = (int) ($user['id'] ?? 0) ?: null;

    $sel = db()->prepare("SELECT * FROM {$team} WHERE id=? LIMIT 1");
    $sel->execute([$siteId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found in Team working copy.'];
    }

    $country = trim((string) ($row['country'] ?? ''));
    $canon = $country !== '' ? resolve_canonical_country($country) : null;
    if ($canon) {
        $country = $canon['name'];
    }
    $domain = trim((string) ($row['domain'] ?? ''));
    if ($domain === '' || $country === '') {
        return ['ok' => false, 'error' => 'Site row is incomplete.'];
    }

    if ($expectCountry !== null && $expectCountry !== '') {
        $expectCanon = resolve_canonical_country($expectCountry);
        $expectName = $expectCanon ? $expectCanon['name'] : trim($expectCountry);
        if ($expectName !== $country) {
            return ['ok' => false, 'error' => 'That site is not on this country sheet.'];
        }
    }

    $slots = email_slots_from_row($row);
    $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
    if (!$hasEmail) {
        return ['ok' => false, 'error' => 'Add at least one email before pushing this site.'];
    }

    $adminSel = db()->prepare(
        "SELECT id, email1, email2, email3, email4, email_sent
         FROM {$admin} WHERE country=? AND domain=? LIMIT 1"
    );
    $adminSel->execute([$country, $domain]);
    $adminRow = $adminSel->fetch(PDO::FETCH_ASSOC) ?: null;
    $already = is_array($adminRow);
    if ($already && !$confirmOverwrite) {
        return [
            'ok' => false,
            'needs_confirm' => true,
            'error' => $domain . ' already exists in Sites with emails - Admin. Confirm to merge Team emails into empty Admin slots (existing Admin emails are kept).',
            'domain' => $domain,
            'country' => $country,
            'site_count' => count_sites_with_emails_for_country($country, 'team'),
        ];
    }

    $droppedEmails = [];
    $skippedFullSlots = 0;
    $emailedCleared = 0;
    $beforeSlots = ['', '', '', ''];
    $wasEmailed = false;
    if ($already) {
        $beforeSlots = email_slots_from_row($adminRow);
        $wasEmailed = (int) ($adminRow['email_sent'] ?? 0) === 1;
        $merged = merge_swe_email_slots_prefer_admin_stats($beforeSlots, $slots);
        $slots = $merged['slots'];
        $skippedFullSlots = (int) $merged['dropped'];
        $droppedEmails = $merged['dropped_emails'];
    }

    $ins = db()->prepare(
        "INSERT INTO {$admin}
           (domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           email1 = VALUES(email1),
           email2 = VALUES(email2),
           email3 = VALUES(email3),
           email4 = VALUES(email4),
           language = IF(VALUES(language) <> '', VALUES(language), language),
           region = IF(VALUES(region) <> '', VALUES(region), region),
           extract_batch_id = COALESCE(VALUES(extract_batch_id), extract_batch_id),
           pushed_by = VALUES(pushed_by),
           updated_at = IF(
             email1 = VALUES(email1) AND email2 = VALUES(email2)
               AND email3 = VALUES(email3) AND email4 = VALUES(email4),
             updated_at,
             NOW()
           )"
    );
    $ins->execute([
        $domain,
        $country,
        (string) ($row['language'] ?? ''),
        (string) ($row['region'] ?? ''),
        $slots[0],
        $slots[1],
        $slots[2],
        $slots[3],
        $row['extract_batch_id'] !== null ? (int) $row['extract_batch_id'] : null,
        $uid,
    ]);
    sync_sites_with_emails_admin_row_to_all([
        'domain' => $domain,
        'country' => $country,
        'language' => (string) ($row['language'] ?? ''),
        'region' => (string) ($row['region'] ?? ''),
        'email1' => $slots[0],
        'email2' => $slots[1],
        'email3' => $slots[2],
        'email4' => $slots[3],
        'extract_batch_id' => $row['extract_batch_id'] !== null ? (int) $row['extract_batch_id'] : null,
        'pushed_by' => $uid,
    ]);

    if ($already && swe_admin_clear_emailed_if_slots_changed(
        $country,
        $domain,
        $beforeSlots,
        $slots,
        $wasEmailed
    )) {
        $emailedCleared = 1;
    }

    $del = db()->prepare("DELETE FROM {$team} WHERE id=?");
    $del->execute([$siteId]);
    $cleared = $del->rowCount();

    if (function_exists('mark_admin_new_data')) {
        mark_admin_new_data('emails_admin', 1, $country);
    }

    return [
        'ok' => true,
        'pushed' => $already ? 0 : 1,
        'updated' => $already ? 1 : 0,
        'cleared' => $cleared,
        'skipped_full_slots' => $skippedFullSlots,
        'dropped_emails' => $droppedEmails,
        'emailed_cleared' => $emailedCleared,
        'domain' => $domain,
        'country' => $country,
        'site_count' => count_sites_with_emails_for_country($country, 'team'),
    ];
}

/**
 * Team → Admin: copy rows that have at least one email into the admin archive,
 * then remove those rows from the Team working copy (sites without emails stay).
 * When any domain already exists in Admin, require $confirmOverwrite then merge
 * Team emails into empty Admin slots only (existing Admin emails are kept).
 *
 * @return array{ok:bool,error?:string,needs_confirm?:bool,conflicts?:int,pushed:int,updated:int,cleared:int,skipped_empty:int,skipped_full_slots:int,dropped_domains:list<string>,country:string}
 */
function push_sites_with_emails_team_to_admin(
    string $country,
    array $user,
    bool $confirmOverwrite = false
): array {
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $team = swe_table('team');
    $admin = swe_table('admin');
    $uid = (int) ($user['id'] ?? 0) ?: null;

    $conflicts = count_sites_with_emails_push_conflicts($country);
    if ($conflicts > 0 && !$confirmOverwrite) {
        return [
            'ok' => false,
            'needs_confirm' => true,
            'conflicts' => $conflicts,
            'error' => $conflicts . ' site(s) already exist in Sites with emails - Admin. Confirm to merge Team emails into empty Admin slots (existing Admin emails are kept).',
            'pushed' => 0,
            'updated' => 0,
            'cleared' => 0,
            'skipped_empty' => 0,
            'skipped_full_slots' => 0,
            'dropped_domains' => [],
            'emailed_cleared' => 0,
            'country' => $country,
        ];
    }

    $sel = db()->prepare(
        "SELECT domain, country, language, region, email1, email2, email3, email4, extract_batch_id
         FROM {$team}
         WHERE country=?
         ORDER BY id ASC"
    );
    $sel->execute([$country]);

    $ins = db()->prepare(
        "INSERT INTO {$admin}
           (domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           email1 = VALUES(email1),
           email2 = VALUES(email2),
           email3 = VALUES(email3),
           email4 = VALUES(email4),
           language = IF(VALUES(language) <> '', VALUES(language), language),
           region = IF(VALUES(region) <> '', VALUES(region), region),
           extract_batch_id = COALESCE(VALUES(extract_batch_id), extract_batch_id),
           pushed_by = VALUES(pushed_by),
           updated_at = IF(
             email1 = VALUES(email1) AND email2 = VALUES(email2)
               AND email3 = VALUES(email3) AND email4 = VALUES(email4),
             updated_at,
             NOW()
           )"
    );
    $adminSel = db()->prepare(
        "SELECT email1, email2, email3, email4, email_sent FROM {$admin} WHERE country=? AND domain=? LIMIT 1"
    );

    $pushed = 0;
    $updated = 0;
    $skippedEmpty = 0;
    $skippedFullSlots = 0;
    $emailedCleared = 0;
    /** @var list<string> $droppedDomains */
    $droppedDomains = [];
    $pushedDomains = [];
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $slots = email_slots_from_row($row);
        $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
        if (!$hasEmail) {
            $skippedEmpty++;
            continue;
        }
        $domain = (string) $row['domain'];
        $adminSel->execute([$country, $domain]);
        $adminRow = $adminSel->fetch(PDO::FETCH_ASSOC) ?: null;
        $already = is_array($adminRow);
        $beforeSlots = ['', '', '', ''];
        $wasEmailed = false;
        if ($already) {
            $beforeSlots = email_slots_from_row($adminRow);
            $wasEmailed = (int) ($adminRow['email_sent'] ?? 0) === 1;
            $merged = merge_swe_email_slots_prefer_admin_stats($beforeSlots, $slots);
            $slots = $merged['slots'];
            if ((int) $merged['dropped'] > 0) {
                $skippedFullSlots += (int) $merged['dropped'];
                if (count($droppedDomains) < 8) {
                    $droppedDomains[] = $domain;
                }
            }
        }
        $ins->execute([
            $domain,
            $country,
            (string) ($row['language'] ?? ''),
            (string) ($row['region'] ?? ''),
            $slots[0],
            $slots[1],
            $slots[2],
            $slots[3],
            $row['extract_batch_id'] !== null ? (int) $row['extract_batch_id'] : null,
            $uid,
        ]);
        sync_sites_with_emails_admin_row_to_all([
            'domain' => $domain,
            'country' => $country,
            'language' => (string) ($row['language'] ?? ''),
            'region' => (string) ($row['region'] ?? ''),
            'email1' => $slots[0],
            'email2' => $slots[1],
            'email3' => $slots[2],
            'email4' => $slots[3],
            'extract_batch_id' => $row['extract_batch_id'] !== null ? (int) $row['extract_batch_id'] : null,
            'pushed_by' => $uid,
        ]);
        if ($already && swe_admin_clear_emailed_if_slots_changed(
            $country,
            $domain,
            $beforeSlots,
            $slots,
            $wasEmailed
        )) {
            $emailedCleared++;
        }
        $pushedDomains[] = $domain;
        if ($already) {
            $updated++;
        } else {
            $pushed++;
        }
    }

    // Clear Team working copy for rows that were pushed (keep no-email sites).
    $cleared = 0;
    if ($pushedDomains) {
        $chunkSize = 200;
        foreach (array_chunk($pushedDomains, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $del = db()->prepare(
                "DELETE FROM {$team} WHERE country=? AND domain IN ({$placeholders})"
            );
            $del->execute(array_merge([$country], $chunk));
            $cleared += $del->rowCount();
        }
    }

    if (($pushed + $updated) > 0 && function_exists('mark_admin_new_data')) {
        mark_admin_new_data('emails_admin', $pushed + $updated, $country);
    }

    return [
        'ok' => true,
        'conflicts' => $conflicts,
        'pushed' => $pushed,
        'updated' => $updated,
        'cleared' => $cleared,
        'skipped_empty' => $skippedEmpty,
        'skipped_full_slots' => $skippedFullSlots,
        'dropped_domains' => $droppedDomains,
        'emailed_cleared' => $emailedCleared,
        'country' => $country,
    ];
}

/**
 * @return list<array{country:string,region:string,language:string,total:int,with_emails:int,last_pushed_at:?string}>
 */
function list_sites_with_emails_country_rows(string $scope = 'team'): array
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    $sql = "SELECT TRIM(country) AS country,
                   MAX(region) AS region,
                   MAX(language) AS language,
                   COUNT(*) AS total,
                   SUM(
                     CASE WHEN email1<>'' OR email2<>'' OR email3<>'' OR email4<>'' THEN 1 ELSE 0 END
                   ) AS with_emails,
                   MAX(updated_at) AS last_pushed_at
            FROM {$table}
            WHERE TRIM(country) <> ''
            GROUP BY TRIM(country)
            ORDER BY last_pushed_at DESC, country ASC";
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $name = (string) $row['country'];
        $canon = resolve_canonical_country($name);
        $out[] = [
            'country' => $canon ? $canon['name'] : $name,
            'region' => $canon ? $canon['region'] : (string) ($row['region'] ?? ''),
            'language' => $canon ? $canon['language'] : (string) ($row['language'] ?? ''),
            'total' => (int) $row['total'],
            'with_emails' => (int) $row['with_emails'],
            'last_pushed_at' => $row['last_pushed_at'] !== null ? (string) $row['last_pushed_at'] : null,
        ];
    }
    return $out;
}

function count_sites_with_emails(string $scope = 'team'): int
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    return (int) db()->query(
        "SELECT COUNT(*) FROM {$table} WHERE LEFT(domain, 8) <> '__blank_'"
    )->fetchColumn();
}

function count_sites_with_emails_for_country(string $country, string $scope = 'team'): int
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE country=?");
    $stmt->execute([$country]);
    return (int) $stmt->fetchColumn();
}

function count_sites_with_emails_ready_to_push(string $country): int
{
    ensure_sites_with_emails_schema();
    $table = swe_table('team');
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM {$table}
         WHERE country=? AND (email1<>'' OR email2<>'' OR email3<>'' OR email4<>'')"
    );
    $stmt->execute([$country]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array{rows:list<array<string,mixed>>,total:int,pages:int}
 */
function sites_with_emails_inventory_query(
    array $filters,
    int $page = 1,
    int $perPage = 100,
    string $scope = 'team'
): array {
    ensure_sites_with_emails_schema();
    $scope = swe_normalize_scope($scope);
    $table = swe_table($scope);
    $page = max(1, $page);
    $perPage = max(1, min(1000, $perPage));
    $country = trim((string) ($filters['country'] ?? ''));
    $q = trim((string) ($filters['q'] ?? ''));
    $sentFilter = (string) ($filters['sent'] ?? ''); // '', '0', '1' — Admin only
    $rowFilter = (string) ($filters['filter'] ?? ''); // '', 'new', 'updated' — Admin only
    $since = (string) ($filters['since'] ?? ''); // watermark for new/updated filters

    $where = ['country = ?'];
    $params = [$country];
    if ($q !== '') {
        $where[] = '(domain LIKE ? OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($scope === 'admin' && ($sentFilter === '0' || $sentFilter === '1')) {
        $where[] = 'email_sent = ?';
        $params[] = (int) $sentFilter;
    }
    if ($scope === 'admin' && $since !== '' && ($rowFilter === 'new' || $rowFilter === 'updated')) {
        if ($rowFilter === 'new') {
            $where[] = 'created_at > ?';
            $params[] = $since;
        } else {
            $where[] = 'updated_at > ? AND created_at <= ?';
            $params[] = $since;
            $params[] = $since;
        }
    }
    $whereSql = implode(' AND ', $where);

    $count = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    // Admin campaign list: oldest first so new Team pushes land at the bottom (unsent).
    $order = $scope === 'admin' ? 'id ASC' : 'id DESC';

    $stmt = db()->prepare(
        "SELECT * FROM {$table}
         WHERE {$whereSql}
         ORDER BY {$order}
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Expand packed multi-email cells (e.g. all four pasted into email1) into email1–4.
    $rows = expand_packed_email_slots_in_rows($rows, $scope);
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
}

/**
 * @return array{total:int,sent:int,unsent:int}
 */
function count_sites_with_emails_sent_stats(string $country): array
{
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN email_sent=1 THEN 1 ELSE 0 END), 0) AS sent
         FROM sites_with_emails_admin
         WHERE country=?"
    );
    $stmt->execute([$country]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int) ($row['total'] ?? 0);
    $sent = (int) ($row['sent'] ?? 0);
    return [
        'total' => $total,
        'sent' => $sent,
        'unsent' => max(0, $total - $sent),
    ];
}

/**
 * Delete Admin row only — leave All sites with emails - Final untouched.
 */
function delete_sites_with_emails_admin_keep_final(int $siteId): bool
{
    ensure_sites_with_emails_schema();
    $row = get_site_with_emails($siteId, 'admin');
    if (!$row) {
        return false;
    }
    sync_sites_with_emails_admin_row_to_all($row);
    $stmt = db()->prepare('DELETE FROM sites_with_emails_admin WHERE id=?');
    $stmt->execute([$siteId]);
    return $stmt->rowCount() > 0;
}

/**
 * Mark one Admin row emailed → sync to Final, then remove from Admin working list.
 * Final keeps the archive copy. Clearing emailed only applies if the row is still on Admin.
 *
 * @return array{ok:bool,error?:string,domain?:string,email_sent?:bool,row_deleted?:bool,site_count?:int}
 */
function set_site_with_emails_admin_email_sent(int $siteId, bool $sent): array
{
    ensure_sites_with_emails_schema();
    $row = get_site_with_emails($siteId, 'admin');
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found in Sites with emails - Admin.'];
    }
    $domain = (string) $row['domain'];
    $country = (string) $row['country'];
    if ($sent) {
        if (function_exists('sheet_history_push_remove')) {
            sheet_history_push_remove('swe', 'admin:' . $country, [$row], ['scope' => 'admin']);
        }
        if (!delete_sites_with_emails_admin_keep_final($siteId)) {
            return ['ok' => false, 'error' => 'Could not remove emailed site from Admin.'];
        }
        return [
            'ok' => true,
            'domain' => $domain,
            'email_sent' => true,
            'row_deleted' => true,
            'site_count' => count_sites_with_emails_for_country($country, 'admin'),
        ];
    }
    db()->prepare(
        'UPDATE sites_with_emails_admin
         SET email_sent=0, email_sent_at=NULL
         WHERE id=?'
    )->execute([$siteId]);
    if (function_exists('sheet_history_push_emailed')) {
        $beforeSent = (int) ($row['email_sent'] ?? 0) === 1;
        if ($beforeSent) {
            sheet_history_push_emailed(
                'swe',
                'admin:' . $country,
                [[
                    'id' => $siteId,
                    'email_sent' => 1,
                    'email_sent_at' => $row['email_sent_at'] ?? null,
                ]],
                [['id' => $siteId, 'email_sent' => 0, 'email_sent_at' => null]],
                ['scope' => 'admin']
            );
        }
    }
    return [
        'ok' => true,
        'domain' => $domain,
        'email_sent' => false,
        'row_deleted' => false,
        'site_count' => count_sites_with_emails_for_country($country, 'admin'),
    ];
}

/**
 * Checkpoint: mark emailed up to $siteId by syncing each to Final and removing from Admin.
 *
 * @return array{ok:bool,error?:string,marked?:int,domain?:string,country?:string,site_count?:int}
 */
function mark_sites_with_emails_admin_emailed_up_to(int $siteId): array
{
    ensure_sites_with_emails_schema();
    $row = get_site_with_emails($siteId, 'admin');
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found in Sites with emails - Admin.'];
    }
    $country = (string) $row['country'];
    $domain = (string) $row['domain'];
    $sel = db()->prepare(
        'SELECT * FROM sites_with_emails_admin WHERE country=? AND id<=? ORDER BY id ASC'
    );
    $sel->execute([$country, $siteId]);
    $snaps = $sel->fetchAll(PDO::FETCH_ASSOC);
    if ($snaps !== [] && function_exists('sheet_history_push_remove')) {
        sheet_history_push_remove('swe', 'admin:' . $country, $snaps, ['scope' => 'admin']);
    }
    $marked = 0;
    foreach ($snaps as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        sync_sites_with_emails_admin_row_to_all($r);
        $del = db()->prepare('DELETE FROM sites_with_emails_admin WHERE id=?');
        $del->execute([$id]);
        if ($del->rowCount() > 0) {
            $marked++;
        }
    }
    return [
        'ok' => true,
        'marked' => $marked,
        'domain' => $domain,
        'country' => $country,
        'site_count' => count_sites_with_emails_for_country($country, 'admin'),
    ];
}

/**
 * Undo checkpoint: clear emailed marks on every Admin row in this country with id <= $siteId.
 * Lets Admin redo a campaign stretch. Final stays unchanged.
 *
 * @return array{ok:bool,error?:string,cleared?:int,domain?:string,country?:string}
 */
function clear_sites_with_emails_admin_emailed_up_to(int $siteId): array
{
    ensure_sites_with_emails_schema();
    $row = get_site_with_emails($siteId, 'admin');
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found in Sites with emails - Admin.'];
    }
    $country = (string) $row['country'];
    $before = [];
    $stSel = db()->prepare(
        'SELECT id, email_sent, email_sent_at FROM sites_with_emails_admin
         WHERE country=? AND id<=? AND email_sent=1'
    );
    $stSel->execute([$country, $siteId]);
    foreach ($stSel->fetchAll(PDO::FETCH_ASSOC) as $flag) {
        $before[] = [
            'id' => (int) ($flag['id'] ?? 0),
            'email_sent' => 1,
            'email_sent_at' => $flag['email_sent_at'] ?? null,
        ];
    }
    $st = db()->prepare(
        'UPDATE sites_with_emails_admin
         SET email_sent=0, email_sent_at=NULL
         WHERE country=? AND id<=? AND email_sent=1'
    );
    $st->execute([$country, $siteId]);
    $after = [];
    foreach ($before as $flag) {
        $after[] = ['id' => (int) $flag['id'], 'email_sent' => 0, 'email_sent_at' => null];
    }
    if ($before !== [] && function_exists('sheet_history_push_emailed')) {
        sheet_history_push_emailed('swe', 'admin:' . $country, $before, $after, ['scope' => 'admin']);
    }
    return [
        'ok' => true,
        'cleared' => $st->rowCount(),
        'domain' => (string) $row['domain'],
        'country' => $country,
    ];
}

/**
 * Clear every emailed mark for one Admin country sheet so Admin can resend and re-track.
 * Final stays neutral.
 *
 * @return array{ok:bool,error?:string,cleared?:int,country?:string}
 */
function clear_all_sites_with_emails_admin_emailed(string $country): array
{
    ensure_sites_with_emails_schema();
    $canon = resolve_canonical_country(trim($country));
    $countryName = $canon ? $canon['name'] : trim($country);
    if ($countryName === '') {
        return ['ok' => false, 'error' => 'Country is required.'];
    }
    $before = [];
    $stSel = db()->prepare(
        'SELECT id, email_sent, email_sent_at FROM sites_with_emails_admin
         WHERE country=? AND email_sent=1'
    );
    $stSel->execute([$countryName]);
    foreach ($stSel->fetchAll(PDO::FETCH_ASSOC) as $flag) {
        $before[] = [
            'id' => (int) ($flag['id'] ?? 0),
            'email_sent' => 1,
            'email_sent_at' => $flag['email_sent_at'] ?? null,
        ];
    }
    $st = db()->prepare(
        'UPDATE sites_with_emails_admin
         SET email_sent=0, email_sent_at=NULL
         WHERE country=? AND email_sent=1'
    );
    $st->execute([$countryName]);
    $after = [];
    foreach ($before as $flag) {
        $after[] = ['id' => (int) $flag['id'], 'email_sent' => 0, 'email_sent_at' => null];
    }
    if ($before !== [] && function_exists('sheet_history_push_emailed')) {
        sheet_history_push_emailed('swe', 'admin:' . $countryName, $before, $after, ['scope' => 'admin']);
    }
    return [
        'ok' => true,
        'cleared' => $st->rowCount(),
        'country' => $countryName,
    ];
}

/**
 * If a row has several addresses crammed into one cell, split into email1–4 and persist.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function expand_packed_email_slots_in_rows(array $rows, string $scope): array
{
    if ($rows === []) {
        return $rows;
    }
    $scope = swe_normalize_scope($scope);
    // Final is a mirror — heal Admin (then sync), otherwise heal the listed table.
    $writeScope = $scope === 'admin_all' ? 'admin' : $scope;
    $writeTable = swe_table($writeScope);
    $upd = db()->prepare(
        "UPDATE {$writeTable}
         SET email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE country=? AND domain=?"
    );
    $out = [];
    foreach ($rows as $row) {
        $slots = email_slots_from_row($row);
        $cur = [
            (string) ($row['email1'] ?? ''),
            (string) ($row['email2'] ?? ''),
            (string) ($row['email3'] ?? ''),
            (string) ($row['email4'] ?? ''),
        ];
        if ($slots !== $cur) {
            $country = (string) ($row['country'] ?? '');
            $domain = (string) ($row['domain'] ?? '');
            if ($country !== '' && $domain !== '') {
                try {
                    $upd->execute([$slots[0], $slots[1], $slots[2], $slots[3], $country, $domain]);
                    if ($writeScope === 'admin') {
                        sync_sites_with_emails_admin_row_to_all([
                            'domain' => $domain,
                            'country' => $country,
                            'language' => (string) ($row['language'] ?? ''),
                            'region' => (string) ($row['region'] ?? ''),
                            'email1' => $slots[0],
                            'email2' => $slots[1],
                            'email3' => $slots[2],
                            'email4' => $slots[3],
                            'extract_batch_id' => $row['extract_batch_id'] ?? null,
                            'pushed_by' => $row['pushed_by'] ?? null,
                        ]);
                    }
                } catch (Throwable $e) {
                    // still return expanded values for display
                }
            }
            $row['email1'] = $slots[0];
            $row['email2'] = $slots[1];
            $row['email3'] = $slots[2];
            $row['email4'] = $slots[3];
        }
        $out[] = $row;
    }
    return $out;
}

function get_site_with_emails(int $id, string $scope = 'team'): ?array
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_site_with_emails_id(string $country, string $domain, string $scope = 'team'): int
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    $stmt = db()->prepare("SELECT id FROM {$table} WHERE country=? AND domain=? LIMIT 1");
    $stmt->execute([$country, $domain]);
    return (int) $stmt->fetchColumn();
}

/**
 * @param list<int> $ids
 * @return array{ok:bool,error?:string,removed:list<array{id:int,domain:string}>,count:int}
 */
function remove_sites_with_emails_by_ids(string $country, array $ids, string $scope = 'team'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
    $snaps = [];
    $removed = [];
    foreach ($ids as $id) {
        $row = get_site_with_emails($id, $scope);
        if (!$row || (string) ($row['country'] ?? '') !== $country) {
            continue;
        }
        $snaps[] = $row;
        if (delete_site_with_emails($id, $scope)) {
            $removed[] = ['id' => $id, 'domain' => (string) ($row['domain'] ?? '')];
        }
    }
    if ($snaps !== [] && function_exists('sheet_history_push_remove')) {
        sheet_history_push_remove('swe', $scope . ':' . $country, $snaps, ['scope' => swe_normalize_scope($scope)]);
    }
    if ($removed === []) {
        return ['ok' => false, 'error' => 'No matching rows to remove.', 'removed' => [], 'count' => 0];
    }
    return ['ok' => true, 'removed' => $removed, 'count' => count($removed)];
}

/**
 * @param list<array<string,mixed>> $flags
 */
function apply_sites_with_emails_admin_emailed_flags(array $flags): bool
{
    if ($flags === []) {
        return false;
    }
    $n = 0;
    foreach ($flags as $flag) {
        if (!is_array($flag)) {
            continue;
        }
        $id = (int) ($flag['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $sent = (int) ($flag['email_sent'] ?? 0) === 1;
        if ($sent) {
            $at = trim((string) ($flag['email_sent_at'] ?? ''));
            db()->prepare(
                'UPDATE sites_with_emails_admin
                 SET email_sent=1, email_sent_at=COALESCE(NULLIF(?, \'\'), NOW())
                 WHERE id=?'
            )->execute([$at, $id]);
        } else {
            db()->prepare(
                'UPDATE sites_with_emails_admin
                 SET email_sent=0, email_sent_at=NULL
                 WHERE id=?'
            )->execute([$id]);
        }
        $n++;
    }
    return $n > 0;
}

/**
 * @param array<string,mixed> $snap
 * @return array{ok:bool,id?:int,already?:bool,error?:string}
 */
function restore_site_with_emails_snapshot(string $scope, array $snap): array
{
    ensure_sites_with_emails_schema();
    $scope = swe_normalize_scope($scope);
    $table = swe_table($scope);
    $country = (string) ($snap['country'] ?? '');
    $domain = (string) ($snap['domain'] ?? '');
    if ($country === '' || $domain === '') {
        return ['ok' => false, 'error' => 'Invalid site.'];
    }
    $existingId = find_site_with_emails_id($country, $domain, $scope);
    if ($existingId > 0) {
        return ['ok' => true, 'id' => $existingId, 'already' => true];
    }
    $wantId = (int) ($snap['id'] ?? 0);
    $language = (string) ($snap['language'] ?? '');
    $region = (string) ($snap['region'] ?? '');
    $e1 = (string) ($snap['email1'] ?? '');
    $e2 = (string) ($snap['email2'] ?? '');
    $e3 = (string) ($snap['email3'] ?? '');
    $e4 = (string) ($snap['email4'] ?? '');
    $batchId = $snap['extract_batch_id'] ?? null;
    $batchId = $batchId !== null && $batchId !== '' ? (int) $batchId : null;
    $pushedBy = $snap['pushed_by'] ?? null;
    $pushedBy = $pushedBy !== null && $pushedBy !== '' ? (int) $pushedBy : null;
    $created = trim((string) ($snap['created_at'] ?? ''));
    $created = $created !== '' ? $created : null;
    $baseCols = 'domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by, created_at';
    $baseParams = [$domain, $country, $language, $region, $e1, $e2, $e3, $e4, $batchId, $pushedBy, $created];
    $tryInsert = static function (bool $withId, bool $withSent) use (
        $table,
        $wantId,
        $baseCols,
        $baseParams,
        $snap,
        $scope
    ): int {
        $cols = $baseCols;
        $params = $baseParams;
        if ($withSent && $scope === 'admin') {
            $cols .= ', email_sent, email_sent_at';
            $sentAt = trim((string) ($snap['email_sent_at'] ?? ''));
            $params[] = (int) ($snap['email_sent'] ?? 0) === 1 ? 1 : 0;
            $params[] = $sentAt !== '' ? $sentAt : null;
        }
        $placeholders = implode(',', array_fill(0, count($params), '?'));
        if ($withId && $wantId > 0) {
            $chk = db()->prepare("SELECT id FROM {$table} WHERE id=? LIMIT 1");
            $chk->execute([$wantId]);
            if ((int) $chk->fetchColumn() > 0) {
                return 0;
            }
            db()->prepare("INSERT INTO {$table} (id, {$cols}) VALUES (?, {$placeholders})")->execute(array_merge([$wantId], $params));
            return $wantId;
        }
        db()->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})")->execute($params);
        return (int) db()->lastInsertId();
    };
    try {
        $newId = $tryInsert(true, true);
        if ($newId < 1) {
            $newId = $tryInsert(false, true);
        }
        return ['ok' => true, 'id' => $newId];
    } catch (PDOException $e) {
        try {
            $newId = $tryInsert(true, false);
            if ($newId < 1) {
                $newId = $tryInsert(false, false);
            }
            return ['ok' => true, 'id' => $newId];
        } catch (PDOException $e2) {
            $existingId = find_site_with_emails_id($country, $domain, $scope);
            if ($existingId > 0) {
                return ['ok' => true, 'id' => $existingId, 'already' => true];
            }
            return ['ok' => false, 'error' => 'Could not restore site.'];
        }
    }
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function save_site_with_emails_row(
    string $country,
    string $domainRaw,
    array $emails,
    array $user,
    ?int $id = null,
    string $scope = 'team'
): array {
    ensure_sites_with_emails_schema();
    $origScope = swe_normalize_scope($scope);
    // All sites with emails - Final is a mirror: edits write to Admin then sync.
    if ($origScope === 'admin_all') {
        if ($id !== null && $id > 0) {
            $fromAll = get_site_with_emails($id, 'admin_all');
            if (!$fromAll) {
                return ['ok' => false, 'error' => 'Row not found in this country.'];
            }
            $map = db()->prepare(
                'SELECT id FROM sites_with_emails_admin WHERE country=? AND domain=? LIMIT 1'
            );
            $map->execute([(string) $fromAll['country'], (string) $fromAll['domain']]);
            $mappedId = (int) $map->fetchColumn();
            if ($mappedId < 1) {
                // Orphan in All — recreate on Admin from the All row, then continue as update.
                $uid = (int) ($user['id'] ?? 0) ?: null;
                db()->prepare(
                    'INSERT INTO sites_with_emails_admin
                       (domain, country, language, region, email1, email2, email3, email4,
                        extract_batch_id, pushed_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    (string) $fromAll['domain'],
                    (string) $fromAll['country'],
                    (string) ($fromAll['language'] ?? ''),
                    (string) ($fromAll['region'] ?? ''),
                    (string) ($fromAll['email1'] ?? ''),
                    (string) ($fromAll['email2'] ?? ''),
                    (string) ($fromAll['email3'] ?? ''),
                    (string) ($fromAll['email4'] ?? ''),
                    $fromAll['extract_batch_id'] !== null && $fromAll['extract_batch_id'] !== ''
                        ? (int) $fromAll['extract_batch_id'] : null,
                    $uid,
                ]);
                $mappedId = (int) db()->lastInsertId();
            }
            $id = $mappedId;
        }
        $scope = 'admin';
    }
    $table = swe_table($scope);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $host = extract_host_candidate($domainRaw);
    $domain = to_root_domain($host);
    if ($domain === '' || !is_root_domain($domain)) {
        return ['ok' => false, 'error' => 'Enter a valid site name (root domain).'];
    }
    $norm = normalize_email_slots($emails);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => (string) ($norm['error'] ?? 'Invalid email.')];
    }
    /** @var array{0:string,1:string,2:string,3:string} $slots */
    $slots = $norm['slots'] ?? ['', '', '', ''];
    $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
    $scopeNorm = swe_normalize_scope($scope);

    // Admin: clearing the last email removes from Admin working list; Final keeps last copy.
    if (!$hasEmail && $id !== null && $id > 0 && ($scopeNorm === 'admin' || $origScope === 'admin_all')) {
        $existing = get_site_with_emails($id, 'admin');
        if (!$existing || (string) $existing['country'] !== $country) {
            return ['ok' => false, 'error' => 'Row not found in this country.'];
        }
        $delDomain = (string) ($existing['domain'] ?? $domain);
        if (function_exists('sheet_history_push_remove')) {
            sheet_history_push_remove('swe', 'admin:' . $country, [$existing], ['scope' => 'admin']);
        }
        sync_sites_with_emails_admin_row_to_all($existing);
        db()->prepare('DELETE FROM sites_with_emails_admin WHERE id=?')->execute([$id]);
        return [
            'ok' => true,
            'id' => $id,
            'row_deleted' => true,
            'domain' => $delDomain,
        ];
    }

    if ($id !== null && $id > 0) {
        $existing = get_site_with_emails($id, $scope);
        if (!$existing || (string) $existing['country'] !== $country) {
            return ['ok' => false, 'error' => 'Row not found in this country.'];
        }
        $dup = db()->prepare(
            "SELECT id FROM {$table} WHERE country=? AND domain=? AND id<>? LIMIT 1"
        );
        $dup->execute([$country, $domain, $id]);
        if ((int) $dup->fetchColumn() > 0) {
            return ['ok' => false, 'error' => $domain . ' already exists in ' . $country . '.'];
        }
        $oldDomain = (string) ($existing['domain'] ?? '');
        db()->prepare(
            "UPDATE {$table}
             SET domain=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
             WHERE id=?"
        )->execute([$domain, $slots[0], $slots[1], $slots[2], $slots[3], $id]);
        if ($scopeNorm === 'admin') {
            if ($oldDomain !== '' && mb_strtolower($oldDomain) !== mb_strtolower($domain)) {
                delete_sites_with_emails_admin_all_by_domain($country, $oldDomain);
            }
            $fresh = get_site_with_emails($id, 'admin');
            if (is_array($fresh)) {
                sync_sites_with_emails_admin_row_to_all($fresh);
            }
        }
        return ['ok' => true, 'id' => $id, 'row_deleted' => false, 'domain' => $domain];
    }

    if (!$hasEmail && $scopeNorm !== 'team') {
        return ['ok' => false, 'error' => 'Add at least one email — each Admin site must have email data.'];
    }

    $uid = (int) ($user['id'] ?? 0) ?: null;
    try {
        db()->prepare(
            "INSERT INTO {$table}
               (domain, country, language, region, email1, email2, email3, email4, pushed_by)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $domain,
            $country,
            $canon['language'],
            $canon['region'],
            $slots[0],
            $slots[1],
            $slots[2],
            $slots[3],
            $uid,
        ]);
        $newId = (int) db()->lastInsertId();
        if (swe_normalize_scope($scope) === 'admin') {
            sync_sites_with_emails_admin_row_to_all([
                'domain' => $domain,
                'country' => $country,
                'language' => $canon['language'],
                'region' => $canon['region'],
                'email1' => $slots[0],
                'email2' => $slots[1],
                'email3' => $slots[2],
                'email4' => $slots[3],
                'extract_batch_id' => null,
                'pushed_by' => $uid,
            ]);
        }
        return ['ok' => true, 'id' => $newId];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => $domain . ' already exists in ' . $country . '.'];
    }
}

function delete_site_with_emails(int $id, string $scope = 'team'): bool
{
    ensure_sites_with_emails_schema();
    $scope = swe_normalize_scope($scope);
    if ($scope === 'admin_all') {
        // Final-only delete — leave Admin working list alone.
        $stmt = db()->prepare('DELETE FROM sites_with_emails_admin_all WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
    $table = swe_table($scope);
    if ($scope === 'admin') {
        $existing = get_site_with_emails($id, 'admin');
        if ($existing) {
            // Ensure Final has the latest copy, then remove from Admin only.
            sync_sites_with_emails_admin_row_to_all($existing);
        }
        $stmt = db()->prepare("DELETE FROM {$table} WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
    $stmt = db()->prepare("DELETE FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function delete_sites_with_emails_for_country(string $country, string $scope = 'team'): int
{
    ensure_sites_with_emails_schema();
    $scope = swe_normalize_scope($scope);
    if ($scope === 'admin_all') {
        $stmt = db()->prepare('DELETE FROM sites_with_emails_admin_all WHERE country=?');
        $stmt->execute([$country]);
        return $stmt->rowCount();
    }
    $table = swe_table($scope);
    if ($scope === 'admin') {
        // Push current Admin rows to Final first, then clear Admin only (Final keeps archive).
        $sel = db()->prepare('SELECT * FROM sites_with_emails_admin WHERE country=?');
        $sel->execute([$country]);
        while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
            sync_sites_with_emails_admin_row_to_all($row);
        }
    }
    $stmt = db()->prepare("DELETE FROM {$table} WHERE country=?");
    $stmt->execute([$country]);
    return $stmt->rowCount();
}

/**
 * @return array{removed:int,not_found:int,invalid:int}
 */
function remove_sites_with_emails_by_list(string $country, string $raw, string $scope = 'team'): array
{
    ensure_sites_with_emails_schema();
    if (swe_normalize_scope($scope) === 'admin_all') {
        $scope = 'admin';
    }
    $table = swe_table($scope);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $parsed = parse_domain_list_strict($raw);
    $domains = $parsed['valid'];
    if ($domains === []) {
        return ['removed' => 0, 'not_found' => 0, 'invalid' => (int) $parsed['invalid_count']];
    }
    $removed = 0;
    $notFound = 0;
    foreach (array_chunk($domains, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$country], $chunk);
        $sel = db()->prepare(
            "SELECT domain FROM {$table} WHERE country=? AND domain IN ({$ph})"
        );
        $sel->execute($params);
        $found = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $foundSet = array_fill_keys($found, true);
        foreach ($chunk as $d) {
            if (!isset($foundSet[$d])) {
                $notFound++;
            }
        }
        if ($found === []) {
            continue;
        }
        $dph = implode(',', array_fill(0, count($found), '?'));
        if (swe_normalize_scope($scope) === 'admin') {
            // Sync latest Admin data to Final before removing from the working list.
            $syncSel = db()->prepare(
                "SELECT * FROM {$table} WHERE country=? AND domain IN ({$dph})"
            );
            $syncSel->execute(array_merge([$country], $found));
            while ($syncRow = $syncSel->fetch(PDO::FETCH_ASSOC)) {
                sync_sites_with_emails_admin_row_to_all($syncRow);
            }
        }
        $del = db()->prepare(
            "DELETE FROM {$table} WHERE country=? AND domain IN ({$dph})"
        );
        $del->execute(array_merge([$country], $found));
        $removed += $del->rowCount();
        // Admin removes from working list only — Final archive is never deleted here.
    }
    return [
        'removed' => $removed,
        'not_found' => $notFound,
        'invalid' => (int) $parsed['invalid_count'],
    ];
}

/**
 * Collect unique emails for a country sheet.
 * Admin only: $sentFilter '0' = not emailed yet, '1' = already emailed, null/'' = all.
 *
 * @return list<string>
 */
function collect_sites_with_emails_all_emails(
    string $country,
    string $scope = 'team',
    ?string $sentFilter = null
): array {
    ensure_sites_with_emails_schema();
    $scope = swe_normalize_scope($scope);
    $table = swe_table($scope);
    $canon = resolve_canonical_country(trim($country));
    $country = $canon ? $canon['name'] : trim($country);

    $where = ['country = ?'];
    $params = [$country];
    if ($scope === 'admin' && ($sentFilter === '0' || $sentFilter === '1')) {
        $where[] = 'email_sent = ?';
        $params[] = (int) $sentFilter;
    }
    $order = $scope === 'admin' ? 'id ASC' : 'id DESC';
    $stmt = db()->prepare(
        "SELECT email1, email2, email3, email4
         FROM {$table}
         WHERE " . implode(' AND ', $where) . "
         ORDER BY {$order}"
    );
    $stmt->execute($params);
    $out = [];
    $seen = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Split packed cells + skip invalid tokens so one bad address never blocks Copy.
        $slots = email_slots_from_row($row);
        foreach ($slots as $e) {
            $e = trim((string) $e);
            if ($e === '') {
                continue;
            }
            $key = mb_strtolower($e);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $e;
        }
    }
    return $out;
}

function stream_sites_with_emails_csv(string $country, string $scope = 'team'): void
{
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $table = swe_table($scope);
    $canon = resolve_canonical_country($country);
    $country = $canon ? $canon['name'] : trim($country);
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $country) ?: 'sites';
    $normScope = swe_normalize_scope($scope);
    $suffix = match ($normScope) {
        'admin' => 'admin',
        'admin_all' => 'admin-all',
        default => 'team',
    };

    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="' . $safe . '-sites-with-emails-' . $suffix . '.csv"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Site name', 'Email 1', 'Email 2', 'Email 3', 'Email 4']);

    $stmt = db()->prepare(
        "SELECT domain, email1, email2, email3, email4
         FROM {$table} WHERE country=? ORDER BY id DESC"
    );
    $stmt->execute([$country]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            (string) $row['domain'],
            (string) $row['email1'],
            (string) $row['email2'],
            (string) $row['email3'],
            (string) $row['email4'],
        ]);
    }
    fclose($out);
    exit;
}

function stream_sites_with_emails_emails_plain(
    string $country,
    string $scope = 'team',
    ?string $sentFilter = null
): void {
    ensure_sites_with_emails_schema();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $scope = swe_normalize_scope($scope);
    if ($scope !== 'admin' || ($sentFilter !== '0' && $sentFilter !== '1')) {
        $sentFilter = null;
    }
    foreach (collect_sites_with_emails_all_emails($country, $scope, $sentFilter) as $email) {
        echo $email, "\n";
    }
    exit;
}

/**
 * Map one Admin row into a super-search suggestion.
 *
 * @param array<string,mixed> $row
 * @return array{id:int,domain:string,country:string,emails:list<string>,match_type:string,matched_value:string,label:string}
 */
function swe_admin_suggestion_from_row(array $row, string $q): array
{
    $domain = (string) ($row['domain'] ?? '');
    $country = (string) ($row['country'] ?? '');
    $emails = [];
    foreach (['email1', 'email2', 'email3', 'email4'] as $k) {
        $e = trim((string) ($row[$k] ?? ''));
        if ($e !== '') {
            $emails[] = $e;
        }
    }
    $matchType = 'domain';
    $matched = $domain;
    $domainLower = mb_strtolower($domain);
    if (!str_contains($domainLower, $q)) {
        foreach ($emails as $e) {
            if (str_contains(mb_strtolower($e), $q)) {
                $matchType = 'email';
                $matched = $e;
                break;
            }
        }
    }
    $emailPreview = $emails !== [] ? implode(', ', $emails) : '(no emails)';
    return [
        'id' => (int) ($row['id'] ?? 0),
        'domain' => $domain,
        'country' => $country,
        'emails' => $emails,
        'match_type' => $matchType,
        'matched_value' => $matched,
        'label' => $domain . ' · ' . $emailPreview . ' · ' . $country,
    ];
}

/**
 * Live suggestions from Sites with emails - Admin (site name or email).
 *
 * Prefix match on domain first (can use INDEX(domain)); fill leftovers with contains.
 *
 * @return list<array{
 *   id:int,domain:string,country:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_sites_with_emails_admin_suggestions(string $q, int $limit = 20): array
{
    ensure_sites_with_emails_schema();
    $q = trim(mb_strtolower($q));
    if ($q === '' || mb_strlen($q) < 2) {
        return [];
    }
    $limit = max(1, min(40, $limit));
    $pdo = db();
    $prefix = $q . '%';
    $contains = '%' . $q . '%';
    $emailQ = str_contains($q, '@');
    $out = [];
    $seen = [];

    $take = static function (PDOStatement $stmt) use (&$out, &$seen, $q, $limit): void {
        while (count($out) < $limit && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = swe_admin_suggestion_from_row($row, $q);
        }
    };

    $select = 'SELECT id, domain, country, email1, email2, email3, email4
         FROM sites_with_emails_admin
         WHERE domain NOT LIKE \'__blank_%\'';

    if (!$emailQ) {
        // Indexed prefix on domain — typing a site name must stay fast on large sheets.
        $stmt = $pdo->prepare(
            $select . '
           AND domain LIKE ?
         ORDER BY
           CASE WHEN LOWER(domain) = ? THEN 0 ELSE 1 END,
           country ASC, domain ASC
         LIMIT ' . (int) $limit
        );
        $stmt->execute([$prefix, $q]);
        $take($stmt);
    }

    if (count($out) < $limit) {
        $remain = $limit - count($out);
        $notIn = '';
        $params = [$contains, $contains, $contains, $contains];
        if ($seen !== []) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $notIn = ' AND id NOT IN (' . $placeholders . ')';
            foreach (array_keys($seen) as $id) {
                $params[] = $id;
            }
        }
        if ($emailQ) {
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $stmt = $pdo->prepare(
                $select . '
           AND (email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?)
           ' . $notIn . '
         ORDER BY
           CASE
             WHEN LOWER(email1) = ? OR LOWER(email2) = ? OR LOWER(email3) = ? OR LOWER(email4) = ? THEN 0
             ELSE 1
           END,
           country ASC, domain ASC
         LIMIT ' . (int) $remain
            );
            $stmt->execute($params);
            $take($stmt);
        } else {
            $stmt = $pdo->prepare(
                $select . '
           AND (
             email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?
           )
           ' . $notIn . '
         ORDER BY country ASC, domain ASC
         LIMIT ' . (int) $remain
            );
            $stmt->execute($params);
            $take($stmt);
        }
    }

    if (!$emailQ && count($out) < $limit) {
        $remain = $limit - count($out);
        $params = [$contains, $prefix];
        $notIn = '';
        if ($seen !== []) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $notIn = ' AND id NOT IN (' . $placeholders . ')';
            foreach (array_keys($seen) as $id) {
                $params[] = $id;
            }
        }
        // Domain contains (not prefix) — last resort, limited.
        $stmt = $pdo->prepare(
            $select . '
           AND domain LIKE ?
           AND domain NOT LIKE ?
           ' . $notIn . '
         ORDER BY country ASC, domain ASC
         LIMIT ' . (int) $remain
        );
        $stmt->execute($params);
        $take($stmt);
    }

    return $out;
}

/**
 * Communication Team / Email Extracting: super search UI for Sites with emails - Admin.
 */
function render_sites_with_emails_admin_super_search(string $postBase = 'index.php?page=team_admin_emails_delete'): void
{
    ensure_sites_with_emails_schema();
    $total = cached_scalar_count('swe_admin_super_total', static function () {
        return (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin WHERE LEFT(domain, 8) <> '__blank_'"
        )->fetchColumn();
    });
    $countries = cached_scalar_count('swe_admin_super_countries', static function () {
        return (int) db()->query(
            "SELECT COUNT(DISTINCT country) FROM sites_with_emails_admin WHERE TRIM(country) <> ''"
        )->fetchColumn();
    });
    $uid = 'swe-admin-super-' . substr(md5($postBase), 0, 6);
    ?>
  <div class="card camp-search-card swe-admin-delete-card" style="margin-bottom:1rem"
       data-swe-admin-delete
       data-suggest-url="<?= h($postBase) ?>&amp;ajax=suggest"
       data-post-url="<?= h($postBase) ?>">
    <?= csrf_field() ?>
    <noscript><p class="help muted">JavaScript is required to search and update these results.</p></noscript>
    <h2 style="margin-top:0"><?= label_with_info('Admin emails search', 'Type a site or email across all countries in Sites with emails - Admin. Choose delete both or remove only email, then Enter + confirm. If you remove the last email on a site, the Admin working-list row is deleted. Final keeps its archive copy.') ?></h2>
    <p class="help muted" style="margin-top:0">
      <?= (int) $countries ?> countr<?= (int) $countries === 1 ? 'y' : 'ies' ?> ·
      <?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?> ·
      search site or email across all countries · updates that country’s Admin row
    </p>
    <label class="swe-admin-delete-label" for="<?= h($uid) ?>"><?= label_with_info('Search site name or email', 'Live suggestions from Sites with emails - Admin. Results always show site + email + country together.') ?></label>
    <div class="swe-admin-delete-search">
      <input id="<?= h($uid) ?>" type="search" class="swe-admin-delete-input" data-swe-q
             placeholder="Type site or email (all countries)…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to search all countries · Arrow keys · Enter to select / confirm">
      <ul class="swe-admin-delete-suggest" data-swe-suggest hidden></ul>
    </div>
    <p class="help camp-status" data-swe-status hidden></p>
    <div class="swe-admin-delete-selected" data-swe-selected hidden>
      <h3 style="margin-top:1rem">Selected</h3>
      <p class="help">Site name + emails + country stay together. Pick an action, then press Enter (confirm first).</p>
      <div class="swe-admin-delete-panel">
        <div>
          <div class="muted" style="font-size:0.82rem">Site name</div>
          <div class="swe-admin-delete-domain" data-swe-sel-domain></div>
          <div class="muted" data-swe-sel-country style="margin-top:0.25rem"></div>
        </div>
        <div>
          <div class="muted" style="font-size:0.82rem;margin-bottom:0.35rem">Emails</div>
          <ul class="swe-admin-delete-emails" data-swe-sel-emails></ul>
          <p class="help" data-swe-no-emails hidden>No emails on this site.</p>
        </div>
      </div>
      <fieldset class="camp-action-fieldset">
        <legend class="visually-hidden">Update action</legend>
        <label class="camp-action-choice">
          <input type="radio" name="swe_action_<?= h($uid) ?>" value="row" data-swe-mode checked>
          Delete both (site name + all emails)
        </label>
        <label class="camp-action-choice">
          <input type="radio" name="swe_action_<?= h($uid) ?>" value="email" data-swe-mode>
          Remove only email
        </label>
        <div class="camp-email-pick" data-swe-email-pick hidden>
          <label class="muted" style="font-size:0.82rem" for="swe-email-select-<?= h($uid) ?>">Which email</label>
          <select id="swe-email-select-<?= h($uid) ?>" data-swe-email-select></select>
        </div>
      </fieldset>
      <div class="actions" style="margin-top:0.85rem;flex-wrap:wrap;gap:0.5rem">
        <button type="button" class="btn danger" data-swe-apply>Update (Enter)</button>
        <button type="button" class="btn secondary" data-swe-clear>Clear selection</button>
      </div>
    </div>
  </div>
    <?php
    echo '<script src="' . h(script_asset_url('js/admin-emails-delete.js')) . '" defer></script>';
}

/**
 * Remove one email slot from an Admin row; keep the site name when others remain.
 * If this was the last email, delete the Admin working row (Final archive keeps the last copy).
 *
 * @return array{ok:bool,error?:string,domain?:string,emails?:list<string>,removed?:string,row_deleted?:bool}
 */
function remove_email_from_sites_with_emails_admin(int $siteId, string $email): array
{
    ensure_sites_with_emails_schema();
    $row = get_site_with_emails($siteId, 'admin');
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found in Sites with emails - Admin.'];
    }
    $target = normalize_email_value($email);
    if ($target === '') {
        $target = strtolower(trim($email));
    }
    if ($target === '') {
        return ['ok' => false, 'error' => 'Email is empty.'];
    }

    $slots = [];
    $found = false;
    foreach (['email1', 'email2', 'email3', 'email4'] as $k) {
        $e = strtolower(trim((string) ($row[$k] ?? '')));
        if ($e === '') {
            continue;
        }
        if ($e === $target) {
            $found = true;
            continue;
        }
        $slots[] = $e;
    }
    if (!$found) {
        return ['ok' => false, 'error' => 'That email is not on this site.'];
    }

    $domain = (string) $row['domain'];
    // Last email gone → remove from Admin working list; Final keeps the archive copy.
    if ($slots === []) {
        delete_site_with_emails($siteId, 'admin');
        return [
            'ok' => true,
            'domain' => $domain,
            'emails' => [],
            'removed' => $target,
            'row_deleted' => true,
        ];
    }

    while (count($slots) < 4) {
        $slots[] = '';
    }
    db()->prepare(
        'UPDATE sites_with_emails_admin
         SET email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE id=?'
    )->execute([$slots[0], $slots[1], $slots[2], $slots[3], $siteId]);

    sync_sites_with_emails_admin_row_to_all([
        'domain' => $domain,
        'country' => (string) $row['country'],
        'language' => (string) ($row['language'] ?? ''),
        'region' => (string) ($row['region'] ?? ''),
        'email1' => $slots[0],
        'email2' => $slots[1],
        'email3' => $slots[2],
        'email4' => $slots[3],
        'extract_batch_id' => $row['extract_batch_id'] ?? null,
        'pushed_by' => $row['pushed_by'] ?? null,
    ]);

    $left = array_values(array_filter($slots, static fn ($e) => $e !== ''));
    return [
        'ok' => true,
        'domain' => $domain,
        'emails' => $left,
        'removed' => $target,
        'row_deleted' => false,
    ];
}

/**
 * Delete complete Admin row (site + all emails).
 *
 * @return array{ok:bool,error?:string,domain?:string}
 */
function delete_sites_with_emails_admin_row(int $siteId): array
{
    ensure_sites_with_emails_schema();
    $row = get_site_with_emails($siteId, 'admin');
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found in Sites with emails - Admin.'];
    }
    delete_site_with_emails($siteId, 'admin');
    return ['ok' => true, 'domain' => (string) $row['domain']];
}

/**
 * Per-admin last-seen watermark for one Admin country folder.
 */
function swe_admin_country_last_seen(int $userId, string $country): ?string
{
    ensure_sites_with_emails_schema();
    $userId = (int) $userId;
    $country = trim($country);
    if ($userId < 1 || $country === '') {
        return null;
    }
    try {
        $stmt = db()->prepare(
            'SELECT last_seen_at FROM swe_admin_country_seen WHERE user_id=? AND country=? LIMIT 1'
        );
        $stmt->execute([$userId, $country]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return null;
        }
        return (string) $v;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Watermark for “new since last visit” on an Admin country.
 * Prefers country seen → section emails_admin seen → signal last_new_at (slightly earlier so that push still counts).
 */
function swe_admin_unseen_since(int $userId, string $country): ?string
{
    $userId = (int) $userId;
    $country = trim($country);
    if ($userId < 1 || $country === '') {
        return null;
    }
    $countrySeen = swe_admin_country_last_seen($userId, $country);
    if ($countrySeen !== null) {
        return $countrySeen;
    }
    try {
        if (function_exists('ensure_admin_new_data_schema')) {
            ensure_admin_new_data_schema();
        }
        $seen = db()->prepare(
            'SELECT last_seen_at FROM admin_data_seen WHERE user_id=? AND section=? LIMIT 1'
        );
        $seen->execute([$userId, 'emails_admin']);
        $sectionSeen = $seen->fetchColumn();
        if ($sectionSeen !== false && $sectionSeen !== null && $sectionSeen !== '') {
            return (string) $sectionSeen;
        }
        $sig = db()->prepare('SELECT last_new_at FROM admin_data_signals WHERE section=? LIMIT 1');
        $sig->execute(['emails_admin']);
        $lastNew = $sig->fetchColumn();
        if ($lastNew === false || $lastNew === null || $lastNew === '') {
            return null;
        }
        // Push stamps rows then mark_admin_new_data(NOW()) — nudge back so that push still counts.
        $ts = strtotime((string) $lastNew);
        if ($ts === false) {
            return (string) $lastNew;
        }
        return date('Y-m-d H:i:s', $ts - 2);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Count Admin rows in a country created after this admin’s watermark.
 */
function swe_admin_new_count_for_country(?array $user, string $country): int
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return 0;
    }
    $uid = (int) ($user['id'] ?? 0);
    $country = trim($country);
    if ($uid < 1 || $country === '') {
        return 0;
    }
    $since = swe_admin_unseen_since($uid, $country);
    if ($since === null) {
        return 0;
    }
    try {
        ensure_sites_with_emails_schema();
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE country=? AND created_at > ?'
        );
        $stmt->execute([$country, $since]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * New-site counts keyed by country (only countries with count > 0).
 *
 * @return array<string,int>
 */
function swe_admin_new_counts_by_country(?array $user = null): array
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return [];
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) {
        return [];
    }
    try {
        ensure_sites_with_emails_schema();
        $countries = db()->query(
            "SELECT DISTINCT TRIM(country) AS country
             FROM sites_with_emails_admin
             WHERE TRIM(country) <> ''"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out = [];
        foreach ($countries as $c) {
            $name = (string) $c;
            $canon = resolve_canonical_country($name);
            $label = $canon ? $canon['name'] : $name;
            $n = swe_admin_new_count_for_country($user, $label);
            if ($n > 0) {
                $out[$label] = $n;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Admin opened a country sheet — set country watermark; clear section New when nothing left unseen.
 */
function swe_admin_mark_country_seen(?array $user, string $country): void
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return;
    }
    $uid = (int) ($user['id'] ?? 0);
    $country = trim($country);
    if ($uid < 1 || $country === '') {
        return;
    }
    try {
        ensure_sites_with_emails_schema();
        db()->prepare(
            'INSERT INTO swe_admin_country_seen (user_id, country, last_seen_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
        )->execute([$uid, $country]);
        $left = swe_admin_new_counts_by_country($user);
        if ($left === [] && function_exists('clear_admin_new_data')) {
            clear_admin_new_data('emails_admin', $user);
        }
    } catch (Throwable $e) {
        // never break page load
    }
}

/**
 * Mark every Admin country folder as seen for this admin (and clear section New).
 */
function swe_admin_mark_all_countries_seen(?array $user = null): void
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return;
    }
    try {
        foreach (list_sites_with_emails_country_rows('admin') as $row) {
            swe_admin_mark_country_seen($user, (string) ($row['country'] ?? ''));
        }
        if (function_exists('clear_admin_new_data')) {
            clear_admin_new_data('emails_admin', $user);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Visit-scoped watermark for Admin country sheet (chips / filter / flash).
 * Survives in-session filter navigation after DB mark-seen on open.
 */
function swe_admin_visit_since(?array $user, string $country, bool $startVisit = false): ?string
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return null;
    }
    $uid = (int) ($user['id'] ?? 0);
    $country = trim($country);
    if ($uid < 1 || $country === '') {
        return null;
    }
    if (!isset($_SESSION['swe_admin_visit_since']) || !is_array($_SESSION['swe_admin_visit_since'])) {
        $_SESSION['swe_admin_visit_since'] = [];
    }
    if (!isset($_SESSION['swe_admin_visit_since'][$uid]) || !is_array($_SESSION['swe_admin_visit_since'][$uid])) {
        $_SESSION['swe_admin_visit_since'][$uid] = [];
    }
    if ($startVisit || !array_key_exists($country, $_SESSION['swe_admin_visit_since'][$uid])) {
        $since = swe_admin_unseen_since($uid, $country);
        // Empty string sentinel so array_key_exists stays true when there is no watermark.
        $_SESSION['swe_admin_visit_since'][$uid][$country] = $since ?? '';
    }
    $v = $_SESSION['swe_admin_visit_since'][$uid][$country] ?? '';
    return ($v !== null && $v !== '') ? (string) $v : null;
}

function swe_admin_clear_visit_since(?array $user, ?string $country = null): void
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return;
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1 || !isset($_SESSION['swe_admin_visit_since'][$uid])) {
        return;
    }
    if ($country === null || trim($country) === '') {
        unset($_SESSION['swe_admin_visit_since'][$uid]);
        return;
    }
    unset($_SESSION['swe_admin_visit_since'][$uid][trim($country)]);
}

/**
 * Row signal vs watermark: new (created after), updated (re-merged after), or ''.
 *
 * @param array<string,mixed> $row
 */
function swe_admin_row_signal(array $row, ?string $since): string
{
    if ($since === null || $since === '') {
        return '';
    }
    $sinceTs = strtotime($since);
    if ($sinceTs === false) {
        return '';
    }
    $createdTs = strtotime((string) ($row['created_at'] ?? ''));
    $updatedTs = strtotime((string) ($row['updated_at'] ?? ''));
    if ($createdTs !== false && $createdTs > $sinceTs) {
        return 'new';
    }
    if ($updatedTs !== false && $updatedTs > $sinceTs
        && ($createdTs === false || $createdTs <= $sinceTs)) {
        return 'updated';
    }
    return '';
}

/**
 * Count Admin rows created after a fixed watermark (visit-scoped).
 */
function swe_admin_count_new_since(string $country, ?string $since): int
{
    $country = trim($country);
    if ($country === '' || $since === null || $since === '') {
        return 0;
    }
    try {
        ensure_sites_with_emails_schema();
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE country=? AND created_at > ?'
        );
        $stmt->execute([$country, $since]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Count Admin rows updated (but not newly created) after a fixed watermark.
 */
function swe_admin_count_updated_since(string $country, ?string $since): int
{
    $country = trim($country);
    if ($country === '' || $since === null || $since === '') {
        return 0;
    }
    try {
        ensure_sites_with_emails_schema();
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE country=? AND updated_at > ? AND created_at <= ?'
        );
        $stmt->execute([$country, $since, $since]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Count Admin rows updated (but not newly created) after watermark.
 */
function swe_admin_updated_count_for_country(?array $user, string $country): int
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return 0;
    }
    $uid = (int) ($user['id'] ?? 0);
    $country = trim($country);
    if ($uid < 1 || $country === '') {
        return 0;
    }
    return swe_admin_count_updated_since($country, swe_admin_unseen_since($uid, $country));
}
