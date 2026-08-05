<?php

/**
 * Global country catalog — company-wide website inventory by country folder.
 * Separate from project `sites` (used for pitching / Send pack).
 * Unique on (country, domain). Plain PHP + MySQL.
 */

function ensure_country_catalog_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS country_catalog_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          url VARCHAR(500) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          niche VARCHAR(255) NOT NULL DEFAULT '',
          da INT NULL,
          dr INT NULL,
          traffic INT NULL,
          publisher_quote_price DECIMAL(12,2) NULL,
          backlink_price DECIMAL(12,2) NULL,
          currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
          status VARCHAR(40) NOT NULL DEFAULT 'draft',
          order_status VARCHAR(40) NOT NULL DEFAULT '',
          inventory_client_name VARCHAR(255) NOT NULL DEFAULT '',
          admin_comments TEXT NULL,
          our_mailbox VARCHAR(190) NOT NULL DEFAULT '',
          our_contact_name VARCHAR(150) NOT NULL DEFAULT '',
          created_by INT NULL,
          updated_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_country_catalog_domain (country, domain),
          INDEX (country),
          INDEX (domain),
          INDEX (status),
          INDEX (order_status),
          INDEX (region),
          CONSTRAINT fk_ccs_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT fk_ccs_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // If catalog empty but project sites exist, seed once (Hostinger safety if upgrade skipped)
    try {
        $catCount = (int) db()->query('SELECT COUNT(*) FROM country_catalog_sites')->fetchColumn();
        $siteCount = (int) db()->query('SELECT COUNT(*) FROM sites')->fetchColumn();
        if ($catCount === 0 && $siteCount > 0) {
            migrate_sites_into_country_catalog();
        }
    } catch (Throwable $e) {
        // sites table may not exist yet during fresh install
    }
}

/**
 * One-time-ish migrate from project sites into country catalog (idempotent upsert).
 */
