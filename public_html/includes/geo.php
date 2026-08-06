<?php

function regions(): array
{
    return [
        'europe' => 'Europe',
        'north_america' => 'North America',
        'english' => 'English markets',
        'other' => 'Other',
    ];
}

/**
 * Full country catalog: Europe + North America + English markets (+ a few Other).
 * Each row: [region, code, name, default_language]
 *
 * @return list<array{0:string,1:string,2:string,3:string}>
 */
function country_catalog(): array
{
    return [
        // --- Europe (all) ---
        ['europe', 'AL', 'Albania', 'Albanian'],
        ['europe', 'AD', 'Andorra', 'Catalan'],
        ['europe', 'AT', 'Austria', 'German'],
        ['europe', 'BY', 'Belarus', 'Russian'],
        ['europe', 'BE', 'Belgium', 'Dutch'],
        ['europe', 'BA', 'Bosnia and Herzegovina', 'Bosnian'],
        ['europe', 'BG', 'Bulgaria', 'Bulgarian'],
        ['europe', 'HR', 'Croatia', 'Croatian'],
        ['europe', 'CY', 'Cyprus', 'Greek'],
        ['europe', 'CZ', 'Czech Republic', 'Czech'],
        ['europe', 'DK', 'Denmark', 'Danish'],
        ['europe', 'EE', 'Estonia', 'Estonian'],
        ['europe', 'FI', 'Finland', 'Finnish'],
        ['europe', 'FR', 'France', 'French'],
        ['europe', 'DE', 'Germany', 'German'],
        ['europe', 'GR', 'Greece', 'Greek'],
        ['europe', 'HU', 'Hungary', 'Hungarian'],
        ['europe', 'IS', 'Iceland', 'Icelandic'],
        ['europe', 'IE', 'Ireland', 'English'],
        ['europe', 'IT', 'Italy', 'Italian'],
        ['europe', 'XK', 'Kosovo', 'Albanian'],
        ['europe', 'LV', 'Latvia', 'Latvian'],
        ['europe', 'LI', 'Liechtenstein', 'German'],
        ['europe', 'LT', 'Lithuania', 'Lithuanian'],
        ['europe', 'LU', 'Luxembourg', 'French'],
        ['europe', 'MT', 'Malta', 'English'],
        ['europe', 'MD', 'Moldova', 'Romanian'],
        ['europe', 'MC', 'Monaco', 'French'],
        ['europe', 'ME', 'Montenegro', 'Montenegrin'],
        ['europe', 'NL', 'Netherlands', 'Dutch'],
        ['europe', 'MK', 'North Macedonia', 'Macedonian'],
        ['europe', 'NO', 'Norway', 'Norwegian'],
        ['europe', 'PL', 'Poland', 'Polish'],
        ['europe', 'PT', 'Portugal', 'Portuguese'],
        ['europe', 'RO', 'Romania', 'Romanian'],
        ['europe', 'RU', 'Russia', 'Russian'],
        ['europe', 'SM', 'San Marino', 'Italian'],
        ['europe', 'RS', 'Serbia', 'Serbian'],
        ['europe', 'SK', 'Slovakia', 'Slovak'],
        ['europe', 'SI', 'Slovenia', 'Slovenian'],
        ['europe', 'ES', 'Spain', 'Spanish'],
        ['europe', 'SE', 'Sweden', 'Swedish'],
        ['europe', 'CH', 'Switzerland', 'German'],
        ['europe', 'UA', 'Ukraine', 'Ukrainian'],
        ['europe', 'GB', 'United Kingdom', 'English'],
        ['europe', 'VA', 'Vatican City', 'Italian'],

        // --- North America (Northern + Central + Caribbean) ---
        ['north_america', 'AG', 'Antigua and Barbuda', 'English'],
        ['north_america', 'BS', 'Bahamas', 'English'],
        ['north_america', 'BB', 'Barbados', 'English'],
        ['north_america', 'BZ', 'Belize', 'English'],
        ['north_america', 'BM', 'Bermuda', 'English'],
        ['north_america', 'CA', 'Canada', 'English'],
        ['north_america', 'CR', 'Costa Rica', 'Spanish'],
        ['north_america', 'CU', 'Cuba', 'Spanish'],
        ['north_america', 'DM', 'Dominica', 'English'],
        ['north_america', 'DO', 'Dominican Republic', 'Spanish'],
        ['north_america', 'SV', 'El Salvador', 'Spanish'],
        ['north_america', 'GL', 'Greenland', 'Danish'],
        ['north_america', 'GD', 'Grenada', 'English'],
        ['north_america', 'GT', 'Guatemala', 'Spanish'],
        ['north_america', 'HT', 'Haiti', 'French'],
        ['north_america', 'HN', 'Honduras', 'Spanish'],
        ['north_america', 'JM', 'Jamaica', 'English'],
        ['north_america', 'MX', 'Mexico', 'Spanish'],
        ['north_america', 'NI', 'Nicaragua', 'Spanish'],
        ['north_america', 'PA', 'Panama', 'Spanish'],
        ['north_america', 'KN', 'Saint Kitts and Nevis', 'English'],
        ['north_america', 'LC', 'Saint Lucia', 'English'],
        ['north_america', 'VC', 'Saint Vincent and the Grenadines', 'English'],
        ['north_america', 'TT', 'Trinidad and Tobago', 'English'],
        ['north_america', 'US', 'United States', 'English'],

        // --- English markets (outside Europe / North America) ---
        ['english', 'AU', 'Australia', 'English'],
        ['english', 'BD', 'Bangladesh', 'English'],
        ['english', 'BW', 'Botswana', 'English'],
        ['english', 'CM', 'Cameroon', 'English'],
        ['english', 'GH', 'Ghana', 'English'],
        ['english', 'GY', 'Guyana', 'English'],
        ['english', 'HK', 'Hong Kong', 'English'],
        ['english', 'IN', 'India', 'English'],
        ['english', 'KE', 'Kenya', 'English'],
        ['english', 'MW', 'Malawi', 'English'],
        ['english', 'MY', 'Malaysia', 'English'],
        ['english', 'MU', 'Mauritius', 'English'],
        ['english', 'NA', 'Namibia', 'English'],
        ['english', 'NG', 'Nigeria', 'English'],
        ['english', 'NZ', 'New Zealand', 'English'],
        ['english', 'PK', 'Pakistan', 'English'],
        ['english', 'PG', 'Papua New Guinea', 'English'],
        ['english', 'PH', 'Philippines', 'English'],
        ['english', 'RW', 'Rwanda', 'English'],
        ['english', 'SG', 'Singapore', 'English'],
        ['english', 'ZA', 'South Africa', 'English'],
        ['english', 'LK', 'Sri Lanka', 'English'],
        ['english', 'TZ', 'Tanzania', 'English'],
        ['english', 'UG', 'Uganda', 'English'],
        ['english', 'ZM', 'Zambia', 'English'],
        ['english', 'ZW', 'Zimbabwe', 'English'],

        // --- Other (kept for existing installs) ---
        ['other', 'BR', 'Brazil', 'Portuguese'],
        ['other', 'JP', 'Japan', 'Japanese'],
        ['other', 'KR', 'South Korea', 'Korean'],
        ['other', 'AE', 'United Arab Emirates', 'Arabic'],
    ];
}

