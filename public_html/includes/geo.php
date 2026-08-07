<?php

function regions(): array
{
    // Display order for Our database markets
    return [
        'europe' => 'Europe',
        'english' => 'English markets',
        'north_america' => 'North America',
        'other' => 'Other',
    ];
}

/**
 * Folder / search label for a country — always the country name (never TLD like .de).
 */
function prospect_folder_display_label(string $countryName, string $region = '', string $code = ''): string
{
    unset($region, $code); // kept in signature for callers; display is name-only
    $countryName = trim($countryName);
    return $countryName !== '' ? $countryName : 'No country';
}

function seed_countries_if_empty(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM countries')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $rows = [
        ['europe', 'DE', 'Germany', 'German'],
        ['europe', 'AT', 'Austria', 'German'],
        ['europe', 'CH', 'Switzerland', 'German'],
        ['europe', 'FR', 'France', 'French'],
        ['europe', 'IT', 'Italy', 'Italian'],
        ['europe', 'ES', 'Spain', 'Spanish'],
        ['europe', 'NL', 'Netherlands', 'Dutch'],
        ['europe', 'BE', 'Belgium', 'Dutch'],
        ['europe', 'PL', 'Poland', 'Polish'],
        ['europe', 'SE', 'Sweden', 'Swedish'],
        ['europe', 'NO', 'Norway', 'Norwegian'],
        ['europe', 'DK', 'Denmark', 'Danish'],
        ['europe', 'FI', 'Finland', 'Finnish'],
        ['europe', 'PT', 'Portugal', 'Portuguese'],
        ['europe', 'IE', 'Ireland', 'English'],
        ['north_america', 'US', 'United States', 'English'],
        ['north_america', 'CA', 'Canada', 'English'],
        ['north_america', 'MX', 'Mexico', 'Spanish'],
        ['english', 'GB', 'United Kingdom', 'English'],
        ['english', 'AU', 'Australia', 'English'],
        ['english', 'NZ', 'New Zealand', 'English'],
        ['english', 'ZA', 'South Africa', 'English'],
        ['english', 'SG', 'Singapore', 'English'],
        ['english', 'IN', 'India', 'English'],
        ['other', 'BR', 'Brazil', 'Portuguese'],
        ['other', 'JP', 'Japan', 'Japanese'],
        ['other', 'KR', 'South Korea', 'Korean'],
        ['other', 'AE', 'United Arab Emirates', 'Arabic'],
    ];
    $ins = $pdo->prepare(
        'INSERT INTO countries (region, code, name, default_language, is_active) VALUES (?,?,?,?,1)'
    );
    foreach ($rows as $r) {
        $ins->execute($r);
    }
}

function list_countries(?string $region = null, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM countries WHERE 1=1';
    $params = [];
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if ($region) {
        $sql .= ' AND region = ?';
        $params[] = $region;
    }
    $sql .= ' ORDER BY name, id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Never show duplicate country names (case/whitespace variants).
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $row['name'] = $name;
        $out[] = $row;
    }
    return $out;
}

/**
 * Remove duplicate rows from countries table (same name, case/space insensitive).
 * Keeps the lowest id.
 */
function dedupe_countries_catalog(): int
{
    $pdo = db();
    $rows = $pdo->query(
        'SELECT id, name FROM countries ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $keep = [];
    $removed = 0;
    $del = $pdo->prepare('DELETE FROM countries WHERE id=?');
    $fixName = $pdo->prepare('UPDATE countries SET name=? WHERE id=?');
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $del->execute([$id]);
            $removed++;
            continue;
        }
        $key = mb_strtolower($name);
        if (isset($keep[$key])) {
            $del->execute([$id]);
            $removed++;
            continue;
        }
        $keep[$key] = $id;
        if ($name !== (string) $row['name']) {
            try {
                $fixName->execute([$name, $id]);
            } catch (Throwable $e) {
                // unique collision after trim — drop this row
                $del->execute([$id]);
                $removed++;
                unset($keep[$key]);
            }
        }
    }
    return $removed;
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

function distinct_site_languages(): array
{
    $rows = db()->query(
        "SELECT DISTINCT language FROM sites WHERE language <> '' ORDER BY language"
    )->fetchAll();
    return array_column($rows, 'language');
}

/**
 * Language labels for optional typeahead (country defaults + any already used on prospects).
 *
 * @return list<string>
 */
function list_language_options(): array
{
    $set = [];
    foreach (list_countries(null, true) as $c) {
        $lang = trim((string) ($c['default_language'] ?? ''));
        if ($lang !== '') {
            $set[$lang] = true;
        }
    }
    try {
        $rows = db()->query(
            "SELECT DISTINCT language FROM prospect_sites WHERE TRIM(language) <> '' ORDER BY language"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $lang) {
            $lang = trim((string) $lang);
            if ($lang !== '') {
                $set[$lang] = true;
            }
        }
    } catch (Throwable $e) {
        // table may be missing before upgrade
    }
    $list = array_keys($set);
    natcasesort($list);
    return array_values($list);
}