function migrate_sites_into_country_catalog(): int
{
    ensure_country_catalog_schema();
    // Prefer row with richest metrics when same country+domain appears in multiple projects
    $sql = "INSERT INTO country_catalog_sites
        (domain, url, country, language, region, niche, da, dr, traffic,
         publisher_quote_price, backlink_price, currency, status, order_status,
         inventory_client_name, admin_comments, our_mailbox, our_contact_name,
         created_by, updated_by)
      SELECT
        s.domain,
        MAX(s.url),
        TRIM(s.country),
        MAX(s.language),
        MAX(s.region),
        MAX(s.niche),
        MAX(s.da),
        MAX(s.dr),
        MAX(s.traffic),
        MAX(s.publisher_quote_price),
        MAX(s.backlink_price),
        COALESCE(MAX(NULLIF(s.currency,'')), 'EUR'),
        COALESCE(MAX(NULLIF(s.status,'')), 'draft'),
        MAX(s.order_status),
        MAX(s.inventory_client_name),
        MAX(s.admin_comments),
        MAX(s.our_mailbox),
        MAX(s.our_contact_name),
        MAX(s.created_by),
        MAX(s.created_by)
      FROM sites s
      WHERE TRIM(COALESCE(s.country,'')) <> '' AND TRIM(s.domain) <> ''
      GROUP BY TRIM(s.country), s.domain
      ON DUPLICATE KEY UPDATE
        url = IF(VALUES(url) <> '', VALUES(url), country_catalog_sites.url),
        language = IF(VALUES(language) <> '', VALUES(language), country_catalog_sites.language),
        region = IF(VALUES(region) <> '', VALUES(region), country_catalog_sites.region),
        niche = IF(VALUES(niche) <> '', VALUES(niche), country_catalog_sites.niche),
        da = COALESCE(VALUES(da), country_catalog_sites.da),
        dr = COALESCE(VALUES(dr), country_catalog_sites.dr),
        traffic = COALESCE(VALUES(traffic), country_catalog_sites.traffic),
        publisher_quote_price = COALESCE(VALUES(publisher_quote_price), country_catalog_sites.publisher_quote_price),
        backlink_price = COALESCE(VALUES(backlink_price), country_catalog_sites.backlink_price),
        order_status = IF(VALUES(order_status) <> '', VALUES(order_status), country_catalog_sites.order_status),
        inventory_client_name = IF(VALUES(inventory_client_name) <> '', VALUES(inventory_client_name), country_catalog_sites.inventory_client_name),
        admin_comments = COALESCE(VALUES(admin_comments), country_catalog_sites.admin_comments),
        our_mailbox = IF(VALUES(our_mailbox) <> '', VALUES(our_mailbox), country_catalog_sites.our_mailbox),
        our_contact_name = IF(VALUES(our_contact_name) <> '', VALUES(our_contact_name), country_catalog_sites.our_contact_name),
        updated_at = CURRENT_TIMESTAMP";
    try {
        $stmt = db()->query($sql);
        return (int) $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Country picker groups: Europe / North America / English markets (+ other + sheet extras).
 *
 * @return array<string, array{label:string, countries:list<array}>
 */
function country_catalog_countries_grouped(): array
{
    ensure_country_catalog_schema();
    if (function_exists('seed_countries_if_empty')) {
        seed_countries_if_empty(db());
    }
    $grouped = countries_grouped();
    $order = ['europe', 'north_america', 'english', 'other'];
    $ordered = [];
    foreach ($order as $code) {
        if (isset($grouped[$code])) {
            $ordered[$code] = $grouped[$code];
            unset($grouped[$code]);
        }
    }
    foreach ($grouped as $code => $block) {
        $ordered[$code] = $block;
    }
    $known = [];
    foreach ($ordered as $block) {
        foreach ($block['countries'] as $c) {
            $known[strtolower(trim((string) $c['name']))] = true;
        }
    }
    foreach (country_catalog_sheets() as $sheet) {
        $name = trim((string) $sheet['country']);
        if ($name === '' || isset($known[strtolower($name)])) {
            continue;
        }
        if (!isset($ordered['other'])) {
            $ordered['other'] = ['label' => 'Other', 'countries' => []];
        }
        $ordered['other']['countries'][] = [
            'name' => $name,
            'region' => 'other',
            'code' => '',
            'default_language' => '',
        ];
        $known[strtolower($name)] = true;
    }
    return $ordered;
}

/**
 * @return list<array{country:string,total:int}>
 */
function country_catalog_sheets(): array
{
    ensure_country_catalog_schema();
    $rows = db()->query(
        "SELECT TRIM(country) AS country, COUNT(*) AS total
         FROM country_catalog_sites
         GROUP BY TRIM(country)
         ORDER BY CASE WHEN TRIM(country)='' THEN 1 ELSE 0 END, country"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'country' => (string) ($r['country'] ?? ''),
            'total' => (int) $r['total'],
        ];
    }
    return $out;
}

/**
 * Folder list for Admin Catalog: all active countries + counts (0 if empty).
 *
 * @return list<array{country:string,region:string,region_label:string,total:int}>
 */
function country_catalog_folder_list(): array
{
    ensure_country_catalog_schema();
    if (function_exists('seed_countries_if_empty')) {
        seed_countries_if_empty(db());
    }
    $counts = [];
    foreach (country_catalog_sheets() as $s) {
        $counts[strtolower($s['country'])] = $s['total'];
    }
    $folders = [];
    foreach (country_catalog_countries_grouped() as $regionCode => $block) {
        foreach ($block['countries'] as $c) {
            $name = (string) $c['name'];
            $folders[] = [
                'country' => $name,
                'region' => (string) ($c['region'] ?? $regionCode),
                'region_label' => (string) $block['label'],
                'total' => (int) ($counts[strtolower($name)] ?? 0),
            ];
        }
    }
    // Extra sheets not in countries table
    foreach (country_catalog_sheets() as $s) {
        $name = $s['country'];
        if ($name === '') {
            $folders[] = [
                'country' => '',
                'region' => 'other',
                'region_label' => 'Other',
                'total' => $s['total'],
            ];
            continue;
        }
        $found = false;
        foreach ($folders as $f) {
            if (strcasecmp($f['country'], $name) === 0) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $folders[] = [
                'country' => $name,
                'region' => 'other',
                'region_label' => 'Other',
                'total' => $s['total'],
            ];
        }
    }
    return $folders;
}

function country_catalog_query(array $filters, int $pageNum = 1, int $per = 50): array
{
    ensure_country_catalog_schema();
    $where = ['1=1'];
    $params = [];
    $country = trim((string) ($filters['country'] ?? ''));
    if (!empty($filters['empty_country'])) {
        $where[] = "(TRIM(COALESCE(c.country,'')) = '')";
    } elseif ($country !== '') {
        $where[] = 'TRIM(c.country) = ?';
        $params[] = $country;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(c.domain LIKE ? OR c.url LIKE ? OR c.inventory_client_name LIKE ?
                     OR c.admin_comments LIKE ? OR c.our_mailbox LIKE ? OR c.niche LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['status'])) {
        $where[] = 'c.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['order_status'])) {
        $where[] = 'c.order_status = ?';
        $params[] = $filters['order_status'];
    }
    if (!empty($filters['region'])) {
        $where[] = 'c.region = ?';
        $params[] = $filters['region'];
    }
    if (!empty($filters['language'])) {
        $where[] = 'c.language = ?';
        $params[] = $filters['language'];
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM country_catalog_sites c WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT c.* FROM country_catalog_sites c
         WHERE $whereSql ORDER BY c.domain ASC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / max(1, $per))),
        'page' => $pageNum,
    ];
}

