<?php

/**
 * Our database niches — closed English labels, multi-value chip field.
 * Stored on prospect_sites.niche as "Health, Fitness" (comma-space).
 */

/**
 * Grouped English niche labels (filter menu + chip typeahead).
 *
 * @return array<string, list<string>>
 */
function prospect_niche_taxonomy(): array
{
    return [
        'General' => [
            'General', 'News', 'Blog', 'Magazine', 'Business', 'Marketing', 'SEO',
            'Technology', 'Software', 'Internet', 'Gadgets', 'Mobile', 'Apps', 'AI',
            'Crypto', 'Web hosting',
        ],
        'Money' => [
            'Finance', 'Banking', 'Insurance', 'Investing', 'Loans', 'Real estate',
            'Accounting', 'Career', 'Education', 'E-commerce', 'Startups',
        ],
        'Lifestyle' => [
            'Lifestyle', 'Fashion', 'Beauty', 'Luxury', 'Home', 'Garden', 'DIY',
            'Design', 'Art', 'Photography', 'Food', 'Recipes', 'Wine', 'Travel',
            'Hotels', 'Aviation',
        ],
        'Health' => [
            'Health', 'Medical', 'Pharmacy', 'Fitness', 'Nutrition', 'Mental health',
            'Dental', 'Pets',
        ],
        'Family' => [
            'Family', 'Parenting', 'Wedding', 'Relationships', 'Women', 'Men',
            'Kids', 'Seniors',
        ],
        'Media' => [
            'Entertainment', 'Movies', 'TV', 'Music', 'Celebrity', 'Gaming',
            'Sports', 'Football', 'Betting', 'Casino', 'Adult',
        ],
        'Society' => [
            'Law', 'Government', 'Politics', 'Environment', 'Energy', 'Science',
            'Religion', 'Charity', 'Local',
        ],
        'Other' => [
            'Automotive', 'Motorcycles', 'Transport', 'Logistics', 'Construction',
            'Agriculture', 'Fashion accessories', 'Jewelry', 'Watches',
        ],
    ];
}

/**
 * Flat list in taxonomy order (unique).
 *
 * @return list<string>
 */
function prospect_niche_labels(): array
{
    $out = [];
    $seen = [];
    foreach (prospect_niche_taxonomy() as $labels) {
        foreach ($labels as $label) {
            $k = mb_strtolower($label);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $label;
        }
    }
    return $out;
}

/**
 * Lowercase alias / spelling → canonical English label.
 *
 * @return array<string, string>
 */
