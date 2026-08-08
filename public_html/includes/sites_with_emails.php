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
        'admin' => 'Sites with emails - Admin',
        'admin_all' => 'All sites with emails - Final',
        default => 'Sites with emails - Team',
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

    // Legacy single table → migrate into Team working copy once.
    try {
        $legacy = $pdo->query("SHOW TABLES LIKE 'sites_with_emails'")->fetchColumn();
        if ($legacy) {
            $countTeam = (int) $pdo->query('SELECT COUNT(*) FROM sites_with_emails_team')->fetchColumn();
            $countLegacy = (int) $pdo->query('SELECT COUNT(*) FROM sites_with_emails')->fetchColumn();
            if ($countLegacy > 0 && $countTeam === 0) {
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
        $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM sites_with_emails_admin')->fetchColumn();
        $allCount = (int) $pdo->query('SELECT COUNT(*) FROM sites_with_emails_admin_all')->fetchColumn();
        if ($adminCount > 0 && $allCount === 0) {
            sync_sites_with_emails_admin_to_all();
        }
    } catch (Throwable $e) {
        // ignore
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
 * Also removes All rows that no longer exist in Admin (same country+domain).
 *
 * @return array{upserted:int,removed:int}
 */
function sync_sites_with_emails_admin_to_all(?string $country = null): array
{
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $pdo = db();
    $upserted = 0;
    if ($country !== null && trim($country) !== '') {
        $sel = $pdo->prepare('SELECT * FROM sites_with_emails_admin WHERE country=?');
        $sel->execute([trim($country)]);
    } else {
        $sel = $pdo->query('SELECT * FROM sites_with_emails_admin');
    }
    $keep = [];
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        sync_sites_with_emails_admin_row_to_all($row);
        $keep[mb_strtolower((string) $row['country']) . "\0" . mb_strtolower((string) $row['domain'])] = true;
        $upserted++;
    }

    $removed = 0;
    if ($country !== null && trim($country) !== '') {
        $all = $pdo->prepare('SELECT id, domain, country FROM sites_with_emails_admin_all WHERE country=?');
        $all->execute([trim($country)]);
    } else {
        $all = $pdo->query('SELECT id, domain, country FROM sites_with_emails_admin_all');
    }
    $del = $pdo->prepare('DELETE FROM sites_with_emails_admin_all WHERE id=?');
    while ($row = $all->fetch(PDO::FETCH_ASSOC)) {
        $key = mb_strtolower((string) $row['country']) . "\0" . mb_strtolower((string) $row['domain']);
        if (!isset($keep[$key])) {
            $del->execute([(int) $row['id']]);
            $removed++;
        }
    }
    return ['upserted' => $upserted, 'removed' => $removed];
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
 * Invalid non-empty tokens are rejected.
 *
 * @param array{0?:string,1?:string,2?:string,3?:string}|list<string> $emails
 * @return array{ok:bool,slots?:array{0:string,1:string,2:string,3:string},error?:string}
 */
function normalize_email_slots(array $emails): array
{
    $out = ['', '', '', ''];
    $i = 0;
    $seen = [];
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
            return ['ok' => false, 'error' => 'Invalid email: ' . $raw];
        }
        if (isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $out[$i] = $n;
        $i++;
    }
    return ['ok' => true, 'slots' => $out];
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
 * Team → Admin: push one site row (must have at least one email), then remove it from Team.
 *
 * @return array{ok:bool,error?:string,pushed?:int,updated?:int,cleared?:int,domain?:string,country?:string,site_count?:int}
 */
function push_one_site_with_emails_team_to_admin(int $siteId, array $user): array
{
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

    $slots = email_slots_from_row($row);
    $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
    if (!$hasEmail) {
        return ['ok' => false, 'error' => 'Add at least one email before pushing this site.'];
    }

    $exists = db()->prepare("SELECT id FROM {$admin} WHERE country=? AND domain=? LIMIT 1");
    $exists->execute([$country, $domain]);
    $already = (int) $exists->fetchColumn() > 0;

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
           updated_at = NOW()"
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
        'domain' => $domain,
        'country' => $country,
        'site_count' => count_sites_with_emails_for_country($country, 'team'),
    ];
}

/**
 * Team → Admin: copy rows that have at least one email into the admin archive,
 * then remove those rows from the Team working copy (sites without emails stay).
 *
 * @return array{pushed:int,updated:int,cleared:int,skipped_empty:int,country:string}
 */
function push_sites_with_emails_team_to_admin(string $country, array $user): array
{
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $team = swe_table('team');
    $admin = swe_table('admin');
    $uid = (int) ($user['id'] ?? 0) ?: null;

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
           updated_at = NOW()"
    );
    $exists = db()->prepare(
        "SELECT id FROM {$admin} WHERE country=? AND domain=? LIMIT 1"
    );

    $pushed = 0;
    $updated = 0;
    $skippedEmpty = 0;
    $pushedDomains = [];
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $slots = email_slots_from_row($row);
        $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
        if (!$hasEmail) {
            $skippedEmpty++;
            continue;
        }
        $domain = (string) $row['domain'];
        $exists->execute([$country, $domain]);
        $already = (int) $exists->fetchColumn() > 0;
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
        'pushed' => $pushed,
        'updated' => $updated,
        'cleared' => $cleared,
        'skipped_empty' => $skippedEmpty,
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
    return (int) db()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
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
    $table = swe_table($scope);
    $page = max(1, $page);
    $perPage = max(1, min(500, $perPage));
    $country = trim((string) ($filters['country'] ?? ''));
    $q = trim((string) ($filters['q'] ?? ''));

    $where = ['country = ?'];
    $params = [$country];
    if ($q !== '') {
        $where[] = '(domain LIKE ? OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
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

    $stmt = db()->prepare(
        "SELECT * FROM {$table}
         WHERE {$whereSql}
         ORDER BY id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Expand packed multi-email cells (e.g. all four pasted into email1) into email1–4.
    $rows = expand_packed_email_slots_in_rows($rows, $scope);
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
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
        if (swe_normalize_scope($scope) === 'admin') {
            if ($oldDomain !== '' && mb_strtolower($oldDomain) !== mb_strtolower($domain)) {
                delete_sites_with_emails_admin_all_by_domain($country, $oldDomain);
            }
            $fresh = get_site_with_emails($id, 'admin');
            if (is_array($fresh)) {
                sync_sites_with_emails_admin_row_to_all($fresh);
            }
        }
        return ['ok' => true, 'id' => $id];
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
        // Resolve by domain/country from All, delete canonical Admin row (syncs All).
        $row = get_site_with_emails($id, 'admin_all');
        if (!$row) {
            return false;
        }
        $admin = db()->prepare(
            'SELECT id FROM sites_with_emails_admin WHERE country=? AND domain=? LIMIT 1'
        );
        $admin->execute([(string) $row['country'], (string) $row['domain']]);
        $adminId = (int) $admin->fetchColumn();
        if ($adminId < 1) {
            delete_sites_with_emails_admin_all_by_domain((string) $row['country'], (string) $row['domain']);
            return true;
        }
        return delete_site_with_emails($adminId, 'admin');
    }
    $table = swe_table($scope);
    $mirrorCountry = null;
    $mirrorDomain = null;
    if ($scope === 'admin') {
        $existing = get_site_with_emails($id, 'admin');
        if ($existing) {
            $mirrorCountry = (string) $existing['country'];
            $mirrorDomain = (string) $existing['domain'];
        }
    }
    $stmt = db()->prepare("DELETE FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    $ok = $stmt->rowCount() > 0;
    if ($ok && $mirrorCountry !== null && $mirrorDomain !== null) {
        delete_sites_with_emails_admin_all_by_domain($mirrorCountry, $mirrorDomain);
    }
    return $ok;
}

function delete_sites_with_emails_for_country(string $country, string $scope = 'team'): int
{
    ensure_sites_with_emails_schema();
    $scope = swe_normalize_scope($scope);
    if ($scope === 'admin_all') {
        $scope = 'admin';
    }
    $table = swe_table($scope);
    $stmt = db()->prepare("DELETE FROM {$table} WHERE country=?");
    $stmt->execute([$country]);
    $n = $stmt->rowCount();
    if ($scope === 'admin') {
        sync_sites_with_emails_admin_to_all($country);
    }
    return $n;
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
        $del = db()->prepare(
            "DELETE FROM {$table} WHERE country=? AND domain IN ({$dph})"
        );
        $del->execute(array_merge([$country], $found));
        $removed += $del->rowCount();
        if (swe_normalize_scope($scope) === 'admin') {
            foreach ($found as $d) {
                delete_sites_with_emails_admin_all_by_domain($country, (string) $d);
            }
        }
    }
    return [
        'removed' => $removed,
        'not_found' => $notFound,
        'invalid' => (int) $parsed['invalid_count'],
    ];
}

/**
 * @return list<string>
 */
function collect_sites_with_emails_all_emails(string $country, string $scope = 'team'): array
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    $stmt = db()->prepare(
        "SELECT email1, email2, email3, email4
         FROM {$table} WHERE country=? ORDER BY id DESC"
    );
    $stmt->execute([$country]);
    $out = [];
    $seen = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach (['email1', 'email2', 'email3', 'email4'] as $k) {
            $e = trim((string) ($row[$k] ?? ''));
            if ($e === '' || isset($seen[$e])) {
                continue;
            }
            $seen[$e] = true;
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

function stream_sites_with_emails_emails_plain(string $country, string $scope = 'team'): void
{
    ensure_sites_with_emails_schema();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    foreach (collect_sites_with_emails_all_emails($country, $scope) as $email) {
        echo $email, "\n";
    }
    exit;
}

/**
 * Live suggestions from Sites with emails - Admin (site name or email).
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
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        "SELECT id, domain, country, email1, email2, email3, email4
         FROM sites_with_emails_admin
         WHERE domain LIKE ?
            OR country LIKE ?
            OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?
         ORDER BY
           CASE
             WHEN domain = ? THEN 0
             WHEN domain LIKE ? THEN 1
             WHEN email1 = ? OR email2 = ? OR email3 = ? OR email4 = ? THEN 2
             ELSE 3
           END,
           country ASC, domain ASC
         LIMIT {$limit}"
    );
    $stmt->execute([
        $like, $like, $like, $like, $like, $like,
        $q,
        $q . '%',
        $q, $q, $q, $q,
    ]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $domain = (string) $row['domain'];
        $country = (string) $row['country'];
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
            if ($matchType === 'domain' && str_contains(mb_strtolower($country), $q)) {
                $matchType = 'country';
                $matched = $country;
            }
        }
        $emailPreview = $emails !== [] ? implode(', ', $emails) : '(no emails)';
        $out[] = [
            'id' => (int) $row['id'],
            'domain' => $domain,
            'country' => $country,
            'emails' => $emails,
            'match_type' => $matchType,
            'matched_value' => $matched,
            'label' => $domain . ' · ' . $emailPreview . ' · ' . $country,
        ];
    }
    return $out;
}

/**
 * Communication Team / Email Extracting: super search UI for Sites with emails - Admin.
 */
function render_sites_with_emails_admin_super_search(string $postBase = 'index.php?page=team_admin_emails_delete'): void
{
    ensure_sites_with_emails_schema();
    $total = 0;
    $countries = 0;
    try {
        $total = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin WHERE LEFT(domain, 8) <> '__blank_'"
        )->fetchColumn();
        $countries = (int) db()->query(
            "SELECT COUNT(DISTINCT country) FROM sites_with_emails_admin WHERE TRIM(country) <> ''"
        )->fetchColumn();
    } catch (Throwable $e) {
        $total = 0;
        $countries = 0;
    }
    $uid = 'swe-admin-super-' . substr(md5($postBase), 0, 6);
    ?>
  <div class="card camp-search-card swe-admin-delete-card" style="margin-bottom:1rem"
       data-swe-admin-delete
       data-suggest-url="<?= h($postBase) ?>&amp;ajax=suggest"
       data-post-url="<?= h($postBase) ?>">
    <h2 style="margin-top:0"><?= label_with_info('Admin emails search', 'Type a site or email across all countries in Sites with emails - Admin. Choose delete both or remove only email, then Enter + confirm. Updates that country’s Admin row (and Final mirror).') ?></h2>
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
 * Remove one email slot from an Admin row; keep the site name.
 *
 * @return array{ok:bool,error?:string,domain?:string,emails?:list<string>,removed?:string}
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
    while (count($slots) < 4) {
        $slots[] = '';
    }
    db()->prepare(
        'UPDATE sites_with_emails_admin
         SET email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE id=?'
    )->execute([$slots[0], $slots[1], $slots[2], $slots[3], $siteId]);

    sync_sites_with_emails_admin_row_to_all([
        'domain' => (string) $row['domain'],
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
        'domain' => (string) $row['domain'],
        'emails' => $left,
        'removed' => $target,
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
