<?php
/**
 * Sites with emails — duplicate of Extracted Sites names (from Team Push),
 * plus up to 4 manually entered emails per site, by country.
 */

function ensure_sites_with_emails_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS sites_with_emails (
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
          UNIQUE KEY uniq_swe_country_domain (country, domain),
          INDEX (country),
          INDEX (domain),
          INDEX (pushed_by),
          CONSTRAINT fk_swe_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
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
 * Insert site names (no emails yet) from Team Push / Extracting Results.
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
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
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
        'INSERT INTO sites_with_emails
           (domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by)
         VALUES (?,?,?,?,\'\',\'\',\'\',\'\',?,?)
         ON DUPLICATE KEY UPDATE
           updated_at = NOW(),
           language = IF(VALUES(language) <> \'\', VALUES(language), language),
           region = IF(VALUES(region) <> \'\', VALUES(region), region)'
    );
    $find = db()->prepare(
        'SELECT id FROM sites_with_emails WHERE country=? AND domain=? LIMIT 1'
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
 * @return list<array{country:string,region:string,language:string,total:int,with_emails:int,last_pushed_at:?string}>
 */
function list_sites_with_emails_country_rows(): array
{
    ensure_sites_with_emails_schema();
    $sql = "SELECT TRIM(country) AS country,
                   MAX(region) AS region,
                   MAX(language) AS language,
                   COUNT(*) AS total,
                   SUM(
                     CASE WHEN email1<>'' OR email2<>'' OR email3<>'' OR email4<>'' THEN 1 ELSE 0 END
                   ) AS with_emails,
                   MAX(created_at) AS last_pushed_at
            FROM sites_with_emails
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

function count_sites_with_emails(): int
{
    ensure_sites_with_emails_schema();
    return (int) db()->query('SELECT COUNT(*) FROM sites_with_emails')->fetchColumn();
}

function count_sites_with_emails_for_country(string $country): int
{
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare('SELECT COUNT(*) FROM sites_with_emails WHERE country=?');
    $stmt->execute([$country]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array{rows:list<array<string,mixed>>,total:int,pages:int}
 */
function sites_with_emails_inventory_query(array $filters, int $page = 1, int $perPage = 100): array
{
    ensure_sites_with_emails_schema();
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

    $count = db()->prepare("SELECT COUNT(*) FROM sites_with_emails WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT * FROM sites_with_emails
         WHERE {$whereSql}
         ORDER BY id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
}

function get_site_with_emails(int $id): ?array
{
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare('SELECT * FROM sites_with_emails WHERE id=? LIMIT 1');
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
    ?int $id = null
): array {
    ensure_sites_with_emails_schema();
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
        $existing = get_site_with_emails($id);
        if (!$existing || (string) $existing['country'] !== $country) {
            return ['ok' => false, 'error' => 'Row not found in this country.'];
        }
        $dup = db()->prepare(
            'SELECT id FROM sites_with_emails WHERE country=? AND domain=? AND id<>? LIMIT 1'
        );
        $dup->execute([$country, $domain, $id]);
        if ((int) $dup->fetchColumn() > 0) {
            return ['ok' => false, 'error' => $domain . ' already exists in ' . $country . '.'];
        }
        db()->prepare(
            'UPDATE sites_with_emails
             SET domain=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$domain, $slots[0], $slots[1], $slots[2], $slots[3], $id]);
        return ['ok' => true, 'id' => $id];
    }

    $uid = (int) ($user['id'] ?? 0) ?: null;
    try {
        db()->prepare(
            'INSERT INTO sites_with_emails
               (domain, country, language, region, email1, email2, email3, email4, pushed_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
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

function delete_site_with_emails(int $id): bool
{
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare('DELETE FROM sites_with_emails WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function delete_sites_with_emails_for_country(string $country): int
{
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare('DELETE FROM sites_with_emails WHERE country=?');
    $stmt->execute([$country]);
    return $stmt->rowCount();
}

/**
 * @return array{removed:int,not_found:int,invalid:int}
 */
function remove_sites_with_emails_by_list(string $country, string $raw): array
{
    ensure_sites_with_emails_schema();
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
            "SELECT domain FROM sites_with_emails WHERE country=? AND domain IN ({$ph})"
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
            "DELETE FROM sites_with_emails WHERE country=? AND domain IN ({$dph})"
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
function collect_sites_with_emails_all_emails(string $country): array
{
    ensure_sites_with_emails_schema();
    $stmt = db()->prepare(
        'SELECT email1, email2, email3, email4
         FROM sites_with_emails WHERE country=? ORDER BY id DESC'
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

function stream_sites_with_emails_csv(string $country): void
{
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    $canon = resolve_canonical_country($country);
    $country = $canon ? $canon['name'] : trim($country);
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $country) ?: 'sites';

    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="' . $safe . '-sites-with-emails.csv"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }
    // Excel-friendly UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Site name', 'Email 1', 'Email 2', 'Email 3', 'Email 4']);

    $stmt = db()->prepare(
        'SELECT domain, email1, email2, email3, email4
         FROM sites_with_emails WHERE country=? ORDER BY id DESC'
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

function stream_sites_with_emails_emails_plain(string $country): void
{
    ensure_sites_with_emails_schema();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    foreach (collect_sites_with_emails_all_emails($country) as $email) {
        echo $email, "\n";
    }
    exit;
}
