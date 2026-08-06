<?php

/**
 * Our database helpers — globally unique domains (no prices).
 * One domain exists only once across all countries.
 * Country folders are for browsing / save destination.
 * Team Filter & add checks uniqueness against the whole database.
 * Admin Add sites saves into a country folder (global uniqueness still applies).
 * Site names must be root domains only: example.com / example.co.uk
 */

/**
 * Multi-part public suffixes (so example.co.uk is a root domain,
 * but blog.example.co.uk is a subdomain and rejected).
 *
 * @return list<string>
 */
function multi_part_public_suffixes(): array
{
    return [
        // UK / IE
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'net.uk', 'ltd.uk', 'plc.uk', 'sch.uk',
        // AU / NZ
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz', 'kiwi.nz',
        // ZA
        'co.za', 'org.za', 'net.za', 'web.za', 'gov.za', 'ac.za',
        // India / Asia
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in',
        'com.sg', 'com.hk', 'com.my', 'com.ph', 'com.tw', 'co.jp', 'or.jp', 'ne.jp',
        // Americas
        'com.br', 'com.mx', 'com.ar', 'com.co', 'com.pe', 'com.ve', 'com.do', 'com.gt', 'com.pa',
        'co.cr', 'com.ni', 'com.sv', 'com.hn', 'com.jm', 'com.tt', 'com.ag', 'com.bs', 'com.bb',
        // Africa / others common in English markets
        'com.ng', 'com.gh', 'co.ke', 'co.ug', 'co.tz', 'co.zw', 'co.bw', 'com.na', 'ac.mw',
        // Europe extras
        'com.pl', 'com.pt', 'co.at', 'com.tr', 'com.ua', 'com.ro',
    ];
}

/**
 * How many trailing labels are the public suffix? (1 for .com, 2 for .co.uk)
 */
function public_suffix_label_count(string $domain): int
{
    $domain = strtolower(trim($domain));
    foreach (multi_part_public_suffixes() as $suffix) {
        if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
            return substr_count($suffix, '.') + 1;
        }
    }
    return 1;
}

/**
 * True only for root domains: example.com, my-site.com, example.co.uk.
 * Rejects https://, //, www., subdomains (blog.example.com), paths, ports, emails.
 */
function is_plain_site_domain(string $value): bool
{
    $value = strtolower(trim($value));
    if ($value === '' || strlen($value) > 253) {
        return false;
    }
    // Must look like a hostname — no protocol, path, port, etc.
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
        return false;
    }
    // www. is a subdomain — root only
    if (str_starts_with($value, 'www.')) {
        return false;
    }
    $labels = explode('.', $value);
    $n = count($labels);
    $suffixLabels = public_suffix_label_count($value);
    // Root domain = one name label + public suffix (example.com or example.co.uk)
    return $n === ($suffixLabels + 1);
}

/**
 * Parse pasted site list. Root domains only (example.com / example.co.uk).
 *
 * @return array{domains:string[],invalid:string[]}
 */
function parse_plain_site_list(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $parts = preg_split('/[\n,\t;]+/', $raw) ?: [];
    $domains = [];
    $invalid = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $domain = strtolower($part);
        if (!is_plain_site_domain($domain)) {
            $invalid[] = $part;
            continue;
        }
        $domains[$domain] = true;
    }
    return [
        'domains' => array_keys($domains),
        'invalid' => $invalid,
    ];
}

/**
 * Try to salvage a root domain from a messy line.
 * https://www.blog.example.com/main → example.com
 * example.co.uk/path → example.co.uk
 */