function prospect_niche_aliases(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (prospect_niche_labels() as $label) {
        $map[mb_strtolower($label)] = $label;
    }
    $extra = [
        'health care' => 'Health',
        'healthcare' => 'Health',
        'medizin' => 'Medical',
        'gesundheit' => 'Health',
        'salud' => 'Health',
        'sante' => 'Health',
        'santé' => 'Health',
        'finanzen' => 'Finance',
        'finanz' => 'Finance',
        'finanzas' => 'Finance',
        'e-comm' => 'E-commerce',
        'ecommerce' => 'E-commerce',
        'ecomm' => 'E-commerce',
        'e commerce' => 'E-commerce',
        'real-estate' => 'Real estate',
        'realestate' => 'Real estate',
        'immobilien' => 'Real estate',
        'seo services' => 'SEO',
        'search engine optimization' => 'SEO',
        'webhosting' => 'Web hosting',
        'hosting' => 'Web hosting',
        'cryptocurrency' => 'Crypto',
        'bitcoin' => 'Crypto',
        'ai tools' => 'AI',
        'artificial intelligence' => 'AI',
        'mental-health' => 'Mental health',
        'mentalhealth' => 'Mental health',
        'wellness' => 'Health',
        'fitness studio' => 'Fitness',
        'fussball' => 'Football',
        'fußball' => 'Football',
        'soccer' => 'Football',
        'auto' => 'Automotive',
        'cars' => 'Automotive',
        'car' => 'Automotive',
        'motorrad' => 'Motorcycles',
        'reisen' => 'Travel',
        'viaje' => 'Travel',
        'voyage' => 'Travel',
        'recetas' => 'Recipes',
        'rezepte' => 'Recipes',
        'mathe' => 'Education',
        'bildung' => 'Education',
        'recht' => 'Law',
        'legal' => 'Law',
        'nachrichten' => 'News',
        'noticias' => 'News',
        'mode' => 'Fashion',
        'moda' => 'Fashion',
        'spiel' => 'Gaming',
        'spiele' => 'Gaming',
        'casino online' => 'Casino',
        'apotheke' => 'Pharmacy',
        'farmacia' => 'Pharmacy',
        'zahn' => 'Dental',
        'zahnarzt' => 'Dental',
        'haustier' => 'Pets',
        'hund' => 'Pets',
        'katze' => 'Pets',
        'tech' => 'Technology',
        'technologie' => 'Technology',
        'software development' => 'Software',
        'krypto' => 'Crypto',
        'versicherung' => 'Insurance',
        'kredit' => 'Loans',
        'loan' => 'Loans',
        'job' => 'Career',
        'jobs' => 'Career',
        'karriere' => 'Career',
        'eltern' => 'Parenting',
        'hochzeit' => 'Wedding',
        'wedding' => 'Wedding',
        'kochen' => 'Food',
        'foodie' => 'Food',
        'rezept' => 'Recipes',
        'garten' => 'Garden',
        'haus' => 'Home',
        'wohnen' => 'Home',
        'schmuck' => 'Jewelry',
        'uhren' => 'Watches',
        'motorsport' => 'Automotive',
        'logistik' => 'Logistics',
        'bau' => 'Construction',
        'agrar' => 'Agriculture',
        'umwelt' => 'Environment',
        'energie' => 'Energy',
        'wissenschaft' => 'Science',
        'politik' => 'Politics',
        'regierung' => 'Government',
        'kirche' => 'Religion',
        'spende' => 'Charity',
        'lokal' => 'Local',
        'nachrichtenportal' => 'News',
        'magazin' => 'Magazine',
        'zeitschrift' => 'Magazine',
        'blogger' => 'Blog',
        'film' => 'Movies',
        'filme' => 'Movies',
        'kino' => 'Movies',
        'musik' => 'Music',
        'tv-serie' => 'TV',
        'fernsehen' => 'TV',
        'celebrity news' => 'Celebrity',
        'promi' => 'Celebrity',
        'wetten' => 'Betting',
        'gambling' => 'Betting',
        'erotik' => 'Adult',
        'xxx' => 'Adult',
        'porn' => 'Adult',
        'porno' => 'Adult',
        'kind' => 'Kids',
        'kinder' => 'Kids',
        'baby' => 'Parenting',
        'senioren' => 'Seniors',
        'frauen' => 'Women',
        'manner' => 'Men',
        'männer' => 'Men',
        'beziehungen' => 'Relationships',
        'dating' => 'Relationships',
        'hotel' => 'Hotels',
        'flug' => 'Aviation',
        'airline' => 'Aviation',
        'flughafen' => 'Aviation',
        'wein' => 'Wine',
        'wine' => 'Wine',
        'foto' => 'Photography',
        'fotos' => 'Photography',
        'kunst' => 'Art',
        'design studio' => 'Design',
        'diy blog' => 'DIY',
        'gartenblog' => 'Garden',
        'beautyblog' => 'Beauty',
        'makeup' => 'Beauty',
        'kosmetik' => 'Beauty',
        'luxus' => 'Luxury',
        'marketing agentur' => 'Marketing',
        'business news' => 'Business',
        'startup' => 'Startups',
        'start-up' => 'Startups',
        'buchhaltung' => 'Accounting',
        'steuer' => 'Accounting',
        'bank' => 'Banking',
        'banken' => 'Banking',
        'invest' => 'Investing',
        'aktien' => 'Investing',
        'boerse' => 'Investing',
        'börse' => 'Investing',
        'pharma' => 'Pharmacy',
        'arzt' => 'Medical',
        'clinic' => 'Medical',
        'klinik' => 'Medical',
        'ernaehrung' => 'Nutrition',
        'ernährung' => 'Nutrition',
        'nutrition' => 'Nutrition',
        'fitnessblog' => 'Fitness',
        'gym' => 'Fitness',
        'sport' => 'Sports',
        'sports' => 'Sports',
        'game' => 'Gaming',
        'games' => 'Gaming',
        'gaming' => 'Gaming',
        'apps' => 'Apps',
        'app' => 'Apps',
        'mobile' => 'Mobile',
        'gadget' => 'Gadgets',
        'gadgets' => 'Gadgets',
        'internet' => 'Internet',
        'software' => 'Software',
        'techblog' => 'Technology',
        'seo' => 'SEO',
        'general' => 'General',
        'news' => 'News',
        'blog' => 'Blog',
        'shop' => 'E-commerce',
        'store' => 'E-commerce',
        'travel' => 'Travel',
        'hotel' => 'Hotels',
        'fashion' => 'Fashion',
        'health' => 'Health',
        'finance' => 'Finance',
        'crypto' => 'Crypto',
        'ai' => 'AI',
    ];
    foreach ($extra as $alias => $label) {
        $map[mb_strtolower($alias)] = $label;
    }
    return $map;
}