function country_catalog_get(int $id): ?array
{
    ensure_country_catalog_schema();
    $s = db()->prepare('SELECT * FROM country_catalog_sites WHERE id=?');
    $s->execute([$id]);
    $row = $s->fetch();
    return $row ?: null;
}

function country_catalog_find(string $country, string $domain): ?array
{
    ensure_country_catalog_schema();
    $domain = normalize_domain($domain);
    $country = trim($country);
    if ($domain === '' || $country === '') {
        return null;
    }
    $s = db()->prepare(
        'SELECT * FROM country_catalog_sites WHERE TRIM(country)=? AND domain=? LIMIT 1'
    );
    $s->execute([$country, $domain]);
    $row = $s->fetch();
    return $row ?: null;
}

/**
 * Upsert one catalog row. Returns ['id'=>int,'inserted'=>bool].
 *
 * @param array $data field map
 */
function country_catalog_save(array $data, array $user): array
{
    ensure_country_catalog_schema();
    $country = trim((string) ($data['country'] ?? ''));
    $domain = normalize_domain((string) ($data['domain'] ?? ''));
    if ($country === '' || $domain === '') {
        throw new InvalidArgumentException('Country and domain are required.');
    }
    $validStatus = array_keys(site_statuses());
    $validOrder = array_keys(inventory_order_statuses());
    $status = strtolower(trim((string) ($data['status'] ?? 'draft')));
    if (!in_array($status, $validStatus, true)) {
        $status = 'draft';
    }
    $orderStatus = strtolower(trim((string) ($data['order_status'] ?? '')));
    if ($orderStatus !== '' && !in_array($orderStatus, $validOrder, true)) {
        $orderStatus = '';
    }
    $num = static function ($v) {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? $v : null;
    };
    $existing = country_catalog_find($country, $domain);
    $fields = [
        'url' => trim((string) ($data['url'] ?? '')),
        'language' => trim((string) ($data['language'] ?? '')),
        'region' => trim((string) ($data['region'] ?? '')),
        'niche' => trim((string) ($data['niche'] ?? '')),
        'da' => $num($data['da'] ?? null),
        'dr' => $num($data['dr'] ?? null),
        'traffic' => $num($data['traffic'] ?? null),
        'publisher_quote_price' => $num($data['publisher_quote_price'] ?? null),
        'backlink_price' => $num($data['backlink_price'] ?? null),
        'currency' => trim((string) ($data['currency'] ?? '')) ?: 'EUR',
        'status' => $status,
        'order_status' => $orderStatus,
        'inventory_client_name' => trim((string) ($data['inventory_client_name'] ?? $data['client_name'] ?? '')),
        'admin_comments' => trim((string) ($data['admin_comments'] ?? '')),
        'our_mailbox' => trim((string) ($data['our_mailbox'] ?? '')),
        'our_contact_name' => trim((string) ($data['our_contact_name'] ?? '')),
    ];
    if ($fields['url'] === '' && $domain !== '') {
        $fields['url'] = 'https://' . $domain;
    }
    $uid = (int) ($user['id'] ?? 0) ?: null;

    if ($existing) {
        $sql = 'UPDATE country_catalog_sites SET
            url=?, language=?, region=?, niche=?, da=?, dr=?, traffic=?,
            publisher_quote_price=?, backlink_price=?, currency=?, status=?, order_status=?,
            inventory_client_name=?, admin_comments=?, our_mailbox=?, our_contact_name=?,
            updated_by=?, updated_at=NOW()
            WHERE id=?';
        db()->prepare($sql)->execute([
            $fields['url'], $fields['language'], $fields['region'], $fields['niche'],
            $fields['da'], $fields['dr'], $fields['traffic'],
            $fields['publisher_quote_price'], $fields['backlink_price'], $fields['currency'],
            $fields['status'], $fields['order_status'],
            $fields['inventory_client_name'], $fields['admin_comments'],
            $fields['our_mailbox'], $fields['our_contact_name'],
            $uid, (int) $existing['id'],
        ]);
        return ['id' => (int) $existing['id'], 'inserted' => false];
    }

    db()->prepare(
        'INSERT INTO country_catalog_sites
         (domain, country, url, language, region, niche, da, dr, traffic,
          publisher_quote_price, backlink_price, currency, status, order_status,
          inventory_client_name, admin_comments, our_mailbox, our_contact_name,
          created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $domain, $country, $fields['url'], $fields['language'], $fields['region'], $fields['niche'],
        $fields['da'], $fields['dr'], $fields['traffic'],
        $fields['publisher_quote_price'], $fields['backlink_price'], $fields['currency'],
        $fields['status'], $fields['order_status'],
        $fields['inventory_client_name'], $fields['admin_comments'],
        $fields['our_mailbox'], $fields['our_contact_name'],
        $uid, $uid,
    ]);
    return ['id' => (int) db()->lastInsertId(), 'inserted' => true];
}

