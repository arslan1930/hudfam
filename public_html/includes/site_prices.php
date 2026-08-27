<?php

/**
 * Website prices — publisher rate book (Office).
 * One country sheet. Identity (domain / DA / DR / traffic) locks after save.
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
        [
            'slug' => 'completed',
            'label' => 'Completed',
            'color' => 'grey',
            'lane' => 'other',
            'sort_order' => 80,
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
          reply_email VARCHAR(190) NOT NULL DEFAULT '',
          row_tint VARCHAR(20) NOT NULL DEFAULT '',
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
    site_price_flush_status_cache();
    site_price_ensure_row_columns();
}

function site_price_ensure_row_columns(): void
{
    $pdo = db();
    $have = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM site_price_rows')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
            $name = strtolower((string) ($col['Field'] ?? ''));
            if ($name !== '') {
                $have[$name] = true;
            }
        }
    } catch (Throwable $e) {
        return;
    }
    if (empty($have['reply_email'])) {
        try {
            $pdo->exec(
                "ALTER TABLE site_price_rows
                 ADD COLUMN reply_email VARCHAR(190) NOT NULL DEFAULT '' AFTER extra_note"
            );
        } catch (Throwable $e) {
            // already present
        }
    }
    if (empty($have['row_tint'])) {
        try {
            $pdo->exec(
                "ALTER TABLE site_price_rows
                 ADD COLUMN row_tint VARCHAR(20) NOT NULL DEFAULT '' AFTER reply_email"
            );
        } catch (Throwable $e) {
            // already present
        }
    }
}

/** @return list<string> */
function site_price_tint_slugs(): array
{
    return ['yellow', 'pink', 'blue', 'green'];
}

function site_price_normalize_tint(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, site_price_tint_slugs(), true) ? $value : '';
}

function site_price_tint_label(string $tint): string
{
    $tint = site_price_normalize_tint($tint);
    return match ($tint) {
        'yellow' => 'yellow',
        'pink' => 'pink',
        'blue' => 'blue',
        'green' => 'green',
        default => 'none',
    };
}

function site_price_status_color(string $slug): string
{
    $slug = site_price_normalize_status($slug);
    $st = site_price_status_map()[$slug] ?? null;
    $color = strtolower(trim((string) ($st['color'] ?? 'grey')));
    $ok = ['green', 'blue', 'rose', 'grey', 'brown', 'teal'];
    return in_array($color, $ok, true) ? $color : 'grey';
}

function site_price_clip_email(string $value): string
{
    $value = trim($value);
    if (mb_strlen($value) > 190) {
        $value = mb_substr($value, 0, 190);
    }
    return $value;
}

/** @return list<int> */
function site_price_per_page_options(): array
{
    return [100, 250, 500];
}