/**
 * Domain-token keyword (lowercase, no spaces) → English niche.
 *
 * @return array<string, string>
 */
function prospect_niche_domain_keywords(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = prospect_niche_aliases();
    $compact = [];
    foreach ($map as $alias => $label) {
        $key = str_replace([' ', '-', '_'], '', mb_strtolower($alias));
        if ($key !== '') {
            $compact[$key] = $label;
        }
    }
    return $compact;
}

function prospect_normalize_niche_label(string $raw): string
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    if ($raw === '') {
        return '';
    }
    $aliases = prospect_niche_aliases();
    $key = mb_strtolower($raw);
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    $compact = str_replace([' ', '-', '_'], '', $key);
    $keywords = prospect_niche_domain_keywords();
    if ($compact !== '' && isset($keywords[$compact])) {
        return $keywords[$compact];
    }
    return '';
}

/**
 * Split a stored / pasted niche string into unique labels.
 * Known aliases become English taxonomy labels. Unknown legacy values are kept.
 *
 * @return list<string>
 */
function prospect_parse_niches(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\s*[,;|]\s*/u', $raw) ?: [];
    $order = [];
    foreach (prospect_niche_labels() as $i => $label) {
        $order[mb_strtolower($label)] = $i;
    }
    $known = [];
    $unknown = [];
    $seen = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $canon = prospect_normalize_niche_label($part);
        $label = $canon !== '' ? $canon : $part;
        $k = mb_strtolower($label);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        if ($canon !== '') {
            $known[] = $canon;
        } else {
            $unknown[] = $part;
        }
    }
    usort($known, static function (string $a, string $b) use ($order): int {
        $ia = $order[mb_strtolower($a)] ?? 1000;
        $ib = $order[mb_strtolower($b)] ?? 1000;
        if ($ia === $ib) {
            return strcasecmp($a, $b);
        }
        return $ia <=> $ib;
    });
    return array_values(array_merge($known, $unknown));
}

/**
 * @param list<string> $list
 */
function prospect_format_niches(array $list): string
{
    $parsed = prospect_parse_niches(implode(', ', $list));
    return implode(', ', $parsed);
}

/**
 * GET/POST filter value: '' (All), '_none', or a canonical label.
 */
function prospect_normalized_niche_filter(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strcasecmp($raw, 'all') === 0) {
        return '';
    }
    if ($raw === '_none' || strcasecmp($raw, 'none') === 0 || strcasecmp($raw, 'no niche') === 0) {
        return '_none';
    }
    return prospect_normalize_niche_label($raw);
}

/**
 * SQL fragment + params so a site matches one filter type (including multi-niche rows).
 *
 * @return array{sql:string,params:list<string>}
 */