function extract_root_domain_candidate(string $raw): string
{
    $value = strtolower(trim($raw));
    if ($value === '') {
        return '';
    }
    if (is_plain_site_domain($value)) {
        return $value;
    }

    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = preg_replace('#^//+#', '', $value) ?? $value;
    // drop credentials / leftover
    if (str_contains($value, '@')) {
        $value = explode('@', $value);
        $value = (string) end($value);
    }
    $host = explode('/', $value, 2)[0];
    $host = explode('?', $host, 2)[0];
    $host = explode('#', $host, 2)[0];
    if (str_contains($host, ':') && !str_contains($host, ']')) {
        $host = explode(':', $host, 2)[0];
    }
    $host = rtrim($host, '.');
    $host = preg_replace('#^www\.#i', '', $host) ?? $host;

    if ($host === '' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
        return '';
    }
    if (is_plain_site_domain($host)) {
        return $host;
    }

    // Subdomain → registrable root (blog.example.com → example.com)
    $suffixLabels = public_suffix_label_count($host);
    $labels = explode('.', $host);
    $need = $suffixLabels + 1;
    if (count($labels) > $need) {
        $root = implode('.', array_slice($labels, -$need));
        if (is_plain_site_domain($root)) {
            return $root;
        }
    }
    return '';
}

/**
 * Clean a pasted list: fix/drop bad lines, remove paste duplicates,
 * and optionally remove sites already in Our database (global uniqueness).
 *
 * @return array{
 *   text:string,
 *   domains:string[],
 *   input_lines:int,
 *   fixed:int,
 *   dropped:int,
 *   dup_paste:int,
 *   dup_db:int,
 *   kept:int
 * }
 */
function clean_site_list(string $raw, string $country = '', bool $removeDbDuplicates = true): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $parts = preg_split('/[\n,\t;]+/', $raw) ?: [];
    $inputLines = 0;
    $fixed = 0;
    $dropped = 0;
    $dupPaste = 0;
    $seen = [];
    /** @var list<string> $order */
    $order = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $inputLines++;
        $wasPlain = is_plain_site_domain(strtolower($part));
        $domain = extract_root_domain_candidate($part);
        if ($domain === '') {
            $dropped++;
            continue;
        }
        if (!$wasPlain) {
            $fixed++;
        }
        if (isset($seen[$domain])) {
            $dupPaste++;
            continue;
        }
        $seen[$domain] = true;
        $order[] = $domain;
    }

    $dupDb = 0;
    // Always check globally (one domain = one entry in the whole database)
    if ($removeDbDuplicates && $order !== []) {
        $check = filter_domains_against_prospects($order, '');
        $existing = array_fill_keys($check['existing'], true);
        $kept = [];
        foreach ($order as $d) {
            if (isset($existing[$d])) {
                $dupDb++;
                continue;
            }
            $kept[] = $d;
        }
        $order = $kept;
    }

    return [
        'text' => implode("\n", $order),
        'domains' => $order,
        'input_lines' => $inputLines,
        'fixed' => $fixed,
        'dropped' => $dropped,
        'dup_paste' => $dupPaste,
        'dup_db' => $dupDb,
        'kept' => count($order),
    ];
}

/** Human-readable summary of a clean_site_list() result. */
function clean_site_list_summary(array $clean): string
{
    $bits = [];
    $bits[] = (int) $clean['kept'] . ' ready';
    if ((int) $clean['fixed'] > 0) {
        $bits[] = 'fixed ' . (int) $clean['fixed'];
    }
    if ((int) $clean['dropped'] > 0) {
        $bits[] = 'dropped ' . (int) $clean['dropped'] . ' unusable';
    }
    if ((int) $clean['dup_paste'] > 0) {
        $bits[] = 'removed ' . (int) $clean['dup_paste'] . ' paste duplicates';
    }
    if ((int) $clean['dup_db'] > 0) {
        $bits[] = 'removed ' . (int) $clean['dup_db'] . ' already in database';
    }
    return 'Clean list: ' . implode(' · ', $bits) . '.';
}

/** @deprecated Prefer is_plain_site_domain / parse_plain_site_list for new input. */
function normalize_domain(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    if (is_plain_site_domain($value)) {
        return $value;
    }
    // Legacy cleanup for old stored/imported values only
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = preg_replace('#^www\.#i', '', $value) ?? $value;
    $host = explode('/', $value, 2)[0];
    $host = explode('?', $host, 2)[0];
    $host = explode('#', $host, 2)[0];
    if (str_contains($host, ':') && !str_contains($host, ']')) {
        $host = explode(':', $host, 2)[0];
    }
    $host = rtrim($host, '.');
    return is_plain_site_domain($host) ? $host : '';
}

