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

/**
 * Generic / global TLDs — ignored when scoring country mismatch
 * (a Spanish .com list should not look "wrong").
 *
 * @return list<string>
 */
function generic_tlds(): array
{
    return [
        'com', 'net', 'org', 'info', 'biz', 'io', 'co', 'app', 'dev', 'xyz',
        'online', 'site', 'website', 'store', 'shop', 'blog', 'cloud', 'tech',
        'eu', 'intl', 'name', 'pro', 'tv', 'me', 'cc', 'ws',
    ];
}

/**
 * Expected country-code / regional TLDs for soft mismatch warnings.
 * Not exclusive — neighbors included where markets overlap (e.g. DACH).
 *
 * @return array<string, list<string>> country name (lowercase) => tld suffixes
 */
function country_expected_tlds_map(): array
{
    return [
        // DACH
        'germany' => ['de', 'at', 'ch', 'co.at'],
        'austria' => ['at', 'co.at', 'de', 'ch'],
        'switzerland' => ['ch', 'de', 'at', 'li', 'co.at'],
        'liechtenstein' => ['li', 'ch', 'de', 'at'],
        // Romance / Iberia
        'france' => ['fr', 're', 'pm', 'yt', 'tf', 'wf', 'nc', 'pf'],
        'spain' => ['es', 'cat', 'gal', 'eus'],
        'portugal' => ['pt', 'com.pt'],
        'italy' => ['it'],
        'belgium' => ['be'],
        'luxembourg' => ['lu'],
        'monaco' => ['mc'],
        'andorra' => ['ad'],
        'san marino' => ['sm'],
        'vatican city' => ['va'],
        // British Isles
        'united kingdom' => ['uk', 'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'net.uk', 'scot', 'wales', 'cymru'],
        'ireland' => ['ie'],
        'malta' => ['mt'],
        // Nordics / Baltics
        'sweden' => ['se'],
        'norway' => ['no'],
        'denmark' => ['dk'],
        'finland' => ['fi', 'ax'],
        'iceland' => ['is'],
        'estonia' => ['ee'],
        'latvia' => ['lv'],
        'lithuania' => ['lt'],
        // Central / East Europe
        'netherlands' => ['nl'],
        'poland' => ['pl', 'com.pl'],
        'czech republic' => ['cz'],
        'slovakia' => ['sk'],
        'hungary' => ['hu'],
        'romania' => ['ro', 'com.ro'],
        'bulgaria' => ['bg'],
        'greece' => ['gr'],
        'cyprus' => ['cy'],
        'croatia' => ['hr'],
        'slovenia' => ['si'],
        'serbia' => ['rs'],
        'bosnia and herzegovina' => ['ba'],
        'montenegro' => ['me'],
        'albania' => ['al'],
        'north macedonia' => ['mk'],
        'kosovo' => ['xk'],
        'moldova' => ['md'],
        'ukraine' => ['ua', 'com.ua'],
        'belarus' => ['by'],
        'russia' => ['ru', 'su', 'рф'],
        // North America
        'united states' => ['us', 'edu', 'gov', 'mil'],
        'canada' => ['ca'],
        'mexico' => ['mx', 'com.mx'],
        'guatemala' => ['gt', 'com.gt'],
        'belize' => ['bz'],
        'honduras' => ['hn', 'com.hn'],
        'el salvador' => ['sv', 'com.sv'],
        'nicaragua' => ['ni', 'com.ni'],
        'costa rica' => ['cr', 'co.cr'],
        'panama' => ['pa', 'com.pa'],
        'cuba' => ['cu'],
        'dominican republic' => ['do', 'com.do'],
        'haiti' => ['ht'],
        'jamaica' => ['jm', 'com.jm'],
        'trinidad and tobago' => ['tt', 'com.tt'],
        'bahamas' => ['bs', 'com.bs'],
        'barbados' => ['bb', 'com.bb'],
        'antigua and barbuda' => ['ag', 'com.ag'],
        'dominica' => ['dm'],
        'grenada' => ['gd'],
        'saint kitts and nevis' => ['kn'],
        'saint lucia' => ['lc'],
        'saint vincent and the grenadines' => ['vc'],
        'bermuda' => ['bm'],
        'greenland' => ['gl'],
        // English markets
        'australia' => ['au', 'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au'],
        'new zealand' => ['nz', 'co.nz', 'net.nz', 'org.nz', 'govt.nz'],
        'south africa' => ['za', 'co.za', 'org.za', 'net.za', 'web.za', 'gov.za'],
        'india' => ['in', 'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in'],
        'pakistan' => ['pk'],
        'bangladesh' => ['bd'],
        'sri lanka' => ['lk'],
        'singapore' => ['sg', 'com.sg'],
        'malaysia' => ['my', 'com.my'],
        'philippines' => ['ph', 'com.ph'],
        'hong kong' => ['hk', 'com.hk'],
        'nigeria' => ['ng', 'com.ng'],
        'ghana' => ['gh', 'com.gh'],
        'kenya' => ['ke', 'co.ke'],
        'uganda' => ['ug', 'co.ug'],
        'tanzania' => ['tz', 'co.tz'],
        'zimbabwe' => ['zw', 'co.zw'],
        'botswana' => ['bw', 'co.bw'],
        'namibia' => ['na', 'com.na'],
        'zambia' => ['zm'],
        'malawi' => ['mw', 'ac.mw'],
        'rwanda' => ['rw'],
        'cameroon' => ['cm'],
        'mauritius' => ['mu'],
        'guyana' => ['gy'],
        'papua new guinea' => ['pg'],
        // Other
        'brazil' => ['br', 'com.br'],
        'japan' => ['jp', 'co.jp', 'or.jp', 'ne.jp'],
        'south korea' => ['kr'],
        'united arab emirates' => ['ae'],
    ];
}

/**
 * @return list<string>
 */
function country_expected_tlds(string $countryName): array
{
    $key = strtolower(trim($countryName));
    $map = country_expected_tlds_map();
    if (isset($map[$key])) {
        return $map[$key];
    }
    // Fallback: ISO code from catalog → lowercase ccTLD
    foreach (country_catalog() as $row) {
        if (strcasecmp((string) $row[2], $countryName) === 0) {
            $code = strtolower(trim((string) $row[1]));
            return $code !== '' ? [$code] : [];
        }
    }
    return [];
}

/**
 * Public suffix / TLD of a domain (e.g. example.co.uk → co.uk, shop.de → de).
 */
function domain_tld_suffix(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
    $domain = preg_replace('#^www\.#i', '', $domain) ?? $domain;
    $domain = explode('/', $domain, 2)[0];
    $domain = explode('?', $domain, 2)[0];
    if ($domain === '' || !str_contains($domain, '.')) {
        return '';
    }
    if (function_exists('multi_part_public_suffixes')) {
        foreach (multi_part_public_suffixes() as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                return $suffix;
            }
        }
    }
    $parts = explode('.', $domain);
    return (string) end($parts);
}