function prospect_sql_niche_filter(string $column, string $filter): array
{
    $filter = prospect_normalized_niche_filter($filter);
    if ($filter === '') {
        return ['sql' => '', 'params' => []];
    }
    if ($filter === '_none') {
        return ['sql' => 'TRIM(' . $column . ") = ''", 'params' => []];
    }
    $norm = 'REPLACE(REPLACE(TRIM(' . $column . "), ', ', ','), ' ,', ',')";
    return [
        'sql' => 'FIND_IN_SET(?, ' . $norm . ') > 0',
        'params' => [$filter],
    ];
}

/**
 * @return list<string>
 */
function prospect_domain_tokens(string $domain): array
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;
    $host = explode('/', $domain, 2)[0];
    $host = explode('?', $host, 2)[0];
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    if ($parts === []) {
        return [];
    }
    $skipTld = [
        'com' => true, 'net' => true, 'org' => true, 'info' => true, 'biz' => true,
        'edu' => true, 'gov' => true, 'io' => true, 'co' => true, 'uk' => true,
        'de' => true, 'fr' => true, 'es' => true, 'it' => true, 'nl' => true,
        'pl' => true, 'at' => true, 'ch' => true, 'be' => true, 'pt' => true,
        'cz' => true, 'sk' => true, 'hu' => true, 'ro' => true, 'se' => true,
        'no' => true, 'dk' => true, 'fi' => true, 'ie' => true, 'us' => true,
        'ca' => true, 'au' => true, 'nz' => true, 'in' => true, 'br' => true,
        'mx' => true, 'ar' => true, 'cl' => true, 'jp' => true, 'cn' => true,
        'ru' => true, 'ua' => true, 'tr' => true, 'gr' => true, 'eu' => true,
        'www' => true, 'www2' => true,
    ];
    $sld = '';
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if (!isset($skipTld[$parts[$i]])) {
            $sld = $parts[$i];
            break;
        }
    }
    if ($sld === '' && isset($parts[0])) {
        $sld = $parts[0];
    }
    $tokens = preg_split('/[-_]+/', $sld) ?: [];
    $tokens[] = $sld;
    $out = [];
    $seen = [];
    foreach ($tokens as $t) {
        $t = strtolower(trim((string) $t));
        if ($t === '' || isset($seen[$t]) || isset($skipTld[$t])) {
            continue;
        }
        $seen[$t] = true;
        $out[] = $t;
    }
    return $out;
}

/**
 * Suggest up to 3 English niches from the domain name. No network.
 *
 * @return list<string>
 */
function prospect_suggest_niches_from_domain(string $domain): array
{
    $keywords = prospect_niche_domain_keywords();
    $found = [];
    $seen = [];
    foreach (prospect_domain_tokens($domain) as $token) {
        $compact = str_replace([' ', '-', '_'], '', $token);
        $label = '';
        if (isset($keywords[$compact])) {
            $label = $keywords[$compact];
        } elseif (isset($keywords[$token])) {
            $label = $keywords[$token];
        } else {
            foreach ($keywords as $key => $lab) {
                if (strlen($key) < 4) {
                    continue;
                }
                if (str_starts_with($compact, $key)) {
                    $label = $lab;
                    break;
                }
            }
        }
        if ($label === '') {
            continue;
        }
        $k = mb_strtolower($label);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $found[] = $label;
        if (count($found) >= 3) {
            break;
        }
    }
    return prospect_parse_niches(implode(', ', $found));
}

/**
 * Combine a human-set list with domain suggestions (human chips first, max 5).
 */
function prospect_niches_for_new_site(string $domain, string $humanNiche = ''): string
{
    $human = prospect_parse_niches($humanNiche);
    $suggested = prospect_suggest_niches_from_domain($domain);
    $merged = [];
    $seen = [];
    foreach (array_merge($human, $suggested) as $n) {
        $k = mb_strtolower($n);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $merged[] = $n;
        if (count($merged) >= 5) {
            break;
        }
    }
    return prospect_format_niches($merged);
}

