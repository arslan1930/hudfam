<?php

/**
 * Website prices — publisher rate book (Office).
 * One country sheet. Identity (domain / DA / DR / traffic) locks after save (later PR).
 * Does not write into Our database.
 */

function site_price_builtin_statuses(): array
{
    return [
        [
            'slug' => 'new',
            'label' => 'New',
            'color' => 'green',
            'lane' => 'new',
            'sort_order' => 10,
        ],
        [
            'slug' => 'processing',
            'label' => 'Processing',
            'color' => 'blue',
            'lane' => 'processing',
            'sort_order' => 20,
        ],
        [
            'slug' => 'already_working',
            'label' => 'Already working',
            'color' => 'rose',
            'lane' => 'other',
            'sort_order' => 30,
        ],
        [
            'slug' => 'ok',
            'label' => 'OK',
            'color' => 'grey',
            'lane' => 'other',
            'sort_order' => 40,
        ],
        [
            'slug' => 'very_high_price',
            'label' => 'Very high price',
            'color' => 'grey',
            'lane' => 'other',
            'sort_order' => 50,
        ],
        [
            'slug' => 'not_interested',
            'label' => 'Not interested',
            'color' => 'brown',
            'lane' => 'other',
            'sort_order' => 60,
        ],
        [
            'slug' => 'agreed',
            'label' => 'Agreed',
            'color' => 'teal',
            'lane' => 'other',
            'sort_order' => 70,
        ],
    ];
}

function ensure_site_prices_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_price_statuses (
          id INT AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(80) NOT NULL,
          label VARCHAR(120) NOT NULL,
          color VARCHAR(40) NOT NULL DEFAULT 'grey',
          lane ENUM('processing','new','other') NOT NULL DEFAULT 'other',
          is_builtin TINYINT(1) NOT NULL DEFAULT 0,
          sort_order INT NOT NULL DEFAULT 100,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_site_price_status_slug (slug),
          INDEX (lane),
          INDEX (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_price_rows (
          id INT AUTO_INCREMENT PRIMARY KEY,
          country VARCHAR(100) NOT NULL,
          domain VARCHAR(255) NOT NULL,
          niche VARCHAR(512) NOT NULL DEFAULT '',
          da VARCHAR(40) NOT NULL DEFAULT '',
          dr VARCHAR(40) NOT NULL DEFAULT '',
          traffic VARCHAR(40) NOT NULL DEFAULT '',
          price_note TEXT NULL,
          extra_note VARCHAR(500) NOT NULL DEFAULT '',
          status_slug VARCHAR(80) NOT NULL DEFAULT 'new',
          sort_in_lane INT NOT NULL DEFAULT 0,
          identity_locked TINYINT(1) NOT NULL DEFAULT 0,
          created_by INT NULL,
          managed_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_site_price_country_domain (country, domain),
          INDEX (country),
          INDEX (status_slug),
          INDEX (created_by),
          INDEX (managed_by),
          INDEX (country, status_slug, sort_in_lane),
          CONSTRAINT fk_spr_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT fk_spr_managed FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_price_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          row_id INT NOT NULL,
          actor_id INT NULL,
          actor_role VARCHAR(20) NOT NULL DEFAULT '',
          kind VARCHAR(40) NOT NULL,
          old_value TEXT NULL,
          new_value TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (row_id, created_at),
          INDEX (actor_id),
          CONSTRAINT fk_spe_row FOREIGN KEY (row_id) REFERENCES site_price_rows(id) ON DELETE CASCADE,
          CONSTRAINT fk_spe_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    site_price_seed_statuses();
}

function site_price_seed_statuses(): void
{
    $pdo = db();
    $have = [];
    try {
        foreach ($pdo->query('SELECT slug FROM site_price_statuses')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $slug) {
            $have[strtolower((string) $slug)] = true;
        }
    } catch (Throwable $e) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT INTO site_price_statuses (slug, label, color, lane, is_builtin, sort_order)
         VALUES (?,?,?,?,1,?)'
    );
    foreach (site_price_builtin_statuses() as $st) {
        $key = strtolower((string) $st['slug']);
        if (isset($have[$key])) {
            continue;
        }
        $ins->execute([
            $st['slug'],
            $st['label'],
            $st['color'],
            $st['lane'],
            (int) $st['sort_order'],
        ]);
    }
}

/**
 * @return list<array<string,mixed>>
 */
function site_price_list_statuses(): array
{
    ensure_site_prices_schema();
    $rows = db()->query(
        'SELECT slug, label, color, lane, is_builtin, sort_order
         FROM site_price_statuses
         ORDER BY sort_order ASC, label ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $rows;
}

function site_price_status_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (site_price_list_statuses() as $st) {
        $map[strtolower((string) $st['slug'])] = $st;
    }
    return $map;
}

function site_price_status_lane(string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        $slug = 'new';
    }
    $st = site_price_status_map()[$slug] ?? null;
    if ($st && in_array((string) $st['lane'], ['processing', 'new', 'other'], true)) {
        return (string) $st['lane'];
    }
    if ($slug === 'processing') {
        return 'processing';
    }
    if ($slug === 'new') {
        return 'new';
    }
    return 'other';
}