/**
 * Unique default languages from the catalog (for optional language pickers).
 *
 * @return list<string>
 */
function catalog_languages(): array
{
    $langs = [];
    foreach (country_catalog() as $row) {
        $lang = trim((string) $row[3]);
        if ($lang !== '') {
            $langs[$lang] = true;
        }
    }
    $list = array_keys($langs);
    natcasesort($list);
    return array_values($list);
}

/**
 * Insert missing countries and refresh region / default language for known names.
 * Runs at most once per request; skips when the DB already has the full catalog.
 */
function sync_countries(PDO $pdo, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS countries (
          id INT AUTO_INCREMENT PRIMARY KEY,
          region VARCHAR(40) NOT NULL DEFAULT 'other',
          code VARCHAR(10) NOT NULL DEFAULT '',
          name VARCHAR(100) NOT NULL,
          default_language VARCHAR(50) NOT NULL DEFAULT '',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          UNIQUE KEY uniq_country_name (name),
          INDEX (region),
          INDEX (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $catalog = country_catalog();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM countries')->fetchColumn();
    if (!$force && $count >= count($catalog)) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO countries (region, code, name, default_language, is_active)
         VALUES (?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           region = VALUES(region),
           code = VALUES(code),
           default_language = VALUES(default_language),
           is_active = 1'
    );
    foreach ($catalog as $r) {
        $ins->execute($r);
    }
}

/** @deprecated use sync_countries() — kept so older call sites still work */
function seed_countries_if_empty(PDO $pdo): void
{
    sync_countries($pdo);
}

function list_countries(?string $region = null, bool $activeOnly = true): array
{
    sync_countries(db());
    $sql = 'SELECT * FROM countries WHERE 1=1';
    $params = [];
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if ($region) {
        $sql .= ' AND region = ?';
        $params[] = $region;
    }
    $sql .= ' ORDER BY region, name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countries_grouped(): array
{
    $grouped = [];
    foreach (regions() as $code => $label) {
        $grouped[$code] = ['label' => $label, 'countries' => []];
    }
    foreach (list_countries(null, true) as $c) {
        $reg = $c['region'] ?: 'other';
        if (!isset($grouped[$reg])) {
            $grouped[$reg] = ['label' => regions()[$reg] ?? $reg, 'countries' => []];
        }
        $grouped[$reg]['countries'][] = $c;
    }
    return $grouped;
}

/**
 * Countries this user adds most often (from Add history batches).
 * Recent activity (last 30 days) is weighted higher.
 *
 * @return list<array{name:string,score:float,sites:int,days:int,last_date:?string}>
 */
function user_frequent_countries(int $userId, int $limit = 8): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        if (function_exists('ensure_prospect_schema')) {
            ensure_prospect_schema();
        }
        $stmt = db()->prepare(
            "SELECT TRIM(country) AS name,
                    SUM(site_count) AS sites,
                    COUNT(*) AS days,
                    MAX(batch_date) AS last_date,
                    SUM(
                      CASE
                        WHEN batch_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          THEN site_count * 3
                        ELSE site_count
                      END
                    ) AS score
             FROM prospect_batches
             WHERE user_id = ?
               AND TRIM(country) <> ''
             GROUP BY TRIM(country)
             ORDER BY score DESC, sites DESC, last_date DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'score' => (float) ($row['score'] ?? 0),
                'sites' => (int) ($row['sites'] ?? 0),
                'days' => (int) ($row['days'] ?? 0),
                'last_date' => $row['last_date'] !== null ? (string) $row['last_date'] : null,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Top country name for a user, or '' if none. */
function user_top_country(int $userId): string
{
    $list = user_frequent_countries($userId, 1);
    return $list[0]['name'] ?? '';
}

/**
 * Country <select> with type-to-search, optional “Often used” group first.
 *
 * @param list<array{name:string,...}>|null $frequent from user_frequent_countries()
 */
function render_country_select(
    string $name,
    string $selected = '',
    string $id = '',
    bool $required = false,
    ?array $frequent = null,
    string $placeholder = '— Select country —'
): string {
    $byName = [];
    foreach (list_countries(null, true) as $c) {
        $byName[(string) $c['name']] = $c;
    }

    $idAttr = $id !== '' ? ' id="' . h($id) . '"' : '';
    $reqAttr = $required ? ' required' : '';
    $html = '<select name="' . h($name) . '"' . $idAttr . $reqAttr . ' data-searchable="1">';
    $html .= '<option value="">' . h($placeholder) . '</option>';

    $optionHtml = static function (string $n, array $meta, string $selected, string $label = '') use (&$byName): string {
        if ($label === '') {
            $label = $n;
        }
        $region = (string) ($meta['region'] ?? ($byName[$n]['region'] ?? ''));
        $lang = (string) ($meta['default_language'] ?? ($byName[$n]['default_language'] ?? ''));
        $sel = strcasecmp($selected, $n) === 0 ? ' selected' : '';
        return '<option value="' . h($n) . '" data-region="' . h($region) . '" data-lang="' . h($lang) . '"' . $sel . '>'
            . h($label) . '</option>';
    };

    $frequentNames = [];
    if ($frequent) {
        foreach ($frequent as $f) {
            $n = trim((string) ($f['name'] ?? ''));
            if ($n !== '') {
                $frequentNames[$n] = true;
            }
        }
        if ($frequentNames !== []) {
            $html .= '<optgroup label="Often used">';
            foreach ($frequent as $f) {
                $n = trim((string) ($f['name'] ?? ''));
                if ($n === '') {
                    continue;
                }
                $sites = (int) ($f['sites'] ?? 0);
                $label = $n . ($sites > 0 ? ' · ' . $sites . ' added' : '');
                $html .= $optionHtml($n, $byName[$n] ?? [], $selected, $label);
            }
            $html .= '</optgroup>';
        }
    }

    foreach (countries_grouped() as $block) {
        if (empty($block['countries'])) {
            continue;
        }
        $html .= '<optgroup label="' . h((string) $block['label']) . '">';
        foreach ($block['countries'] as $c) {
            $n = (string) $c['name'];
            if (isset($frequentNames[$n])) {
                continue;
            }
            $html .= $optionHtml($n, $c, $selected);
        }
        $html .= '</optgroup>';
    }

    if ($selected !== '' && !isset($byName[$selected]) && !isset($frequentNames[$selected])) {
        $html .= $optionHtml($selected, [], $selected);
    }

    $html .= '</select>';
    return $html;
}

/**
 * Optional language <select> with type-to-search.
 */
function render_language_select(string $name, string $selected = '', string $id = ''): string
{
    $idAttr = $id !== '' ? ' id="' . h($id) . '"' : '';
    $html = '<select name="' . h($name) . '"' . $idAttr . ' data-searchable="1">';
    $html .= '<option value="">— Optional —</option>';
    $seen = [];
    foreach (catalog_languages() as $lang) {
        $seen[$lang] = true;
        $sel = strcasecmp($selected, $lang) === 0 ? ' selected' : '';
        $html .= '<option value="' . h($lang) . '"' . $sel . '>' . h($lang) . '</option>';
    }
    if ($selected !== '' && !isset($seen[$selected])) {
        $html .= '<option value="' . h($selected) . '" selected>' . h($selected) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Region <select> with type-to-search.
 */
function render_region_select(string $name, string $selected = '', string $id = ''): string
{
    $idAttr = $id !== '' ? ' id="' . h($id) . '"' : '';
    $html = '<select name="' . h($name) . '"' . $idAttr . ' data-searchable="1">';
    $html .= '<option value="">—</option>';
    foreach (regions() as $k => $v) {
        $sel = $selected === $k ? ' selected' : '';
        $html .= '<option value="' . h($k) . '"' . $sel . '>' . h($v) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Shortcut chips for often-used countries (links).
 *
 * @param list<array{name:string,sites?:int}> $frequent
 */
function render_frequent_country_chips(array $frequent, string $hrefPrefix): string
{
    if ($frequent === []) {
        return '';
    }
    $html = '<div class="usage-chips" aria-label="Countries you use most">';
    $html .= '<span class="usage-chips-label">Often used:</span>';
    foreach ($frequent as $f) {
        $n = trim((string) ($f['name'] ?? ''));
        if ($n === '') {
            continue;
        }
        $sites = (int) ($f['sites'] ?? 0);
        $html .= '<a class="usage-chip" href="' . h($hrefPrefix . rawurlencode($n)) . '">'
            . h($n)
            . ($sites > 0 ? ' <span class="muted">(' . $sites . ')</span>' : '')
            . '</a>';
    }
    $html .= '</div>';
    return $html;
}

function distinct_site_languages(): array
{
    // Legacy helper (sites table may be gone); fall back to catalog.
    try {
        $rows = db()->query(
            "SELECT DISTINCT language FROM sites WHERE language <> '' ORDER BY language"
        )->fetchAll();
        if ($rows) {
            return array_column($rows, 'language');
        }
    } catch (Throwable $e) {
        // ignore
    }
    return catalog_languages();
}

function apply_site_geo_filters(array &$where, array &$params, array $filters): void
{
    if (!empty($filters['region'])) {
        $where[] = 's.region = ?';
        $params[] = $filters['region'];
    }
    if (!empty($filters['country'])) {
        $where[] = 's.country = ?';
        $params[] = $filters['country'];
    }
    if (!empty($filters['language'])) {
        $where[] = 's.language = ?';
        $params[] = $filters['language'];
    }
}