/**
 * Ensure prospect tables exist (Hostinger safety net if upgrade.php was skipped).
 * Global uniqueness: one domain exists only once (any country).
 * Country still decides which folder new sites are saved into.
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
          UNIQUE KEY uniq_prospect_domain (domain),
          INDEX (country),
          INDEX (language),
          INDEX (region),
          INDEX (status),
          CONSTRAINT fk_prospect_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Migrate to global unique(domain): resolve cross-country dupes, swap indexes
    try {
        $idx = $pdo->query('SHOW INDEX FROM prospect_sites')->fetchAll(PDO::FETCH_ASSOC);
        $hasGlobal = false;
        $hasPerCountry = false;
        foreach ($idx as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === 'uniq_prospect_domain') {
                $hasGlobal = true;
            }
            if ($name === 'uniq_prospect_country_domain') {
                $hasPerCountry = true;
            }
        }
        if ($hasPerCountry || !$hasGlobal) {
            resolve_cross_country_domain_duplicates($pdo);
        }
        if ($hasPerCountry) {
            $pdo->exec('ALTER TABLE prospect_sites DROP INDEX uniq_prospect_country_domain');
        }
        if (!$hasGlobal) {
            $pdo->exec('ALTER TABLE prospect_sites ADD UNIQUE KEY uniq_prospect_domain (domain)');
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
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_site_trash (
          trash_id INT AUTO_INCREMENT PRIMARY KEY,
          undo_token CHAR(32) NOT NULL,
          original_id INT NULL,
          domain VARCHAR(255) NOT NULL,
          url VARCHAR(500) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          niche VARCHAR(255) NOT NULL DEFAULT '',
          notes TEXT NULL,
          status ENUM('new','contacting','replied','skipped') NOT NULL DEFAULT 'new',
          created_by INT NULL,
          site_created_at DATETIME NULL,
          site_updated_at DATETIME NULL,
          deleted_by INT NULL,
          deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (undo_token),
          INDEX (country),
          INDEX (domain),
          INDEX (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Before enforcing global UNIQUE(domain), keep the oldest row per domain
 * and remove later copies from other countries.
 */
function resolve_cross_country_domain_duplicates(PDO $pdo): int
{
    $domains = $pdo->query(
        'SELECT domain FROM prospect_sites GROUP BY domain HAVING COUNT(*) > 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$domains) {
        return 0;
    }
    $removed = 0;
    $sel = $pdo->prepare(
        'SELECT id FROM prospect_sites WHERE domain=? ORDER BY created_at ASC, id ASC'
    );
    foreach ($domains as $domain) {
        $sel->execute([(string) $domain]);
        $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) < 2) {
            continue;
        }
        array_shift($ids); // keep oldest
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $del = $pdo->prepare("DELETE FROM prospect_sites WHERE id IN ($ph)");
            $del->execute($chunk);
            $removed += $del->rowCount();
        }
    }
    return $removed;
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
    return parse_plain_site_list($raw)['domains'];
}

/**
 * Check domains against Our database (global uniqueness).
 * A domain exists only once across all countries.
 * $country is ignored (kept for call-site compatibility).
 *
 * @return array{existing:string[],new:string[],invalid:int,total_input:int}
 */
function filter_domains_against_prospects(array $domains, string $country = ''): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $clean = [];
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if (is_plain_site_domain($d)) {
            $clean[$d] = true;
        } else {
            $n = normalize_domain((string) $d);
            if ($n !== '' && is_plain_site_domain($n)) {
                $clean[$n] = true;
            }
        }
    }
    $domains = array_keys($clean);
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
        $sql = "SELECT domain FROM prospect_sites WHERE domain IN ($placeholders)";
        $stmt = db()->prepare($sql);
        $stmt->execute($chunk);
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
function list_prospect_domain_names(int $maxDisplay = 100000, string $country = ''): array
{
    ensure_prospect_schema();
    $country = trim($country);
    $maxDisplay = max(1, min(150000, $maxDisplay));
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
    $clean = [];
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if (is_plain_site_domain($d)) {
            $clean[$d] = true;
        }
    }
    $domains = array_keys($clean);
    $check = filter_domains_against_prospects($domains, '');
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
 * Admin: paste sites into a country folder.
 * Auto-cleans bad lines and skips sites already in Our database (any country).
 *
 * @return array{
 *   inserted:int,
 *   skipped_existing:int,
 *   total:int,
 *   batch_id:int|null,
 *   country:string,
 *   clean:array,
 *   text:string
 * }
 */