function prospect_niches_search_haystack(string $niche): string
{
    return mb_strtolower(trim($niche));
}

/**
 * JSON for the chip typeahead (one copy per page).
 *
 * @return list<array{value:string,label:string,group:string}>
 */
function prospect_niche_typeahead_items(): array
{
    $items = [];
    foreach (prospect_niche_taxonomy() as $group => $labels) {
        foreach ($labels as $label) {
            $items[] = [
                'value' => $label,
                'label' => $label,
                'group' => $group,
            ];
        }
    }
    return $items;
}

function prospect_niche_taxonomy_script(): string
{
    $json = json_encode(
        prospect_niche_typeahead_items(),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    );
    return '<script type="application/json" id="prospect-niche-taxonomy">' . $json . '</script>';
}

function prospect_niche_chip_html(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    return '<span class="niche-chip" data-niche="' . h($label) . '">'
        . '<span class="niche-chip-label">' . h($label) . '</span>'
        . '<button type="button" class="niche-chip-x" data-niche-remove aria-label="Remove ' . h($label) . '">×</button>'
        . '</span>';
}

/**
 * Chip box: type to add, × to remove, click label to edit.
 *
 * @param array{
 *   name?:string,id?:string,siteId?:int,autosave?:bool,compact?:bool,
 *   placeholder?:string,disabled?:bool
 * } $opts
 */
function render_niche_chip_box(string $value, array $opts = []): string
{
    $list = prospect_parse_niches($value);
    $formatted = prospect_format_niches($list);
    $name = (string) ($opts['name'] ?? 'niche');
    $id = (string) ($opts['id'] ?? $name);
    $siteId = (int) ($opts['siteId'] ?? 0);
    $autosave = !empty($opts['autosave']);
    $compact = !empty($opts['compact']);
    $disabled = !empty($opts['disabled']);
    $placeholder = (string) ($opts['placeholder'] ?? ($compact ? 'Add…' : 'Type a niche, Enter to add'));
    $class = 'niche-chip-box' . ($compact ? ' is-compact' : '');
    $html = '<div class="' . h($class) . '" data-niche-chips';
    if ($id !== '') {
        $html .= ' id="' . h($id) . '_box"';
    }
    if ($siteId > 0) {
        $html .= ' data-site-id="' . $siteId . '"';
    }
    if ($autosave) {
        $html .= ' data-autosave="1"';
    }
    if ($disabled) {
        $html .= ' data-disabled="1"';
    }
    $html .= '>';
    if ($name !== '') {
        $html .= '<input type="hidden" name="' . h($name) . '" id="' . h($id) . '"'
            . ' value="' . h($formatted) . '" data-niche-value autocomplete="off">';
    } else {
        $html .= '<input type="hidden" value="' . h($formatted) . '" data-niche-value autocomplete="off">';
    }
    $html .= '<div class="niche-chip-control">';
    $html .= '<span class="niche-chip-list" data-niche-chips-list>';
    foreach ($list as $label) {
        $html .= prospect_niche_chip_html($label);
    }
    $html .= '</span>';
    if (!$disabled) {
        $html .= '<input type="text" class="niche-chip-input" data-niche-input'
            . ' id="' . h($id) . '_q"'
            . ' placeholder="' . h($placeholder) . '"'
            . ' autocomplete="off" spellcheck="false" data-no-draft>';
    }
    $html .= '</div>';
    $html .= '<ul class="typeahead-list niche-chip-suggest" hidden data-niche-list></ul>';
    $html .= '</div>';
    return $html;
}

function niche_chips_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/niche-chips.js')) . '" defer></script>';
}

/**
 * All / No niche chips + searchable Niche menu (Enter = jump).
 */