function site_price_lane_rank(string $lane): int
{
    return match ($lane) {
        'processing' => 0,
        'new' => 1,
        default => 2,
    };
}

function site_price_normalize_domain(string $value): string
{
    if (function_exists('normalize_domain')) {
        return normalize_domain($value);
    }
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = preg_replace('#^www\.#i', '', $value) ?? $value;
    return rtrim(explode('/', $value, 2)[0], '.');
}

function site_price_lookup_niche(string $domain, string $country): string
{
    $domain = site_price_normalize_domain($domain);
    $country = trim($country);
    if ($domain === '' || $country === '') {
        return '';
    }
    try {
        $stmt = db()->prepare(
            'SELECT niche FROM prospect_sites WHERE country=? AND domain=? LIMIT 1'
        );
        $stmt->execute([$country, $domain]);
        $raw = trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
    if ($raw === '') {
        return '';
    }
    if (function_exists('prospect_format_niches') && function_exists('prospect_parse_niches')) {
        return prospect_format_niches(prospect_parse_niches($raw));
    }
    return $raw;
}

/**
 * Low-level insert used by tests and later by the sheet save.
 *
 * @param array{
 *   country:string,domain:string,niche?:string,da?:string,dr?:string,traffic?:string,
 *   price_note?:string,extra_note?:string,status_slug?:string,created_by?:int,managed_by?:int
 * } $fields
 */
function site_price_insert_row(array $fields): int
{
    ensure_site_prices_schema();
    $country = trim((string) ($fields['country'] ?? ''));
    $domain = site_price_normalize_domain((string) ($fields['domain'] ?? ''));
    if ($country === '' || $domain === '') {
        throw new InvalidArgumentException('Country and site name are required.');
    }
    $canon = function_exists('resolve_canonical_country') ? resolve_canonical_country($country) : null;
    if ($canon !== null) {
        $country = (string) $canon['name'];
    }
    $niche = trim((string) ($fields['niche'] ?? ''));
    if ($niche === '') {
        $niche = site_price_lookup_niche($domain, $country);
    } elseif (function_exists('prospect_format_niches') && function_exists('prospect_parse_niches')) {
        $niche = prospect_format_niches(prospect_parse_niches($niche));
    }
    $status = strtolower(trim((string) ($fields['status_slug'] ?? 'new')));
    if ($status === '' || !isset(site_price_status_map()[$status])) {
        $status = 'new';
    }
    try {
        db()->prepare(
            'INSERT INTO site_price_rows
              (country, domain, niche, da, dr, traffic, price_note, extra_note, status_slug, created_by, managed_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $country,
            $domain,
            $niche,
            trim((string) ($fields['da'] ?? '')),
            trim((string) ($fields['dr'] ?? '')),
            trim((string) ($fields['traffic'] ?? '')),
            trim((string) ($fields['price_note'] ?? '')),
            trim((string) ($fields['extra_note'] ?? '')),
            $status,
            ((int) ($fields['created_by'] ?? 0) > 0) ? (int) $fields['created_by'] : null,
            ((int) ($fields['managed_by'] ?? 0) > 0) ? (int) $fields['managed_by'] : null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new RuntimeException('That site is already on this country’s price sheet.');
        }
        throw $e;
    }
    return (int) db()->lastInsertId();
}

/**
 * @return list<array<string,mixed>>
 */
function site_price_country_counts(): array
{
    ensure_site_prices_schema();
    $rows = db()->query(
        'SELECT country, COUNT(*) AS total, MAX(updated_at) AS updated_at
         FROM site_price_rows
         GROUP BY country
         ORDER BY country ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $rows;
}

function count_site_price_rows(?string $country = null): int
{
    ensure_site_prices_schema();
    $country = trim((string) $country);
    if ($country === '') {
        return (int) db()->query('SELECT COUNT(*) FROM site_price_rows')->fetchColumn();
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM site_price_rows WHERE country=?');
    $stmt->execute([$country]);
    return (int) $stmt->fetchColumn();
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function site_price_sort_rows(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $laneA = site_price_status_lane((string) ($a['status_slug'] ?? 'new'));
        $laneB = site_price_status_lane((string) ($b['status_slug'] ?? 'new'));
        $rank = site_price_lane_rank($laneA) <=> site_price_lane_rank($laneB);
        if ($rank !== 0) {
            return $rank;
        }
        $sa = (int) ($a['sort_in_lane'] ?? 0);
        $sb = (int) ($b['sort_in_lane'] ?? 0);
        if ($sa !== 0 || $sb !== 0) {
            if ($sa === 0) {
                $sa = PHP_INT_MAX;
            }
            if ($sb === 0) {
                $sb = PHP_INT_MAX;
            }
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
        }
        $ta = (string) ($a['created_at'] ?? '');
        $tb = (string) ($b['created_at'] ?? '');
        if ($laneA === 'processing') {
            return $ta <=> $tb;
        }
        return $tb <=> $ta;
    });
    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function list_site_price_rows(string $country): array
{
    ensure_site_prices_schema();
    $country = trim($country);
    if ($country === '') {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT r.*,
                cu.username AS added_by_username, cu.full_name AS added_by_full, cu.role AS added_by_role,
                mu.username AS managed_by_username, mu.full_name AS managed_by_full
         FROM site_price_rows r
         LEFT JOIN users cu ON cu.id = r.created_by
         LEFT JOIN users mu ON mu.id = r.managed_by
         WHERE r.country=?
         ORDER BY r.id ASC'
    );
    $stmt->execute([$country]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return site_price_sort_rows($rows);
}

/**
 * Strip admin-only fields for a Team viewer.
 *
 * @param array<string,mixed> $row
 * @param array{role?:string} $viewer
 * @return array<string,mixed>
 */
function site_price_row_for_viewer(array $row, array $viewer): array
{
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    $addedRole = (string) ($row['added_by_role'] ?? '');
    $addedName = trim((string) (($row['added_by_full'] ?? '') ?: ($row['added_by_username'] ?? '')));
    if ($isAdmin) {
        $row['added_by_label'] = $addedName;
        $managed = trim((string) (($row['managed_by_full'] ?? '') ?: ($row['managed_by_username'] ?? '')));
        $row['managed_by_label'] = $managed;
        return $row;
    }
    unset($row['managed_by'], $row['managed_by_username'], $row['managed_by_full'], $row['managed_by_label']);
    if ($addedRole === 'admin') {
        $row['added_by_label'] = 'Admin';
        $row['added_by_username'] = '';
        $row['added_by_full'] = '';
        $row['created_by'] = null;
    } else {
        $row['added_by_label'] = $addedName;
    }
    return $row;
}

function site_price_hub_url(): string
{
    return 'index.php?page=admin_site_prices';
}

function site_price_sheet_url(string $country): string
{
    $country = trim($country);
    if ($country === '') {
        return site_price_hub_url();
    }
    return site_price_hub_url() . '&country=' . rawurlencode($country);
}