function resolve_site_price_per_page(): int
{
    $n = function_exists('resolve_sheet_per_page') ? resolve_sheet_per_page() : 100;
    if ($n === 1000) {
        return 500;
    }
    return in_array($n, site_price_per_page_options(), true) ? $n : 100;
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

function site_price_status_map(bool $refresh = false): array
{
    static $map = null;
    if ($refresh) {
        $map = null;
    }
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (site_price_list_statuses() as $st) {
        $map[strtolower((string) $st['slug'])] = $st;
    }
    return $map;
}

function site_price_flush_status_cache(): void
{
    site_price_status_map(true);
}

/** @return array<string,string> */
function site_price_lane_labels(): array
{
    return [
        'processing' => 'Processing',
        'new' => 'New',
        'other' => 'Other',
    ];
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
 *   price_note?:string,extra_note?:string,reply_email?:string,row_tint?:string,
 *   status_slug?:string,created_by?:int,managed_by?:int
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
        $locked = array_key_exists('identity_locked', $fields)
            ? ((int) $fields['identity_locked'] ? 1 : 0)
            : 1;
        db()->prepare(
            'INSERT INTO site_price_rows
              (country, domain, niche, da, dr, traffic, price_note, extra_note, reply_email, row_tint,
               status_slug, identity_locked, created_by, managed_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $country,
            $domain,
            $niche,
            trim((string) ($fields['da'] ?? '')),
            trim((string) ($fields['dr'] ?? '')),
            trim((string) ($fields['traffic'] ?? '')),
            trim((string) ($fields['price_note'] ?? '')),
            trim((string) ($fields['extra_note'] ?? '')),
            site_price_clip_email((string) ($fields['reply_email'] ?? '')),
            site_price_normalize_tint((string) ($fields['row_tint'] ?? '')),
            $status,
            $locked,
            ((int) ($fields['created_by'] ?? 0) > 0) ? (int) $fields['created_by'] : null,
            ((int) ($fields['managed_by'] ?? 0) > 0) ? (int) $fields['managed_by'] : null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new RuntimeException('That site is already on this country’s price sheet.');
        }
        throw $e;
    }
    $id = (int) db()->lastInsertId();
    if (function_exists('order_sync_from_site_price_row')) {
        try {
            order_sync_from_site_price_row($id);
        } catch (Throwable $e) {
            // Order management sync is best-effort
        }
    }
    return $id;
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
         ORDER BY total DESC, updated_at DESC, country ASC'
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
                mu.username AS managed_by_username, mu.full_name AS managed_by_full, mu.role AS managed_by_role
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
 * Normalize in-country sheet filters. Empty string means “all” except tint=none (no color).
 *
 * @param array<string,mixed> $opts
 * @return array{q:string,lane:string,status:string,tint:string,added:string}
 */
function site_price_normalize_filter_opts(array $opts): array
{
    $q = trim((string) ($opts['q'] ?? ''));
    $lane = strtolower(trim((string) ($opts['lane'] ?? '')));
    if (!isset(site_price_lane_labels()[$lane])) {
        $lane = '';
    }
    $status = strtolower(trim((string) ($opts['status'] ?? '')));
    if ($status !== '' && !isset(site_price_status_map()[$status])) {
        $status = '';
    }
    $tint = strtolower(trim((string) ($opts['tint'] ?? '')));
    if ($tint === 'none') {
        $tint = 'none';
    } elseif ($tint !== '' && !in_array($tint, site_price_tint_slugs(), true)) {
        $tint = '';
    }
    $added = trim((string) ($opts['added'] ?? ''));
    return [
        'q' => $q,
        'lane' => $lane,
        'status' => $status,
        'tint' => $tint,
        'added' => $added,
    ];
}

function site_price_filters_active(array $filters): bool
{
    $filters = site_price_normalize_filter_opts($filters);
    return $filters['q'] !== ''
        || $filters['lane'] !== ''
        || $filters['status'] !== ''
        || $filters['tint'] !== ''
        || $filters['added'] !== '';
}

/**
 * Filters from the current request. Team cannot filter by Admin names.
 *
 * @param array{role?:string}|null $viewer
 * @return array{q:string,lane:string,status:string,tint:string,added:string}
 */
function site_price_filters_from_request(?array $viewer = null): array
{
    $opts = site_price_normalize_filter_opts([
        'q' => (string) get('q'),
        'lane' => (string) get('lane'),
        'status' => (string) get('status'),
        'tint' => (string) get('tint'),
        'added' => (string) get('added'),
    ]);
    if (($viewer['role'] ?? '') !== 'admin') {
        $opts['added'] = '';
    }
    return $opts;
}

/**
 * Query extras to keep on pager / per-page / Ctrl+Enter URLs.
 *
 * @param array{q?:string,lane?:string,status?:string,tint?:string,added?:string} $filters
 * @return array<string, scalar>
 */
function site_price_filter_query_extra(array $filters, int $perPage = 0): array
{
    $filters = site_price_normalize_filter_opts($filters);
    $extra = [];
    foreach (['q', 'lane', 'status', 'tint', 'added'] as $key) {
        if ($filters[$key] !== '') {
            $extra[$key] = $filters[$key];
        }
    }
    if (in_array($perPage, site_price_per_page_options(), true)) {
        $extra['per_page'] = $perPage;
    }
    return $extra;
}

/**
 * Search haystack for one rate-book row (matches data-search on the sheet).
 *
 * @param array<string,mixed> $row
 * @param array{id?:int,role?:string} $viewer
 */
function site_price_row_search_haystack(array $row, array $viewer = []): string
{
    $view = $viewer !== [] ? site_price_row_for_viewer($row, $viewer) : $row;
    return mb_strtolower(trim(
        (string) ($view['domain'] ?? '') . ' '
        . (string) ($view['niche'] ?? '') . ' '
        . (string) ($view['price_note'] ?? '') . ' '
        . (string) ($view['extra_note'] ?? '') . ' '
        . (string) ($view['reply_email'] ?? '') . ' '
        . (string) ($view['status_slug'] ?? '') . ' '
        . (string) ($view['added_by_label'] ?? '')
    ));
}

/**
 * Pure: filter already-loaded country rows. Does not write.
 *
 * @param list<array<string,mixed>> $rows
 * @param array<string,mixed> $opts
 * @param array{id?:int,role?:string}|null $viewer
 * @return list<array<string,mixed>>
 */
function site_price_filter_rows(array $rows, array $opts = [], ?array $viewer = null): array
{
    $filters = site_price_normalize_filter_opts($opts);
    if (!site_price_filters_active($filters)) {
        return array_values($rows);
    }
    $viewer = $viewer ?? [];
    $q = mb_strtolower($filters['q']);
    $out = [];
    foreach ($rows as $row) {
        $view = $viewer !== [] ? site_price_row_for_viewer($row, $viewer) : $row;
        if ($filters['lane'] !== '') {
            $lane = site_price_status_lane((string) ($view['status_slug'] ?? 'new'));
            if ($lane !== $filters['lane']) {
                continue;
            }
        }
        if ($filters['status'] !== '' && (string) ($view['status_slug'] ?? '') !== $filters['status']) {
            continue;
        }
        if ($filters['tint'] !== '') {
            $tint = site_price_normalize_tint((string) ($view['row_tint'] ?? ''));
            if ($filters['tint'] === 'none') {
                if ($tint !== '') {
                    continue;
                }
            } elseif ($tint !== $filters['tint']) {
                continue;
            }
        }
        if ($filters['added'] !== '') {
            $label = trim((string) ($view['added_by_label'] ?? ''));
            if ($label !== $filters['added']) {
                continue;
            }
        }
        if ($q !== '' && !str_contains(site_price_row_search_haystack($view, []), $q)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * @param array<string,mixed> $opts
 * @param array{id?:int,role?:string}|null $viewer
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   total:int,
 *   total_all:int,
 *   page:int,
 *   pages:int,
 *   per_page:int,
 *   lane_counts:array<string,int>,
 *   all:list<array<string,mixed>>,
 *   filters:array{q:string,lane:string,status:string,tint:string,added:string}
 * }
 */
function list_site_price_rows_page(
    string $country,
    int $page,
    int $perPage,
    array $opts = [],
    ?array $viewer = null
): array {
    $perPage = $perPage < 1 ? 100 : $perPage;
    $filters = site_price_normalize_filter_opts($opts);
    $all = list_site_price_rows($country);
    $matched = site_price_filter_rows($all, $filters, $viewer);
    $total = count($matched);
    $totalAll = count($all);
    $pages = max(1, (int) ceil(($total > 0 ? $total : 1) / $perPage));
    if ($total === 0) {
        $pages = 1;
    }
    $page = max(1, min($page, $pages));
    $laneCounts = ['processing' => 0, 'new' => 0, 'other' => 0];
    foreach ($matched as $row) {
        $lane = site_price_status_lane((string) ($row['status_slug'] ?? 'new'));
        if (!isset($laneCounts[$lane])) {
            $lane = 'other';
        }
        $laneCounts[$lane]++;
    }
    $offset = ($page - 1) * $perPage;
    $slice = $total > 0 ? array_slice($matched, $offset, $perPage) : [];
    return [
        'rows' => $slice,
        'total' => $total,
        'total_all' => $totalAll,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'lane_counts' => $laneCounts,
        'all' => $all,
        'filters' => $filters,
    ];
}

function site_price_page_for_row_id(string $country, int $rowId, int $perPage): int
{
    $perPage = in_array($perPage, site_price_per_page_options(), true) ? $perPage : 100;
    $rowId = max(0, $rowId);
    if ($rowId < 1) {
        return 1;
    }
    $i = 0;
    foreach (list_site_price_rows($country) as $row) {
        if ((int) ($row['id'] ?? 0) === $rowId) {
            return (int) floor($i / $perPage) + 1;
        }
        $i++;
    }
    return 1;
}

function site_price_like_needle(string $q): string
{
    $q = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    return '%' . $q . '%';
}

/**
 * Search every country. Returns jump targets with the page that row lives on.
 *
 * @return list<array{id:int,country:string,domain:string,status:string,page:int,url:string}>
 */
function site_price_jump_search(string $q, string $pageKey, int $perPage, int $limit = 80): array
{
    ensure_site_prices_schema();
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $perPage = in_array($perPage, site_price_per_page_options(), true) ? $perPage : 100;
    $limit = max(1, min(100, $limit));
    $needle = site_price_like_needle($q);
    $stmt = db()->prepare(
        'SELECT id, country, domain, status_slug, niche, price_note, extra_note, reply_email
         FROM site_price_rows
         WHERE domain LIKE ? ESCAPE \'!\'
            OR niche LIKE ? ESCAPE \'!\'
            OR IFNULL(price_note, \'\') LIKE ? ESCAPE \'!\'
            OR extra_note LIKE ? ESCAPE \'!\'
            OR reply_email LIKE ? ESCAPE \'!\'
            OR country LIKE ? ESCAPE \'!\'
         ORDER BY country ASC, domain ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$needle, $needle, $needle, $needle, $needle, $needle]);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($found === []) {
        return [];
    }
    $rank = [];
    $i = 0;
    foreach (site_price_country_counts() as $f) {
        $c = (string) ($f['country'] ?? '');
        if ($c !== '') {
            $rank[$c] = $i;
            $i++;
        }
    }
    usort($found, static function (array $a, array $b) use ($rank): int {
        $ca = (string) ($a['country'] ?? '');
        $cb = (string) ($b['country'] ?? '');
        $ra = $rank[$ca] ?? 9999;
        $rb = $rank[$cb] ?? 9999;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcasecmp((string) ($a['domain'] ?? ''), (string) ($b['domain'] ?? ''));
    });
    $pageCache = [];
    $out = [];
    foreach ($found as $row) {
        $id = (int) ($row['id'] ?? 0);
        $country = (string) ($row['country'] ?? '');
        if ($id < 1 || $country === '') {
            continue;
        }
        if (!isset($pageCache[$country])) {
            $ids = [];
            foreach (list_site_price_rows($country) as $r) {
                $ids[] = (int) ($r['id'] ?? 0);
            }
            $pageCache[$country] = $ids;
        }
        $idx = array_search($id, $pageCache[$country], true);
        $pageNum = $idx === false ? 1 : ((int) floor(((int) $idx) / $perPage) + 1);
        $out[] = [
            'id' => $id,
            'country' => $country,
            'domain' => (string) ($row['domain'] ?? ''),
            'status' => (string) ($row['status_slug'] ?? ''),
            'page' => $pageNum,
            'url' => site_price_sheet_url($country, $pageKey, [
                'per_page' => $perPage,
                'p' => $pageNum,
                'row' => $id,
                'jump' => $q,
            ]),
        ];
    }
    return $out;
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

function site_price_hub_url(string $page = 'admin_site_prices'): string
{
    $page = $page !== '' ? $page : 'admin_site_prices';
    return 'index.php?page=' . $page;
}

/**
 * @param array<string, scalar|null> $extra
 */
function site_price_sheet_url(string $country, string $page = 'admin_site_prices', array $extra = []): string
{
    $country = trim($country);
    if ($country === '') {
        return site_price_hub_url($page);
    }
    $url = site_price_hub_url($page) . '&country=' . rawurlencode($country);
    foreach ($extra as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $k = (string) $key;
        if ($k === 'page' || $k === 'country') {
            continue;
        }
        $url .= '&' . rawurlencode($k) . '=' . rawurlencode((string) $value);
    }
    return $url;
}

function site_price_hub_list_url(string $page = 'admin_site_prices'): string
{
    return site_price_hub_url($page) . '&hub=1';
}

function site_price_page_for_viewer(array $viewer): string
{
    return (($viewer['role'] ?? '') === 'admin') ? 'admin_site_prices' : 'team_site_prices';
}

function site_price_normalize_status(string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === '' || !isset(site_price_status_map()[$slug])) {
        return 'new';
    }
    return $slug;
}

function get_site_price_row(int $id): ?array
{
    ensure_site_prices_schema();
    $id = max(0, $id);
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT r.*,
                cu.username AS added_by_username, cu.full_name AS added_by_full, cu.role AS added_by_role,
                mu.username AS managed_by_username, mu.full_name AS managed_by_full, mu.role AS managed_by_role
         FROM site_price_rows r
         LEFT JOIN users cu ON cu.id = r.created_by
         LEFT JOIN users mu ON mu.id = r.managed_by
         WHERE r.id=? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function site_price_log_event(int $rowId, array $viewer, string $kind, string $old = '', string $new = ''): void
{
    $rowId = max(0, $rowId);
    if ($rowId < 1 || $kind === '') {
        return;
    }
    $actor = (int) ($viewer['id'] ?? 0);
    db()->prepare(
        'INSERT INTO site_price_events (row_id, actor_id, actor_role, kind, old_value, new_value)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $rowId,
        $actor > 0 ? $actor : null,
        (string) ($viewer['role'] ?? ''),
        $kind,
        $old,
        $new,
    ]);
}

function site_price_format_niche(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('prospect_format_niches') && function_exists('prospect_parse_niches')) {
        return prospect_format_niches(prospect_parse_niches($raw));
    }
    return $raw;
}

/**
 * @param array<string,mixed> $viewer
 * @return array<string,mixed>
 */
function site_price_add_row_for_user(array $fields, array $viewer): array
{
    $fields['created_by'] = (int) ($viewer['id'] ?? 0);
    $fields['identity_locked'] = 1;
    $id = site_price_insert_row($fields);
    site_price_log_event($id, $viewer, 'added', '', site_price_normalize_domain((string) ($fields['domain'] ?? '')));
    $row = get_site_price_row($id);
    if (!$row) {
        throw new RuntimeException('Could not save that site.');
    }
    return $row;
}

/**
 * Save one row. Locked identity cannot change unless Admin unlocked first.
 *
 * @param array<string,mixed> $fields
 * @param array{id?:int,role?:string} $viewer
 * @return array<string,mixed>
 */
function site_price_save_row(int $id, array $fields, array $viewer): array
{
    ensure_site_prices_schema();
    $row = get_site_price_row($id);
    if (!$row) {
        throw new RuntimeException('Site not found.');
    }
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    $locked = (int) ($row['identity_locked'] ?? 0) === 1;
    $postedIdentity = array_key_exists('domain', $fields)
        || array_key_exists('da', $fields)
        || array_key_exists('dr', $fields)
        || array_key_exists('traffic', $fields);

    $domain = (string) ($row['domain'] ?? '');
    $da = (string) ($row['da'] ?? '');
    $dr = (string) ($row['dr'] ?? '');
    $traffic = (string) ($row['traffic'] ?? '');

    if ($postedIdentity) {
        $nextDomain = array_key_exists('domain', $fields)
            ? site_price_normalize_domain((string) $fields['domain'])
            : $domain;
        $nextDa = array_key_exists('da', $fields) ? trim((string) $fields['da']) : $da;
        $nextDr = array_key_exists('dr', $fields) ? trim((string) $fields['dr']) : $dr;
        $nextTraffic = array_key_exists('traffic', $fields) ? trim((string) $fields['traffic']) : $traffic;
        $changed = $nextDomain !== $domain || $nextDa !== $da || $nextDr !== $dr || $nextTraffic !== $traffic;
        if ($changed) {
            if ($locked) {
                throw new RuntimeException('Website, DA, DR, and traffic are locked. Admin can Unlock to edit them.');
            }
            if (!$isAdmin) {
                throw new RuntimeException('Only Admin can change website, DA, DR, or traffic.');
            }
            if ($nextDomain === '') {
                throw new InvalidArgumentException('Site name is required.');
            }
            $domain = $nextDomain;
            $da = $nextDa;
            $dr = $nextDr;
            $traffic = $nextTraffic;
        }
    }

    $niche = array_key_exists('niche', $fields)
        ? site_price_format_niche((string) $fields['niche'])
        : (string) ($row['niche'] ?? '');
    $price = array_key_exists('price_note', $fields)
        ? trim((string) $fields['price_note'])
        : (string) ($row['price_note'] ?? '');
    $note = array_key_exists('extra_note', $fields)
        ? trim((string) $fields['extra_note'])
        : (string) ($row['extra_note'] ?? '');
    $status = array_key_exists('status_slug', $fields)
        ? site_price_normalize_status((string) $fields['status_slug'])
        : site_price_normalize_status((string) ($row['status_slug'] ?? 'new'));

    $email = array_key_exists('reply_email', $fields)
        ? site_price_clip_email((string) $fields['reply_email'])
        : site_price_clip_email((string) ($row['reply_email'] ?? ''));
    $tint = array_key_exists('row_tint', $fields)
        ? site_price_normalize_tint((string) $fields['row_tint'])
        : site_price_normalize_tint((string) ($row['row_tint'] ?? ''));

    $oldStatus = (string) ($row['status_slug'] ?? '');
    $oldPrice = (string) ($row['price_note'] ?? '');
    $oldEmail = site_price_clip_email((string) ($row['reply_email'] ?? ''));
    $oldTint = site_price_normalize_tint((string) ($row['row_tint'] ?? ''));
    $sortInLane = (int) ($row['sort_in_lane'] ?? 0);
    if (site_price_status_lane($oldStatus) !== site_price_status_lane($status)) {
        $sortInLane = 0;
    }

    try {
        db()->prepare(
            'UPDATE site_price_rows
             SET domain=?, niche=?, da=?, dr=?, traffic=?, price_note=?, extra_note=?, reply_email=?,
                 row_tint=?, status_slug=?, sort_in_lane=?, identity_locked=1, updated_at=NOW()
             WHERE id=?'
        )->execute([$domain, $niche, $da, $dr, $traffic, $price, $note, $email, $tint, $status, $sortInLane, $id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new RuntimeException('That site is already on this country’s price sheet.');
        }
        throw $e;
    }

    if ($oldStatus !== $status) {
        site_price_log_event($id, $viewer, 'status', $oldStatus, $status);
    }
    if ($oldPrice !== $price) {
        site_price_log_event($id, $viewer, 'price', $oldPrice, $price);
    }
    if ($oldEmail !== $email) {
        site_price_log_event($id, $viewer, 'email', $oldEmail, $email);
    }
    if ($oldTint !== $tint) {
        site_price_log_event($id, $viewer, 'tint', $oldTint, $tint);
    }
    site_price_touch_managed($id, $viewer);

    if (function_exists('order_sync_from_site_price_row')) {
        try {
            order_sync_from_site_price_row($id);
        } catch (Throwable $e) {
            // Order management sync is best-effort — never write LIVE/profit/client back here
        }
    }

    $saved = get_site_price_row($id);
    if (!$saved) {
        throw new RuntimeException('Site not found.');
    }
    return $saved;
}

/**
 * @param array{id?:int,role?:string} $viewer
 * @return array<string,mixed>
 */
function site_price_unlock_row(int $id, array $viewer): array
{
    if (($viewer['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Only Admin can unlock website, DA, DR, or traffic.');
    }
    $row = get_site_price_row($id);
    if (!$row) {
        throw new RuntimeException('Site not found.');
    }
    db()->prepare('UPDATE site_price_rows SET identity_locked=0, updated_at=NOW() WHERE id=?')->execute([$id]);
    site_price_log_event($id, $viewer, 'unlock', '1', '0');
    site_price_touch_managed($id, $viewer);
    $saved = get_site_price_row($id);
    if (!$saved) {
        throw new RuntimeException('Site not found.');
    }
    return $saved;
}

function site_price_touch_managed(int $id, array $viewer): void
{
    if (($viewer['role'] ?? '') !== 'admin') {
        return;
    }
    $uid = (int) ($viewer['id'] ?? 0);
    if ($uid < 1) {
        return;
    }
    $row = get_site_price_row($id);
    if (!$row || (int) ($row['managed_by'] ?? 0) > 0) {
        return;
    }
    db()->prepare(
        'UPDATE site_price_rows SET managed_by=? WHERE id=? AND (managed_by IS NULL OR managed_by=0)'
    )->execute([$uid, $id]);
    site_price_log_event($id, $viewer, 'manage', '', (string) $uid);
}

/**
 * @param array{id?:int,role?:string} $viewer
 * @return array<string,mixed>
 */
function site_price_claim_row(int $id, array $viewer): array
{
    if (($viewer['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Only Admin can take a row as manager.');
    }
    $uid = (int) ($viewer['id'] ?? 0);
    if ($uid < 1) {
        throw new RuntimeException('Only Admin can take a row as manager.');
    }
    $row = get_site_price_row($id);
    if (!$row) {
        throw new RuntimeException('Site not found.');
    }
    $old = (string) ((int) ($row['managed_by'] ?? 0));
    db()->prepare('UPDATE site_price_rows SET managed_by=?, updated_at=NOW() WHERE id=?')->execute([$uid, $id]);
    if ($old !== (string) $uid) {
        site_price_log_event($id, $viewer, 'manage', $old, (string) $uid);
    }
    $saved = get_site_price_row($id);
    if (!$saved) {
        throw new RuntimeException('Site not found.');
    }
    return $saved;
}

function site_price_event_actor_label(array $event, array $viewer): string
{
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    $role = (string) (($event['actor_role'] ?? '') ?: ($event['actor_user_role'] ?? ''));
    $name = trim((string) (($event['actor_full'] ?? '') ?: ($event['actor_username'] ?? '')));
    if (!$isAdmin && $role === 'admin') {
        return 'Admin';
    }
    if ($name !== '') {
        return $name;
    }
    return $role === 'admin' ? 'Admin' : 'Team';
}

function site_price_event_summary(array $event): string
{
    $kind = (string) ($event['kind'] ?? '');
    $old = (string) ($event['old_value'] ?? '');
    $new = (string) ($event['new_value'] ?? '');
    $map = site_price_status_map();
    $oldLabel = $old !== '' && isset($map[$old]) ? (string) $map[$old]['label'] : $old;
    $newLabel = $new !== '' && isset($map[$new]) ? (string) $map[$new]['label'] : $new;
    return match ($kind) {
        'added' => 'Added ' . ($new !== '' ? $new : 'site'),
        'status' => 'Status ' . ($oldLabel !== '' ? $oldLabel . ' → ' : '') . ($newLabel !== '' ? $newLabel : $new),
        'price' => 'Price' . ($old !== '' || $new !== '' ? ' ' . $old . ' → ' . $new : ''),
        'unlock' => 'Unlocked identity',
        'manage' => 'Took as manager',
        'email' => 'Reply email' . ($old !== '' || $new !== '' ? ' ' . $old . ' → ' . $new : ''),
        'tint' => 'Color ' . site_price_tint_label($old) . ' → ' . site_price_tint_label($new),
        default => $kind !== '' ? $kind : 'Updated',
    };
}

/**
 * @param array{id?:int,role?:string} $viewer
 * @return list<array<string,mixed>>
 */
function list_site_price_events(int $rowId, array $viewer): array
{
    ensure_site_prices_schema();
    $rowId = max(0, $rowId);
    if ($rowId < 1) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT e.*, u.username AS actor_username, u.full_name AS actor_full, u.role AS actor_user_role
         FROM site_price_events e
         LEFT JOIN users u ON u.id = e.actor_id
         WHERE e.row_id=?
         ORDER BY e.id DESC
         LIMIT 80'
    );
    $stmt->execute([$rowId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ev) {
        $ev['actor_label'] = site_price_event_actor_label($ev, $viewer);
        $ev['summary'] = site_price_event_summary($ev);
        if (($viewer['role'] ?? '') !== 'admin') {
            unset($ev['actor_id'], $ev['actor_username'], $ev['actor_full']);
        }
        $out[] = $ev;
    }
    return $out;
}

function render_site_price_history_html(int $rowId, array $viewer): string
{
    $events = list_site_price_events($rowId, $viewer);
    if ($events === []) {
        return '<p class="muted" style="margin:0">No history yet.</p>';
    }
    $html = '<ol class="site-price-history">';
    foreach ($events as $ev) {
        $when = substr((string) ($ev['created_at'] ?? ''), 0, 16);
        $html .= '<li><span class="muted">' . h($when) . '</span> '
            . h((string) ($ev['actor_label'] ?? '')) . ' · '
            . h((string) ($ev['summary'] ?? '')) . '</li>';
    }
    $html .= '</ol>';
    return $html;
}

function site_price_sheet_colspan(): int
{
    return 12;
}

function site_price_country_short(string $name): string
{
    static $codes = null;
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if ($codes === null) {
        $codes = [];
        try {
            foreach (db()->query('SELECT name, code FROM countries')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $n = trim((string) ($row['name'] ?? ''));
                $c = strtoupper(trim((string) ($row['code'] ?? '')));
                if ($n !== '' && $c !== '') {
                    $codes[mb_strtolower($n)] = $c;
                }
            }
        } catch (Throwable $e) {
            $codes = [];
        }
    }
    return $codes[mb_strtolower($name)] ?? $name;
}

/**
 * @param array<string, scalar|null> $query
 */
function render_site_price_country_tabs(string $current, string $page = 'admin_site_prices', array $query = []): string
{
    $folders = site_price_country_counts();
    if ($folders === []) {
        return '';
    }
    $pin = 10;
    $shown = [];
    $shownNames = [];
    $i = 0;
    foreach ($folders as $f) {
        $c = (string) ($f['country'] ?? '');
        if ($c === '') {
            continue;
        }
        if ($i < $pin || $c === $current) {
            $shown[] = $f;
            $shownNames[$c] = true;
        }
        $i++;
    }
    $more = [];
    foreach ($folders as $f) {
        $c = (string) ($f['country'] ?? '');
        if ($c !== '' && empty($shownNames[$c])) {
            $more[] = $f;
        }
    }
    $perPage = isset($query['per_page']) ? (int) $query['per_page'] : 0;
    $extra = [];
    if (in_array($perPage, site_price_per_page_options(), true)) {
        $extra['per_page'] = $perPage;
    }
    $html = '<nav class="sheet-tabs site-price-country-tabs" aria-label="Country sheets">';
    foreach ($shown as $f) {
        $c = (string) ($f['country'] ?? '');
        $short = site_price_country_short($c);
        $cls = $c === $current ? ' class="active"' : '';
        $html .= '<a' . $cls . ' href="' . h(site_price_sheet_url($c, $page, $extra)) . '"'
            . ' title="' . h($c) . '">'
            . h($short) . '<span class="sheet-count">' . (int) ($f['total'] ?? 0) . '</span></a>';
    }
    if ($more !== []) {
        $html .= '<details class="site-price-country-more"><summary>More</summary><div class="site-price-country-more-list">';
        foreach ($more as $f) {
            $c = (string) ($f['country'] ?? '');
            $short = site_price_country_short($c);
            $html .= '<a href="' . h(site_price_sheet_url($c, $page, $extra)) . '" title="' . h($c) . '">'
                . h($short !== $c ? $short . ' · ' . $c : $c)
                . '<span class="sheet-count">' . (int) ($f['total'] ?? 0) . '</span></a>';
        }
        $html .= '</div></details>';
    }
    $html .= '</nav>';
    return $html;
}

function site_price_slug_from_label(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    if (strlen($slug) > 80) {
        $slug = substr($slug, 0, 80);
        $slug = rtrim($slug, '_');
    }
    return $slug;
}

function site_price_people_cell(array $view, bool $isAdmin): string
{
    $id = (int) ($view['id'] ?? 0);
    $added = trim((string) ($view['added_by_label'] ?? ''));
    if ($added === '') {
        $added = '—';
    }
    $html = '<div class="site-price-people">';
    $html .= '<div class="site-price-people-line"><span class="muted">Added by</span> ' . h($added) . '</div>';
    if ($isAdmin) {
        $mgr = trim((string) ($view['managed_by_label'] ?? ''));
        $html .= '<div class="site-price-people-line"><span class="muted">Managed by</span> '
            . ($mgr !== '' ? h($mgr) : '<span class="muted">—</span>') . '</div>';
    }
    $html .= '<div class="site-price-people-actions">';
    $html .= '<button type="button" class="btn-link js-site-price-history" data-site-price-history data-id="'
        . $id . '">History</button>';
    if ($isAdmin) {
        $html .= '<button type="button" class="btn-link js-site-price-claim" data-site-price-claim data-id="'
            . $id . '">Take</button>';
    }
    $html .= '</div></div>';
    return $html;
}

/**
 * Admin adds an extra status word. Lands in the Other lane. Builtins stay closed.
 *
 * @param array{id?:int,role?:string} $viewer
 * @return array<string,mixed>
 */
function site_price_add_custom_status(string $label, array $viewer): array
{
    if (($viewer['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Only Admin can add status words.');
    }
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 80) {
        throw new InvalidArgumentException('Type a short status word.');
    }
    $slug = site_price_slug_from_label($label);
    if ($slug === '') {
        throw new InvalidArgumentException('Type a status word with letters or numbers.');
    }
    ensure_site_prices_schema();
    $map = site_price_status_map(true);
    if (isset($map[$slug])) {
        throw new RuntimeException('That status word is already on the list.');
    }
    foreach ($map as $st) {
        if (mb_strtolower(trim((string) ($st['label'] ?? ''))) === mb_strtolower($label)) {
            throw new RuntimeException('That status word is already on the list.');
        }
    }
    $max = (int) db()->query('SELECT COALESCE(MAX(sort_order), 90) FROM site_price_statuses')->fetchColumn();
    db()->prepare(
        'INSERT INTO site_price_statuses (slug, label, color, lane, is_builtin, sort_order)
         VALUES (?,?,?,?,0,?)'
    )->execute([$slug, $label, 'grey', 'other', $max + 10]);
    site_price_flush_status_cache();
    $saved = site_price_status_map()[$slug] ?? null;
    if (!$saved) {
        throw new RuntimeException('Could not save that status word.');
    }
    return $saved;
}

/**
 * @param array{id?:int,role?:string} $viewer
 */
function site_price_delete_custom_status(string $slug, array $viewer): void
{
    if (($viewer['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Only Admin can remove status words.');
    }
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        throw new InvalidArgumentException('Status word is required.');
    }
    ensure_site_prices_schema();
    $st = site_price_status_map(true)[$slug] ?? null;
    if (!$st) {
        throw new RuntimeException('Status word not found.');
    }
    if ((int) ($st['is_builtin'] ?? 0) === 1) {
        throw new RuntimeException('The seven closed statuses cannot be removed.');
    }
    $used = db()->prepare('SELECT COUNT(*) FROM site_price_rows WHERE status_slug=?');
    $used->execute([$slug]);
    $n = (int) $used->fetchColumn();
    if ($n > 0) {
        throw new RuntimeException(
            $n === 1
                ? '1 site still uses this status. Change it first.'
                : $n . ' sites still use this status. Change them first.'
        );
    }
    db()->prepare('DELETE FROM site_price_statuses WHERE slug=? AND is_builtin=0')->execute([$slug]);
    site_price_flush_status_cache();
}

/**
 * Admin drag: persist order of row ids inside one lane of one country.
 *
 * @param list<int|string> $ids
 * @param array{id?:int,role?:string} $viewer
 */
function site_price_reorder_lane(string $country, string $lane, array $ids, array $viewer): void
{
    if (($viewer['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Only Admin can drag rows.');
    }
    $country = trim($country);
    $lane = strtolower(trim($lane));
    if ($country === '' || !isset(site_price_lane_labels()[$lane])) {
        throw new InvalidArgumentException('Country and lane are required.');
    }
    $canon = function_exists('resolve_canonical_country') ? resolve_canonical_country($country) : null;
    if ($canon !== null) {
        $country = (string) $canon['name'];
    }
    $want = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0 && !isset($want[$n])) {
            $want[$n] = $n;
        }
    }
    $want = array_values($want);
    $rows = list_site_price_rows($country);
    $inLane = [];
    foreach ($rows as $row) {
        if (site_price_status_lane((string) ($row['status_slug'] ?? 'new')) === $lane) {
            $inLane[(int) $row['id']] = true;
        }
    }
    if (count($want) !== count($inLane)) {
        throw new RuntimeException('Reload the sheet and drag again.');
    }
    foreach ($want as $id) {
        if (!isset($inLane[$id])) {
            throw new RuntimeException('That site is not in this lane.');
        }
    }
    $upd = db()->prepare('UPDATE site_price_rows SET sort_in_lane=?, updated_at=NOW() WHERE id=? AND country=?');
    foreach ($want as $i => $id) {
        $upd->execute([$i + 1, $id, $country]);
    }
}

function site_price_status_select_html(string $current, string $id = ''): string
{
    $current = site_price_normalize_status($current);
    $cur = site_price_status_map()[$current] ?? null;
    $color = $cur ? (string) ($cur['color'] ?? 'grey') : 'grey';
    $html = '<select class="site-price-status is-color-' . h($color) . '" data-site-price-status data-no-draft'
        . ($id !== '' ? ' id="' . h($id) . '"' : '')
        . ' aria-label="Status">';
    foreach (site_price_list_statuses() as $st) {
        $slug = (string) $st['slug'];
        $sel = $slug === $current ? ' selected' : '';
        $html .= '<option value="' . h($slug) . '" data-color="' . h((string) ($st['color'] ?? 'grey')) . '"'
            . ' data-lane="' . h((string) ($st['lane'] ?? 'other')) . '"'
            . $sel . '>' . h((string) $st['label']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * @param array{role?:string} $viewer
 */
function render_site_price_status_words_card(array $viewer, string $page = 'admin_site_prices'): string
{
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    $action = site_price_hub_url($page);
    $html = '<div class="card" id="status-words" style="margin-top:1rem">';
    $html .= '<h2 style="margin:0 0 0.35rem">Status words</h2>';
    $html .= '<p class="help" style="margin-top:0">The seven closed statuses stay. Extra words land in Other on every country sheet.</p>';
    $html .= '<ul class="site-price-status-list">';
    foreach (site_price_list_statuses() as $st) {
        $builtin = (int) ($st['is_builtin'] ?? 0) === 1;
        $html .= '<li class="site-price-status-chip is-color-' . h((string) ($st['color'] ?? 'grey')) . '">';
        $html .= '<span>' . h((string) $st['label']) . '</span>';
        if (!$builtin && $isAdmin) {
            $html .= '<form method="post" action="' . h($action) . '" class="site-price-status-del" data-no-draft>';
            $html .= function_exists('csrf_field') ? csrf_field() : '';
            $html .= '<input type="hidden" name="action" value="delete_status">';
            $html .= '<input type="hidden" name="slug" value="' . h((string) $st['slug']) . '">';
            $html .= '<button type="submit" class="site-price-status-x" aria-label="Remove ' . h((string) $st['label']) . '">×</button>';
            $html .= '</form>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    if ($isAdmin) {
        $html .= '<form method="post" action="' . h($action) . '" class="form-grid site-price-status-add" autocomplete="off" data-no-draft>';
        $html .= function_exists('csrf_field') ? csrf_field() : '';
        $html .= '<input type="hidden" name="action" value="add_status">';
        $html .= '<label for="site_price_status_label">Add a word';
        $html .= '<input id="site_price_status_label" type="text" name="label" maxlength="80" required data-no-draft';
        $html .= ' placeholder="Follow up" autocomplete="off"></label>';
        $html .= '<p class="actions" style="margin-top:0;align-self:end"><button class="btn secondary" type="submit">Add word</button></p>';
        $html .= '</form>';
    }
    $html .= '</div>';
    return $html;
}

function site_price_locked_text(string $value, bool $copyLock): string
{
    $show = $value !== '' ? $value : '—';
    $cls = 'site-price-id is-locked' . ($copyLock ? ' is-copy-lock' : '');
    return '<span class="' . h($cls) . '">' . h($show) . '</span>';
}

function site_price_tint_buttons_html(string $current, bool $withLabels = false): string
{
    $current = site_price_normalize_tint($current);
    $opts = ['' => 'No color', 'yellow' => 'Yellow', 'pink' => 'Pink', 'blue' => 'Blue', 'green' => 'Green'];
    $html = '';
    foreach ($opts as $slug => $label) {
        $on = $slug === $current ? ' is-on' : '';
        $cls = $slug === '' ? 'is-none' : 'is-' . $slug;
        $html .= '<button type="button" class="site-price-tint ' . h($cls) . $on . '" data-site-price-tint="'
            . h($slug) . '" title="' . h($label) . '" aria-label="' . h($label) . '">';
        if ($withLabels) {
            $html .= '<span>' . h($label) . '</span>';
        }
        $html .= '</button>';
    }
    return $html;
}

function site_price_tint_controls(string $current, array $opts = []): string
{
    $current = site_price_normalize_tint($current);
    $variant = (string) ($opts['variant'] ?? 'inline');
    $hidden = '<input type="hidden" data-site-price-tint-value value="' . h($current) . '" data-no-draft>';
    if ($variant === 'menu') {
        $swatch = $current !== '' ? ' is-' . $current : '';
        $html = '<details class="sheet-row-more site-price-color-menu">';
        $html .= '<summary class="btn secondary small sheet-row-more-btn site-price-color-summary' . h($swatch) . '"'
            . ' title="Row color" aria-label="Row color">' . ($current !== '' ? '' : '⋯') . '</summary>';
        $html .= '<div class="sheet-row-more-panel site-price-color-panel">' . site_price_tint_buttons_html($current, true) . '</div>';
        $html .= $hidden . '</details>';
        return $html;
    }
    $html = '<div class="site-price-tints" role="group" aria-label="Row color">';
    $html .= site_price_tint_buttons_html($current, false);
    $html .= '</div>' . $hidden;
    return $html;
}

/**
 * @param array<string,mixed> $row
 * @param array{id?:int,role?:string} $viewer
 */
function render_site_price_sheet_row(array $row, array $viewer): string
{
    $view = site_price_row_for_viewer($row, $viewer);
    $id = (int) ($view['id'] ?? 0);
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    $locked = (int) ($row['identity_locked'] ?? 0) === 1;
    $copyLock = !$isAdmin && $locked;
    $domain = (string) ($view['domain'] ?? '');
    $tint = site_price_normalize_tint((string) ($view['row_tint'] ?? ''));
    $hay = site_price_row_search_haystack($view, []);
    $lane = site_price_status_lane((string) ($view['status_slug'] ?? 'new'));
    $html = '<tr class="site-price-row" id="sp-row-' . $id . '" data-site-price-row data-row-id="' . $id . '"'
        . ' data-locked="' . ($locked ? '1' : '0') . '" data-lane="' . h($lane) . '"'
        . ' data-status="' . h((string) ($view['status_slug'] ?? '')) . '"'
        . ' data-tint="' . h($tint) . '"'
        . ' data-added-by="' . h((string) ($view['added_by_label'] ?? '')) . '"'
        . ' data-search="' . h($hay) . '">';

    $html .= '<td class="site-price-check-td" data-label="">'
        . '<input type="checkbox" class="site-price-check" data-site-price-select data-no-draft'
        . ' data-domain="' . h($domain) . '" aria-label="Select ' . h($domain) . '"></td>';

    $html .= '<td data-label="Website">';
    if ($locked) {
        $html .= site_price_locked_text($domain, $copyLock);
        if ($isAdmin && function_exists('render_open_site_anchor')) {
            $html .= ' ' . render_open_site_anchor($domain, ['label' => 'Open', 'class' => 'small']);
        }
    } else {
        $html .= '<input type="text" class="site-price-input" data-site-price-domain value="' . h($domain) . '"'
            . ' autocomplete="off" spellcheck="false" data-no-draft aria-label="Website">';
    }
    $html .= '</td>';

    $html .= '<td class="prospect-niche-td" data-label="Niche">';
    if (function_exists('render_niche_chip_box')) {
        $html .= render_niche_chip_box((string) ($view['niche'] ?? ''), [
            'name' => '',
            'id' => 'sp_niche_' . $id,
            'compact' => true,
            'placeholder' => 'Add…',
        ]);
    } else {
        $html .= '<input type="text" class="site-price-input" data-site-price-niche value="'
            . h((string) ($view['niche'] ?? '')) . '" autocomplete="off" data-no-draft aria-label="Niche">';
    }
    $html .= '</td>';

    foreach (['da' => 'DA', 'dr' => 'DR', 'traffic' => 'Traffic'] as $key => $label) {
        $val = (string) ($view[$key] ?? '');
        $html .= '<td data-label="' . h($label) . '">';
        if ($locked) {
            $html .= site_price_locked_text($val, $copyLock);
        } else {
            $html .= '<input type="text" class="site-price-input" data-site-price-' . h($key)
                . ' value="' . h($val) . '" autocomplete="off" spellcheck="false" data-no-draft aria-label="' . h($label) . '">';
        }
        $html .= '</td>';
    }

    $html .= '<td data-label="Price"><input type="text" class="site-price-input" data-site-price-price value="'
        . h((string) ($view['price_note'] ?? '')) . '" autocomplete="off" spellcheck="false" data-no-draft aria-label="Price"></td>';
    $html .= '<td data-label="Status">' . site_price_status_select_html((string) ($view['status_slug'] ?? 'new')) . '</td>';
    $html .= '<td data-label="Note"><input type="text" class="site-price-input" data-site-price-note value="'
        . h((string) ($view['extra_note'] ?? '')) . '" autocomplete="off" spellcheck="false" data-no-draft aria-label="Note"></td>';
    $html .= '<td data-label="Email"><input type="text" class="site-price-input" data-site-price-email value="'
        . h((string) ($view['reply_email'] ?? '')) . '" placeholder="inbox@…" autocomplete="off" spellcheck="false"'
        . ' data-no-draft aria-label="Reply email"></td>';
    $html .= '<td data-label="People" class="site-price-people-cell">' . site_price_people_cell($view, $isAdmin) . '</td>';
    $html .= '<td data-label="Actions"><div class="site-price-actions">';
    $html .= site_price_tint_controls($tint, ['variant' => 'menu']);
    if ($isAdmin) {
        $html .= '<span class="site-price-drag" data-site-price-drag draggable="true"'
            . ' title="Drag to reorder in this lane" aria-label="Drag to reorder in this lane">⋮⋮</span>';
    }
    if ($isAdmin && $locked) {
        $html .= '<button type="button" class="btn secondary small" data-site-price-unlock>Unlock</button>';
    }
    $html .= '</div></td>';
    $html .= '</tr>';
    return $html;
}

function render_site_price_add_row(): string
{
    $html = '<tr class="site-price-add" data-site-price-add>';
    $html .= '<td class="site-price-check-td" data-label=""></td>';
    $html .= '<td data-label="Website"><input type="text" class="site-price-input" data-add-domain'
        . ' placeholder="example.com" autocomplete="off" spellcheck="false" data-no-draft aria-label="New website"></td>';
    $html .= '<td class="prospect-niche-td" data-label="Niche">';
    if (function_exists('render_niche_chip_box')) {
        $html .= render_niche_chip_box('', [
            'name' => '',
            'id' => 'sp_niche_add',
            'compact' => true,
            'placeholder' => 'Add…',
        ]);
    } else {
        $html .= '<input type="text" class="site-price-input" data-add-niche autocomplete="off" data-no-draft aria-label="Niche">';
    }
    $html .= '</td>';
    $html .= '<td data-label="DA"><input type="text" class="site-price-input" data-add-da autocomplete="off" spellcheck="false" data-no-draft aria-label="DA"></td>';
    $html .= '<td data-label="DR"><input type="text" class="site-price-input" data-add-dr autocomplete="off" spellcheck="false" data-no-draft aria-label="DR"></td>';
    $html .= '<td data-label="Traffic"><input type="text" class="site-price-input" data-add-traffic autocomplete="off" spellcheck="false" data-no-draft aria-label="Traffic"></td>';
    $html .= '<td data-label="Price"><input type="text" class="site-price-input" data-add-price placeholder="Price" autocomplete="off" spellcheck="false" data-no-draft aria-label="Price"></td>';
    $html .= '<td data-label="Status"><div class="site-price-add-status">' . site_price_status_select_html('new')
        . site_price_tint_controls('', ['variant' => 'inline']) . '</div></td>';
    $html .= '<td data-label="Note"><input type="text" class="site-price-input" data-add-note autocomplete="off" spellcheck="false" data-no-draft aria-label="Note"></td>';
    $html .= '<td data-label="Email"><input type="text" class="site-price-input" data-add-email placeholder="inbox@…" autocomplete="off" spellcheck="false"'
        . ' data-no-draft aria-label="Reply email"></td>';
    $html .= '<td class="site-price-add-commit" colspan="2" data-label="">';
    $html .= '<button type="button" class="btn small" data-site-price-add-btn>Add site</button>';
    $html .= '</td>';
    $html .= '</tr>';
    return $html;
}

function render_site_price_lane_header(string $lane, int $count, bool $isAdmin = false): string
{
    $labels = site_price_lane_labels();
    $label = $labels[$lane] ?? 'Other';
    $hint = '';
    if ($lane === 'processing') {
        $hint = $isAdmin ? 'Oldest first. Drag to reorder.' : 'Oldest first.';
    } elseif ($lane === 'new') {
        $hint = $isAdmin ? 'Newest first. Drag to reorder.' : 'Newest first.';
    } else {
        $hint = $isAdmin ? 'Extra status words live here. Drag to reorder.' : 'Extra status words live here.';
    }
    return '<tr class="site-price-lane" data-site-price-lane="' . h($lane) . '">'
        . '<td colspan="' . site_price_sheet_colspan() . '"><span class="site-price-lane-title">' . h($label)
        . ' · ' . (int) $count . '</span>'
        . '<span class="muted site-price-lane-hint"> ' . h($hint) . '</span></td></tr>';
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array{id?:int,role?:string} $viewer
 * @param array<string,int>|null $laneCounts
 */
function render_site_price_sheet_tbody(array $rows, array $viewer, ?array $laneCounts = null): string
{
    $html = render_site_price_add_row();
    $groups = ['processing' => [], 'new' => [], 'other' => []];
    foreach ($rows as $row) {
        $lane = site_price_status_lane((string) ($row['status_slug'] ?? 'new'));
        if (!isset($groups[$lane])) {
            $lane = 'other';
        }
        $groups[$lane][] = $row;
    }
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    foreach ($groups as $lane => $items) {
        $count = $laneCounts[$lane] ?? count($items);
        if ($items === []) {
            if ($laneCounts === null) {
                $html .= render_site_price_lane_header($lane, 0, $isAdmin);
            }
            continue;
        }
        $html .= render_site_price_lane_header($lane, (int) $count, $isAdmin);
        foreach ($items as $row) {
            $html .= render_site_price_sheet_row($row, $viewer);
        }
    }
    $html .= '<tr class="site-price-filter-empty" data-site-price-filter-empty hidden>'
        . '<td colspan="' . site_price_sheet_colspan() . '" class="muted">'
        . 'No search matches on this page. Try Ctrl/Cmd+Enter to search all pages.</td></tr>';
    return $html;
}

/**
 * @param array{id?:int,role?:string} $viewer
 * @param list<array<string,mixed>> $rows
 * @param array{q?:string,lane?:string,status?:string,tint?:string,added?:string} $filters
 * @param array{matching?:int,total_all?:int} $counts
 */
function render_site_price_filters(
    array $viewer,
    array $rows,
    string $pageKey = 'admin_site_prices',
    string $country = '',
    int $perPage = 100,
    array $filters = [],
    array $counts = []
): string {
    $isAdmin = ($viewer['role'] ?? '') === 'admin';
    $filters = site_price_normalize_filter_opts($filters !== [] ? $filters : [
        'q' => (string) get('q'),
        'lane' => (string) get('lane'),
        'status' => (string) get('status'),
        'tint' => (string) get('tint'),
        'added' => (string) get('added'),
    ]);
    if (!$isAdmin) {
        $filters['added'] = '';
    }
    $q = $filters['q'];
    $lane = $filters['lane'];
    $status = $filters['status'];
    $added = $filters['added'];
    $tint = (string) ($filters['tint'] ?? '');
    $html = '<div class="invoice-list-toolbar swe-list-toolbar site-price-list-toolbar site-price-filters" data-site-price-filters>';
    $html .= '<div>';
    $html .= '<label class="sheet-search swe-row-search-wrap" for="site-price-filter-q" style="margin:0">';
    $html .= '<span class="visually-hidden">Search this country</span>';
    $html .= '<input id="site-price-filter-q" type="search" data-site-price-filter="q" value="' . h($q) . '"'
        . ' placeholder="Search this country…" autocomplete="off" spellcheck="false" data-no-draft'
        . ' title="Filters this page after you pause typing · Enter = next match · Ctrl/Cmd+Enter = search all pages">';
    $html .= '<span class="sheet-search-meta muted" data-site-price-search-meta hidden></span>';
    $html .= '</label>';
    $html .= '<label class="site-price-filter">Lane ';
    $html .= '<select data-site-price-filter="lane" data-no-draft aria-label="Lane">';
    $html .= '<option value="">All lanes</option>';
    foreach (site_price_lane_labels() as $slug => $label) {
        $sel = $lane === $slug ? ' selected' : '';
        $html .= '<option value="' . h($slug) . '"' . $sel . '>' . h($label) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label class="site-price-filter">Status ';
    $html .= '<select data-site-price-filter="status" data-no-draft aria-label="Status">';
    $html .= '<option value="">All statuses</option>';
    foreach (site_price_list_statuses() as $st) {
        $slug = (string) ($st['slug'] ?? '');
        $sel = $status === $slug ? ' selected' : '';
        $html .= '<option value="' . h($slug) . '"' . $sel . '>' . h((string) ($st['label'] ?? $slug)) . '</option>';
    }
    $html .= '</select></label>';
    if ($isAdmin) {
        $addedOpts = [];
        foreach ($rows as $row) {
            $view = site_price_row_for_viewer($row, $viewer);
            $label = trim((string) ($view['added_by_label'] ?? ''));
            if ($label !== '') {
                $addedOpts[$label] = $label;
            }
        }
        ksort($addedOpts, SORT_NATURAL | SORT_FLAG_CASE);
        $html .= '<label class="site-price-filter">Added by ';
        $html .= '<select data-site-price-filter="added" data-no-draft aria-label="Added by">';
        $html .= '<option value="">Anyone</option>';
        foreach ($addedOpts as $label) {
            $sel = $added === $label ? ' selected' : '';
            $html .= '<option value="' . h($label) . '"' . $sel . '>' . h($label) . '</option>';
        }
        $html .= '</select></label>';
    }
    if ($country !== '') {
        $html .= '<div class="site-price-tint-chips" role="group" aria-label="Color">';
        $chipOpts = ['' => 'All', 'none' => 'None', 'yellow' => 'Yellow', 'pink' => 'Pink', 'blue' => 'Blue', 'green' => 'Green'];
        foreach ($chipOpts as $slug => $label) {
            $on = $tint === $slug ? ' is-on' : '';
            $cls = $slug === '' ? 'is-all' : ($slug === 'none' ? 'is-none' : 'is-' . $slug);
            $next = $filters;
            $next['tint'] = $slug;
            $href = site_price_sheet_url($country, $pageKey, site_price_filter_query_extra($next, $perPage));
            $html .= '<a class="site-price-tint-chip ' . h($cls) . $on . '" data-site-price-filter="tint" data-tint="'
                . h($slug) . '" href="' . h($href) . '">' . h($label) . '</a>';
        }
        $html .= '</div>';
    }
    $matching = (int) ($counts['matching'] ?? -1);
    $totalAll = (int) ($counts['total_all'] ?? -1);
    if ($country !== '' && $matching >= 0 && $totalAll >= 0 && site_price_filters_active($filters)) {
        $html .= '<span class="muted site-price-match-count">'
            . (int) $matching . ' matching · ' . (int) $totalAll . ' in ' . h($country) . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="actions">';
    $html .= render_site_price_toolbar();
    if ($country !== '') {
        $html .= render_site_price_per_page_filter($pageKey, $country, $perPage, $filters);
    }
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function site_prices_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/site-prices.js')) . '" defer></script>';
}

/**
 * @param array{id?:int,role?:string} $viewer
 * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int,lane_counts:array<string,int>,tbody_html:string}
 */
function site_price_sheet_pack(
    string $country,
    array $viewer,
    int $page,
    int $perPage,
    ?int $focusId = null,
    array $filters = []
): array {
    $filters = site_price_normalize_filter_opts($filters);
    if ($focusId && $focusId > 0 && !site_price_filters_active($filters)) {
        $page = site_price_page_for_row_id($country, $focusId, $perPage);
    }
    $pack = list_site_price_rows_page($country, $page, $perPage, $filters, $viewer);
    $pack['tbody_html'] = render_site_price_sheet_tbody($pack['rows'], $viewer, $pack['lane_counts']);
    return $pack;
}

function render_site_price_jump_bar(string $preset = ''): string
{
    $open = trim($preset) !== '' ? ' open' : '';
    $html = '<details class="site-price-jump-wrap"' . $open . '>';
    $html .= '<summary class="btn-link">Find in other countries</summary>';
    $html .= '<div class="site-price-jump" data-site-price-jump>';
    $html .= '<label class="sheet-search" for="site-price-jump-q" style="margin:0">';
    $html .= '<span class="visually-hidden">Search all countries</span>';
    $html .= '<input id="site-price-jump-q" type="search" data-site-price-jump-q value="' . h($preset) . '"'
        . ' placeholder="Search all countries…" autocomplete="off" spellcheck="false" data-no-draft'
        . ' title="Search every country, then jump to the matching row">';
    $html .= '</label>';
    $html .= '<button type="button" class="btn secondary small" data-site-price-jump-go>Jump</button>';
    $html .= '<span class="muted site-price-jump-status" data-site-price-jump-status></span>';
    $html .= '<button type="button" class="btn-link" data-site-price-jump-prev hidden>Prev</button>';
    $html .= '<button type="button" class="btn-link" data-site-price-jump-next hidden>Next</button>';
    $html .= '</div>';
    $html .= '</details>';
    return $html;
}

function render_site_price_toolbar(): string
{
    $html = '<button type="button" class="btn secondary small" data-site-price-copy-selected'
        . ' title="Copies ticked websites on this page.">Copy selected</button>';
    return $html;
}

function render_site_price_per_page_filter(
    string $pageKey,
    string $country,
    int $current,
    array $filters = []
): string {
    $current = in_array($current, site_price_per_page_options(), true) ? $current : 100;
    $filters = site_price_normalize_filter_opts($filters);
    $html = '<form class="sheet-per-page-filter" method="get" action="index.php">';
    $html .= '<input type="hidden" name="page" value="' . h($pageKey) . '">';
    $html .= '<input type="hidden" name="country" value="' . h($country) . '">';
    foreach (['q', 'lane', 'status', 'tint', 'added'] as $key) {
        if ($filters[$key] !== '') {
            $html .= '<input type="hidden" name="' . h($key) . '" value="' . h($filters[$key]) . '">';
        }
    }
    $html .= '<label for="site_price_per_page">Per page</label>';
    $html .= '<select id="site_price_per_page" name="per_page" onchange="this.form.submit()" data-no-draft'
        . ' title="Rows per page on this country sheet">';
    foreach (site_price_per_page_options() as $n) {
        $sel = $n === $current ? ' selected' : '';
        $html .= '<option value="' . (int) $n . '"' . $sel . '>' . (int) $n . '</option>';
    }
    $html .= '</select></form>';
    return $html;
}

function render_site_price_pager(
    string $pageKey,
    string $country,
    int $pageNum,
    int $pages,
    int $perPage,
    array $filters = []
): string {
    $extra = site_price_filter_query_extra($filters, $perPage);
    $html = '<div class="site-price-pager" data-site-price-pager>';
    if ($pageNum > 1) {
        $html .= '<a href="' . h(site_price_sheet_url($country, $pageKey, $extra + ['p' => $pageNum - 1])) . '">Prev</a>';
    }
    $html .= '<span class="muted">Page ' . (int) $pageNum . ' / ' . (int) $pages . '</span>';
    if ($pageNum < $pages) {
        $html .= '<a href="' . h(site_price_sheet_url($country, $pageKey, $extra + ['p' => $pageNum + 1])) . '">Next</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Shared Admin / Team Website prices hub + country sheet.
 *
 * @param array<string,mixed> $user
 * @param 'admin'|'team' $panel
 */
function site_price_run_page(array $user, string $panel = 'admin'): void
{
    $panel = $panel === 'team' ? 'team' : 'admin';
    $pageKey = $panel === 'admin' ? 'admin_site_prices' : 'team_site_prices';
    $isAdmin = ($user['role'] ?? '') === 'admin';
    ensure_site_prices_schema();
    seed_countries_if_empty(db());

    $hubList = site_price_hub_list_url($pageKey);
    $sheet = trim((string) get('country'));
    $wantHub = (string) get('hub') === '1';
    $inCountry = false;
    $countryName = '';

    if ($sheet !== '') {
        $canon = resolve_canonical_country($sheet);
        if ($canon === null) {
            flash('error', 'That country is not in the country list.');
            redirect($hubList);
        }
        if ($canon['name'] !== $sheet) {
            redirect(site_price_sheet_url($canon['name'], $pageKey, [
                'per_page' => resolve_site_price_per_page() !== 100 ? resolve_site_price_per_page() : '',
                'p' => get('p'),
                'row' => get('row'),
            ]));
        }
        $inCountry = true;
        $countryName = $canon['name'];
    } elseif (!$wantHub && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $top = site_price_country_counts();
        if ($top !== []) {
            $first = (string) ($top[0]['country'] ?? '');
            if ($first !== '') {
                redirect(site_price_sheet_url($first, $pageKey));
            }
        }
    }

    $hubActions = ['add_status', 'delete_status'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) post('action'), $hubActions, true)) {
        $viewer = [
            'id' => (int) ($user['id'] ?? 0),
            'role' => (string) ($user['role'] ?? ''),
        ];
        $back = $inCountry ? site_price_sheet_url($countryName, $pageKey) : $hubList;
        try {
            if ((string) post('action') === 'add_status') {
                $st = site_price_add_custom_status((string) post('label'), $viewer);
                flash('ok', 'Added status word “' . (string) ($st['label'] ?? '') . '”.');
            } else {
                site_price_delete_custom_status((string) post('slug'), $viewer);
                flash('ok', 'Removed that status word.');
            }
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect($back . '#status-words');
    }

    $sheetActions = [
        'add_row', 'save_row', 'unlock_row', 'suggest_niche', 'reorder_lane',
        'claim_row', 'row_history', 'jump_search',
    ];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) post('action'), $sheetActions, true)) {
        $wantsJson = (string) post('ajax') === '1'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $jsonOut = static function (array $payload, int $code = 200) use ($wantsJson, $hubList, $pageKey): void {
            if ($wantsJson) {
                http_response_code($code);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!empty($payload['ok'])) {
                flash('ok', (string) ($payload['message'] ?? 'Saved.'));
            } else {
                flash('error', (string) ($payload['error'] ?? 'Could not save.'));
            }
            $back = trim((string) ($payload['country'] ?? ''));
            redirect($back !== '' ? site_price_sheet_url($back, $pageKey) : $hubList);
        };
        $action = (string) post('action');
        $viewer = [
            'id' => (int) ($user['id'] ?? 0),
            'role' => (string) ($user['role'] ?? ''),
        ];
        $perPagePost = resolve_site_price_per_page();
        $pagePost = max(1, (int) post('p', get('p', 1)));
        if ($action === 'jump_search') {
            try {
                $matches = site_price_jump_search((string) post('q'), $pageKey, $perPagePost);
                $jsonOut([
                    'ok' => true,
                    'matches' => $matches,
                    'total' => count($matches),
                ]);
            } catch (Throwable $e) {
                $jsonOut(['ok' => false, 'error' => 'Could not search.'], 400);
            }
        }
        $workCountry = trim((string) post('country'));
        if ($workCountry === '') {
            $workCountry = $countryName;
        }
        $canonWork = $workCountry !== '' ? resolve_canonical_country($workCountry) : null;
        if ($canonWork === null) {
            $jsonOut(['ok' => false, 'error' => 'Open a country sheet first.'], 400);
        }
        $workCountry = $canonWork['name'];
        $sheetFilters = site_price_filters_from_request($viewer);
        try {
            if ($action === 'suggest_niche') {
                $domain = site_price_normalize_domain((string) post('domain'));
                $niche = site_price_lookup_niche($domain, $workCountry);
                $chips = '';
                if ($niche !== '' && function_exists('prospect_parse_niches') && function_exists('prospect_niche_chip_html')) {
                    foreach (prospect_parse_niches($niche) as $label) {
                        $chips .= prospect_niche_chip_html($label);
                    }
                }
                $jsonOut(['ok' => true, 'niche' => $niche, 'chips_html' => $chips, 'country' => $workCountry]);
            }
            if ($action === 'add_row') {
                $row = site_price_add_row_for_user([
                    'country' => $workCountry,
                    'domain' => (string) post('domain'),
                    'niche' => (string) post('niche'),
                    'da' => (string) post('da'),
                    'dr' => (string) post('dr'),
                    'traffic' => (string) post('traffic'),
                    'price_note' => (string) post('price_note'),
                    'extra_note' => (string) post('extra_note'),
                    'reply_email' => (string) post('reply_email'),
                    'row_tint' => (string) post('row_tint'),
                    'status_slug' => (string) post('status_slug'),
                ], $viewer);
                $pack = site_price_sheet_pack($workCountry, $viewer, $pagePost, $perPagePost, (int) $row['id']);
                $jsonOut([
                    'ok' => true,
                    'id' => (int) $row['id'],
                    'domain' => (string) $row['domain'],
                    'country' => $workCountry,
                    'total' => $pack['total'],
                    'page' => $pack['page'],
                    'pages' => $pack['pages'],
                    'per_page' => $pack['per_page'],
                    'tbody_html' => $pack['tbody_html'],
                    'message' => 'Added ' . (string) $row['domain'] . '.',
                ]);
            }
            $siteId = (int) post('site_id');
            if ($action === 'save_row') {
                $fields = [
                    'niche' => (string) post('niche'),
                    'price_note' => (string) post('price_note'),
                    'extra_note' => (string) post('extra_note'),
                    'reply_email' => (string) post('reply_email'),
                    'row_tint' => (string) post('row_tint'),
                    'status_slug' => (string) post('status_slug'),
                ];
                if (array_key_exists('domain', $_POST)) {
                    $fields['domain'] = (string) post('domain');
                    $fields['da'] = (string) post('da');
                    $fields['dr'] = (string) post('dr');
                    $fields['traffic'] = (string) post('traffic');
                }
                $row = site_price_save_row($siteId, $fields, $viewer);
                if ((string) ($row['country'] ?? '') !== $workCountry) {
                    throw new RuntimeException('Site not found.');
                }
                $pack = site_price_sheet_pack($workCountry, $viewer, $pagePost, $perPagePost, null, $sheetFilters);
                $jsonOut([
                    'ok' => true,
                    'id' => (int) $row['id'],
                    'country' => $workCountry,
                    'total' => $pack['total'],
                    'page' => $pack['page'],
                    'pages' => $pack['pages'],
                    'per_page' => $pack['per_page'],
                    'tbody_html' => $pack['tbody_html'],
                    'message' => 'Saved.',
                ]);
            }
            if ($action === 'unlock_row') {
                $row = site_price_unlock_row($siteId, $viewer);
                if ((string) ($row['country'] ?? '') !== $workCountry) {
                    throw new RuntimeException('Site not found.');
                }
                $pack = site_price_sheet_pack($workCountry, $viewer, $pagePost, $perPagePost, null, $sheetFilters);
                $jsonOut([
                    'ok' => true,
                    'id' => (int) $row['id'],
                    'country' => $workCountry,
                    'total' => $pack['total'],
                    'page' => $pack['page'],
                    'pages' => $pack['pages'],
                    'tbody_html' => $pack['tbody_html'],
                    'message' => 'Unlocked.',
                ]);
            }
            if ($action === 'claim_row') {
                $row = site_price_claim_row($siteId, $viewer);
                if ((string) ($row['country'] ?? '') !== $workCountry) {
                    throw new RuntimeException('Site not found.');
                }
                $pack = site_price_sheet_pack($workCountry, $viewer, $pagePost, $perPagePost, null, $sheetFilters);
                $jsonOut([
                    'ok' => true,
                    'id' => (int) $row['id'],
                    'country' => $workCountry,
                    'total' => $pack['total'],
                    'page' => $pack['page'],
                    'pages' => $pack['pages'],
                    'tbody_html' => $pack['tbody_html'],
                    'message' => 'You are managing this site.',
                ]);
            }
            if ($action === 'row_history') {
                $row = get_site_price_row($siteId);
                if (!$row || (string) ($row['country'] ?? '') !== $workCountry) {
                    throw new RuntimeException('Site not found.');
                }
                $jsonOut([
                    'ok' => true,
                    'id' => $siteId,
                    'country' => $workCountry,
                    'html' => render_site_price_history_html($siteId, $viewer),
                ]);
            }
            if ($action === 'reorder_lane') {
                $ids = preg_split('/[,\s]+/', trim((string) post('ids'))) ?: [];
                site_price_reorder_lane($workCountry, (string) post('lane'), $ids, $viewer);
                $pack = site_price_sheet_pack($workCountry, $viewer, $pagePost, $perPagePost, null, $sheetFilters);
                $jsonOut([
                    'ok' => true,
                    'country' => $workCountry,
                    'total' => $pack['total'],
                    'page' => $pack['page'],
                    'pages' => $pack['pages'],
                    'tbody_html' => $pack['tbody_html'],
                    'message' => 'Order saved.',
                ]);
            }
            $jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
        } catch (InvalidArgumentException $e) {
            $jsonOut(['ok' => false, 'error' => $e->getMessage(), 'country' => $workCountry], 400);
        } catch (RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'Only Admin')
                ? 403
                : 400;
            $jsonOut(['ok' => false, 'error' => $e->getMessage(), 'country' => $workCountry], $code);
        }
    }

    $dashHref = $panel === 'admin' ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard';

    if ($inCountry) {
        $perPage = resolve_site_price_per_page();
        $pageNum = max(1, (int) get('p', 1));
        $jumpRowId = max(0, (int) get('row'));
        $filters = site_price_filters_from_request($user);
        $pack = site_price_sheet_pack(
            $countryName,
            $user,
            $pageNum,
            $perPage,
            $jumpRowId > 0 ? $jumpRowId : null,
            $jumpRowId > 0 ? [] : $filters
        );
        if ($jumpRowId > 0) {
            $filters = site_price_normalize_filter_opts([]);
        }
        $rows = $pack['rows'];
        $total = $pack['total'];
        $pageNum = $pack['page'];
        $pages = $pack['pages'];
        render_header('Website prices · ' . $countryName, $panel);
        render_breadcrumbs([
            ['label' => 'Dashboard', 'href' => $dashHref],
            ['label' => 'Website prices', 'href' => $hubList],
            ['label' => $countryName],
        ]);
        ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info(
            $countryName,
            'Publisher prices and statuses for this country. Website, DA, DR, and traffic lock after save. Team edits price and status.'
        ) ?></h1>
        <p class="muted">
          <span data-site-price-count><?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?></span>
          · Processing stays first, then New, then Other.
          Website, DA, DR, and traffic lock after add.<?= $isAdmin ? ' Admin can drag inside a lane on this page.' : '' ?>
        </p>
        <p class="help site-price-status-msg" data-site-price-status-msg role="status" hidden></p>
      </div>
      <div class="actions">
        <?php if ($isAdmin): ?>
        <a class="btn secondary" href="<?= h($hubList) ?>#status-words">Status words</a>
        <?php endif; ?>
        <a class="btn secondary" href="<?= h($hubList) ?>">All countries</a>
      </div>
    </div>

    <?= render_site_price_filters(
        $user,
        $pack['all'] ?? $rows,
        $pageKey,
        $countryName,
        $perPage,
        $filters,
        ['matching' => (int) $total, 'total_all' => (int) ($pack['total_all'] ?? $total)]
    ) ?>
    <?= render_site_price_jump_bar((string) get('jump')) ?>
    <div class="site-price-switcher">
      <?= render_site_price_country_tabs($countryName, $pageKey, ['per_page' => $perPage]) ?>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table class="sheet-cards-mobile site-price-sheet" data-site-price-sheet data-country="<?= h($countryName) ?>"
               data-admin="<?= $isAdmin ? '1' : '0' ?>"
               data-page="<?= (int) $pageNum ?>" data-pages="<?= (int) $pages ?>"
               data-per-page="<?= (int) $perPage ?>"
               <?php if ($jumpRowId > 0): ?>data-jump-row="<?= (int) $jumpRowId ?>"<?php endif; ?>>
          <colgroup>
            <col class="sp-col-check">
            <col class="sp-col-site">
            <col class="sp-col-niche">
            <col class="sp-col-metric">
            <col class="sp-col-metric">
            <col class="sp-col-metric">
            <col class="sp-col-price">
            <col class="sp-col-status">
            <col class="sp-col-note">
            <col class="sp-col-email">
            <col class="sp-col-people">
            <col class="sp-col-actions">
          </colgroup>
          <thead>
            <tr>
              <th class="site-price-check-th">
                <input type="checkbox" data-site-price-select-all data-no-draft aria-label="Select visible rows">
              </th>
              <th>Website</th>
              <th>Niche</th>
              <th>DA</th>
              <th>DR</th>
              <th>Traffic</th>
              <th>Price</th>
              <th>Status</th>
              <th>Note</th>
              <th>Email</th>
              <th>People</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody data-site-price-tbody>
            <?= $pack['tbody_html'] ?>
          </tbody>
        </table>
      </div>
    </div>
    <?= render_site_price_pager($pageKey, $countryName, $pageNum, $pages, $perPage, $filters) ?>
    <?= prospect_niche_taxonomy_script() ?>
    <?= niche_chips_script_tag() ?>
    <?= open_site_script_tag() ?>
    <?= site_prices_script_tag() ?>
        <?php
        render_footer($panel);
        return;
    }

    $folders = site_price_country_counts();
    $grand = 0;
    foreach ($folders as $f) {
        $grand += (int) $f['total'];
    }

    render_header('Website prices', $panel);
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => $dashHref],
        ['label' => 'Website prices'],
    ]);
    ?>
<div class="topbar">
  <div>
    <h1><?= label_with_info(
        'Website prices',
        'Office publisher rate book. One country sheet: prices and statuses. Site name, DA, DR, and traffic lock after they are saved.'
    ) ?></h1>
    <p class="muted">
      <?= (int) $grand ?> site<?= (int) $grand === 1 ? '' : 's' ?>
      in <?= count($folders) ?> countr<?= count($folders) === 1 ? 'y' : 'ies' ?>.
      Open a country to see that sheet.
    </p>
  </div>
</div>

<?= guide_site_prices() ?>

<?php if ($isAdmin): ?>
<?= render_site_price_status_words_card($user, $pageKey) ?>
<?php endif; ?>

<div class="card" id="open-country">
  <h2 style="margin:0 0 0.45rem">Open a country sheet</h2>
  <p class="help" style="margin-top:0">Country is chosen here. New rows on that sheet will use this country automatically.</p>
  <form method="get" action="index.php" class="form-grid" autocomplete="off" data-no-draft>
    <input type="hidden" name="page" value="<?= h($pageKey) ?>">
    <?= render_country_typeahead('', [
        'id' => 'site_price_country',
        'label' => 'Country',
        'required' => true,
        'placeholder' => 'Type a country, Enter to select',
    ]) ?>
    <p class="actions" style="margin-top:0.35rem;align-self:end">
      <button class="btn" type="submit">Open sheet</button>
    </p>
  </form>
</div>
<?= sites_form_script_tag() ?>

<?php if ($folders === []): ?>
<div class="card" style="margin-top:1rem">
  <div class="empty-state">
    <p>No country sheets have sites yet.</p>
    <p class="muted">Open a country above to start its sheet. Add sites on the country sheet.</p>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-top:1rem">
  <div class="invoice-list-toolbar" style="margin-bottom:0.65rem">
    <h2 style="margin:0">Countries with prices</h2>
    <label class="sheet-search" for="site-price-country-search" style="margin:0">
      <span class="visually-hidden">Search countries</span>
      <input id="site-price-country-search" type="search" placeholder="Search country…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type a country · Enter = next match">
    </label>
  </div>
  <div class="table-wrap">
    <table id="site-price-country-table">
      <thead>
        <tr>
          <th>Country</th>
          <th class="num">Sites</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f):
          $c = (string) $f['country'];
          $updated = substr((string) ($f['updated_at'] ?? ''), 0, 16);
          $hay = mb_strtolower(trim($c . ' ' . (int) $f['total'] . ' ' . $updated));
          ?>
        <tr data-site-price-country-row data-search="<?= h($hay) ?>">
          <td><a href="<?= h(site_price_sheet_url($c, $pageKey)) ?>"><strong><?= h($c) ?></strong></a></td>
          <td class="num"><?= (int) $f['total'] ?></td>
          <td class="muted"><?= h($updated) ?></td>
          <td><a class="btn secondary small" href="<?= h(site_price_sheet_url($c, $pageKey)) ?>">Open sheet</a></td>
        </tr>
      <?php endforeach; ?>
        <tr class="sheet-search-empty" data-site-price-country-empty hidden>
          <td colspan="4" class="muted">No countries match your search.</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('site-price-country-search');
  if (!input) return;
  var matchIndex = -1;
  function norm(s) { return String(s || '').trim().toLowerCase(); }
  function visibleRows() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-site-price-country-row]')).filter(function (row) {
      return !row.hidden;
    });
  }
  input.addEventListener('input', function () {
    var q = norm(input.value);
    var any = false;
    matchIndex = -1;
    document.querySelectorAll('[data-site-price-country-row]').forEach(function (row) {
      var hit = !q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1;
      row.hidden = !hit;
      row.classList.remove('sheet-search-hit');
      if (hit) any = true;
    });
    var empty = document.querySelector('[data-site-price-country-empty]');
    if (empty) empty.hidden = !q || any;
  });
  input.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var rows = visibleRows();
    if (!rows.length) return;
    matchIndex = (matchIndex + 1) % rows.length;
    rows.forEach(function (r) { r.classList.remove('sheet-search-hit'); });
    rows[matchIndex].classList.add('sheet-search-hit');
    try { rows[matchIndex].scrollIntoView({ block: 'nearest' }); } catch (err) { rows[matchIndex].scrollIntoView(true); }
  });
})();
</script>
<?php endif; ?>
    <?php
    render_footer($panel);
}