function render_prospect_niche_filter_bar(string $baseHref, string $current, array $keep = []): string
{
    $current = prospect_normalized_niche_filter($current);
    $qs = [];
    foreach ($keep as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $qs[(string) $k] = (string) $v;
    }
    $hrefFor = static function (string $niche) use ($baseHref, $qs): string {
        $q = $qs;
        if ($niche !== '') {
            $q['niche'] = $niche;
        }
        $url = $baseHref;
        foreach ($q as $k => $v) {
            $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return $url;
    };
    $chip = static function (string $href, string $label, bool $active): string {
        return '<a class="btn small' . ($active ? '' : ' secondary') . '" href="' . h($href) . '">' . h($label) . '</a>';
    };
    $html = '<p class="swe-sent-filters prospect-niche-filters">';
    $html .= $chip($hrefFor(''), 'All', $current === '');
    $html .= $chip($hrefFor('_none'), 'No niche', $current === '_none');
    $html .= '</p>';
    $summary = $current !== '' && $current !== '_none' ? 'Niche: ' . $current : 'Niche';
    $html .= '<details class="sheet-tool-menu niche-filter-menu">';
    $html .= '<summary class="btn secondary sheet-tool-menu-summary">' . h($summary) . '</summary>';
    $html .= '<div class="sheet-tool-menu-panel" role="group" aria-label="Filter by niche">';
    $html .= '<label class="sheet-search" for="prospect-niche-menu-search" style="margin:0 0 0.45rem">';
    $html .= '<span class="visually-hidden">Search niche</span>';
    $html .= '<input id="prospect-niche-menu-search" type="search" placeholder="Search niche…"'
        . ' autocomplete="off" spellcheck="false" data-no-draft'
        . ' title="Type a niche · Enter = next match">';
    $html .= '<span class="sheet-search-meta muted" data-niche-menu-search-meta hidden></span>';
    $html .= '</label>';
    foreach (prospect_niche_taxonomy() as $group => $labels) {
        $html .= '<div class="niche-filter-group" data-niche-menu-group>';
        $html .= '<p class="help niche-filter-group-label">' . h($group) . '</p>';
        foreach ($labels as $label) {
            $hay = mb_strtolower($group . ' ' . $label);
            $active = $current === $label;
            $html .= '<a class="btn small' . ($active ? '' : ' secondary') . ' niche-filter-item"'
                . ' data-niche-menu-item data-search="' . h($hay) . '"'
                . ' href="' . h($hrefFor($label)) . '">' . h($label) . '</a>';
        }
        $html .= '</div>';
    }
    $html .= '<p class="help sheet-search-empty" data-niche-menu-empty hidden>No niches match.</p>';
    $html .= '</div></details>';
    return $html;
}

function update_prospect_site_niches(int $id, string $raw): ?array
{
    if (!function_exists('ensure_prospect_schema')) {
        return null;
    }
    ensure_prospect_schema();
    $id = max(0, $id);
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, domain, country, niche FROM prospect_sites WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $formatted = prospect_format_niches(prospect_parse_niches($raw));
    db()->prepare('UPDATE prospect_sites SET niche=?, updated_at=NOW() WHERE id=?')->execute([$formatted, $id]);
    $row['niche'] = $formatted;
    return $row;
}

/**
 * Fill blank niches from the domain (never overwrites a set value).
 */
function backfill_blank_prospect_niches(?string $country = null, int $limit = 400): int
{
    if (!function_exists('ensure_prospect_schema')) {
        return 0;
    }
    ensure_prospect_schema();
    $limit = max(1, min(2000, $limit));
    $sql = "SELECT id, domain, niche FROM prospect_sites WHERE TRIM(niche)=''";
    $params = [];
    $country = trim((string) $country);
    if ($country !== '') {
        $sql .= ' AND country=?';
        $params[] = $country;
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $upd = db()->prepare('UPDATE prospect_sites SET niche=? WHERE id=? AND TRIM(niche)=\'\'');
    $n = 0;
    foreach ($rows as $row) {
        $suggested = prospect_niches_for_new_site((string) ($row['domain'] ?? ''), '');
        if ($suggested === '') {
            continue;
        }
        $upd->execute([$suggested, (int) $row['id']]);
        if ($upd->rowCount() > 0) {
            $n++;
        }
    }
    return $n;
}
