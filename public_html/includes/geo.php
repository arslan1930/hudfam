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

/**
 * Every language we know about: country defaults + anything already saved on a site.
 *
 * @return list<string>
 */
function list_all_languages(): array
{
    $langs = [];
    foreach (list_countries(null, true) as $c) {
        $lang = trim((string) ($c['default_language'] ?? ''));
        if ($lang !== '') {
            $langs[$lang] = true;
        }
    }
    try {
        $rows = db()->query(
            "SELECT DISTINCT language FROM prospect_sites WHERE TRIM(language) <> '' ORDER BY language"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $lang) {
            $lang = trim((string) $lang);
            if ($lang !== '') {
                $langs[$lang] = true;
            }
        }
    } catch (Throwable $e) {
        // prospect_sites may not exist yet on a fresh install
    }
    $out = array_keys($langs);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Type-to-search country picker. Countries are grouped by region.
 *
 * @param string $placeholder Text shown for the empty option.
 */
function render_country_select(
    string $name = 'country',
    string $selected = '',
    string $id = '',
    bool $required = false,
    string $placeholder = 'All countries'
): string {
    $id = $id !== '' ? $id : $name;
    $html = '<select data-searchable="1" data-placeholder="Type a country…" name="' . h($name) . '" id="' . h($id) . '"'
        . ($required ? ' required' : '') . '>';
    $html .= '<option value="">' . h($placeholder) . '</option>';
    foreach (countries_grouped() as $block) {
        if (empty($block['countries'])) {
            continue;
        }
        $html .= '<optgroup label="' . h((string) $block['label']) . '">';
        foreach ($block['countries'] as $c) {
            $nameC = (string) $c['name'];
            $lang = trim((string) ($c['default_language'] ?? ''));
            $html .= '<option value="' . h($nameC) . '"'
                . ' data-lang="' . h($lang) . '"'
                . ' data-region="' . h((string) $c['region']) . '"'
                . (strcasecmp($selected, $nameC) === 0 ? ' selected' : '') . '>'
                . h($nameC) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '</select>';
}

/**
 * Type-to-search language picker. Language is optional, so a value that is not
 * in the list can still be typed and kept.
 */
function render_language_select(
    string $name = 'language',
    string $selected = '',
    string $id = '',
    bool $allowCustom = true,
    string $placeholder = 'Any language'
): string {
    $id = $id !== '' ? $id : $name;
    $languages = list_all_languages();
    $selected = trim($selected);
    if ($selected !== '' && !in_array($selected, $languages, true)) {
        $languages[] = $selected;
    }
    $html = '<select data-searchable="1" data-placeholder="Type a language…"'
        . ($allowCustom ? ' data-allow-custom="1"' : '')
        . ' name="' . h($name) . '" id="' . h($id) . '">';
    $html .= '<option value="">' . h($placeholder) . '</option>';
    foreach ($languages as $lang) {
        $html .= '<option value="' . h($lang) . '"'
            . (strcasecmp($selected, $lang) === 0 ? ' selected' : '') . '>'
            . h($lang) . '</option>';
    }
    return $html . '</select>';
}

function distinct_site_languages(): array
{
    $rows = db()->query(
        "SELECT DISTINCT language FROM sites WHERE language <> '' ORDER BY language"
    )->fetchAll();
    return array_column($rows, 'language');
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