/**
 * Flat country rows for typeahead (name + default language + region).
 *
 * @return list<array{name:string,language:string,region:string}>
 */
function list_country_typeahead_items(): array
{
    $items = [];
    foreach (list_countries(null, true) as $c) {
        $items[] = [
            'name' => (string) $c['name'],
            'language' => (string) ($c['default_language'] ?? ''),
            'region' => (string) ($c['region'] ?? ''),
        ];
    }
    return $items;
}

/**
 * Resolve typed/posted country text to an existing countries-table row.
 * Never creates a new country — returns null when there is no match.
 *
 * @return array{name:string,region:string,language:string}|null
 */
function resolve_canonical_country(string $input): ?array
{
    $input = trim($input);
    if ($input === '' || strcasecmp($input, '_none') === 0) {
        return null;
    }
    if (function_exists('seed_countries_if_empty')) {
        try {
            seed_countries_if_empty(db());
        } catch (Throwable $e) {
            // ignore
        }
    }
    foreach (list_countries(null, true) as $c) {
        $name = trim((string) ($c['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, $input) === 0) {
            return [
                'name' => $name,
                'region' => (string) ($c['region'] ?? ''),
                'language' => (string) ($c['default_language'] ?? ''),
            ];
        }
    }
    return null;
}

/**
 * Require a catalog country name. Throws when the value is empty or unknown.
 */
function require_canonical_country(string $input): array
{
    $resolved = resolve_canonical_country($input);
    if ($resolved === null) {
        throw new InvalidArgumentException(
            'Select an existing country database (e.g. Germany, Spain). New country folders are not created.'
        );
    }
    return $resolved;
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
 * (a German .com list should not look "wrong").
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
 * Neighbors included where markets overlap (e.g. DACH for Germany).
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
        'spain' => ['es', 'cat', 'gal', 'eus', 'com.es'],
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
        'malta' => ['mt', 'com.mt'],
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
        'greece' => ['gr', 'com.gr'],
        'cyprus' => ['cy', 'com.cy'],
        'croatia' => ['hr', 'com.hr'],
        'slovenia' => ['si'],
        'serbia' => ['rs'],
        'bosnia and herzegovina' => ['ba', 'com.ba'],
        'montenegro' => ['me'],
        'albania' => ['al'],
        'north macedonia' => ['mk'],
        'kosovo' => ['xk'],
        'moldova' => ['md'],
        'ukraine' => ['ua', 'com.ua'],
        'belarus' => ['by'],
        'russia' => ['ru', 'su'],
        // North America
        'united states' => ['us', 'edu', 'gov', 'mil'],
        'canada' => ['ca'],
        'mexico' => ['mx', 'com.mx'],
        // English markets
        'australia' => ['au', 'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au'],
        'new zealand' => ['nz', 'co.nz', 'net.nz', 'org.nz', 'govt.nz'],
        'south africa' => ['za', 'co.za', 'org.za', 'net.za', 'web.za'],
        'india' => ['in', 'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in'],
        'pakistan' => ['pk', 'com.pk'],
        'singapore' => ['sg', 'com.sg'],
        'malaysia' => ['my', 'com.my'],
        'philippines' => ['ph', 'com.ph'],
        'hong kong' => ['hk', 'com.hk'],
        'nigeria' => ['ng', 'com.ng'],
        'kenya' => ['ke', 'co.ke'],
        // Other
        'brazil' => ['br', 'com.br'],
        'japan' => ['jp', 'co.jp', 'or.jp', 'ne.jp'],
        'south korea' => ['kr', 'co.kr'],
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
    // Fallback: ISO code from countries table → lowercase ccTLD
    foreach (list_countries(null, false) as $row) {
        if (strcasecmp(trim((string) ($row['name'] ?? '')), $countryName) === 0) {
            $code = strtolower(trim((string) ($row['code'] ?? '')));
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
    if (function_exists('domain_public_suffix')) {
        $suffix = domain_public_suffix($domain);
        if ($suffix !== '') {
            return $suffix;
        }
    }
    $parts = explode('.', $domain);
    return (string) end($parts);
}

/**
 * Soft check: do these domains look like they belong to $country?
 * Generic TLDs (.com etc.) are ignored. Never hard-blocks — UI warns + confirm.
 * Warns when match on country-specific TLDs is under 70% (and enough signal).
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

    // Soft threshold: under 70% of signal TLDs match the selected country
    if ($matchPct >= 70) {
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
    $expectLabel = implode(', ', array_map(static fn ($t) => '.' . $t, array_slice($expected, 0, 4)));
    $foundLabel = $topBits !== [] ? implode(', ', $topBits) : ('.' . $dominant);

    $message = 'This list may not match '
        . $country
        . '. For '
        . $country
        . ' we expect domains like '
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