function admin_add_sites_to_database(string $raw, array $user, string $country, string $language = ''): array
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

    // Fix/drop bad lines + remove paste & DB duplicates
    $clean = clean_site_list($raw, $country, true);
    $domains = $clean['domains'];
    if ($domains === []) {
        return [
            'inserted' => 0,
            'skipped_existing' => (int) $clean['dup_db'],
            'total' => 0,
            'batch_id' => null,
            'country' => $country,
            'clean' => $clean,
            'text' => $clean['text'],
        ];
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
         VALUES (?,\'\',?,?,?,\'\',\'\',\'new\',?)'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );

    $inserted = 0;
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($domains as $domain) {
            try {
                $ins->execute([$domain, $country, $language, $region, $user['id']]);
                $siteId = (int) db()->lastInsertId();
                $insItem->execute([$batchId, $domain, $siteId ?: null]);
                $inserted++;
            } catch (PDOException $e) {
                // race / already exists — skip
            }
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
        'skipped_existing' => (int) $clean['dup_db'],
        'total' => $inserted,
        'batch_id' => $batchId,
        'country' => $country,
        'clean' => $clean,
        'text' => $clean['text'],
    ];
}

/** @deprecated use admin_add_sites_to_database */
function admin_add_urls_to_database(string $raw, array $user, string $country, string $language = ''): array
{
    return admin_add_sites_to_database($raw, $user, $country, $language);
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

/** Allowed page sizes for country site tables (large lists). */
function prospect_per_page_choices(): array
{
    return [50, 100, 250, 500, 1000];
}

function normalize_prospect_per_page(int $per): int
{
    return in_array($per, prospect_per_page_choices(), true) ? $per : 100;
}

/**
 * Build WHERE for listing/exporting one country’s sites.
 *
 * @return array{0:string,1:list<mixed>}
 */
function prospect_country_where(string $countryKey, string $q = '', string $status = ''): array
{
    $emptyCountry = ($countryKey === '_none');
    $where = [];
    $params = [];
    if ($emptyCountry) {
        $where[] = "TRIM(p.country)=''";
    } else {
        $where[] = 'TRIM(p.country)=?';
        $params[] = $countryKey;
    }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.domain LIKE ? OR p.url LIKE ? OR p.niche LIKE ? OR p.notes LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '') {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    return [implode(' AND ', $where), $params];
}

/**
 * Plain domain list for “view all names” (ordered A–Z).
 *
 * @return array{domains:string[],total:int,truncated:bool}
 */
function list_prospect_domains_plain(
    string $countryKey,
    string $q = '',
    string $status = '',
    int $max = 150000
): array {
    ensure_prospect_schema();
    [$whereSql, $params] = prospect_country_where($countryKey, $q, $status);
    $count = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $max = max(1, min(200000, $max));
    $stmt = db()->prepare(
        "SELECT p.domain FROM prospect_sites p WHERE $whereSql ORDER BY p.domain ASC LIMIT " . (int) $max
    );
    $stmt->execute($params);
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return [
        'domains' => $domains,
        'total' => $total,
        'truncated' => $total > count($domains),
    ];
}

/**
 * Download all matching domains as a .txt file (one per line). Best for 100k+ lists.
 */
function stream_prospect_domains_export(string $countryKey, string $q = '', string $status = ''): void
{
    ensure_prospect_schema();
    @set_time_limit(0);
    [$whereSql, $params] = prospect_country_where($countryKey, $q, $status);

    $label = $countryKey === '_none' ? 'no-country' : $countryKey;
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $label) ?: 'country';
    $filename = 'sites-' . $safe . '-' . date('Y-m-d') . '.txt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $stmt = db()->prepare(
        "SELECT p.domain FROM prospect_sites p WHERE $whereSql ORDER BY p.domain ASC"
    );
    $stmt->execute($params);
    while ($domain = $stmt->fetchColumn()) {
        echo $domain, "\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
    exit;
}

