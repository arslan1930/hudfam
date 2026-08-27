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
        $locked = array_key_exists('identity_locked', $fields)
            ? ((int) $fields['identity_locked'] ? 1 : 0)
            : 1;
        db()->prepare(
            'INSERT INTO site_price_rows
              (country, domain, niche, da, dr, traffic, price_note, extra_note, status_slug,
               identity_locked, created_by, managed_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
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
                mu.username AS managed_by_username, mu.full_name AS managed_by_full
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

    $oldStatus = (string) ($row['status_slug'] ?? '');
    $oldPrice = (string) ($row['price_note'] ?? '');

    try {
        db()->prepare(
            'UPDATE site_price_rows
             SET domain=?, niche=?, da=?, dr=?, traffic=?, price_note=?, extra_note=?, status_slug=?,
                 identity_locked=1, updated_at=NOW()
             WHERE id=?'
        )->execute([$domain, $niche, $da, $dr, $traffic, $price, $note, $status, $id]);
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
    $saved = get_site_price_row($id);
    if (!$saved) {
        throw new RuntimeException('Site not found.');
    }
    return $saved;
}

function site_price_status_select_html(string $current, string $id = ''): string
{
    $current = site_price_normalize_status($current);
    $html = '<select class="site-price-status" data-site-price-status data-no-draft'
        . ($id !== '' ? ' id="' . h($id) . '"' : '')
        . ' aria-label="Status">';
    foreach (site_price_list_statuses() as $st) {
        $slug = (string) $st['slug'];
        $sel = $slug === $current ? ' selected' : '';
        $html .= '<option value="' . h($slug) . '"' . $sel . '>' . h((string) $st['label']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function site_price_locked_text(string $value, bool $copyLock): string
{
    $show = $value !== '' ? $value : '—';
    $cls = 'site-price-id is-locked' . ($copyLock ? ' is-copy-lock' : '');
    return '<span class="' . h($cls) . '">' . h($show) . '</span>';
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
    $hay = mb_strtolower(trim(
        $domain . ' ' . (string) ($view['niche'] ?? '') . ' ' . (string) ($view['price_note'] ?? '')
        . ' ' . (string) ($view['status_slug'] ?? '')
    ));
    $html = '<tr class="site-price-row" data-site-price-row data-row-id="' . $id . '"'
        . ' data-locked="' . ($locked ? '1' : '0') . '" data-search="' . h($hay) . '">';

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
    $html .= '<td data-label="Actions" class="site-price-actions">';
    if ($isAdmin && $locked) {
        $html .= '<button type="button" class="btn secondary small" data-site-price-unlock>Unlock</button>';
    }
    $html .= '</td>';
    $html .= '</tr>';
    return $html;
}

function render_site_price_add_row(): string
{
    $html = '<tr class="site-price-add" data-site-price-add>';
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
    $html .= '<td data-label="Price"><input type="text" class="site-price-input" data-add-price placeholder="60 euro article only" autocomplete="off" spellcheck="false" data-no-draft aria-label="Price"></td>';
    $html .= '<td data-label="Status">' . site_price_status_select_html('new') . '</td>';
    $html .= '<td data-label="Note"><input type="text" class="site-price-input" data-add-note autocomplete="off" spellcheck="false" data-no-draft aria-label="Note"></td>';
    $html .= '<td data-label="Actions"><button type="button" class="btn small" data-site-price-add-btn>Add site</button></td>';
    $html .= '</tr>';
    return $html;
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array{id?:int,role?:string} $viewer
 */
function render_site_price_sheet_tbody(array $rows, array $viewer): string
{
    $html = render_site_price_add_row();
    foreach ($rows as $row) {
        $html .= render_site_price_sheet_row($row, $viewer);
    }
    return $html;
}

function site_prices_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/site-prices.js')) . '" defer></script>';
}
