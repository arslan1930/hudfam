<?php
/**
 * Extracted URLs — admin country folders of sites pushed from Team Extracting Results.
 */

function ensure_extracted_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS extracted_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          url VARCHAR(500) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL,
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          notes TEXT NULL,
          extract_batch_id INT NULL,
          pushed_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extracted_country_domain (country, domain),
          INDEX (country),
          INDEX (domain),
          INDEX (pushed_by),
          CONSTRAINT fk_extracted_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Push pasted extracting-results domains into admin Extracted URLs for one country.
 *
 * @return array{inserted:int,skipped:int,invalid:int,country:string,domains:list<string>}
 */
function push_extract_results_to_extracted(
    string $raw,
    string $country,
    array $user,
    string $language = '',
    string $region = '',
    ?int $extractBatchId = null
): array {
    ensure_extracted_schema();
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    if ($region === '') {
        $region = $canon['region'];
    }
    if ($language === '') {
        $language = $canon['language'];
    }

    $parsed = parse_domain_list_strict($raw);
    $domains = $parsed['valid'];
    $invalid = (int) $parsed['invalid_count'];
    if ($domains === []) {
        return [
            'inserted' => 0,
            'skipped' => 0,
            'invalid' => $invalid,
            'country' => $country,
            'domains' => [],
        ];
    }

    $ins = db()->prepare(
        'INSERT INTO extracted_sites (domain, url, country, language, region, notes, extract_batch_id, pushed_by)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           updated_at = NOW(),
           language = IF(VALUES(language) <> \'\', VALUES(language), language),
           region = IF(VALUES(region) <> \'\', VALUES(region), region)'
    );
    $find = db()->prepare(
        'SELECT id FROM extracted_sites WHERE country=? AND domain=? LIMIT 1'
    );

    $inserted = 0;
    $skipped = 0;
    $uid = (int) ($user['id'] ?? 0) ?: null;
    $notes = 'Pushed from Extracting Results · ' . $country;

    foreach ($domains as $domain) {
        $find->execute([$country, $domain]);
        $exists = (int) $find->fetchColumn() > 0;
        try {
            $ins->execute([
                $domain,
                '',
                $country,
                $language,
                $region,
                $notes,
                $extractBatchId,
                $uid,
            ]);
            if ($exists) {
                $skipped++;
            } else {
                $inserted++;
            }
        } catch (PDOException $e) {
            $skipped++;
        }
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'invalid' => $invalid,
        'country' => $country,
        'domains' => $domains,
    ];
}

/**
 * Country folders for Extracted URLs (catalog countries + counts).
 *
 * @return list<array{country:string,region:string,region_label:string,total:int,language:string}>
 */
function extracted_country_folders(): array
{
    ensure_extracted_schema();
    if (function_exists('seed_countries_if_empty')) {
        try {
            seed_countries_if_empty(db());
        } catch (Throwable $e) {
            // ignore
        }
    }

    $counts = [];
    foreach (db()->query(
        "SELECT TRIM(country) AS country, COUNT(*) AS total
         FROM extracted_sites
         GROUP BY TRIM(country)"
    )->fetchAll() as $row) {
        $key = (string) $row['country'];
        $canon = $key !== '' ? resolve_canonical_country($key) : null;
        $folderKey = $canon ? $canon['name'] : '';
        $counts[$folderKey] = ($counts[$folderKey] ?? 0) + (int) $row['total'];
    }

    $folders = [];
    foreach (list_countries(null, true) as $c) {
        $name = (string) $c['name'];
        $region = (string) $c['region'];
        $folders[] = [
            'country' => $name,
            'region' => $region,
            'region_label' => regions()[$region] ?? $region,
            'total' => $counts[$name] ?? 0,
            'language' => (string) ($c['default_language'] ?? ''),
        ];
        unset($counts[$name]);
    }
    if (!empty($counts[''])) {
        $folders[] = [
            'country' => '',
            'region' => 'other',
            'region_label' => 'Other',
            'total' => (int) $counts[''],
            'language' => '',
        ];
    }
    usort($folders, static function ($a, $b) {
        $ra = (string) $a['region_label'];
        $rb = (string) $b['region_label'];
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcasecmp((string) $a['country'], (string) $b['country']);
    });
    return $folders;
}

/**
 * @return array{rows:list<array<string,mixed>>,total:int,pages:int,page:int}
 */
function extracted_inventory_query(array $filters, int $pageNum = 1, int $per = 50): array
{
    ensure_extracted_schema();
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(e.domain LIKE ? OR e.url LIKE ? OR e.notes LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (array_key_exists('country', $filters)) {
        $country = (string) ($filters['country'] ?? '');
        if ($country === '') {
            $where[] = "TRIM(e.country)=''";
        } else {
            $where[] = 'e.country = ?';
            $params[] = $country;
        }
    }
    if (!empty($filters['language'])) {
        $where[] = 'e.language = ?';
        $params[] = $filters['language'];
    }
    if (!empty($filters['region'])) {
        $where[] = 'e.region = ?';
        $params[] = $filters['region'];
    }
    if (!empty($filters['pushed_by'])) {
        $where[] = 'e.pushed_by = ?';
        $params[] = (int) $filters['pushed_by'];
    }

    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM extracted_sites e WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $per = max(1, min(200, $per));
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT e.*, u.username pushed_by_name, u.full_name pushed_by_full
         FROM extracted_sites e
         LEFT JOIN users u ON u.id = e.pushed_by
         WHERE $whereSql
         ORDER BY e.created_at DESC
         LIMIT {$per} OFFSET {$offset}"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

function count_extracted_sites(): int
{
    ensure_extracted_schema();
    return (int) db()->query('SELECT COUNT(*) FROM extracted_sites')->fetchColumn();
}

/**
 * Countries that already have extracted sites — simple row list (no empty folders).
 *
 * @return list<array{country:string,region:string,language:string,total:int,last_pushed_at:?string}>
 */
function list_extracted_country_rows(): array
{
    ensure_extracted_schema();
    $sql = "SELECT TRIM(e.country) AS country,
                   MAX(e.region) AS region,
                   MAX(e.language) AS language,
                   COUNT(*) AS total,
                   MAX(e.created_at) AS last_pushed_at
            FROM extracted_sites e
            WHERE TRIM(e.country) <> ''
            GROUP BY TRIM(e.country)
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
            'last_pushed_at' => $row['last_pushed_at'] !== null ? (string) $row['last_pushed_at'] : null,
        ];
    }
    return $out;
}

/**
 * @return list<string>
 */
function get_extracted_domains_for_country(string $country, int $limit = 100000): array
{
    ensure_extracted_schema();
    $limit = max(1, min(100000, $limit));
    $stmt = db()->prepare(
        'SELECT domain FROM extracted_sites WHERE country=? ORDER BY domain ASC LIMIT ' . (int) $limit
    );
    $stmt->execute([$country]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function get_extracted_site(int $id): ?array
{
    ensure_extracted_schema();
    $stmt = db()->prepare(
        'SELECT e.*, u.username pushed_by_name, u.full_name pushed_by_full
         FROM extracted_sites e
         LEFT JOIN users u ON u.id = e.pushed_by
         WHERE e.id=? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Rename an extracted site domain within its country.
 *
 * @return array{ok:bool,error?:string,domain?:string}
 */
function update_extracted_site_domain(int $id, string $newDomain): array
{
    ensure_extracted_schema();
    $site = get_extracted_site($id);
    if (!$site) {
        return ['ok' => false, 'error' => 'Site not found.'];
    }
    $host = function_exists('extract_host_candidate') ? extract_host_candidate($newDomain) : normalize_domain($newDomain);
    $root = function_exists('to_root_domain') ? to_root_domain($host) : normalize_domain($host);
    if ($root === '') {
        return ['ok' => false, 'error' => 'Enter a valid root domain (e.g. example.com).'];
    }
    $country = (string) $site['country'];
    if ($root === (string) $site['domain']) {
        return ['ok' => true, 'domain' => $root];
    }
    $dup = db()->prepare('SELECT id FROM extracted_sites WHERE country=? AND domain=? AND id<>? LIMIT 1');
    $dup->execute([$country, $root, $id]);
    if ((int) $dup->fetchColumn() > 0) {
        return ['ok' => false, 'error' => $root . ' already exists in ' . $country . '.'];
    }
    db()->prepare(
        'UPDATE extracted_sites SET domain=?, updated_at=NOW() WHERE id=?'
    )->execute([$root, $id]);
    return ['ok' => true, 'domain' => $root];
}

function delete_extracted_site(int $id): bool
{
    ensure_extracted_schema();
    $stmt = db()->prepare('DELETE FROM extracted_sites WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Delete every extracted site for a country.
 */
function delete_extracted_sites_for_country(string $country): int
{
    ensure_extracted_schema();
    $stmt = db()->prepare('DELETE FROM extracted_sites WHERE country=?');
    $stmt->execute([$country]);
    return $stmt->rowCount();
}
