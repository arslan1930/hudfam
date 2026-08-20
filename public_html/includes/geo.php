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
    // One-time repair: merge German → Germany (and similar demonym folders), drop fake catalog rows.
    if (function_exists('repair_country_alias_folders')) {
        try {
            repair_country_alias_folders();
        } catch (Throwable $e) {
            // ignore
        }
    }

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
    // Never show demonyms (German, Spanish, …) even if still in DB mid-repair.
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (function_exists('is_country_name_alias') && is_country_name_alias($name)) {
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

/**
 * Language / demonym / mistaken labels that must never be country folders.
 * Keys lowercase. Values = real catalog country names.
 *
 * @return array<string,string>
 */
function country_name_aliases(): array
{
    return [
        // Germany (fixes "German" showing next to Germany)
        'german' => 'Germany',
        'deutschland' => 'Germany',
        'deutchland' => 'Germany',
        'federal republic of germany' => 'Germany',
        // Austria
        'austrian' => 'Austria',
        'österreich' => 'Austria',
        'osterreich' => 'Austria',
        // Switzerland
        'swiss' => 'Switzerland',
        'schweiz' => 'Switzerland',
        'suisse' => 'Switzerland',
        'svizzera' => 'Switzerland',
        // France
        'french' => 'France',
        'frankreich' => 'France',
        // Spain
        'spanish' => 'Spain',
        'españa' => 'Spain',
        'espana' => 'Spain',
        // Italy
        'italian' => 'Italy',
        'italia' => 'Italy',
        // Netherlands
        'dutch' => 'Netherlands',
        'holland' => 'Netherlands',
        'the netherlands' => 'Netherlands',
        // UK / US
        'uk' => 'United Kingdom',
        'u.k.' => 'United Kingdom',
        'great britain' => 'United Kingdom',
        'britain' => 'United Kingdom',
        'england' => 'United Kingdom',
        'british' => 'United Kingdom',
        'english' => 'United Kingdom',
        'usa' => 'United States',
        'u.s.' => 'United States',
        'u.s.a.' => 'United States',
        'america' => 'United States',
        'american' => 'United States',
        // Others
        'polish' => 'Poland',
        'polska' => 'Poland',
        'czech' => 'Czech Republic',
        'czechia' => 'Czech Republic',
        'belgian' => 'Belgium',
        'swedish' => 'Sweden',
        'norwegian' => 'Norway',
        'danish' => 'Denmark',
        'finnish' => 'Finland',
        'portuguese' => 'Portugal',
        'greek' => 'Greece',
        'hungarian' => 'Hungary',
        'romanian' => 'Romania',
        'bulgarian' => 'Bulgaria',
        'croatian' => 'Croatia',
        'slovak' => 'Slovakia',
        'slovenian' => 'Slovenia',
        'irish' => 'Ireland',
        'canadian' => 'Canada',
        'australian' => 'Australia',
        'japanese' => 'Japan',
        'korean' => 'South Korea',
        'brazilian' => 'Brazil',
        'mexican' => 'Mexico',
        'indian' => 'India',
    ];
}

/**
 * True when $name is a demonym/alias that maps to a different country.
 */
function is_country_name_alias(string $name): bool
{
    $key = mb_strtolower(trim($name));
    if ($key === '') {
        return false;
    }
    $aliases = country_name_aliases();
    if (!isset($aliases[$key])) {
        return false;
    }
    return strcasecmp($aliases[$key], trim($name)) !== 0;
}

/**
 * Merge rows from one country label into another across a table that has country (+ optional domain).
 *
 * @return int rows changed (updated or deleted)
 */
function merge_country_label_rows(PDO $pdo, string $table, string $from, string $to): int
{
    $from = trim($from);
    $to = trim($to);
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
        return 0;
    }
    try {
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            return 0;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('country', $cols, true)) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    $changed = 0;
    $hasDomain = in_array('domain', $cols, true);

    if ($hasDomain) {
        $sel = $pdo->prepare("SELECT id, domain FROM `{$table}` WHERE TRIM(country)=?");
        $find = $pdo->prepare("SELECT id FROM `{$table}` WHERE TRIM(country)=? AND domain=? LIMIT 1");
        $upd = $pdo->prepare("UPDATE `{$table}` SET country=? WHERE id=?");
        $del = $pdo->prepare("DELETE FROM `{$table}` WHERE id=?");
        $sel->execute([$from]);
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $domain = (string) ($row['domain'] ?? '');
            $find->execute([$to, $domain]);
            $existingId = (int) $find->fetchColumn();
            if ($existingId > 0 && $existingId !== $id) {
                $del->execute([$id]);
            } else {
                $upd->execute([$to, $id]);
            }
            $changed++;
        }
        return $changed;
    }

    $updAll = $pdo->prepare("UPDATE `{$table}` SET country=? WHERE TRIM(country)=?");
    $updAll->execute([$to, $from]);
    return (int) $updAll->rowCount();
}