/**
 * Team quick-add into a country (domain required; metrics optional — Admin fills later).
 */
function add_domain_to_country_catalog(
    string $country,
    string $domain,
    array $user,
    string $language = '',
    string $region = '',
    string $niche = '',
    string $notes = ''
): array {
    $country = trim($country);
    $domain = normalize_domain($domain);
    if ($country === '' || $domain === '') {
        return ['ok' => false, 'error' => 'Country and domain are required.', 'id' => 0];
    }
    if (country_catalog_find($country, $domain)) {
        return ['ok' => false, 'error' => 'Already in this country catalog.', 'id' => 0];
    }
    $res = country_catalog_save([
        'country' => $country,
        'domain' => $domain,
        'language' => $language,
        'region' => $region,
        'niche' => $niche,
        'admin_comments' => $notes,
        'status' => 'draft',
    ], $user);
    return ['ok' => true, 'error' => '', 'id' => $res['id']];
}

/**
 * Team lookup in one country catalog (+ Our inventory note).
 *
 * @return array{domain:string,country:string,catalog:?array,in_catalog:bool,in_inventory:bool,inventory:?array}
 */
function lookup_domain_in_country_catalog(string $q, string $country): array
{
    ensure_country_catalog_schema();
    $country = trim($country);
    $domain = normalize_domain($q);
    $catalog = $domain !== '' && $country !== '' ? country_catalog_find($country, $domain) : null;
    $inventory = null;
    $inInventory = false;
    if ($domain !== '' && function_exists('ensure_prospect_schema')) {
        ensure_prospect_schema();
        $p = db()->prepare('SELECT * FROM prospect_sites WHERE domain=? LIMIT 1');
        $p->execute([$domain]);
        $inventory = $p->fetch() ?: null;
        $inInventory = (bool) $inventory;
    }
    return [
        'domain' => $domain,
        'country' => $country,
        'catalog' => $catalog,
        'in_catalog' => (bool) $catalog,
        'in_inventory' => $inInventory,
        'inventory' => $inventory,
    ];
}