/**
 * Soft check: do these domains look like they belong to $country?
 * Generic TLDs (.com etc.) are ignored. Never hard-blocks — UI warns + confirm.
 *
 * @param list<string> $domains
 * @return array{
 *   warn:bool,
 *   message:string,
 *   match_pct:float,
 *   signal:int,
 *   matched:int,
 *   expected:list<string>,
 *   top_tlds:array<string,int>,
 *   dominant_tld:string
 * }
 */
function analyze_country_tld_match(array $domains, string $country): array
{
    $expected = country_expected_tlds($country);
    $expectedSet = array_fill_keys($expected, true);
    $generic = array_fill_keys(generic_tlds(), true);

    $tldCounts = [];
    $signal = 0;
    $matched = 0;

    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if ($d === '') {
            continue;
        }
        $tld = domain_tld_suffix($d);
        if ($tld === '') {
            continue;
        }
        $tldCounts[$tld] = ($tldCounts[$tld] ?? 0) + 1;
        if (isset($generic[$tld])) {
            continue; // ignore global TLDs in the score
        }
        $signal++;
        if (isset($expectedSet[$tld])) {
            $matched++;
        }
    }

    arsort($tldCounts);
    $dominant = $tldCounts !== [] ? (string) array_key_first($tldCounts) : '';
    $matchPct = $signal > 0 ? round(100 * $matched / $signal, 1) : 100.0;

    $empty = [
        'warn' => false,
        'message' => '',
        'match_pct' => $matchPct,
        'signal' => $signal,
        'matched' => $matched,
        'expected' => $expected,
        'top_tlds' => $tldCounts,
        'dominant_tld' => $dominant,
    ];

    // Not enough country-specific TLDs to judge (mostly .com etc.)
    if ($expected === [] || $signal < 5) {
        return $empty;
    }

    // Soft threshold: under 40% of signal TLDs match the selected country
    if ($matchPct >= 40) {
        return $empty;
    }

    $topBits = [];
    $i = 0;
    foreach ($tldCounts as $tld => $n) {
        if (isset($generic[$tld])) {
            continue;
        }
        $topBits[] = '.' . $tld . ' (' . $n . ')';
        if (++$i >= 4) {
            break;
        }
    }
    $expectLabel = implode(', ', array_map(static fn($t) => '.' . $t, array_slice($expected, 0, 4)));
    $foundLabel = $topBits !== [] ? implode(', ', $topBits) : ('.' . $dominant);

    $message = 'This list may not match '
        . $country
        . '. Expected TLDs like '
        . $expectLabel
        . ', but most country-specific domains look like '
        . $foundLabel
        . ' ('
        . (int) $matchPct
        . '% match). Continue anyway?';

    return [
        'warn' => true,
        'message' => $message,
        'match_pct' => $matchPct,
        'signal' => $signal,
        'matched' => $matched,
        'expected' => $expected,
        'top_tlds' => $tldCounts,
        'dominant_tld' => $dominant,
    ];
}