/**
 * Merge extract_batches when both alias and target country batches exist.
 */
function merge_extract_batch_country_label(PDO $pdo, string $from, string $to): int
{
    $from = trim($from);
    $to = trim($to);
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
        return 0;
    }
    try {
        if (!$pdo->query('SHOW TABLES LIKE ' . $pdo->quote('extract_batches'))->fetchColumn()) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    $get = $pdo->prepare('SELECT id FROM extract_batches WHERE TRIM(country)=? LIMIT 1');
    $get->execute([$from]);
    $fromId = (int) $get->fetchColumn();
    if ($fromId < 1) {
        return 0;
    }
    $get->execute([$to]);
    $toId = (int) $get->fetchColumn();

    $changed = 0;
    if ($toId < 1) {
        $pdo->prepare('UPDATE extract_batches SET country=? WHERE id=?')->execute([$to, $fromId]);
        return 1;
    }

    // Move sites into target batch; drop duplicates
    try {
        $sites = $pdo->prepare('SELECT id, domain FROM extract_batch_sites WHERE batch_id=?');
        $find = $pdo->prepare('SELECT id FROM extract_batch_sites WHERE batch_id=? AND domain=? LIMIT 1');
        $move = $pdo->prepare('UPDATE extract_batch_sites SET batch_id=? WHERE id=?');
        $delSite = $pdo->prepare('DELETE FROM extract_batch_sites WHERE id=?');
        $sites->execute([$fromId]);
        foreach ($sites->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $find->execute([$toId, $row['domain']]);
            if ((int) $find->fetchColumn() > 0) {
                $delSite->execute([(int) $row['id']]);
            } else {
                $move->execute([$toId, (int) $row['id']]);
            }
            $changed++;
        }
        $cStmt = $pdo->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=?');
        $cStmt->execute([$toId]);
        $cnt = (int) $cStmt->fetchColumn();
        $pdo->prepare('UPDATE extract_batches SET site_count=?, updated_at=NOW() WHERE id=?')
            ->execute([$cnt, $toId]);
        $pdo->prepare('DELETE FROM extract_batches WHERE id=?')->execute([$fromId]);
        $changed++;
    } catch (Throwable $e) {
        // ignore
    }
    return $changed;
}

/**
 * Merge email campaign sheet named like an alias into the real country sheet.
 */
