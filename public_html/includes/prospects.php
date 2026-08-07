<?php

/**
 * Our database helpers — unique domains (no prices).
 * Team Filter & add checks uniqueness against prospect_sites.
 * Admin Add sites saves directly (no uniqueness preview).
 */

/** Strip protocol/path → bare host for storage/lookup (does not validate apex-only). */
function normalize_domain(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = preg_replace('#^//#', '', $value) ?? $value;
    $value = preg_replace('#^www\.#i', '', $value) ?? $value;
    $host = explode('/', $value, 2)[0];
    $host = explode('?', $host, 2)[0];
    $host = explode('#', $host, 2)[0];
    if (str_contains($host, ':') && !str_contains($host, ']')) {
        $host = explode(':', $host, 2)[0];
    }
    return rtrim($host, '.');
}

/**
 * Common multi-part public suffixes (e.g. example.co.uk is a root domain).
 *
 * @return list<string>
 */
function known_multi_part_tlds(): array
{
    return [
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'ltd.uk', 'plc.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'org.nz', 'net.nz', 'govt.nz', 'ac.nz',
        'co.za', 'org.za', 'web.za', 'net.za',
        'com.br', 'net.br', 'org.br', 'gov.br',
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
        'com.mx', 'org.mx', 'gob.mx',
        'com.sg', 'com.hk', 'com.tw', 'com.tr', 'com.my', 'com.ph',
        'co.in', 'firm.in', 'gen.in', 'ind.in', 'net.in', 'org.in',
        'com.ar', 'com.co', 'com.pe', 'com.ve', 'com.ec',
        'co.kr', 'co.th', 'co.il', 'org.il', 'ac.il',
        'com.cn', 'net.cn', 'org.cn',
        'co.id', 'or.id', 'web.id',
    ];
}

function domain_public_suffix(string $host): string
{
    $host = strtolower(trim($host));
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    $n = count($parts);
    if ($n < 2) {
        return '';
    }
    $two = $parts[$n - 2] . '.' . $parts[$n - 1];
    if (in_array($two, known_multi_part_tlds(), true)) {
        return $two;
    }
    return $parts[$n - 1];
}

/**
 * True when $host is an apex/root domain (no subdomain), allowing multi-part TLDs like .co.uk.
 */
function is_root_domain(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || !str_contains($host, '.')) {
        return false;
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        return false;
    }
    if (str_starts_with($host, '-') || str_ends_with($host, '-') || str_contains($host, '..')) {
        return false;
    }
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    if (count($parts) < 2) {
        return false;
    }
    foreach ($parts as $label) {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
            return false;
        }
    }
    $suffix = domain_public_suffix($host);
    if ($suffix === '') {
        return false;
    }
    $suffixParts = substr_count($suffix, '.') + 1;
    $nameParts = count($parts) - $suffixParts;
    return $nameParts === 1;
}

/**
 * Classify one pasted line for root-domain-only input.
 *
 * @return array{ok:bool,domain:string,reason:string,raw:string}
 */
function analyze_pasted_domain_line(string $line): array
{
    $raw = trim($line);
    if ($raw === '') {
        return ['ok' => false, 'domain' => '', 'reason' => 'empty', 'raw' => $raw];
    }
    if (preg_match('#https?://#i', $raw) || str_starts_with($raw, '//') || str_contains($raw, '://')) {
        return ['ok' => false, 'domain' => '', 'reason' => 'has_scheme', 'raw' => $raw];
    }
    if (str_contains($raw, '/') || str_contains($raw, '?') || str_contains($raw, '#')) {
        return ['ok' => false, 'domain' => '', 'reason' => 'has_path', 'raw' => $raw];
    }
    if (str_contains($raw, ' ') || str_contains($raw, "\t")) {
        return ['ok' => false, 'domain' => '', 'reason' => 'has_spaces', 'raw' => $raw];
    }

    $host = strtolower($raw);
    $host = preg_replace('#^www\.#i', '', $host) ?? $host;
    if (str_contains($host, ':') && !str_contains($host, ']')) {
        $host = explode(':', $host, 2)[0];
    }
    $host = rtrim($host, '.');

    if ($host === '' || !str_contains($host, '.')) {
        return ['ok' => false, 'domain' => '', 'reason' => 'invalid', 'raw' => $raw];
    }
    if (!is_root_domain($host)) {
        $suffix = domain_public_suffix($host);
        $suffixParts = $suffix !== '' ? substr_count($suffix, '.') + 1 : 1;
        $parts = array_values(array_filter(explode('.', $host)));
        if (count($parts) - $suffixParts > 1) {
            return ['ok' => false, 'domain' => '', 'reason' => 'subdomain', 'raw' => $raw];
        }
        return ['ok' => false, 'domain' => '', 'reason' => 'invalid', 'raw' => $raw];
    }

    return ['ok' => true, 'domain' => $host, 'reason' => '', 'raw' => $raw];
}