/**
 * Bulk CSV into a country catalog sheet.
 *
 * @return array{inserted:int,updated:int,skipped:int,errors:string[]}
 */
function bulk_import_country_catalog_csv(string $country, string $tmpPath, int $createdBy): array
{
    ensure_country_catalog_schema();
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $country = trim($country);
    if ($country === '') {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Country is required.']];
    }
    $fh = fopen($tmpPath, 'rb');
    if (!$fh) {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Could not open CSV file.']];
    }
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Empty CSV.']];
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $header = str_getcsv($first);
    $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
    $map = [];
    foreach ($header as $i => $name) {
        $map[$name] = $i;
    }
    if (!isset($map['domain'])) {
        fclose($fh);
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['CSV must include a domain column.']];
    }
    $validOrder = array_keys(inventory_order_statuses());
    $validStatus = array_keys(site_statuses());
    $user = ['id' => $createdBy];
    $cell = static function (array $row, array $map, string $key): string {
        if (!isset($map[$key])) {
            return '';
        }
        return isset($row[$map[$key]]) ? trim((string) $row[$map[$key]]) : '';
    };
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $line = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if ($row === [null] || $row === false) {
            continue;
        }
        if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $domain = normalize_domain($cell($row, $map, 'domain'));
        if ($domain === '') {
            $skipped++;
            if (count($errors) < 30) {
                $errors[] = "Line {$line}: missing domain";
            }
            continue;
        }
        // CSV country column can override only if empty target? Plan: import INTO selected country.
        $rowCountry = $country;
        $orderStatus = strtolower($cell($row, $map, 'order_status'));
        if ($orderStatus !== '' && !in_array($orderStatus, $validOrder, true)) {
            $orderStatus = '';
        }
        $status = strtolower($cell($row, $map, 'status') ?: 'draft');
        if (!in_array($status, $validStatus, true)) {
            $status = 'draft';
        }
        $comments = $cell($row, $map, 'admin_comments');
        if ($comments === '') {
            $comments = $cell($row, $map, 'comments');
        }
        try {
            $before = country_catalog_find($rowCountry, $domain);
            country_catalog_save([
                'country' => $rowCountry,
                'domain' => $domain,
                'url' => $cell($row, $map, 'url'),
                'language' => $cell($row, $map, 'language'),
                'region' => $cell($row, $map, 'region'),
                'niche' => $cell($row, $map, 'niche'),
                'da' => $cell($row, $map, 'da'),
                'dr' => $cell($row, $map, 'dr'),
                'traffic' => $cell($row, $map, 'traffic'),
                'publisher_quote_price' => $cell($row, $map, 'publisher_quote_price'),
                'backlink_price' => $cell($row, $map, 'backlink_price'),
                'currency' => $cell($row, $map, 'currency') ?: 'EUR',
                'status' => $status,
                'order_status' => $orderStatus,
                'client_name' => $cell($row, $map, 'client_name'),
                'admin_comments' => $comments,
                'our_mailbox' => $cell($row, $map, 'our_mailbox'),
                'our_contact_name' => $cell($row, $map, 'our_contact_name'),
            ], $user);
            if ($before) {
                $updated++;
            } else {
                $inserted++;
            }
        } catch (Throwable $e) {
            $skipped++;
            if (count($errors) < 30) {
                $errors[] = "Line {$line} ({$domain}): " . $e->getMessage();
            }
        }
    }
    fclose($fh);
    return compact('inserted', 'updated', 'skipped', 'errors');
}