function merge_email_sheet_country_label(PDO $pdo, string $from, string $to): int
{
    $from = trim($from);
    $to = trim($to);
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
        return 0;
    }
    try {
        if (!$pdo->query('SHOW TABLES LIKE ' . $pdo->quote('email_campaign_sheets'))->fetchColumn()) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    $get = $pdo->prepare('SELECT id FROM email_campaign_sheets WHERE TRIM(name)=? LIMIT 1');
    $get->execute([$from]);
    $fromId = (int) $get->fetchColumn();
    if ($fromId < 1) {
        return 0;
    }
    $get->execute([$to]);
    $toId = (int) $get->fetchColumn();
    $changed = 0;

    if ($toId < 1) {
        try {
            $pdo->prepare('UPDATE email_campaign_sheets SET name=? WHERE id=?')->execute([$to, $fromId]);
            $pdo->prepare('UPDATE email_campaign_rows SET country=? WHERE sheet_id=?')->execute([$to, $fromId]);
            return 1;
        } catch (Throwable $e) {
            return 0;
        }
    }

    try {
        $rows = $pdo->prepare('SELECT id, domain FROM email_campaign_rows WHERE sheet_id=?');
        $find = $pdo->prepare('SELECT id FROM email_campaign_rows WHERE sheet_id=? AND domain=? LIMIT 1');
        $move = $pdo->prepare('UPDATE email_campaign_rows SET sheet_id=?, country=? WHERE id=?');
        $del = $pdo->prepare('DELETE FROM email_campaign_rows WHERE id=?');
        $rows->execute([$fromId]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $find->execute([$toId, $row['domain']]);
            if ((int) $find->fetchColumn() > 0) {
                $del->execute([(int) $row['id']]);
            } else {
                $move->execute([$toId, $to, (int) $row['id']]);
            }
            $changed++;
        }
        $pdo->prepare('DELETE FROM email_campaign_sheets WHERE id=?')->execute([$fromId]);
        $changed++;
    } catch (Throwable $e) {
        // ignore
    }
    return $changed;
}

/**
 * Merge demonym folders (German → Germany) in data + delete fake countries rows.
 * Safe to call often (runs once per request unless $force).
 *
 * @return array{merged:int,removed_catalog:int}
 */
