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
 * Optional language <select>: blank first, then catalog languages (+ current value if custom).
 */
function render_language_select(string $name, string $selected = '', string $id = ''): string
{
    $idAttr = $id !== '' ? ' id="' . h($id) . '"' : '';
    $html = '<select name="' . h($name) . '"' . $idAttr . '>';
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