/**
 * Parse pasted sites: only apex/root domains (no https, paths, or subdomains).
 *
 * @return array{valid:list<string>,invalid:list<array{raw:string,reason:string}>,valid_text:string,invalid_count:int}
 */
function parse_domain_list_strict(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = preg_split('/\n+/', $raw) ?: [];
    $valid = [];
    $invalid = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $chunks = preg_split('/\s*,\s*/', $line) ?: [$line];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $a = analyze_pasted_domain_line($chunk);
            if ($a['ok']) {
                $valid[$a['domain']] = true;
            } else {
                $invalid[] = ['raw' => $a['raw'], 'reason' => $a['reason']];
            }
        }
    }
    $validList = array_keys($valid);
    return [
        'valid' => $validList,
        'invalid' => $invalid,
        'valid_text' => implode("\n", $validList),
        'invalid_count' => count($invalid),
    ];
}

/**
 * Ensure prospect tables exist (Hostinger safety net if upgrade.php was skipped).
 * Each country is its own URL database: unique on (country, domain).
 */
function ensure_prospect_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          url VARCHAR(500) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          niche VARCHAR(255) NOT NULL DEFAULT '',
          notes TEXT,
          status ENUM('new','contacting','replied','skipped') NOT NULL DEFAULT 'new',
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_prospect_country_domain (country, domain),
          INDEX (domain),
          INDEX (country),
          INDEX (language),
          INDEX (region),
          INDEX (status),
          CONSTRAINT fk_prospect_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Migrate older installs: global unique(domain) → unique(country, domain)
    try {
        $idx = $pdo->query('SHOW INDEX FROM prospect_sites')->fetchAll(PDO::FETCH_ASSOC);
        $hasOld = false;
        $hasNew = false;
        foreach ($idx as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === 'uniq_prospect_domain') {
                $hasOld = true;
            }
            if ($name === 'uniq_prospect_country_domain') {
                $hasNew = true;
            }
        }
        if ($hasOld) {
            $pdo->exec('ALTER TABLE prospect_sites DROP INDEX uniq_prospect_domain');
        }
        if (!$hasNew) {
            $pdo->exec('ALTER TABLE prospect_sites ADD UNIQUE KEY uniq_prospect_country_domain (country, domain)');
        }
    } catch (Throwable $e) {
        // ignore — CREATE above already has the right key on new installs
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_batches (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          batch_date DATE NOT NULL,
          site_count INT NOT NULL DEFAULT 0,
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          niche VARCHAR(255) NOT NULL DEFAULT '',
          notes TEXT,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_user_batch_date (user_id, batch_date),
          INDEX (batch_date),
          INDEX (user_id),
          CONSTRAINT fk_pbatch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_batch_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          batch_id INT NOT NULL,
          domain VARCHAR(255) NOT NULL,
          prospect_site_id INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_batch_domain (batch_id, domain),
          INDEX (domain),
          CONSTRAINT fk_pbi_batch FOREIGN KEY (batch_id) REFERENCES prospect_batches(id) ON DELETE CASCADE,
          CONSTRAINT fk_pbi_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Country folders for Admin: every active country + URL counts.
 *
 * @return list<array{country:string,region:string,region_label:string,total:int,language:string}>
 */
function prospect_country_folders(): array
{
    ensure_prospect_schema();
    if (function_exists('seed_countries_if_empty')) {
        try {
            seed_countries_if_empty(db());
        } catch (Throwable $e) {
            // countries table may be created by upgrade/install
        }
    }
    $counts = [];
    foreach (db()->query(
        "SELECT TRIM(country) AS country, COUNT(*) AS total
         FROM prospect_sites
         GROUP BY TRIM(country)"
    )->fetchAll() as $row) {
        $counts[(string) $row['country']] = (int) $row['total'];
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
    // Leftover country names in data that are not in countries table
    foreach ($counts as $name => $total) {
        if ($name === '') {
            $folders[] = [
                'country' => '',
                'region' => 'other',
                'region_label' => 'Other',
                'total' => $total,
                'language' => '',
            ];
            continue;
        }
        $folders[] = [
            'country' => $name,
            'region' => 'other',
            'region_label' => 'Other',
            'total' => $total,
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

function parse_domain_list(string $raw): array
{
    return parse_domain_list_strict($raw)['valid'];
}

/**
 * Check domains against Our database.
 * When $country is set, only that country’s database is checked.
 *
 * @return array{existing:string[],new:string[],invalid:int,total_input:int}
 */
function filter_domains_against_prospects(array $domains, string $country = ''): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    $country = trim($country);
    $existing = [];
    $new = [];
    if (!$domains) {
        return ['existing' => [], 'new' => [], 'invalid' => 0, 'total_input' => 0];
    }

    $chunkSize = 500;
    $found = [];
    for ($i = 0, $n = count($domains); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($domains, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        if ($country !== '') {
            $sql = "SELECT domain FROM prospect_sites WHERE TRIM(country)=? AND domain IN ($placeholders)";
            $params = array_merge([$country], $chunk);
        } else {
            $sql = "SELECT domain FROM prospect_sites WHERE domain IN ($placeholders)";
            $params = $chunk;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $found[$d] = true;
        }
    }
    foreach ($domains as $d) {
        if (isset($found[$d])) {
            $existing[] = $d;
        } else {
            $new[] = $d;
        }
    }
    return [
        'existing' => $existing,
        'new' => $new,
        'invalid' => 0,
        'total_input' => count($domains),
    ];
}

/**
 * Plain domain names for Filter Box 1. Optionally scoped to one country database.
 *
 * @return array{domains:string[],total:int,truncated:bool}
 */
function list_prospect_domain_names(int $maxDisplay = 25000, string $country = ''): array
{
    ensure_prospect_schema();
    $country = trim($country);
    if ($country !== '') {
        $count = db()->prepare('SELECT COUNT(*) FROM prospect_sites WHERE TRIM(country)=?');
        $count->execute([$country]);
        $total = (int) $count->fetchColumn();
        $stmt = db()->prepare(
            'SELECT domain FROM prospect_sites WHERE TRIM(country)=? ORDER BY domain ASC LIMIT ' . (int) $maxDisplay
        );
        $stmt->execute([$country]);
    } else {
        $total = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
        $stmt = db()->query(
            'SELECT domain FROM prospect_sites ORDER BY domain ASC LIMIT ' . (int) $maxDisplay
        );
    }
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return [
        'domains' => $domains,
        'total' => $total,
        'truncated' => $total > count($domains),
    ];
}

/**
 * Get or create a dated batch for a user (one row per user per calendar day).
 */
function get_or_create_prospect_batch(
    int $userId,
    string $country,
    string $language,
    string $region,
    string $niche,
    string $notes,
    ?string $batchDate = null
): int {
    ensure_prospect_schema();
    $date = $batchDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDate) ? $batchDate : date('Y-m-d');
    $stmt = db()->prepare('SELECT id FROM prospect_batches WHERE user_id=? AND batch_date=? LIMIT 1');
    $stmt->execute([$userId, $date]);
    $id = (int) $stmt->fetchColumn();
    if ($id) {
        return $id;
    }
    db()->prepare(
        'INSERT INTO prospect_batches (user_id, batch_date, site_count, country, language, region, niche, notes)
         VALUES (?,?,0,?,?,?,?,?)'
    )->execute([$userId, $date, $country, $language, $region, $niche, $notes]);
    return (int) db()->lastInsertId();
}

/**
 * Insert new prospect domains into old inventory + dated batch (both sides).
 *
 * @return array{inserted:int,skipped:int,batch_id:int|null}
 */
function add_prospect_domains(
    array $domains,
    array $user,
    string $country = '',
    string $language = '',
    string $region = '',
    string $niche = '',
    string $notes = ''
): array {
    ensure_prospect_schema();
    @set_time_limit(0);
    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    $check = filter_domains_against_prospects($domains, $country);
    $toAdd = $check['new'];
    $skipped = count($check['existing']);
    if (!$toAdd) {
        return ['inserted' => 0, 'skipped' => $skipped, 'batch_id' => null];
    }

    if ($country !== '') {
        foreach (list_countries(null, true) as $c) {
            if (strcasecmp($c['name'], $country) === 0) {
                if ($region === '') {
                    $region = $c['region'];
                }
                if ($language === '' && $c['default_language'] !== '') {
                    $language = $c['default_language'];
                }
                break;
            }
        }
    }

    $batchId = get_or_create_prospect_batch(
        (int) $user['id'],
        $country,
        $language,
        $region,
        $niche,
        $notes
    );

    $ins = db()->prepare(
        'INSERT INTO prospect_sites (domain, country, language, region, niche, notes, status, created_by)
         VALUES (?,?,?,?,?,?,\'new\',?)'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $inserted = 0;
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($toAdd as $d) {
            try {
                $ins->execute([$d, $country, $language, $region, $niche, $notes, $user['id']]);
                $siteId = (int) db()->lastInsertId();
                $insItem->execute([$batchId, $d, $siteId ?: null]);
                $inserted++;
            } catch (PDOException $e) {
                $skipped++;
            }
            $n++;
            if ($n % 250 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        $cnt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
        $cnt->execute([$batchId]);
        $siteCount = (int) $cnt->fetchColumn();
        db()->prepare(
            'UPDATE prospect_batches SET site_count=?, country=?, language=?, region=?, niche=?, notes=?, updated_at=NOW() WHERE id=?'
        )->execute([$siteCount, $country, $language, $region, $niche, $notes, $batchId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return ['inserted' => $inserted, 'skipped' => $skipped, 'batch_id' => $batchId];
}

/**
 * Admin: paste URLs into one country’s database (no uniqueness preview).
 *
 * @return array{inserted:int,updated:int,total:int,batch_id:int|null,country:string}
 */
function admin_add_urls_to_database(string $raw, array $user, string $country, string $language = ''): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $country = trim($country);
    if ($country === '') {
        throw new InvalidArgumentException('Country is required.');
    }

    $region = '';
    $defaultLang = '';
    foreach (list_countries(null, true) as $c) {
        if (strcasecmp((string) $c['name'], $country) === 0) {
            $country = (string) $c['name'];
            $region = (string) $c['region'];
            $defaultLang = (string) ($c['default_language'] ?? '');
            break;
        }
    }
    if ($language === '') {
        $language = $defaultLang;
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $parsed = parse_domain_list_strict($raw);
    if ($parsed['invalid_count'] > 0) {
        throw new InvalidArgumentException(
            'Remove invalid lines first (use Clean errors). Paste root domains only, e.g. example.com or my-site.co.uk — no https, paths, or subdomains.'
        );
    }
    /** @var array<string,string> $rows domain => url (empty for root-domain paste) */
    $rows = [];
    foreach ($parsed['valid'] as $domain) {
        $rows[$domain] = '';
    }

    if ($rows === []) {
        return ['inserted' => 0, 'updated' => 0, 'total' => 0, 'batch_id' => null, 'country' => $country];
    }

    $batchId = get_or_create_prospect_batch(
        (int) $user['id'],
        $country,
        $language,
        $region,
        '',
        'Admin Add sites · ' . $country
    );
    $ins = db()->prepare(
        'INSERT INTO prospect_sites (domain, url, country, language, region, niche, notes, status, created_by)
         VALUES (?,?,?,?,?,\'\',\'\',\'new\',?)
         ON DUPLICATE KEY UPDATE
           url = IF(VALUES(url) <> \'\', VALUES(url), url),
           language = IF(VALUES(language) <> \'\', VALUES(language), language),
           region = IF(VALUES(region) <> \'\', VALUES(region), region),
           updated_at = NOW()'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $findId = db()->prepare(
        'SELECT id FROM prospect_sites WHERE TRIM(country)=? AND domain=? LIMIT 1'
    );

    $inserted = 0;
    $updated = 0;
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($rows as $domain => $url) {
            $findId->execute([$country, $domain]);
            $beforeId = (int) $findId->fetchColumn();
            $ins->execute([$domain, $url, $country, $language, $region, $user['id']]);
            if ($beforeId > 0) {
                $updated++;
                $siteId = $beforeId;
            } else {
                $inserted++;
                $siteId = (int) db()->lastInsertId();
                if ($siteId <= 0) {
                    $findId->execute([$country, $domain]);
                    $siteId = (int) $findId->fetchColumn();
                }
            }
            $insItem->execute([$batchId, $domain, $siteId ?: null]);
            $n++;
            if ($n % 250 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        $cnt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
        $cnt->execute([$batchId]);
        db()->prepare(
            'UPDATE prospect_batches SET site_count=?, country=?, language=?, region=?, notes=?, updated_at=NOW() WHERE id=?'
        )->execute([
            (int) $cnt->fetchColumn(),
            $country,
            $language,
            $region,
            'Admin Add sites · ' . $country,
            $batchId,
        ]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'total' => count($rows),
        'batch_id' => $batchId,
        'country' => $country,
    ];
}

function list_prospect_batches(?int $userId = null, int $limit = 60, string $roleFilter = ''): array
{
    ensure_prospect_schema();
    $sql = "SELECT b.*, u.username, u.full_name, u.role
            FROM prospect_batches b
            JOIN users u ON u.id = b.user_id";
    $where = [];
    $params = [];
    if ($userId) {
        $where[] = 'b.user_id = ?';
        $params[] = $userId;
    }
    if ($roleFilter === 'team' || $roleFilter === 'admin') {
        $where[] = 'u.role = ?';
        $params[] = $roleFilter;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY b.batch_date DESC, b.id DESC LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Per-user totals for admin Add history (sites + days with adds).
 *
 * @return list<array{user_id:int,username:string,full_name:string,role:string,batch_days:int,site_count:int,last_batch_date:?string}>
 */
function prospect_add_history_by_user(?int $userId = null, string $roleFilter = 'team'): array
{
    ensure_prospect_schema();
    $sql = "SELECT u.id AS user_id, u.username, u.full_name, u.role,
                   COUNT(b.id) AS batch_days,
                   COALESCE(SUM(b.site_count), 0) AS site_count,
                   MAX(b.batch_date) AS last_batch_date
            FROM users u
            LEFT JOIN prospect_batches b ON b.user_id = u.id";
    $where = [];
    $params = [];
    if ($roleFilter === 'team' || $roleFilter === 'admin') {
        $where[] = 'u.role = ?';
        $params[] = $roleFilter;
    } else {
        $where[] = "u.role IN ('team','admin')";
    }
    if ($userId) {
        $where[] = 'u.id = ?';
        $params[] = $userId;
    }
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY u.id, u.username, u.full_name, u.role
              HAVING batch_days > 0 OR u.role = \'team\'
              ORDER BY site_count DESC, u.full_name, u.username';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function list_team_users(bool $activeOnly = true): array
{
    $sql = "SELECT id, username, full_name, email, role, is_active FROM users WHERE role='team'";
    if ($activeOnly) {
        $sql .= ' AND is_active=1';
    }
    $sql .= ' ORDER BY full_name, username';
    return db()->query($sql)->fetchAll();
}

/**
 * Backfill dated add history from inventory rows that never landed in a batch
 * (e.g. older single-add form saves). Idempotent.
 *
 * @return int number of domains attached to history
 */
function sync_missing_prospect_batch_history(int $limit = 5000): int
{
    ensure_prospect_schema();
    $stmt = db()->query(
        'SELECT p.id, p.domain, p.created_by, DATE(p.created_at) AS batch_date,
                p.country, p.language, p.region, p.niche, p.notes
         FROM prospect_sites p
         LEFT JOIN prospect_batch_items i ON i.prospect_site_id = p.id
         WHERE p.created_by IS NOT NULL AND i.id IS NULL
         ORDER BY p.created_by, batch_date, p.id
         LIMIT ' . (int) $limit
    );
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id, created_at)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $countStmt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
    $updateBatch = db()->prepare(
        'UPDATE prospect_batches SET site_count=?, updated_at=NOW() WHERE id=?'
    );
    $touched = [];
    $added = 0;

    foreach ($rows as $row) {
        $uid = (int) $row['created_by'];
        $date = (string) $row['batch_date'];
        if ($uid <= 0 || $date === '') {
            continue;
        }
        $batchId = get_or_create_prospect_batch(
            $uid,
            (string) ($row['country'] ?? ''),
            (string) ($row['language'] ?? ''),
            (string) ($row['region'] ?? ''),
            (string) ($row['niche'] ?? ''),
            (string) ($row['notes'] ?? ''),
            $date
        );
        try {
            $insItem->execute([
                $batchId,
                $row['domain'],
                (int) $row['id'],
                $date . ' 12:00:00',
            ]);
            if ($insItem->rowCount() > 0) {
                $added++;
            }
            $touched[$batchId] = true;
        } catch (PDOException $e) {
            // ignore duplicates / race
        }
    }

    foreach (array_keys($touched) as $batchId) {
        $countStmt->execute([$batchId]);
        $updateBatch->execute([(int) $countStmt->fetchColumn(), $batchId]);
    }

    return $added;
}

function get_prospect_batch(int $batchId): ?array
{
    ensure_prospect_schema();
    $stmt = db()->prepare(
        "SELECT b.*, u.username, u.full_name, u.role
         FROM prospect_batches b JOIN users u ON u.id=b.user_id WHERE b.id=?"
    );
    $stmt->execute([$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_prospect_batch_domains(int $batchId, int $limit = 50000): array
{
    ensure_prospect_schema();
    $stmt = db()->prepare(
        'SELECT domain FROM prospect_batch_items WHERE batch_id=? ORDER BY domain LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * @return list<array{domain:string,created_at:string,prospect_site_id:?int}>
 */
function get_prospect_batch_items(int $batchId, int $limit = 50000): array
{
    ensure_prospect_schema();
    $stmt = db()->prepare(
        'SELECT domain, created_at, prospect_site_id
         FROM prospect_batch_items WHERE batch_id=?
         ORDER BY created_at ASC, domain ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll();
}

function prospect_inventory_query(array $filters, int $pageNum = 1, int $per = 50): array
{
    ensure_prospect_schema();
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.domain LIKE ? OR p.niche LIKE ? OR p.notes LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filters['country'])) {
        $where[] = 'p.country = ?';
        $params[] = $filters['country'];
    }
    if (!empty($filters['language'])) {
        $where[] = 'p.language = ?';
        $params[] = $filters['language'];
    }
    if (!empty($filters['region'])) {
        $where[] = 'p.region = ?';
        $params[] = $filters['region'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['created_by'])) {
        $where[] = 'p.created_by = ?';
        $params[] = (int) $filters['created_by'];
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT p.*, u.username added_by_name, u.full_name added_by_full
         FROM prospect_sites p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE $whereSql ORDER BY p.created_at DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

function distinct_prospect_languages(): array
{
    ensure_prospect_schema();
    $rows = db()->query(
        "SELECT DISTINCT language FROM prospect_sites WHERE language <> '' ORDER BY language"
    )->fetchAll();
    return array_column($rows, 'language');
}

function distinct_prospect_countries(): array
{
    ensure_prospect_schema();
    $rows = db()->query(
        "SELECT DISTINCT country FROM prospect_sites WHERE country <> '' ORDER BY country"
    )->fetchAll();
    return array_column($rows, 'country');
}

function list_admin_users(bool $activeOnly = true): array
{
    $sql = "SELECT * FROM users WHERE role='admin'";
    if ($activeOnly) {
        $sql .= ' AND is_active=1';
    }
    $sql .= ' ORDER BY full_name, username';
    return db()->query($sql)->fetchAll();
}