function repair_country_alias_folders(bool $force = false): array
{
    static $done = false;
    if ($done && !$force) {
        return ['merged' => 0, 'removed_catalog' => 0];
    }
    $done = true;

    $pdo = db();
    $aliases = country_name_aliases();
    $merged = 0;
    $removedCatalog = 0;

    // Catalog lookup: lowercase real country name => canonical casing + region
    $catalog = [];
    try {
        foreach ($pdo->query('SELECT id, name, region, default_language FROM countries')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $catalog[mb_strtolower($name)] = $c;
        }
    } catch (Throwable $e) {
        return ['merged' => 0, 'removed_catalog' => 0];
    }

    $pairs = []; // fromLabel => toLabel
    foreach ($aliases as $aliasKey => $targetName) {
        $targetKey = mb_strtolower($targetName);
        if (!isset($catalog[$targetKey])) {
            continue; // target country not in catalog — skip
        }
        $to = trim((string) $catalog[$targetKey]['name']);

        // Fake countries-table row named like the alias (e.g. name="German")
        if (isset($catalog[$aliasKey])) {
            $from = trim((string) $catalog[$aliasKey]['name']);
            if (strcasecmp($from, $to) !== 0) {
                $pairs[$from] = $to;
            }
        }
        // Also merge common casings in data even if not in catalog
        $pairs[ucfirst($aliasKey)] = $to;
        $pairs[$aliasKey] = $to;
    }

    $dataTables = [
        'prospect_sites',
        'prospect_batches',
        'extracted_sites',
        'sites_with_emails_team',
        'sites_with_emails_admin',
        'sites_with_emails_admin_all',
        'email_campaign_rows',
        'order_items',
        'order_clients',
    ];

    foreach ($pairs as $from => $to) {
        if (strcasecmp($from, $to) === 0) {
            continue;
        }
        foreach ($dataTables as $table) {
            $merged += merge_country_label_rows($pdo, $table, $from, $to);
        }
        $merged += merge_extract_batch_country_label($pdo, $from, $to);
        $merged += merge_email_sheet_country_label($pdo, $from, $to);
    }

    // Delete demonym rows from countries catalog (German, Spanish, …)
    $del = $pdo->prepare('DELETE FROM countries WHERE id=?');
    foreach ($catalog as $key => $row) {
        if (!isset($aliases[$key])) {
            continue;
        }
        $targetKey = mb_strtolower($aliases[$key]);
        if ($targetKey === $key || !isset($catalog[$targetKey])) {
            continue;
        }
        try {
            $del->execute([(int) $row['id']]);
            $removedCatalog++;
        } catch (Throwable $e) {
            // ignore
        }
    }

    return ['merged' => $merged, 'removed_catalog' => $removedCatalog];
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
 * True when a language value is actually a country name (e.g. "Germany").
 * Demonyms like "German" are valid languages and return false.
 */
function is_country_name_used_as_language(string $language): bool
{
    $language = trim($language);
    if ($language === '') {
        return false;
    }
    foreach (list_countries(null, false) as $c) {
        $name = trim((string) ($c['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, $language) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve a stored/posted language for a country.
 * Never keeps a country name (Germany) as the language — maps to default (German).
 */
function normalize_site_language(string $language, string $country = ''): string
{
    $language = trim($language);
    $country = trim($country);
    if ($language !== '' && !is_country_name_used_as_language($language)) {
        return $language;
    }
    if ($country !== '') {
        $canon = resolve_canonical_country($country);
        if ($canon) {
            $fallback = trim((string) ($canon['language'] ?? ''));
            if ($fallback !== '' && !is_country_name_used_as_language($fallback)) {
                return $fallback;
            }
        }
    }
    if ($language !== '' && is_country_name_used_as_language($language)) {
        $canon = resolve_canonical_country($language);
        if ($canon) {
            return trim((string) ($canon['language'] ?? ''));
        }
        return '';
    }
    return $language;
}

/**
 * Language labels for optional typeahead (country defaults + any already used on prospects).
 * Never includes country names (fixes Language list showing "German" and "Germany").
 *
 * @return list<string>
 */
function list_language_options(): array
{
    $set = [];
    foreach (list_countries(null, true) as $c) {
        $lang = normalize_site_language((string) ($c['default_language'] ?? ''), (string) ($c['name'] ?? ''));
        if ($lang !== '') {
            $set[$lang] = true;
        }
    }
    try {
        $rows = db()->query(
            "SELECT DISTINCT language FROM prospect_sites WHERE TRIM(language) <> '' ORDER BY language"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $lang) {
            $lang = normalize_site_language((string) $lang);
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

    // Demonyms / mistakes first: German → Germany (never treat "German" as its own folder).
    $aliases = country_name_aliases();
    $aliasKey = mb_strtolower($input);
    if (isset($aliases[$aliasKey])) {
        $mapped = $aliases[$aliasKey];
        foreach (list_countries(null, true) as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            if ($name !== '' && strcasecmp($name, $mapped) === 0) {
                return [
                    'name' => $name,
                    'region' => (string) ($c['region'] ?? ''),
                    'language' => (string) ($c['default_language'] ?? ''),
                ];
            }
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
 * Primary country-code TLD → catalog country name (one owner each).
 * Used by Extracting Results Push to auto-route .de→Germany, .at→Austria, etc.
 * Not the soft “neighbors” list — each TLD has a single destination.
 *
 * @return array<string, string> tld suffix (lowercase) => country name
 */
function primary_tld_country_map(): array
{
    return [
        // DACH (primary owners — not neighbors)
        'de' => 'Germany',
        'at' => 'Austria',
        'co.at' => 'Austria',
        'ch' => 'Switzerland',
        'li' => 'Liechtenstein',
        // Romance / Iberia
        'fr' => 'France',
        're' => 'France',
        'es' => 'Spain',
        'cat' => 'Spain',
        'com.es' => 'Spain',
        'pt' => 'Portugal',
        'com.pt' => 'Portugal',
        'it' => 'Italy',
        'be' => 'Belgium',
        'lu' => 'Luxembourg',
        'mc' => 'Monaco',
        'ad' => 'Andorra',
        'sm' => 'San Marino',
        // British Isles (catalog code is GB)
        'uk' => 'United Kingdom',
        'co.uk' => 'United Kingdom',
        'org.uk' => 'United Kingdom',
        'ac.uk' => 'United Kingdom',
        'gov.uk' => 'United Kingdom',
        'me.uk' => 'United Kingdom',
        'net.uk' => 'United Kingdom',
        'scot' => 'United Kingdom',
        'wales' => 'United Kingdom',
        'cymru' => 'United Kingdom',
        'ie' => 'Ireland',
        'mt' => 'Malta',
        'com.mt' => 'Malta',
        // Nordics / Baltics
        'se' => 'Sweden',
        'no' => 'Norway',
        'dk' => 'Denmark',
        'fi' => 'Finland',
        'ax' => 'Finland',
        'is' => 'Iceland',
        'ee' => 'Estonia',
        'lv' => 'Latvia',
        'lt' => 'Lithuania',
        // Central / East Europe
        'nl' => 'Netherlands',
        'pl' => 'Poland',
        'com.pl' => 'Poland',
        'cz' => 'Czech Republic',
        'sk' => 'Slovakia',
        'hu' => 'Hungary',
        'ro' => 'Romania',
        'com.ro' => 'Romania',
        'bg' => 'Bulgaria',
        'gr' => 'Greece',
        'com.gr' => 'Greece',
        'cy' => 'Cyprus',
        'com.cy' => 'Cyprus',
        'hr' => 'Croatia',
        'com.hr' => 'Croatia',
        'si' => 'Slovenia',
        'rs' => 'Serbia',
        'ba' => 'Bosnia and Herzegovina',
        'com.ba' => 'Bosnia and Herzegovina',
        'al' => 'Albania',
        'mk' => 'North Macedonia',
        'md' => 'Moldova',
        'ua' => 'Ukraine',
        'com.ua' => 'Ukraine',
        'by' => 'Belarus',
        'ru' => 'Russia',
        // North America
        'us' => 'United States',
        'ca' => 'Canada',
        'mx' => 'Mexico',
        'com.mx' => 'Mexico',
        // English / other markets
        'au' => 'Australia',
        'com.au' => 'Australia',
        'net.au' => 'Australia',
        'org.au' => 'Australia',
        'nz' => 'New Zealand',
        'co.nz' => 'New Zealand',
        'za' => 'South Africa',
        'co.za' => 'South Africa',
        'in' => 'India',
        'co.in' => 'India',
        'pk' => 'Pakistan',
        'com.pk' => 'Pakistan',
        'sg' => 'Singapore',
        'com.sg' => 'Singapore',
        'my' => 'Malaysia',
        'com.my' => 'Malaysia',
        'ph' => 'Philippines',
        'com.ph' => 'Philippines',
        'hk' => 'Hong Kong',
        'com.hk' => 'Hong Kong',
        'ng' => 'Nigeria',
        'com.ng' => 'Nigeria',
        'ke' => 'Kenya',
        'co.ke' => 'Kenya',
        'br' => 'Brazil',
        'com.br' => 'Brazil',
        'jp' => 'Japan',
        'co.jp' => 'Japan',
        'kr' => 'South Korea',
        'co.kr' => 'South Korea',
        'ae' => 'United Arab Emirates',
    ];
}

/**
 * Resolve which country folder a domain should land in on Extracting Results Push.
 * Generic TLDs (.com, .net, .eu, …) and unknown TLDs stay in $selectedCountry.
 */
function country_for_push_domain(string $domain, string $selectedCountry): string
{
    $fallback = resolve_canonical_country($selectedCountry);
    $fallbackName = $fallback['name'] ?? trim($selectedCountry);
    if ($fallbackName === '') {
        return '';
    }

    $root = function_exists('to_root_domain') ? to_root_domain($domain) : normalize_domain($domain);
    if ($root === '' && function_exists('normalize_domain')) {
        $root = normalize_domain($domain);
    }
    if ($root === '') {
        return $fallbackName;
    }

    $tld = domain_tld_suffix($root);
    if ($tld === '') {
        return $fallbackName;
    }

    $generic = array_fill_keys(generic_tlds(), true);
    if (isset($generic[$tld])) {
        return $fallbackName;
    }

    $primary = primary_tld_country_map();
    if (isset($primary[$tld])) {
        $canon = resolve_canonical_country($primary[$tld]);
        if ($canon) {
            return $canon['name'];
        }
    }

    // ISO / catalog code fallback (e.g. .se → Sweden when code=SE).
    $cc = $tld;
    if (str_contains($tld, '.')) {
        $parts = explode('.', $tld);
        $cc = (string) end($parts);
    }
    if ($cc !== '' && !isset($generic[$cc])) {
        foreach (list_countries(null, true) as $c) {
            $code = strtolower(trim((string) ($c['code'] ?? '')));
            $name = trim((string) ($c['name'] ?? ''));
            if ($code !== '' && $name !== '' && $code === $cc) {
                return $name;
            }
        }
        // United Kingdom is stored as GB but uses .uk
        if ($cc === 'uk' || $cc === 'gb') {
            $uk = resolve_canonical_country('United Kingdom');
            if ($uk) {
                return $uk['name'];
            }
        }
    }

    return $fallbackName;
}

/**
 * Group domains by destination country for Extracting Results Push.
 *
 * @param list<string> $domains
 * @return array<string, list<string>> country name => domains
 */
function route_domains_by_country_tld(array $domains, string $selectedCountry): array
{
    $groups = [];
    foreach ($domains as $d) {
        $raw = trim((string) $d);
        if ($raw === '') {
            continue;
        }
        $root = function_exists('to_root_domain') ? to_root_domain($raw) : '';
        if ($root === '' && function_exists('normalize_domain')) {
            $root = normalize_domain($raw);
        }
        if ($root === '') {
            continue;
        }
        $dest = country_for_push_domain($root, $selectedCountry);
        if ($dest === '') {
            continue;
        }
        if (!isset($groups[$dest])) {
            $groups[$dest] = [];
        }
        $groups[$dest][$root] = $root;
    }
    $out = [];
    foreach ($groups as $country => $map) {
        $out[$country] = array_values($map);
    }
    ksort($out);
    return $out;
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
 * Group root domains by public suffix for Site Finding “Separate all”.
 * Keys are suffixes (es, com, com.es, …); empty/unknown → "other".
 * Groups are sorted by count descending; domains sorted within each group.
 *
 * @param list<string> $domains
 * @return array<string, list<string>>
 */
function group_domains_by_tld(array $domains): array
{
    $groups = [];
    foreach ($domains as $raw) {
        $d = strtolower(trim((string) $raw));
        if ($d === '') {
            continue;
        }
        if (function_exists('normalize_domain')) {
            $norm = normalize_domain($d);
            if ($norm !== '') {
                $d = $norm;
            }
        } elseif (function_exists('extract_host_candidate')) {
            $host = extract_host_candidate($d);
            if ($host !== '') {
                $d = $host;
            }
        }
        $tld = domain_tld_suffix($d);
        if ($tld === '') {
            $tld = 'other';
        }
        $groups[$tld][$d] = $d;
    }
    $out = [];
    foreach ($groups as $tld => $set) {
        $list = array_values($set);
        sort($list, SORT_STRING);
        $out[$tld] = $list;
    }
    uasort($out, static fn (array $a, array $b): int => count($b) <=> count($a));
    return $out;
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
