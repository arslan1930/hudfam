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
 * Insert domains into Extracted Sites for one country.
 *
 * @param list<string> $domains
 * @return array{inserted:int,skipped:int,country:string,domains:list<string>}
 */
function add_extracted_domains_to_country(
    array $domains,
    string $country,
    array $user,
    string $language = '',
    string $region = '',
    string $notes = '',
    ?int $extractBatchId = null
): array {
    ensure_extracted_schema();
    @set_time_limit(0);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    if ($region === '') {
        $region = $canon['region'];
    }
    if ($language === '') {
        $language = $canon['language'];
    }
    if ($notes === '') {
        $notes = 'Added to Extracted Sites · ' . $country;
    }

    $unique = [];
    foreach ($domains as $d) {
        $n = normalize_domain((string) $d);
        $root = function_exists('to_root_domain') ? to_root_domain($n) : $n;
        if ($root === '' && $n !== '') {
            $root = function_exists('to_root_domain') ? to_root_domain(extract_host_candidate((string) $d)) : $n;
        }
        if ($root !== '') {
            $unique[$root] = true;
        }
    }
    $list = array_keys($unique);
    if ($list === []) {
        return ['inserted' => 0, 'skipped' => 0, 'country' => $country, 'domains' => []];
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
    $n = 0;
    foreach ($list as $domain) {
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
        $n++;
        if ($n % 500 === 0) {
            // keep long imports from timing out mid-batch
        }
    }

    if ($inserted > 0 && function_exists('mark_admin_new_data')) {
        mark_admin_new_data('extracted_sites', $inserted, $country);
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'country' => $country,
        'domains' => $list,
    ];
}

/**
 * Parse pasted/CSV text into root domains (repairs https/paths when possible).
 *
 * @return array{valid:list<string>,invalid_count:int,valid_text:string}
 */
function parse_extracted_sites_input(string $raw): array
{
    $parsed = parse_domain_list_strict($raw);
    return [
        'valid' => $parsed['valid'],
        'invalid_count' => (int) $parsed['invalid_count'],
        'valid_text' => (string) $parsed['valid_text'],
    ];
}

/**
 * Read a 1-column CSV/TXT upload into raw paste text (first column per row).
 */
function read_extracted_sites_upload(?array $file): string
{
    if (!$file || !is_array($file)) {
        return '';
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('CSV upload failed. Try again.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('CSV upload missing on server.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size > 25 * 1024 * 1024) {
        throw new InvalidArgumentException('CSV is too large (max 25 MB).');
    }
    $fh = fopen($tmp, 'rb');
    if (!$fh) {
        throw new InvalidArgumentException('Could not read the uploaded file.');
    }
    $lines = [];
    $rowNum = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $rowNum++;
        if ($row === [null] || $row === false) {
            continue;
        }
        $cell = trim((string) ($row[0] ?? ''));
        if ($cell === '') {
            continue;
        }
        // Skip a header like "site" / "domain" / "url"
        if ($rowNum === 1 && preg_match('/^(site|sites|domain|domains|url|urls|website|websites)$/i', $cell)) {
            continue;
        }
        $lines[] = $cell;
        if (count($lines) >= 100000) {
            break;
        }
    }
    fclose($fh);
    return implode("\n", $lines);
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
    $parsed = parse_extracted_sites_input($raw);
    $added = add_extracted_domains_to_country(
        $parsed['valid'],
        $country,
        $user,
        $language,
        $region,
        'Pushed from Extracting Results · ' . trim($country),
        $extractBatchId
    );
    // Site names also go to Sites with emails - Team (emails filled there, then Team Push → Admin).
    if ($parsed['valid'] !== [] && function_exists('add_sites_with_emails_domains')) {
        add_sites_with_emails_domains(
            $parsed['valid'],
            $country,
            $user,
            $language,
            $region,
            $extractBatchId
        );
    }
    return [
        'inserted' => (int) $added['inserted'],
        'skipped' => (int) $added['skipped'],
        'invalid' => (int) $parsed['invalid_count'],
        'country' => (string) $added['country'],
        'domains' => $added['domains'],
    ];
}

/**
 * Admin add from paste and/or CSV upload.
 *
 * @return array{inserted:int,skipped:int,invalid:int,country:string}
 */
function admin_add_extracted_sites(
    string $country,
    array $user,
    string $paste = '',
    ?array $upload = null
): array {
    $fromFile = read_extracted_sites_upload($upload);
    $raw = trim($paste);
    if ($fromFile !== '') {
        $raw = $raw !== '' ? ($raw . "\n" . $fromFile) : $fromFile;
    }
    $parsed = parse_extracted_sites_input($raw);
    if ($parsed['valid'] === []) {
        return [
            'inserted' => 0,
            'skipped' => 0,
            'invalid' => (int) $parsed['invalid_count'],
            'country' => trim($country),
        ];
    }
    $added = add_extracted_domains_to_country(
        $parsed['valid'],
        $country,
        $user,
        '',
        '',
        'Added by admin · text/CSV'
    );
    return [
        'inserted' => (int) $added['inserted'],
        'skipped' => (int) $added['skipped'],
        'invalid' => (int) $parsed['invalid_count'],
        'country' => (string) $added['country'],
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

function count_extracted_sites_for_country(string $country): int
{
    ensure_extracted_schema();
    $stmt = db()->prepare('SELECT COUNT(*) FROM extracted_sites WHERE country=?');
    $stmt->execute([$country]);
    return (int) $stmt->fetchColumn();
}

/**
 * Stream domain names (one per line) for copy/download — supports ~100k without bloating HTML.
 */
function stream_extracted_domains_plain(string $country, bool $asDownload = false): void
{
    ensure_extracted_schema();
    @set_time_limit(0);
    $canon = resolve_canonical_country($country);
    $country = $canon ? $canon['name'] : trim($country);
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $country) ?: 'sites';

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($asDownload) {
        header('Content-Disposition: attachment; filename="' . $safeName . '-extracted-sites.txt"');
    }

    $pdo = db();
    // Unbuffered where supported so we don't load 100k rows into PHP memory at once
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
    } catch (Throwable $e) {
        // ignore
    }

    $stmt = $pdo->prepare(
        'SELECT domain FROM extracted_sites WHERE country=? ORDER BY domain ASC'
    );
    $stmt->execute([$country]);
    $i = 0;
    while ($domain = $stmt->fetchColumn()) {
        echo (string) $domain, "\n";
        $i++;
        if ($i % 2000 === 0) {
            if (function_exists('flush')) {
                flush();
            }
        }
    }
    $stmt->closeCursor();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    } catch (Throwable $e) {
        // ignore
    }
    exit;
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

/**
 * Remove sites that match a pasted/CSV domain list (exact root-domain match).
 *
 * @return array{removed:int,not_found:int,invalid:int}
 */
function remove_extracted_sites_by_list(string $country, string $raw): array
{
    ensure_extracted_schema();
    @set_time_limit(0);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $parsed = parse_extracted_sites_input($raw);
    $domains = $parsed['valid'];
    if ($domains === []) {
        return ['removed' => 0, 'not_found' => 0, 'invalid' => (int) $parsed['invalid_count']];
    }

    $removed = 0;
    $notFound = 0;
    $chunkSize = 400;
    for ($i = 0, $n = count($domains); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($domains, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$country], $chunk);
        $sel = db()->prepare(
            "SELECT domain FROM extracted_sites WHERE country=? AND domain IN ({$placeholders})"
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
        $delPlaceholders = implode(',', array_fill(0, count($found), '?'));
        $del = db()->prepare(
            "DELETE FROM extracted_sites WHERE country=? AND domain IN ({$delPlaceholders})"
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

function count_extracted_sites_matching(string $country, string $q): int
{
    ensure_extracted_schema();
    $q = trim($q);
    if ($q === '') {
        return 0;
    }
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM extracted_sites WHERE country=? AND (domain LIKE ? OR url LIKE ? OR notes LIKE ?)'
    );
    $stmt->execute([$country, $like, $like, $like]);
    return (int) $stmt->fetchColumn();
}

/**
 * Remove sites in a country that match the search query.
 */
function remove_extracted_sites_by_search(string $country, string $q): int
{
    ensure_extracted_schema();
    $q = trim($q);
    if ($q === '') {
        return 0;
    }
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        'DELETE FROM extracted_sites WHERE country=? AND (domain LIKE ? OR url LIKE ? OR notes LIKE ?)'
    );
    $stmt->execute([$country, $like, $like, $like]);
    return $stmt->rowCount();
}
