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
    $sql .= ' ORDER BY name';
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
