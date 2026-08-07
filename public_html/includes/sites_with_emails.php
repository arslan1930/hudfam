<?php
/**
 * Sites with emails — two stores:
 *   team  → working copy from Extracting Results Push; team adds emails, then Push to admin
 *   admin → final archive; data stays here (no further push)
 */

function swe_normalize_scope(string $scope): string
{
    return $scope === 'admin' ? 'admin' : 'team';
}

function swe_table(string $scope): string
{
    return swe_normalize_scope($scope) === 'admin'
        ? 'sites_with_emails_admin'
        : 'sites_with_emails_team';
}

function swe_label(string $scope): string
{
    return swe_normalize_scope($scope) === 'admin'
        ? 'Sites with emails - Admin'
        : 'Sites with emails - Team';
}

function swe_create_table_sql(string $table): string
{
    $fk = $table === 'sites_with_emails_admin' ? 'fk_swe_admin_pushed_by' : 'fk_swe_team_pushed_by';
    $uniq = $table === 'sites_with_emails_admin' ? 'uniq_swe_admin_country_domain' : 'uniq_swe_team_country_domain';
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
}

function normalize_email_value(string $email): string
{
    $email = strtolower(trim($email));
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
 * Compact up to 4 unique valid emails. Invalid non-empty values are rejected.
 *
 * @param array{0?:string,1?:string,2?:string,3?:string}|list<string> $emails
 * @return array{ok:bool,slots?:array{0:string,1:string,2:string,3:string},error?:string}
 */
function normalize_email_slots(array $emails): array
{
    $out = ['', '', '', ''];
    $i = 0;
    $seen = [];
    foreach ($emails as $e) {
        if ($i >= 4) {
            break;
        }
        $raw = trim((string) $e);
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
 * Team → Admin: copy rows that have at least one email into the admin archive.
 *
 * @return array{pushed:int,updated:int,skipped_empty:int,country:string}
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
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $hasEmail = trim((string) ($row['email1'] ?? '')) !== ''
            || trim((string) ($row['email2'] ?? '')) !== ''
            || trim((string) ($row['email3'] ?? '')) !== ''
            || trim((string) ($row['email4'] ?? '')) !== '';
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
            (string) ($row['email1'] ?? ''),
            (string) ($row['email2'] ?? ''),
            (string) ($row['email3'] ?? ''),
            (string) ($row['email4'] ?? ''),
            $row['extract_batch_id'] !== null ? (int) $row['extract_batch_id'] : null,
            $uid,
        ]);
        if ($already) {
            $updated++;
        } else {
            $pushed++;
        }
    }

    return [
        'pushed' => $pushed,
        'updated' => $updated,
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
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
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
        db()->prepare(
            "UPDATE {$table}
             SET domain=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
             WHERE id=?"
        )->execute([$domain, $slots[0], $slots[1], $slots[2], $slots[3], $id]);
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
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => $domain . ' already exists in ' . $country . '.'];
    }
}

function delete_site_with_emails(int $id, string $scope = 'team'): bool
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
    $stmt = db()->prepare("DELETE FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function delete_sites_with_emails_for_country(string $country, string $scope = 'team'): int
{
    ensure_sites_with_emails_schema();
    $table = swe_table($scope);
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
    $suffix = swe_normalize_scope($scope) === 'admin' ? 'admin' : 'team';

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
            OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?
         ORDER BY
           CASE
             WHEN domain = ? THEN 0
             WHEN domain LIKE ? THEN 1
             WHEN email1 = ? OR email2 = ? OR email3 = ? OR email4 = ? THEN 2
             ELSE 3
           END,
           domain ASC
         LIMIT {$limit}"
    );
    $stmt->execute([
        $like, $like, $like, $like, $like,
        $q,
        $q . '%',
        $q, $q, $q, $q,
    ]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $domain = (string) $row['domain'];
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
        $out[] = [
            'id' => (int) $row['id'],
            'domain' => $domain,
            'country' => (string) $row['country'],
            'emails' => $emails,
            'match_type' => $matchType,
            'matched_value' => $matched,
            'label' => $domain . ' · ' . $emailPreview . ' · ' . (string) $row['country'],
        ];
    }
    return $out;
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