function count_prospect_sites_filtered(string $countryKey, string $q = '', string $status = ''): int
{
    ensure_prospect_schema();
    [$whereSql, $params] = prospect_country_where($countryKey, $q, $status);
    $stmt = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * IDs matching country + keyword/status filter (capped for select-all).
 *
 * @return list<int>
 */
function list_prospect_ids_filtered(string $countryKey, string $q = '', string $status = '', int $limit = 1000): array
{
    ensure_prospect_schema();
    $limit = max(1, min(1000, $limit));
    [$whereSql, $params] = prospect_country_where($countryKey, $q, $status);
    $stmt = db()->prepare(
        "SELECT p.id FROM prospect_sites p WHERE $whereSql ORDER BY p.domain ASC LIMIT " . (int) $limit
    );
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Move rows into trash then delete from live table. Returns undo token.
 *
 * @param list<array<string,mixed>> $rows full prospect_sites rows
 * @return array{deleted:int,undo_token:string}
 */
function trash_and_delete_prospect_rows(array $rows, int $deletedBy = 0): array
{
    ensure_prospect_schema();
    if ($rows === []) {
        return ['deleted' => 0, 'undo_token' => ''];
    }
    $token = bin2hex(random_bytes(16));
    $ins = db()->prepare(
        'INSERT INTO prospect_site_trash
           (undo_token, original_id, domain, url, country, language, region, niche, notes, status,
            created_by, site_created_at, site_updated_at, deleted_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $ids = [];
    db()->beginTransaction();
    try {
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $ins->execute([
                $token,
                $id,
                (string) ($row['domain'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['country'] ?? ''),
                (string) ($row['language'] ?? ''),
                (string) ($row['region'] ?? ''),
                (string) ($row['niche'] ?? ''),
                $row['notes'] ?? null,
                (string) ($row['status'] ?? 'new'),
                $row['created_by'] !== null && $row['created_by'] !== '' ? (int) $row['created_by'] : null,
                $row['created_at'] ?? null,
                $row['updated_at'] ?? null,
                $deletedBy > 0 ? $deletedBy : null,
            ]);
        }
        $ids = array_values(array_unique($ids));
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $del = db()->prepare("DELETE FROM prospect_sites WHERE id IN ($ph)");
            $del->execute($chunk);
            $deleted += $del->rowCount();
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return ['deleted' => $deleted, 'undo_token' => $token];
}

/**
 * Restore sites from a trash undo token (skips domain+country that already exist).
 *
 * @return array{restored:int,skipped:int}
 */
function undo_prospect_delete(string $token): array
{
    ensure_prospect_schema();
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return ['restored' => 0, 'skipped' => 0];
    }
    $stmt = db()->prepare('SELECT * FROM prospect_site_trash WHERE undo_token=? ORDER BY trash_id');
    $stmt->execute([$token]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return ['restored' => 0, 'skipped' => 0];
    }

    $ins = db()->prepare(
        'INSERT INTO prospect_sites
           (domain, url, country, language, region, niche, notes, status, created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $restored = 0;
    $skipped = 0;
    $trashIds = [];
    db()->beginTransaction();
    try {
        foreach ($rows as $row) {
            $domain = (string) $row['domain'];
            $country = (string) $row['country'];
            $exists = db()->prepare(
                'SELECT id FROM prospect_sites WHERE TRIM(country)=? AND domain=? LIMIT 1'
            );
            $exists->execute([$country, $domain]);
            if ($exists->fetchColumn()) {
                $skipped++;
                $trashIds[] = (int) $row['trash_id'];
                continue;
            }
            $createdAt = $row['site_created_at'] ?: date('Y-m-d H:i:s');
            try {
                $ins->execute([
                    $domain,
                    (string) ($row['url'] ?? ''),
                    $country,
                    (string) ($row['language'] ?? ''),
                    (string) ($row['region'] ?? ''),
                    (string) ($row['niche'] ?? ''),
                    $row['notes'] ?? '',
                    (string) ($row['status'] ?? 'new'),
                    $row['created_by'] !== null && $row['created_by'] !== '' ? (int) $row['created_by'] : null,
                    $createdAt,
                ]);
                $restored++;
            } catch (PDOException $e) {
                $skipped++;
            }
            $trashIds[] = (int) $row['trash_id'];
        }
        if ($trashIds) {
            foreach (array_chunk($trashIds, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                db()->prepare("DELETE FROM prospect_site_trash WHERE trash_id IN ($ph)")->execute($chunk);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return ['restored' => $restored, 'skipped' => $skipped];
}

/**
 * Recent delete batches for a country (for Undo / restore UI).
 *
 * @return list<array{undo_token:string,deleted_at:string,site_count:int}>
 */
function list_prospect_trash_batches(string $countryKey, int $limit = 10): array
{
    ensure_prospect_schema();
    $limit = max(1, min(50, $limit));
    if ($countryKey === '_none') {
        $sql = "SELECT undo_token, MAX(deleted_at) AS deleted_at, COUNT(*) AS site_count
                FROM prospect_site_trash WHERE TRIM(country)=''
                GROUP BY undo_token ORDER BY deleted_at DESC LIMIT $limit";
        $stmt = db()->query($sql);
    } else {
        $sql = "SELECT undo_token, MAX(deleted_at) AS deleted_at, COUNT(*) AS site_count
                FROM prospect_site_trash WHERE TRIM(country)=?
                GROUP BY undo_token ORDER BY deleted_at DESC LIMIT $limit";
        $stmt = db()->prepare($sql);
        $stmt->execute([$countryKey]);
    }
    return $stmt->fetchAll();
}

/**
 * Delete selected site IDs within one country (max 1000). Keeps trash for Undo.
 *
 * @param list<int|string> $ids
 * @return array{deleted:int,undo_token:string}
 */
function delete_prospect_sites_by_ids(array $ids, string $countryKey, int $deletedBy = 0): array
{
    ensure_prospect_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if ($ids === []) {
        return ['deleted' => 0, 'undo_token' => ''];
    }
    if (count($ids) > 1000) {
        $ids = array_slice($ids, 0, 1000);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if ($countryKey === '_none') {
        $sql = "SELECT * FROM prospect_sites WHERE id IN ($placeholders) AND TRIM(country)=''";
        $params = $ids;
    } else {
        $sql = "SELECT * FROM prospect_sites WHERE id IN ($placeholders) AND TRIM(country)=?";
        $params = array_merge($ids, [$countryKey]);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return trash_and_delete_prospect_rows($rows, $deletedBy);
}

/**
 * Delete root domains from one country database (with Undo trash).
 *
 * @param list<string> $domains
 * @return array{deleted:int,undo_token:string}
 */
function delete_prospect_sites_by_domains(array $domains, string $countryKey, int $deletedBy = 0): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $clean = [];
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if ($d === '') {
            continue;
        }
        $root = is_plain_site_domain($d) ? $d : extract_root_domain_candidate($d);
        if ($root !== '') {
            $clean[$root] = true;
        }
    }
    $list = array_keys($clean);
    if ($list === []) {
        return ['deleted' => 0, 'undo_token' => ''];
    }

    $rows = [];
    foreach (array_chunk($list, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        if ($countryKey === '_none') {
            $sql = "SELECT * FROM prospect_sites WHERE TRIM(country)='' AND domain IN ($placeholders)";
            $params = $chunk;
        } else {
            $sql = "SELECT * FROM prospect_sites WHERE TRIM(country)=? AND domain IN ($placeholders)";
            $params = array_merge([$countryKey], $chunk);
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = $row;
        }
    }
    return trash_and_delete_prospect_rows($rows, $deletedBy);
}

function prospect_inventory_query(array $filters, int $pageNum = 1, int $per = 100): array
{
    ensure_prospect_schema();
    $per = normalize_prospect_per_page($per);
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.domain LIKE ? OR p.niche LIKE ? OR p.notes LIKE ? OR p.url LIKE ?)';
        array_push($params, $like, $like, $like, $like);
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
         WHERE $whereSql ORDER BY p.domain ASC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
        'per' => $per,
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

