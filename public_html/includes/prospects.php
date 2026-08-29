<?php

/**
 * Our database helpers — unique domains (no prices).
 * Team Filter & add checks uniqueness against prospect_sites.
 * Admin Add sites saves directly (no uniqueness preview).
 */

require_once __DIR__ . '/prospect_niches.php';

/** Strip protocol/path → bare host for storage/lookup (does not validate apex-only). */
function normalize_domain(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = preg_replace('#^//#', '', $value) ?? $value;
    $value = preg_replace('#^www\.#i', '', $value) ?? $value;
    $host = explode('/', $value, 2)[0];
    $host = explode('?', $host, 2)[0];
    $host = explode('#', $host, 2)[0];
    if (str_contains($host, ':') && !str_contains($host, ']')) {
        $host = explode(':', $host, 2)[0];
    }
    return rtrim($host, '.');
}

/**
 * Common multi-part public suffixes (e.g. example.co.uk / shop.com.pl are root domains).
 *
 * @return list<string>
 */
function known_multi_part_tlds(): array
{
    return [
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'ltd.uk', 'plc.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'org.nz', 'net.nz', 'govt.nz', 'ac.nz',
        'co.za', 'org.za', 'web.za', 'net.za',
        'com.br', 'net.br', 'org.br', 'gov.br',
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
        'com.mx', 'org.mx', 'gob.mx',
        'com.sg', 'com.hk', 'com.tw', 'com.tr', 'com.my', 'com.ph',
        'co.in', 'firm.in', 'gen.in', 'ind.in', 'net.in', 'org.in',
        'com.ar', 'com.co', 'com.pe', 'com.ve', 'com.ec',
        'co.kr', 'co.th', 'co.il', 'org.il', 'ac.il',
        'com.cn', 'net.cn', 'org.cn',
        'co.id', 'or.id', 'web.id',
        // Poland, Pakistan, and other com.*/co.* country suffixes
        'com.pl', 'net.pl', 'org.pl', 'info.pl', 'biz.pl', 'edu.pl', 'gov.pl',
        'com.pk', 'net.pk', 'org.pk', 'gov.pk', 'edu.pk',
        'com.ua', 'net.ua', 'org.ua', 'gov.ua',
        'com.pt', 'net.pt', 'org.pt', 'gov.pt', 'edu.pt', 'publ.pt',
        'com.es', 'nom.es', 'org.es', 'gob.es', 'edu.es',
        'com.ng', 'org.ng', 'gov.ng', 'edu.ng', 'net.ng',
        'com.eg', 'net.eg', 'org.eg', 'edu.eg', 'gov.eg',
        'com.sa', 'net.sa', 'org.sa', 'edu.sa', 'gov.sa',
        'com.bd', 'net.bd', 'org.bd', 'edu.bd', 'gov.bd', 'ac.bd',
        'com.np', 'net.np', 'org.np', 'edu.np', 'gov.np',
        'com.lk', 'org.lk', 'edu.lk', 'gov.lk', 'net.lk',
        'com.kh', 'net.kh', 'org.kh', 'edu.kh', 'gov.kh',
        'co.ke', 'or.ke', 'ne.ke', 'go.ke', 'ac.ke',
        'com.cy', 'net.cy', 'org.cy', 'ac.cy', 'gov.cy',
        'com.mt', 'org.mt', 'net.mt', 'edu.mt', 'gov.mt',
        'com.ro', 'org.ro',
        'com.gr', 'net.gr', 'org.gr', 'edu.gr', 'gov.gr',
        'com.hr', 'from.hr', 'iz.hr', 'name.hr',
        'com.ba', 'net.ba', 'org.ba', 'edu.ba', 'gov.ba',
        'co.ao', 'it.ao', 'og.ao', 'pb.ao', 'gv.ao',
        'co.bw', 'org.bw',
        'co.ug', 'or.ug', 'ac.ug', 'go.ug', 'ne.ug', 'sc.ug',
        'co.tz', 'or.tz', 'ac.tz', 'go.tz', 'ne.tz', 'sc.tz',
        'co.zm', 'org.zm',
        'co.zw', 'org.zw', 'ac.zw', 'gov.zw',
    ];
}

/**
 * Second-level labels commonly paired with a 2-letter country code (com.pl, co.uk, …).
 *
 * @return list<string>
 */
function known_country_sld_labels(): array
{
    return [
        'com', 'co', 'org', 'net', 'gov', 'edu', 'ac', 'gob', 'go', 'or', 'ne',
        'me', 'ltd', 'plc', 'gen', 'firm', 'ind', 'web', 'asn', 'id', 'info',
        'biz', 'name', 'nom', 'publ', 'from', 'iz', 'it', 'og', 'pb', 'gv',
        'sc', 'govt',
    ];
}

/**
 * Valid DNS TLDs (ccTLDs + common gTLDs). Rejects fakes like .comz.
 *
 * @return array<string,true>
 */
function known_valid_tlds_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $cc = 'ad ae af ag ai al am ao aq ar as at au aw ax az ba bb bd be bf bg bh bi bj'
        . ' bl bm bn bo bq br bs bt bv bw by bz ca cc cd cf cg ch ci ck cl cm cn co cr'
        . ' cu cv cw cx cy cz de dj dk dm do dz ec ee eg eh er es et eu fi fj fk fm fo'
        . ' fr ga gb gd ge gf gg gh gi gl gm gn gp gq gr gs gt gu gw gy hk hm hn hr ht'
        . ' hu id ie il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw ky'
        . ' kz la lb lc li lk lr ls lt lu lv ly ma mc md me mg mh mk ml mm mn mo mp mq'
        . ' mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz om pa pe pf'
        . ' pg ph pk pl pm pn pr ps pt pw py qa re ro rs ru rw sa sb sc sd se sg sh si'
        . ' sj sk sl sm sn so sr ss st su sv sx sy sz tc td tf tg th tj tk tl tm tn to'
        . ' tr tt tv tw tz ua ug uk us uy uz va vc ve vg vi vn vu wf ws ye yt za zm zw';
    $gtld = 'com net org info biz name pro edu gov mil int aero asia cat coop jobs'
        . ' mobi museum post tel travel xxx app dev page site online store shop blog'
        . ' cloud digital email agency studio media news world club live life today'
        . ' space tech website company solutions services systems network global'
        . ' international group ltd limited llc inc corp center centre design art'
        . ' photography video game games software support help care health clinic'
        . ' dental legal law accountant finance bank money insurance realestate'
        . ' properties homes house hotel travel vacations vacations tours cricket'
        . ' football soccer tennis golf sports fitness gym yoga music band film'
        . ' movie tv radio podcast books education school university college kids'
        . ' family baby wedding dating singles church faith bible charity ngo'
        . ' foundation org community social link click download host hosting'
        . ' server domain domains email mail web webs website websites xyz top'
        . ' win bid loan work works expert review reviews report reports press'
        . ' news blog spot zip mov new old cool fun wow one two red blue green'
        . ' black white gold vip rich luxury boutique fashion watch jewelry diamonds'
        . ' cafe bar pub beer wine vodka restaurant menu kitchen food pizza sushi'
        . ' burger chicken vegan organic farm garden flowers plants pet dog cat'
        . ' auto cars car motor motors bike boats yachts build builder construction'
        . ' engineer engineering energy solar power green earth eco bio science'
        . ' academy institute training coaching consulting management marketing'
        . ' advertising agency digital seo brand brands sale sales deal deals'
        . ' discount coupon market marketplace auction trade trading exchange'
        . ' crypto bitcoin nft token wallet cash pay payment credit card'
        . ' ai io co tv me cc ws info';
    $map = [];
    foreach (preg_split('/\s+/', trim($cc . ' ' . $gtld)) ?: [] as $t) {
        $t = strtolower(trim((string) $t));
        if ($t !== '') {
            $map[$t] = true;
        }
    }
    return $map;
}

function is_known_tld(string $tld): bool
{
    $tld = strtolower(trim($tld));
    return $tld !== '' && isset(known_valid_tlds_map()[$tld]);
}

function is_known_public_suffix(string $suffix): bool
{
    $suffix = strtolower(trim($suffix));
    if ($suffix === '') {
        return false;
    }
    if (in_array($suffix, known_multi_part_tlds(), true)) {
        return true;
    }
    if (in_array($suffix, known_platform_public_suffixes(), true)) {
        return true;
    }
    if (!str_contains($suffix, '.')) {
        return is_known_tld($suffix);
    }
    $parts = array_values(array_filter(explode('.', $suffix), static fn ($p) => $p !== ''));
    if (count($parts) !== 2) {
        return false;
    }
    return is_known_tld($parts[1])
        && in_array($parts[0], known_country_sld_labels(), true);
}

/**
 * Multi-tenant / platform public suffixes — keep utilfox.vercel.app, not vercel.app.
 *
 * @return list<string>
 */
function known_platform_public_suffixes(): array
{
    return [
        'vercel.app',
        'github.io',
        'herokuapp.com',
        'netlify.app',
        'pages.dev',
        'workers.dev',
        'web.app',
        'firebaseapp.com',
        'azurewebsites.net',
        'myshopify.com',
        'blogspot.com',
        'wordpress.com',
        'tumblr.com',
        'gitlab.io',
    ];
}

function domain_public_suffix(string $host): string
{
    $host = strtolower(trim($host));
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    $n = count($parts);
    if ($n < 2) {
        return '';
    }
    $two = $parts[$n - 2] . '.' . $parts[$n - 1];
    if (in_array($two, known_platform_public_suffixes(), true)) {
        return $two;
    }
    if (in_array($two, known_multi_part_tlds(), true)) {
        return $two;
    }
    // Heuristic: foo.com.pl / bar.com.pk / shop.co.uk — keep multi-part country suffixes.
    $sld = $parts[$n - 2];
    $cc = $parts[$n - 1];
    if (
        strlen($cc) === 2
        && is_known_tld($cc)
        && in_array($sld, known_country_sld_labels(), true)
    ) {
        return $two;
    }
    return $parts[$n - 1];
}

/**
 * True when $host is an apex/root domain (no subdomain), allowing multi-part TLDs like .co.uk.
 */
function is_root_domain(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || !str_contains($host, '.')) {
        return false;
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        return false;
    }
    if (str_starts_with($host, '-') || str_ends_with($host, '-') || str_contains($host, '..')) {
        return false;
    }
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    if (count($parts) < 2) {
        return false;
    }
    foreach ($parts as $label) {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
            return false;
        }
    }
    $suffix = domain_public_suffix($host);
    if ($suffix === '' || !is_known_public_suffix($suffix)) {
        return false;
    }
    $suffixParts = substr_count($suffix, '.') + 1;
    $nameParts = count($parts) - $suffixParts;
    return $nameParts === 1;
}

/**
 * Extract a hostname candidate from a messy paste (https, path, port, www).
 */
function extract_host_candidate(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    // Strip attention-box reason tags: "junk  # has_spaces"
    if (preg_match('/^(.*)\s+#\s+[a-z0-9_]+\s*$/i', $s, $m)) {
        $s = trim($m[1]);
    }
    // Markdown link: [text](https://example.com/x)
    if (preg_match('/\[[^\]]*\]\((https?:\/\/[^)\s]+)\)/i', $s, $m)) {
        $s = $m[1];
    } elseif (preg_match('/href\s*=\s*["\']\s*(https?:\/\/[^"\']+)["\']/i', $s, $m)) {
        $s = $m[1];
    } elseif (preg_match('#(https?://[^\s<>"\']+)#i', $s, $m)) {
        // Line with surrounding junk but a clear URL
        $s = $m[1];
    }
    // Excel-style "domain\tnotes" — keep first column if it looks like a host/URL
    if (str_contains($s, "\t")) {
        $first = trim(explode("\t", $s, 2)[0]);
        if ($first !== '') {
            $s = $first;
        }
    }

    $s = preg_replace('/^[\s\'"\[<\(]+/', '', $s) ?? $s;
    $s = preg_replace('/[\s\'"\]>\)]+$/', '', $s) ?? $s;

    // Prefer parse_url for full https://…/path?#… pastes (Filter & add Clean to root domains).
    $probe = $s;
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $probe) && str_contains($probe, '.')) {
        if (preg_match('~^[a-z0-9.-]+(/|\?|#|$)~i', $probe)) {
            $probe = 'https://' . $probe;
        }
    }
    // Typo schemes: ttps://, htps://, ttp://
    $probe = preg_replace('#^(?:h?ttps?|tps?)://#i', 'https://', $probe) ?? $probe;
    $host = parse_url($probe, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $s = $host;
    } else {
        $s = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $s) ?? $s;
        if (str_starts_with($s, '//')) {
            $s = substr($s, 2);
        }
        $s = explode('/', $s, 2)[0];
        $s = explode('?', $s, 2)[0];
        $s = explode('#', $s, 2)[0];
    }

    if (str_contains($s, '@')) {
        $parts = explode('@', $s);
        $s = (string) end($parts);
    }
    $s = strtolower($s);
    if (str_contains($s, ':') && !str_contains($s, ']')) {
        $s = explode(':', $s, 2)[0];
    }
    $s = preg_replace('#^www\.#i', '', $s) ?? $s;
    return rtrim($s, '.');
}

/**
 * Reduce a host to apex/root domain (eTLD+1), e.g. blog.example.co.uk → example.co.uk
 */
function to_root_domain(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('#^www\.#i', '', $host) ?? $host;
    $host = rtrim($host, '.');
    if ($host === '' || !str_contains($host, '.')) {
        return '';
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        return '';
    }
    if (str_starts_with($host, '-') || str_ends_with($host, '-') || str_contains($host, '..')) {
        return '';
    }
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    if (count($parts) < 2) {
        return '';
    }
    foreach ($parts as $label) {
        if ($label === '' || strlen($label) > 63) {
            return '';
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
            return '';
        }
    }
    $suffix = domain_public_suffix($host);
    if ($suffix === '') {
        return '';
    }
    $suffixParts = substr_count($suffix, '.') + 1;
    $need = $suffixParts + 1;
    if (count($parts) < $need) {
        return '';
    }
    $root = implode('.', array_slice($parts, -$need));
    return is_root_domain($root) ? $root : '';
}

/**
 * Classify one pasted line for root-domain-only input.
 * Prefers correcting (https / path / subdomain → root domain) over rejecting.
 *
 * @return array{ok:bool,domain:string,reason:string,raw:string,fixed?:bool}
 */
function analyze_pasted_domain_line(string $line): array
{
    $raw = trim($line);
    if ($raw === '') {
        return ['ok' => false, 'domain' => '', 'reason' => 'empty', 'raw' => $raw, 'fixed' => false];
    }

    $host = extract_host_candidate($raw);
    $root = to_root_domain($host);
    if ($root !== '') {
        return [
            'ok' => true,
            'domain' => $root,
            'reason' => '',
            'raw' => $raw,
            'fixed' => strtolower($raw) !== $root,
        ];
    }

    if (preg_match('#https?://#i', $raw) || str_starts_with($raw, '//') || str_contains($raw, '://')) {
        return ['ok' => false, 'domain' => '', 'reason' => 'has_scheme', 'raw' => $raw, 'fixed' => false];
    }
    if (str_contains($raw, '/') || str_contains($raw, '?') || str_contains($raw, '#')) {
        return ['ok' => false, 'domain' => '', 'reason' => 'has_path', 'raw' => $raw, 'fixed' => false];
    }
    if (str_contains($raw, ' ') || str_contains($raw, "\t")) {
        return ['ok' => false, 'domain' => '', 'reason' => 'has_spaces', 'raw' => $raw, 'fixed' => false];
    }
    if ($host !== '' && str_contains($host, '.')) {
        $suffix = domain_public_suffix($host);
        $suffixParts = $suffix !== '' ? substr_count($suffix, '.') + 1 : 1;
        $parts = array_values(array_filter(explode('.', $host)));
        if (count($parts) - $suffixParts > 1) {
            return ['ok' => false, 'domain' => '', 'reason' => 'subdomain', 'raw' => $raw, 'fixed' => false];
        }
    }

    return ['ok' => false, 'domain' => '', 'reason' => 'invalid', 'raw' => $raw, 'fixed' => false];
}

/**
 * Parse pasted sites: only apex/root domains (no https, paths, or subdomains).
 *
 * @return array{valid:list<string>,invalid:list<array{raw:string,reason:string}>,valid_text:string,invalid_count:int}
 */
function parse_domain_list_strict(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = preg_split('/\n+/', $raw) ?: [];
    $valid = [];
    $invalid = [];
    $duplicateCount = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // Commas or whitespace (browsers often turn newlines in <input value> into spaces).
        $chunks = preg_split('/[\s,]+/', $line) ?: [$line];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $a = analyze_pasted_domain_line($chunk);
            if ($a['ok']) {
                $domain = (string) $a['domain'];
                if (isset($valid[$domain])) {
                    $duplicateCount++;
                } else {
                    $valid[$domain] = true;
                }
            } else {
                $invalid[] = ['raw' => $a['raw'], 'reason' => $a['reason']];
            }
        }
    }
    $validList = array_keys($valid);
    return [
        'valid' => $validList,
        'invalid' => $invalid,
        'valid_text' => implode("\n", $validList),
        'invalid_count' => count($invalid),
        'duplicate_count' => $duplicateCount,
    ];
}

/**
 * Ensure prospect tables exist (Hostinger safety net if upgrade.php was skipped).
 * Each country is its own URL database: unique on (country, domain).
 */
function ensure_prospect_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          url VARCHAR(500) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          niche VARCHAR(512) NOT NULL DEFAULT '',
          notes TEXT,
          status ENUM('new','contacting','replied','skipped') NOT NULL DEFAULT 'new',
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_prospect_country_domain (country, domain),
          INDEX (domain),
          INDEX (country),
          INDEX (language),
          INDEX (region),
          INDEX (status),
          CONSTRAINT fk_prospect_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Migrate older installs: global unique(domain) → unique(country, domain)
    try {
        $idx = $pdo->query('SHOW INDEX FROM prospect_sites')->fetchAll(PDO::FETCH_ASSOC);
        $hasOld = false;
        $hasNew = false;
        foreach ($idx as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === 'uniq_prospect_domain') {
                $hasOld = true;
            }
            if ($name === 'uniq_prospect_country_domain') {
                $hasNew = true;
            }
        }
        if ($hasOld) {
            $pdo->exec('ALTER TABLE prospect_sites DROP INDEX uniq_prospect_domain');
        }
        if (!$hasNew) {
            $pdo->exec('ALTER TABLE prospect_sites ADD UNIQUE KEY uniq_prospect_country_domain (country, domain)');
        }
    } catch (Throwable $e) {
        // ignore — CREATE above already has the right key on new installs
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_batches (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          batch_date DATE NOT NULL,
          site_count INT NOT NULL DEFAULT 0,
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          niche VARCHAR(512) NOT NULL DEFAULT '',
          notes TEXT,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_user_batch_date_country (user_id, batch_date, country),
          INDEX (batch_date),
          INDEX (user_id),
          CONSTRAINT fk_pbatch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Migrate older installs: one batch per user/day → per user/day/country.
    try {
        $idx = $pdo->query("SHOW INDEX FROM prospect_batches WHERE Key_name='uniq_user_batch_date'")->fetchAll();
        if ($idx) {
            $pdo->exec('ALTER TABLE prospect_batches DROP INDEX uniq_user_batch_date');
        }
        $idx2 = $pdo->query("SHOW INDEX FROM prospect_batches WHERE Key_name='uniq_user_batch_date_country'")->fetchAll();
        if (!$idx2) {
            $pdo->exec(
                'ALTER TABLE prospect_batches
                 ADD UNIQUE KEY uniq_user_batch_date_country (user_id, batch_date, country)'
            );
        }
    } catch (Throwable $e) {
        // ignore — may already be migrated or empty
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prospect_batch_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          batch_id INT NOT NULL,
          domain VARCHAR(255) NOT NULL,
          prospect_site_id INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_batch_domain (batch_id, domain),
          INDEX (domain),
          CONSTRAINT fk_pbi_batch FOREIGN KEY (batch_id) REFERENCES prospect_batches(id) ON DELETE CASCADE,
          CONSTRAINT fk_pbi_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach (['prospect_sites', 'prospect_batches'] as $table) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'niche'")->fetch(PDO::FETCH_ASSOC);
            $type = strtolower((string) ($col['Type'] ?? ''));
            if ($type !== '' && !str_contains($type, '512') && !str_starts_with($type, 'text')) {
                $pdo->exec("ALTER TABLE {$table} MODIFY niche VARCHAR(512) NOT NULL DEFAULT ''");
            }
        } catch (Throwable $e) {
            // ignore — CREATE above already has the wider column on new installs
        }
    }
}

/**
 * User-facing copy for auto-removed duplicates in a country folder.
 */
function prospect_duplicates_deleted_message(int $n): string
{
    $n = max(0, $n);
    if ($n === 1) {
        return '1 duplicate found and removed';
    }
    return $n . ' duplicates found and removed';
}

/**
 * Copy-all button label: say which subset will actually copy.
 */
function prospect_copy_all_label(int $createdBy, string $nicheFilter): string
{
    $nicheFilter = prospect_normalized_niche_filter($nicheFilter);
    $person = $createdBy > 0;
    if ($person && $nicheFilter === '_none') {
        return 'Copy this person’s sites with no niche';
    }
    if ($person && $nicheFilter !== '') {
        return 'Copy this person’s ' . $nicheFilter . ' sites';
    }
    if ($person) {
        return 'Copy this person’s sites';
    }
    if ($nicheFilter === '_none') {
        return 'Copy sites with no niche';
    }
    if ($nicheFilter !== '') {
        return 'Copy ' . $nicheFilter;
    }
    return 'Copy all';
}

/**
 * Country-folder URL that keeps search / niche / person / paging.
 *
 * @param array{q?:string,niche?:string,created_by?:int|string,per_page?:int|string,p?:int|string,hash?:string} $keep
 */
function prospect_country_sheet_url(string $country, array $keep = []): string
{
    $country = trim($country);
    if ($country === '') {
        $country = '_none';
    }
    $qs = [
        'page' => 'admin_prospects',
        'country' => $country,
    ];
    $q = trim((string) ($keep['q'] ?? ''));
    if ($q !== '') {
        $qs['q'] = $q;
    }
    $niche = function_exists('prospect_normalized_niche_filter')
        ? prospect_normalized_niche_filter((string) ($keep['niche'] ?? ''))
        : trim((string) ($keep['niche'] ?? ''));
    if ($niche !== '') {
        $qs['niche'] = $niche;
    }
    $createdBy = (int) ($keep['created_by'] ?? 0);
    if ($createdBy > 0) {
        $qs['created_by'] = (string) $createdBy;
    }
    $per = (int) ($keep['per_page'] ?? 0);
    if ($per > 0) {
        $qs['per_page'] = (string) $per;
    }
    $p = (int) ($keep['p'] ?? 0);
    if ($p > 1) {
        $qs['p'] = (string) $p;
    }
    $url = 'index.php?' . http_build_query($qs);
    $hash = trim((string) ($keep['hash'] ?? ''));
    if ($hash !== '') {
        $url .= '#' . ltrim($hash, '#');
    }
    return $url;
}

function prospect_open_in_folder_label(string $country): string
{
    $country = trim($country);
    return 'Open in ' . ($country !== '' ? $country : 'No country');
}

/**
 * HTML for Our database country site table rows (AJAX search + initial render).
 *
 * @param list<array<string,mixed>> $rows
 */
function prospect_site_rows_html(array $rows): string
{
    ob_start();
    foreach ($rows as $s) {
        $domain = (string) ($s['domain'] ?? '');
        $url = (string) ($s['url'] ?? '');
        $lang = (string) ($s['language'] ?? '');
        $niche = (string) ($s['niche'] ?? '');
        $added = (string) (($s['added_by_full'] ?? '') ?: ($s['added_by_name'] ?? ''));
        $when = substr((string) ($s['created_at'] ?? ''), 0, 10);
        $hay = mb_strtolower(trim($domain . ' ' . $url . ' ' . $niche . ' ' . $lang . ' ' . $added));
        echo '<tr data-prospect-site-row data-domain="' . h($domain) . '" data-site-id="' . (int) ($s['id'] ?? 0) . '"'
            . ' data-search="' . h($hay) . '">';
        echo '<td class="sheet-td-check" data-label="Select">';
        echo '<label class="sheet-check">';
        echo '<input type="checkbox" data-sheet-row-check value="' . (int) ($s['id'] ?? 0) . '" aria-label="Select ' . h($domain) . '">';
        echo '</label></td>';
        echo '<td class="prospect-niche-td" data-label="Niche">';
        echo render_niche_chip_box($niche, [
            'name' => '',
            'id' => 'niche_' . (int) ($s['id'] ?? 0),
            'siteId' => (int) ($s['id'] ?? 0),
            'autosave' => true,
            'compact' => true,
        ]);
        echo '</td>';
        echo '<td class="prospect-domain-td" data-label="Domain"><strong>' . h($domain) . '</strong>';
        $openSrc = $url !== '' ? $url : $domain;
        if (function_exists('render_open_site_anchor')) {
            echo ' ' . render_open_site_anchor($openSrc, [
                'class' => 'small',
                'label' => 'Open website',
            ]);
        } else {
            $openHref = preg_match('#^https?://#i', $openSrc)
                ? $openSrc
                : ('https://' . ltrim($domain, '/'));
            if ($domain !== '') {
                echo ' <a class="open-site-link small" href="' . h($openHref)
                    . '" target="_blank" rel="noopener noreferrer">Open website</a>';
            }
        }
        echo '</td>';
        echo '<td data-label="Language">' . h($lang !== '' ? $lang : '—') . '</td>';
        echo '<td data-label="Added by">' . h($added !== '' ? $added : '—') . '</td>';
        echo '<td data-label="When">' . h($when) . '</td>';
        echo '</tr>';
    }
    return (string) ob_get_clean();
}

/**
 * Delete extra prospect_sites rows that share the same (country, domain).
 * Keeps the lowest id. Returns how many rows were removed.
 */
function purge_duplicate_prospect_site_rows(?string $country = null): int
{
    ensure_prospect_schema();
    $pdo = db();
    $country = trim((string) $country);
    if ($country !== '') {
        $canon = resolve_canonical_country($country);
        if ($canon === null) {
            return 0;
        }
        $country = $canon['name'];
        $stmt = $pdo->prepare(
            'DELETE p1 FROM prospect_sites p1
             INNER JOIN prospect_sites p2
               ON p1.country = p2.country
              AND p1.domain = p2.domain
              AND p1.id > p2.id
             WHERE p1.country = ?'
        );
        $stmt->execute([$country]);
        return (int) $stmt->rowCount();
    }

    $removed = (int) $pdo->exec(
        'DELETE p1 FROM prospect_sites p1
         INNER JOIN prospect_sites p2
           ON p1.country = p2.country
          AND p1.domain = p2.domain
          AND p1.id > p2.id'
    );
    return $removed;
}

/**
 * Merge orphan / misspelled country labels into existing catalog countries.
 * Team/admin must never create separate country folders — only the countries table list.
 *
 * @return int number of prospect_sites rows rewritten or removed as duplicates
 */
function merge_orphan_prospect_countries(): int
{
    static $done = false;
    if ($done) {
        return 0;
    }
    $done = true;

    ensure_prospect_schema();
    if (function_exists('seed_countries_if_empty')) {
        try {
            seed_countries_if_empty(db());
        } catch (Throwable $e) {
            // ignore
        }
    }

    $pdo = db();
    $changed = 0;
    try {
        $labels = $pdo->query(
            "SELECT DISTINCT TRIM(country) AS country FROM prospect_sites"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return 0;
    }

    $findId = $pdo->prepare(
        'SELECT id FROM prospect_sites WHERE TRIM(country)=? AND domain=? LIMIT 1'
    );
    $upd = $pdo->prepare('UPDATE prospect_sites SET country=?, region=?, updated_at=NOW() WHERE id=?');
    $del = $pdo->prepare('DELETE FROM prospect_sites WHERE id=?');
    $updBatch = $pdo->prepare(
        "UPDATE prospect_batches SET country=?, region=IF(?<>'', ?, region), updated_at=NOW()
         WHERE TRIM(country)=?"
    );

    foreach ($labels as $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $canon = resolve_canonical_country($label);
        if ($canon === null) {
            // Unknown free-text folder: clear label so it does not appear as a new country.
            // Domains remain under empty country (No country) instead of a custom folder.
            if ($label !== '') {
                $stmt = $pdo->prepare('SELECT id, domain FROM prospect_sites WHERE TRIM(country)=?');
                $stmt->execute([$label]);
                foreach ($stmt->fetchAll() as $row) {
                    $findId->execute(['', $row['domain']]);
                    $existingId = (int) $findId->fetchColumn();
                    if ($existingId > 0 && $existingId !== (int) $row['id']) {
                        $del->execute([(int) $row['id']]);
                    } else {
                        $upd->execute(['', '', (int) $row['id']]);
                    }
                    $changed++;
                }
                try {
                    $updBatch->execute(['', '', '', $label]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            continue;
        }
        if (strcasecmp($canon['name'], $label) === 0 && $label === $canon['name']) {
            continue; // already canonical spelling
        }
        // Case / spacing variant of an existing country → merge into catalog name
        $stmt = $pdo->prepare('SELECT id, domain, region FROM prospect_sites WHERE TRIM(country)=?');
        $stmt->execute([$label]);
        foreach ($stmt->fetchAll() as $row) {
            $findId->execute([$canon['name'], $row['domain']]);
            $existingId = (int) $findId->fetchColumn();
            if ($existingId > 0 && $existingId !== (int) $row['id']) {
                // Domain already in the real country folder — drop the orphan duplicate.
                $del->execute([(int) $row['id']]);
            } else {
                $region = trim((string) ($row['region'] ?? ''));
                if ($region === '') {
                    $region = $canon['region'];
                }
                $upd->execute([$canon['name'], $region, (int) $row['id']]);
            }
            $changed++;
        }
        try {
            $updBatch->execute([$canon['name'], $canon['region'], $canon['region'], $label]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $changed;
}

/**
 * Country folders for Admin/Team: only existing countries table folders + URL counts.
 * Orphan free-text country labels are merged away first (never create separate countries).
 *
 * @return list<array{country:string,region:string,region_label:string,total:int,language:string}>
 */
function prospect_country_folders(): array
{
    ensure_prospect_schema();
    if (function_exists('seed_countries_if_empty')) {
        try {
            seed_countries_if_empty(db());
        } catch (Throwable $e) {
            // countries table may be created by upgrade/install
        }
    }
    if (function_exists('dedupe_countries_catalog')) {
        try {
            dedupe_countries_catalog();
        } catch (Throwable $e) {
            // ignore
        }
    }
    try {
        merge_orphan_prospect_countries();
    } catch (Throwable $e) {
        // ignore repair failures
    }

    $counts = [];
    foreach (db()->query(
        "SELECT TRIM(country) AS country, COUNT(*) AS total
         FROM prospect_sites
         GROUP BY TRIM(country)"
    )->fetchAll() as $row) {
        $key = (string) $row['country'];
        $canon = $key !== '' ? resolve_canonical_country($key) : null;
        $folderKey = $canon ? $canon['name'] : '';
        $counts[$folderKey] = ($counts[$folderKey] ?? 0) + (int) $row['total'];
    }

    $regionOrder = array_flip(array_keys(regions()));
    $folders = [];
    $seenNames = [];
    foreach (list_countries(null, true) as $c) {
        $name = trim((string) $c['name']);
        if ($name === '') {
            continue;
        }
        $nameKey = mb_strtolower($name);
        if (isset($seenNames[$nameKey])) {
            continue;
        }
        $seenNames[$nameKey] = true;
        $region = (string) $c['region'];
        $code = strtoupper(trim((string) ($c['code'] ?? '')));
        $folders[] = [
            'country' => $name,
            'code' => $code,
            'region' => $region,
            'region_label' => regions()[$region] ?? $region,
            'total' => $counts[$name] ?? 0,
            'language' => (string) ($c['default_language'] ?? ''),
            'display_label' => function_exists('prospect_folder_display_label')
                ? prospect_folder_display_label($name, $region, $code)
                : $name,
        ];
        unset($counts[$name]);
    }
    // Empty-country leftovers only (never invent new country folders)
    if (!empty($counts[''])) {
        $folders[] = [
            'country' => '',
            'code' => '',
            'region' => 'other',
            'region_label' => 'Other',
            'total' => (int) $counts[''],
            'language' => '',
            'display_label' => 'No country',
        ];
    }
    usort($folders, static function ($a, $b) use ($regionOrder) {
        $ra = (string) ($a['region'] ?? '');
        $rb = (string) ($b['region'] ?? '');
        $oa = $regionOrder[$ra] ?? 99;
        $ob = $regionOrder[$rb] ?? 99;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        $ta = (int) ($a['total'] ?? 0);
        $tb = (int) ($b['total'] ?? 0);
        if ($ta !== $tb) {
            return $tb <=> $ta; // most sites first
        }
        return strcasecmp((string) ($a['display_label'] ?? $a['country']), (string) ($b['display_label'] ?? $b['country']));
    });
    return $folders;
}

function parse_domain_list(string $raw): array
{
    return parse_domain_list_strict($raw)['valid'];
}

/**
 * Check domains against Our database.
 * When $country is set, only that country’s database is checked.
 *
 * @return array{existing:string[],new:string[],invalid:int,total_input:int}
 */
function filter_domains_against_prospects(array $domains, string $country = ''): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    $country = trim($country);
    if ($country !== '') {
        $canon = resolve_canonical_country($country);
        if ($canon === null) {
            throw new InvalidArgumentException(
                'Select an existing country database (e.g. Germany, Spain). New country folders are not created.'
            );
        }
        $country = $canon['name'];
    }
    $existing = [];
    $new = [];
    if (!$domains) {
        return ['existing' => [], 'new' => [], 'invalid' => 0, 'total_input' => 0];
    }

    $chunkSize = 500;
    $found = [];
    for ($i = 0, $n = count($domains); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($domains, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        if ($country !== '') {
            $sql = "SELECT domain FROM prospect_sites WHERE TRIM(country)=? AND domain IN ($placeholders)";
            $params = array_merge([$country], $chunk);
        } else {
            $sql = "SELECT domain FROM prospect_sites WHERE domain IN ($placeholders)";
            $params = $chunk;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $found[$d] = true;
        }
    }
    foreach ($domains as $d) {
        if (isset($found[$d])) {
            $existing[] = $d;
        } else {
            $new[] = $d;
        }
    }
    return [
        'existing' => $existing,
        'new' => $new,
        'invalid' => 0,
        'total_input' => count($domains),
    ];
}

/**
 * Route domains by TLD (same rules as Extracting Results Push), then drop
 * duplicates against each destination country’s Our database.
 *
 * Generic TLDs (.com, .net, .eu, …) and unknown TLDs stay in $selectedCountry.
 * Country TLDs (.de, .at, .ch, …) go to their primary country folder.
 *
 * @param list<string> $domains
 * @return array{
 *   existing:list<string>,
 *   new:list<string>,
 *   invalid:int,
 *   total_input:int,
 *   by_country:array<string, array{new:list<string>,existing:list<string>}>,
 *   routed_groups:array<string, list<string>>
 * }
 */
function filter_domains_routed_against_prospects(array $domains, string $selectedCountry): array
{
    ensure_prospect_schema();
    $selected = require_canonical_country($selectedCountry);
    $selectedName = $selected['name'];

    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    if ($domains === []) {
        return [
            'existing' => [],
            'new' => [],
            'invalid' => 0,
            'total_input' => 0,
            'by_country' => [],
            'routed_groups' => [],
        ];
    }

    if (!function_exists('route_domains_by_country_tld')) {
        require_once __DIR__ . '/geo.php';
    }
    $groups = route_domains_by_country_tld($domains, $selectedName);

    $byCountry = [];
    $allNew = [];
    $allExisting = [];
    foreach ($groups as $dest => $list) {
        $destCanon = resolve_canonical_country((string) $dest);
        $destName = $destCanon['name'] ?? $selectedName;
        $check = filter_domains_against_prospects($list, $destName);
        $byCountry[$destName] = [
            'new' => $check['new'],
            'existing' => $check['existing'],
        ];
        foreach ($check['new'] as $d) {
            $allNew[$d] = true;
        }
        foreach ($check['existing'] as $d) {
            $allExisting[$d] = true;
        }
    }

    // Domains already present in their destination must not appear as "new".
    foreach (array_keys($allExisting) as $d) {
        unset($allNew[$d]);
    }

    return [
        'existing' => array_keys($allExisting),
        'new' => array_keys($allNew),
        'invalid' => 0,
        'total_input' => count($domains),
        'by_country' => $byCountry,
        'routed_groups' => $groups,
    ];
}

/**
 * After Filter unique sites: remember which domains passed for this country.
 * Add / Separate Send may only save domains from this set (workflow gate).
 *
 * @param list<string> $uniqueDomains
 */
function prospect_filter_gate_set(string $country, array $uniqueDomains): void
{
    $country = trim($country);
    $allowed = [];
    foreach ($uniqueDomains as $d) {
        $n = normalize_domain((string) $d);
        if ($n !== '') {
            $allowed[$n] = true;
        }
    }
    $_SESSION['prospect_filter_gate'] = [
        'country' => $country,
        'allowed' => $allowed,
        'at' => time(),
    ];
}

function prospect_filter_gate_clear(): void
{
    unset($_SESSION['prospect_filter_gate']);
}

/**
 * True when $country matches the last Filter run and every domain is in that unique set.
 *
 * @param list<string> $domains
 */
function prospect_filter_gate_allows(string $country, array $domains): bool
{
    $gate = $_SESSION['prospect_filter_gate'] ?? null;
    if (!is_array($gate)) {
        return false;
    }
    $country = trim($country);
    if ($country === '' || ($gate['country'] ?? '') !== $country) {
        return false;
    }
    $at = (int) ($gate['at'] ?? 0);
    if ($at < 1 || (time() - $at) > 7200) {
        return false;
    }
    $allowed = $gate['allowed'] ?? null;
    if (!is_array($allowed)) {
        return false;
    }
    if (!$domains) {
        return false;
    }
    foreach ($domains as $d) {
        $n = normalize_domain((string) $d);
        if ($n === '' || empty($allowed[$n])) {
            return false;
        }
    }
    return true;
}

/**
 * Plain domain names for Filter Box 1. Optionally scoped to one country database.
 *
 * @return array{domains:string[],total:int,truncated:bool}
 */
function list_prospect_domain_names(int $maxDisplay = 25000, string $country = ''): array
{
    ensure_prospect_schema();
    $country = trim($country);
    if ($country !== '') {
        $canon = resolve_canonical_country($country);
        if ($canon === null) {
            return ['domains' => [], 'total' => 0, 'truncated' => false];
        }
        $country = $canon['name'];
        $count = db()->prepare('SELECT COUNT(*) FROM prospect_sites WHERE TRIM(country)=?');
        $count->execute([$country]);
        $total = (int) $count->fetchColumn();
        $stmt = db()->prepare(
            'SELECT domain FROM prospect_sites WHERE TRIM(country)=? ORDER BY domain ASC LIMIT ' . (int) $maxDisplay
        );
        $stmt->execute([$country]);
    } else {
        $total = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
        $stmt = db()->query(
            'SELECT domain FROM prospect_sites ORDER BY domain ASC LIMIT ' . (int) $maxDisplay
        );
    }
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return [
        'domains' => $domains,
        'total' => $total,
        'truncated' => $total > count($domains),
    ];
}

/**
 * Count sites in one Our database country folder matching $q (domain / url / niche / notes).
 * Empty $q → total sites in that country (or 0 for unknown country).
 */
function count_prospect_sites_matching(string $country, string $q = '', string $niche = ''): int
{
    ensure_prospect_schema();
    $country = trim($country);
    if ($country === '') {
        return 0;
    }
    $canon = resolve_canonical_country($country);
    if ($canon === null) {
        return 0;
    }
    $country = $canon['name'];
    $q = trim($q);
    $sql = 'SELECT COUNT(*) FROM prospect_sites WHERE country=?';
    $params = [$country];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (domain LIKE ? OR url LIKE ? OR niche LIKE ? OR notes LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $nf = prospect_sql_niche_filter('niche', $niche);
    if ($nf['sql'] !== '') {
        $sql .= ' AND ' . $nf['sql'];
        array_push($params, ...$nf['params']);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Download basename for Our database export (no extension).
 * e.g. germany-our-database or germany-our-database-matches
 */
function prospect_export_basename(string $country, string $q = ''): string
{
    $canon = resolve_canonical_country(trim($country));
    $label = $canon ? (string) $canon['name'] : trim($country);
    $safe = strtolower((string) (preg_replace('/[^a-zA-Z0-9]+/', '-', $label) ?: 'sites'));
    $safe = trim($safe, '-') ?: 'sites';
    $suffix = trim($q) !== '' ? '-matches' : '';
    return $safe . '-our-database' . $suffix;
}

/**
 * Stream one domain per line for Copy all / Download .txt (optionally filtered by $q).
 */
function stream_prospect_domains_plain(string $country, bool $asDownload = false, string $q = '', int $createdBy = 0, string $niche = ''): void
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $canon = resolve_canonical_country($country);
    if ($canon === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Country not found.\n";
        exit;
    }
    $country = $canon['name'];
    $q = trim($q);
    $createdBy = max(0, $createdBy);
    $base = prospect_export_basename($country, $q);

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($asDownload) {
        header('Content-Disposition: attachment; filename="' . $base . '.txt"');
    }

    $pdo = db();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
    } catch (Throwable $e) {
        // ignore
    }

    $sql = 'SELECT domain FROM prospect_sites WHERE country=?';
    $params = [$country];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (domain LIKE ? OR url LIKE ? OR niche LIKE ? OR notes LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if ($createdBy > 0) {
        $sql .= ' AND created_by = ?';
        $params[] = $createdBy;
    }
    $nf = prospect_sql_niche_filter('niche', $niche);
    if ($nf['sql'] !== '') {
        $sql .= ' AND ' . $nf['sql'];
        array_push($params, ...$nf['params']);
    }
    $sql .= ' ORDER BY domain ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $i = 0;
    while ($domain = $stmt->fetchColumn()) {
        echo (string) $domain, "\n";
        $i++;
        if ($i % 2000 === 0 && function_exists('flush')) {
            flush();
        }
    }
    $stmt->closeCursor();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    } catch (Throwable $e) {
        // ignore
    }
    exit;
}

/**
 * Stream CSV (domain column only) for one country folder (optionally filtered by $q).
 */
function stream_prospect_domains_csv(string $country, string $q = '', int $createdBy = 0, string $niche = ''): void
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $canon = resolve_canonical_country($country);
    if ($canon === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Country not found.\n";
        exit;
    }
    $country = $canon['name'];
    $q = trim($q);
    $createdBy = max(0, $createdBy);
    $base = prospect_export_basename($country, $q);

    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');

    $pdo = db();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
    } catch (Throwable $e) {
        // ignore
    }

    $sql = 'SELECT domain FROM prospect_sites WHERE country=?';
    $params = [$country];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (domain LIKE ? OR url LIKE ? OR niche LIKE ? OR notes LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if ($createdBy > 0) {
        $sql .= ' AND created_by = ?';
        $params[] = $createdBy;
    }
    $nf = prospect_sql_niche_filter('niche', $niche);
    if ($nf['sql'] !== '') {
        $sql .= ' AND ' . $nf['sql'];
        array_push($params, ...$nf['params']);
    }
    $sql .= ' ORDER BY domain ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // UTF-8 BOM helps Excel open the file correctly.
    echo "\xEF\xBB\xBF";
    echo "domain\n";
    $i = 0;
    while ($domain = $stmt->fetchColumn()) {
        $d = (string) $domain;
        if (str_contains($d, '"') || str_contains($d, ',') || str_contains($d, "\n")) {
            echo '"' . str_replace('"', '""', $d) . "\"\n";
        } else {
            echo $d, "\n";
        }
        $i++;
        if ($i % 2000 === 0 && function_exists('flush')) {
            flush();
        }
    }
    $stmt->closeCursor();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    } catch (Throwable $e) {
        // ignore
    }
    exit;
}

/**
 * Get or create a dated batch for a user (one row per user per calendar day per country).
 */
function get_or_create_prospect_batch(
    int $userId,
    string $country,
    string $language,
    string $region,
    string $niche,
    string $notes,
    ?string $batchDate = null
): int {
    ensure_prospect_schema();
    $date = $batchDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDate) ? $batchDate : date('Y-m-d');
    $country = trim($country);
    $niche = prospect_format_niches(prospect_parse_niches($niche));
    $stmt = db()->prepare(
        'SELECT id FROM prospect_batches WHERE user_id=? AND batch_date=? AND country=? LIMIT 1'
    );
    $stmt->execute([$userId, $date, $country]);
    $id = (int) $stmt->fetchColumn();
    if ($id) {
        return $id;
    }
    db()->prepare(
        'INSERT INTO prospect_batches (user_id, batch_date, site_count, country, language, region, niche, notes)
         VALUES (?,?,0,?,?,?,?,?)'
    )->execute([$userId, $date, $country, $language, $region, $niche, $notes]);
    return (int) db()->lastInsertId();
}

/**
 * Insert unique domains into Our database + Extracting Sites list.
 * Country TLDs are routed to their destination folders (same as Push);
 * each destination is de-duplicated against that country’s Our database.
 *
 * @return array{
 *   inserted:int,
 *   skipped:int,
 *   batch_id:int|null,
 *   extract_batch_id:int|null,
 *   by_country:array<string, array{inserted:int,skipped:int,extract_batch_id:int|null}>
 * }
 */
function add_prospect_domains(
    array $domains,
    array $user,
    string $country = '',
    string $language = '',
    string $region = '',
    string $niche = '',
    string $notes = ''
): array {
    ensure_prospect_schema();
    @set_time_limit(0);
    try {
        merge_orphan_prospect_countries();
    } catch (Throwable $e) {
        // ignore
    }

    $canon = require_canonical_country($country);
    $selectedCountry = $canon['name'];
    if ($region === '') {
        $region = $canon['region'];
    }
    if ($language === '') {
        $language = $canon['language'];
    }
    if (function_exists('normalize_site_language')) {
        $language = normalize_site_language($language, $selectedCountry);
    }
    $niche = prospect_format_niches(prospect_parse_niches($niche));

    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    $routed = filter_domains_routed_against_prospects($domains, $selectedCountry);
    $byCountryUnique = $routed['by_country'] ?? [];

    $empty = [
        'inserted' => 0,
        'skipped' => count($routed['existing'] ?? []),
        'duplicated' => count($routed['existing'] ?? []),
        'batch_id' => null,
        'extract_batch_id' => null,
        'by_country' => [],
    ];
    $hasAnyNew = false;
    foreach ($byCountryUnique as $bucket) {
        if (!empty($bucket['new'])) {
            $hasAnyNew = true;
            break;
        }
    }
    if (!$hasAnyNew) {
        return $empty;
    }

    if (!function_exists('add_domains_to_extract_sites')) {
        require_once __DIR__ . '/extracting.php';
    }

    $ins = db()->prepare(
        'INSERT INTO prospect_sites (domain, country, language, region, niche, notes, status, created_by)
         VALUES (?,?,?,?,?,?,\'new\',?)'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );

    $totalInserted = 0;
    $totalSkipped = count($routed['existing'] ?? []);
    $byCountryOut = [];
    $primaryBatchId = null;
    $primaryExtractId = null;
    $selectedExtractId = null;
    $selectedBatchId = null;

    foreach ($byCountryUnique as $destName => $bucket) {
        $toAdd = array_values($bucket['new'] ?? []);
        $destSkipped = count($bucket['existing'] ?? []);
        if ($toAdd === []) {
            if ($destSkipped > 0) {
                $byCountryOut[$destName] = [
                    'inserted' => 0,
                    'skipped' => $destSkipped,
                    'extract_batch_id' => null,
                ];
            }
            continue;
        }

        $destCanon = resolve_canonical_country((string) $destName) ?? $canon;
        $destCountry = $destCanon['name'];
        $destRegion = $destCountry === $selectedCountry
            ? $region
            : (string) ($destCanon['region'] ?? '');
        $destLanguage = $destCountry === $selectedCountry
            ? $language
            : (string) ($destCanon['language'] ?? '');
        if (function_exists('normalize_site_language')) {
            $destLanguage = normalize_site_language($destLanguage, $destCountry);
        }

        $batchId = get_or_create_prospect_batch(
            (int) $user['id'],
            $destCountry,
            $destLanguage,
            $destRegion,
            $niche,
            $notes
        );

        $inserted = 0;
        /** @var list<array{domain:string,prospect_site_id:int|null}> $insertedRows */
        $insertedRows = [];
        db()->beginTransaction();
        try {
            $n = 0;
            foreach ($toAdd as $d) {
                try {
                    $siteNiche = prospect_niches_for_new_site($d, $niche);
                    $ins->execute([
                        $d,
                        $destCountry,
                        $destLanguage,
                        $destRegion,
                        $siteNiche,
                        $notes,
                        $user['id'],
                    ]);
                    $siteId = (int) db()->lastInsertId();
                    $insItem->execute([$batchId, $d, $siteId ?: null]);
                    $inserted++;
                    $insertedRows[] = ['domain' => $d, 'prospect_site_id' => $siteId ?: null];
                } catch (PDOException $e) {
                    $destSkipped++;
                    $totalSkipped++;
                }
                $n++;
                if ($n % 250 === 0) {
                    db()->commit();
                    db()->beginTransaction();
                }
            }
            $cnt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
            $cnt->execute([$batchId]);
            $siteCount = (int) $cnt->fetchColumn();
            db()->prepare(
                'UPDATE prospect_batches SET site_count=?, country=?, language=?, region=?, niche=?, notes=?, updated_at=NOW() WHERE id=?'
            )->execute([$siteCount, $destCountry, $destLanguage, $destRegion, $niche, $notes, $batchId]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $e;
        }

        $extractBatchId = null;
        if ($insertedRows) {
            try {
                $extract = add_domains_to_extract_sites(
                    $insertedRows,
                    $user,
                    $destCountry,
                    $destLanguage,
                    $destRegion
                );
                $extractBatchId = !empty($extract['batch_id']) ? (int) $extract['batch_id'] : null;
            } catch (Throwable $e) {
                $extractBatchId = null;
            }
        }

        if ($inserted > 0 && function_exists('mark_admin_new_data')) {
            mark_admin_new_data('our_database', $inserted, $destCountry);
        }

        $totalInserted += $inserted;
        $byCountryOut[$destCountry] = [
            'inserted' => $inserted,
            'skipped' => $destSkipped,
            'extract_batch_id' => $extractBatchId,
        ];

        if ($primaryBatchId === null && $inserted > 0) {
            $primaryBatchId = $batchId;
        }
        if ($primaryExtractId === null && $extractBatchId) {
            $primaryExtractId = $extractBatchId;
        }
        if ($destCountry === $selectedCountry) {
            if ($inserted > 0) {
                $selectedBatchId = $batchId;
            }
            if ($extractBatchId) {
                $selectedExtractId = $extractBatchId;
            }
        }
    }

    $purged = 0;
    foreach (array_keys($byCountryOut) as $destName) {
        try {
            $purged += purge_duplicate_prospect_site_rows((string) $destName);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'inserted' => $totalInserted,
        'skipped' => $totalSkipped,
        'duplicated' => $totalSkipped + $purged,
        'batch_id' => $selectedBatchId ?? $primaryBatchId,
        'extract_batch_id' => $selectedExtractId ?? $primaryExtractId,
        'by_country' => $byCountryOut,
    ];
}

/**
 * Admin: paste URLs into one country’s database (no uniqueness preview).
 * Duplicates in the paste and domains already in that country are dropped (not updated).
 *
 * @return array{inserted:int,updated:int,duplicated:int,purged:int,total:int,batch_id:int|null,country:string}
 */
function admin_add_urls_to_database(string $raw, array $user, string $country, string $language = ''): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    try {
        merge_orphan_prospect_countries();
    } catch (Throwable $e) {
        // ignore
    }

    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $region = $canon['region'];
    if ($language === '') {
        $language = $canon['language'];
    }
    if (function_exists('normalize_site_language')) {
        $language = normalize_site_language($language, $country);
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $parsed = parse_domain_list_strict($raw);
    if ($parsed['invalid_count'] > 0) {
        throw new InvalidArgumentException(
            'Remove invalid lines first (use Clean to root domains). Paste root domains only, e.g. example.com or my-site.co.uk — no https, paths, or subdomains.'
        );
    }
    $listDuplicates = (int) ($parsed['duplicate_count'] ?? 0);
    /** @var list<string> $domains */
    $domains = $parsed['valid'];

    if ($domains === []) {
        return [
            'inserted' => 0,
            'updated' => 0,
            'duplicated' => $listDuplicates,
            'purged' => 0,
            'total' => 0,
            'batch_id' => null,
            'country' => $country,
        ];
    }

    $batchId = get_or_create_prospect_batch(
        (int) $user['id'],
        $country,
        $language,
        $region,
        '',
        'Admin Add sites · ' . $country
    );
    $ins = db()->prepare(
        'INSERT INTO prospect_sites (domain, url, country, language, region, niche, notes, status, created_by)
         VALUES (?,?,?,?,?,?,\'\',\'new\',?)'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $findId = db()->prepare(
        'SELECT id FROM prospect_sites WHERE country=? AND domain=? LIMIT 1'
    );

    $inserted = 0;
    $alreadyInDb = 0;
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($domains as $domain) {
            $findId->execute([$country, $domain]);
            $beforeId = (int) $findId->fetchColumn();
            if ($beforeId > 0) {
                $alreadyInDb++;
                // Duplicate of an existing country folder row — do not keep a second copy.
                $n++;
                continue;
            }
            try {
                $siteNiche = prospect_niches_for_new_site($domain, '');
                $ins->execute([$domain, '', $country, $language, $region, $siteNiche, $user['id']]);
            } catch (Throwable $e) {
                // Race / unique key — treat as duplicate.
                $alreadyInDb++;
                $n++;
                continue;
            }
            $siteId = (int) db()->lastInsertId();
            if ($siteId <= 0) {
                $findId->execute([$country, $domain]);
                $siteId = (int) $findId->fetchColumn();
            }
            if ($siteId > 0) {
                $inserted++;
                $insItem->execute([$batchId, $domain, $siteId]);
            } else {
                $alreadyInDb++;
            }
            $n++;
            if ($n % 250 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        $cnt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
        $cnt->execute([$batchId]);
        db()->prepare(
            'UPDATE prospect_batches SET site_count=?, country=?, language=?, region=?, notes=?, updated_at=NOW() WHERE id=?'
        )->execute([
            (int) $cnt->fetchColumn(),
            $country,
            $language,
            $region,
            'Admin Add sites · ' . $country,
            $batchId,
        ]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    $purged = 0;
    try {
        $purged = purge_duplicate_prospect_site_rows($country);
    } catch (Throwable $e) {
        // ignore
    }

    $duplicated = $listDuplicates + $alreadyInDb + $purged;

    return [
        'inserted' => $inserted,
        'updated' => 0, // legacy key — duplicates are deleted, not updated
        'duplicated' => $duplicated,
        'purged' => $purged,
        'total' => count($domains),
        'batch_id' => $batchId,
        'country' => $country,
    ];
}

/**
 * Remove sites from Our database for one country using a pasted/CSV domain list.
 *
 * @return array{removed:int,not_found:int,invalid:int,country:string}
 */
function remove_prospect_sites_by_list(string $country, string $raw): array
{
    ensure_prospect_schema();
    @set_time_limit(0);
    $canon = require_canonical_country($country);
    $country = $canon['name'];
    $parsed = parse_domain_list_strict($raw);
    $domains = $parsed['valid'];
    if ($domains === []) {
        return [
            'removed' => 0,
            'not_found' => 0,
            'invalid' => (int) $parsed['invalid_count'],
            'country' => $country,
        ];
    }

    $removed = 0;
    $notFound = 0;
    $chunkSize = 400;
    for ($i = 0, $n = count($domains); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($domains, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$country], $chunk);
        $sel = db()->prepare(
            "SELECT domain FROM prospect_sites WHERE country=? AND domain IN ({$placeholders})"
        );
        $sel->execute($params);
        $found = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $foundSet = array_fill_keys($found, true);
        foreach ($chunk as $d) {
            if (!isset($foundSet[$d])) {
                $notFound++;
            }
        }
        if ($found === []) {
            continue;
        }
        $delPlaceholders = implode(',', array_fill(0, count($found), '?'));
        $del = db()->prepare(
            "DELETE FROM prospect_sites WHERE country=? AND domain IN ({$delPlaceholders})"
        );
        $del->execute(array_merge([$country], $found));
        $removed += $del->rowCount();
    }

    return [
        'removed' => $removed,
        'not_found' => $notFound,
        'invalid' => (int) $parsed['invalid_count'],
        'country' => $country,
    ];
}

function list_prospect_batches(?int $userId = null, int $limit = 60, string $roleFilter = '', int $offset = 0): array
{
    ensure_prospect_schema();
    $sql = "SELECT b.*, u.username, u.full_name, u.role
            FROM prospect_batches b
            JOIN users u ON u.id = b.user_id";
    $where = [];
    $params = [];
    if ($userId) {
        $where[] = 'b.user_id = ?';
        $params[] = $userId;
    }
    if ($roleFilter === 'team' || $roleFilter === 'admin') {
        $where[] = 'u.role = ?';
        $params[] = $roleFilter;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $limit = max(1, min(500, (int) $limit));
    $offset = max(0, (int) $offset);
    $sql .= ' ORDER BY b.batch_date DESC, b.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function count_prospect_batches(?int $userId = null, string $roleFilter = ''): int
{
    ensure_prospect_schema();
    $sql = 'SELECT COUNT(*) FROM prospect_batches b JOIN users u ON u.id = b.user_id';
    $where = [];
    $params = [];
    if ($userId) {
        $where[] = 'b.user_id = ?';
        $params[] = $userId;
    }
    if ($roleFilter === 'team' || $roleFilter === 'admin') {
        $where[] = 'u.role = ?';
        $params[] = $roleFilter;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Country folders that contain sites added by one person (Our database person filter).
 *
 * @return list<array{country:string,total:int,is_empty:bool}>
 */
function list_prospect_countries_for_creator(int $userId): array
{
    ensure_prospect_schema();
    if ($userId < 1) {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT TRIM(p.country) AS country, COUNT(*) AS total
         FROM prospect_sites p
         WHERE p.created_by = ?
         GROUP BY TRIM(p.country)
         ORDER BY total DESC, country ASC"
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $name = trim((string) ($row['country'] ?? ''));
        $out[] = [
            'country' => $name,
            'total' => (int) ($row['total'] ?? 0),
            'is_empty' => $name === '',
        ];
    }
    return $out;
}

/**
 * Per-user totals for admin Site adding history (sites + days with adds).
 *
 * @return list<array{user_id:int,username:string,full_name:string,role:string,batch_days:int,site_count:int,last_batch_date:?string}>
 */
function prospect_add_history_by_user(?int $userId = null, string $roleFilter = 'team'): array
{
    ensure_prospect_schema();
    $sql = "SELECT u.id AS user_id, u.username, u.full_name, u.role,
                   COUNT(b.id) AS batch_days,
                   COALESCE(SUM(b.site_count), 0) AS site_count,
                   MAX(b.batch_date) AS last_batch_date
            FROM users u
            LEFT JOIN prospect_batches b ON b.user_id = u.id";
    $where = [];
    $params = [];
    if ($roleFilter === 'team' || $roleFilter === 'admin') {
        $where[] = 'u.role = ?';
        $params[] = $roleFilter;
    } else {
        $where[] = "u.role IN ('team','admin')";
    }
    if ($userId) {
        $where[] = 'u.id = ?';
        $params[] = $userId;
    }
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY u.id, u.username, u.full_name, u.role
              HAVING batch_days > 0 OR u.role = \'team\'
              ORDER BY site_count DESC, u.full_name, u.username';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function list_team_users(bool $activeOnly = true): array
{
    $sql = "SELECT id, username, full_name, email, role, is_active FROM users WHERE role='team'";
    if ($activeOnly) {
        $sql .= ' AND is_active=1';
    }
    $sql .= ' ORDER BY full_name, username';
    return db()->query($sql)->fetchAll();
}

/**
 * Backfill dated site adding history from inventory rows that never landed in a batch
 * (e.g. older single-add form saves). Idempotent.
 *
 * @return int number of domains attached to history
 */
function sync_missing_prospect_batch_history(int $limit = 5000): int
{
    ensure_prospect_schema();
    $stmt = db()->query(
        'SELECT p.id, p.domain, p.created_by, DATE(p.created_at) AS batch_date,
                p.country, p.language, p.region, p.niche, p.notes
         FROM prospect_sites p
         LEFT JOIN prospect_batch_items i ON i.prospect_site_id = p.id
         WHERE p.created_by IS NOT NULL AND i.id IS NULL
         ORDER BY p.created_by, batch_date, p.id
         LIMIT ' . (int) $limit
    );
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id, created_at)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $countStmt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
    $updateBatch = db()->prepare(
        'UPDATE prospect_batches SET site_count=?, updated_at=NOW() WHERE id=?'
    );
    $touched = [];
    $added = 0;

    foreach ($rows as $row) {
        $uid = (int) $row['created_by'];
        $date = (string) $row['batch_date'];
        if ($uid <= 0 || $date === '') {
            continue;
        }
        $batchId = get_or_create_prospect_batch(
            $uid,
            (string) ($row['country'] ?? ''),
            (string) ($row['language'] ?? ''),
            (string) ($row['region'] ?? ''),
            (string) ($row['niche'] ?? ''),
            (string) ($row['notes'] ?? ''),
            $date
        );
        try {
            $insItem->execute([
                $batchId,
                $row['domain'],
                (int) $row['id'],
                $date . ' 12:00:00',
            ]);
            if ($insItem->rowCount() > 0) {
                $added++;
            }
            $touched[$batchId] = true;
        } catch (PDOException $e) {
            // ignore duplicates / race
        }
    }

    foreach (array_keys($touched) as $batchId) {
        $countStmt->execute([$batchId]);
        $updateBatch->execute([(int) $countStmt->fetchColumn(), $batchId]);
    }

    return $added;
}

function get_prospect_batch(int $batchId): ?array
{
    ensure_prospect_schema();
    $stmt = db()->prepare(
        "SELECT b.*, u.username, u.full_name, u.role
         FROM prospect_batches b JOIN users u ON u.id=b.user_id WHERE b.id=?"
    );
    $stmt->execute([$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_prospect_batch_domains(int $batchId, int $limit = 50000): array
{
    ensure_prospect_schema();
    $stmt = db()->prepare(
        'SELECT domain FROM prospect_batch_items WHERE batch_id=? ORDER BY domain LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * @return list<array{domain:string,created_at:string,prospect_site_id:?int}>
 */
function get_prospect_batch_items(int $batchId, int $limit = 50000): array
{
    ensure_prospect_schema();
    $stmt = db()->prepare(
        'SELECT domain, created_at, prospect_site_id
         FROM prospect_batch_items WHERE batch_id=?
         ORDER BY created_at ASC, domain ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll();
}

function prospect_inventory_query(array $filters, int $pageNum = 1, int $per = 1000): array
{
    ensure_prospect_schema();
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.domain LIKE ? OR p.url LIKE ? OR p.niche LIKE ? OR p.notes LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($filters['country'])) {
        $where[] = 'p.country = ?';
        $params[] = $filters['country'];
    }
    if (!empty($filters['language'])) {
        $where[] = 'p.language = ?';
        $params[] = $filters['language'];
    }
    if (!empty($filters['region'])) {
        $where[] = 'p.region = ?';
        $params[] = $filters['region'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['created_by'])) {
        $where[] = 'p.created_by = ?';
        $params[] = (int) $filters['created_by'];
    }
    $nf = prospect_sql_niche_filter('p.niche', (string) ($filters['niche'] ?? ''));
    if ($nf['sql'] !== '') {
        $where[] = $nf['sql'];
        array_push($params, ...$nf['params']);
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $per = max(1, min(1000, $per));
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT p.*, u.username added_by_name, u.full_name added_by_full
         FROM prospect_sites p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE $whereSql ORDER BY p.created_at DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

function distinct_prospect_languages(): array
{
    ensure_prospect_schema();
    $rows = db()->query(
        "SELECT DISTINCT language FROM prospect_sites WHERE language <> '' ORDER BY language"
    )->fetchAll();
    return array_column($rows, 'language');
}

function distinct_prospect_countries(): array
{
    ensure_prospect_schema();
    $rows = db()->query(
        "SELECT DISTINCT country FROM prospect_sites WHERE country <> '' ORDER BY country"
    )->fetchAll();
    return array_column($rows, 'country');
}

/**
 * Super search: find a site across every country folder in Our database.
 *
 * @return list<array<string,mixed>>
 */
function search_prospect_sites_global(string $q, int $limit = 200): array
{
    ensure_prospect_schema();
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $limit = max(1, min(500, $limit));

    // Prefer exact / root-domain matches first, then partial.
    $host = function_exists('extract_host_candidate') ? extract_host_candidate($q) : $q;
    $root = function_exists('to_root_domain') ? to_root_domain($host) : $host;
    $like = '%' . $q . '%';
    $rootLike = $root !== '' ? '%' . $root . '%' : $like;

    $stmt = db()->prepare(
        "SELECT p.*, u.username AS added_by_name, u.full_name AS added_by_full
         FROM prospect_sites p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.domain LIKE ? OR p.url LIKE ? OR p.niche LIKE ? OR IFNULL(p.notes,'') LIKE ?
            OR p.domain LIKE ? OR p.url LIKE ? OR p.niche LIKE ? OR IFNULL(p.notes,'') LIKE ?
         ORDER BY
           CASE
             WHEN p.domain = ? THEN 0
             WHEN p.domain = ? THEN 1
             WHEN p.domain LIKE ? THEN 2
             WHEN p.niche LIKE ? THEN 3
             ELSE 4
           END,
           p.country ASC,
           p.domain ASC
         LIMIT {$limit}"
    );
    $exact = $root !== '' ? $root : $q;
    $stmt->execute([
        $like,
        $like,
        $like,
        $like,
        $rootLike,
        $rootLike,
        $rootLike,
        $rootLike,
        $exact,
        $q,
        $exact . '%',
        $like,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Update metadata on a site-adding history day (does not change domains).
 *
 * @return array{ok:bool,error?:string}
 */
function update_prospect_batch_meta(
    int $batchId,
    string $country = '',
    string $language = '',
    string $region = '',
    string $niche = '',
    string $notes = ''
): array {
    ensure_prospect_schema();
    $batch = get_prospect_batch($batchId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Site adding history day not found.'];
    }
    $country = trim($country);
    $language = trim($language);
    $region = trim($region);
    $niche = prospect_format_niches(prospect_parse_niches($niche));
    $notes = trim($notes);
    if ($country !== '') {
        try {
            $canon = require_canonical_country($country);
            $country = $canon['name'];
            if ($region === '') {
                $region = $canon['region'];
            }
            if ($language === '') {
                $language = $canon['language'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    } else {
        $country = (string) ($batch['country'] ?? '');
    }
    if (function_exists('normalize_site_language') && $language !== '') {
        $language = normalize_site_language($language, $country);
    }
    db()->prepare(
        'UPDATE prospect_batches
         SET country=?, language=?, region=?, niche=?, notes=?, updated_at=NOW()
         WHERE id=?'
    )->execute([$country, $language, $region, $niche, $notes, $batchId]);
    return ['ok' => true];
}

/**
 * Replace the domain list for a history day. Optionally remove dropped domains from Our database.
 * New domains are linked to existing Our-database rows when present, otherwise inserted there.
 *
 * @return array{ok:bool,error?:string,total?:int,removed?:int,inserted?:int,db_removed?:int}
 */
function set_prospect_batch_domains_from_text(
    int $batchId,
    string $text,
    bool $alsoRemoveFromDb = false
): array {
    ensure_prospect_schema();
    $batch = get_prospect_batch($batchId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Site adding history day not found.'];
    }

    $rawLines = preg_split('/\R/u', $text) ?: [];
    $wanted = [];
    foreach ($rawLines as $line) {
        $d = normalize_domain((string) $line);
        if ($d !== '' && !isset($wanted[$d])) {
            $wanted[$d] = true;
        }
    }
    $wantedList = array_keys($wanted);

    $items = get_prospect_batch_items($batchId);
    /** @var array<string,array{domain:string,created_at:string,prospect_site_id:?int}> $current */
    $current = [];
    foreach ($items as $item) {
        $d = normalize_domain((string) ($item['domain'] ?? ''));
        if ($d !== '') {
            $current[$d] = $item;
        }
    }

    $toRemove = array_values(array_diff(array_keys($current), $wantedList));
    $toAdd = array_values(array_diff($wantedList, array_keys($current)));

    $removed = 0;
    $inserted = 0;
    $dbRemoved = 0;
    $country = (string) ($batch['country'] ?? '');
    $language = (string) ($batch['language'] ?? '');
    $region = (string) ($batch['region'] ?? '');
    $niche = (string) ($batch['niche'] ?? '');
    $notes = (string) ($batch['notes'] ?? '');
    $ownerId = (int) ($batch['user_id'] ?? 0);

    $delItem = db()->prepare('DELETE FROM prospect_batch_items WHERE batch_id=? AND domain=?');
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $findSite = db()->prepare(
        'SELECT id FROM prospect_sites WHERE country=? AND domain=? LIMIT 1'
    );
    $insSite = db()->prepare(
        'INSERT INTO prospect_sites (domain, country, language, region, niche, notes, status, created_by)
         VALUES (?,?,?,?,?,?,\'new\',?)'
    );

    db()->beginTransaction();
    try {
        foreach ($toRemove as $d) {
            $delItem->execute([$batchId, $d]);
            $removed++;
            if ($alsoRemoveFromDb) {
                $siteId = (int) ($current[$d]['prospect_site_id'] ?? 0);
                if ($siteId <= 0) {
                    $findSite->execute([$country, $d]);
                    $siteId = (int) ($findSite->fetchColumn() ?: 0);
                }
                if ($siteId > 0 && delete_prospect_site_by_id($siteId)) {
                    $dbRemoved++;
                }
            }
        }

        foreach ($toAdd as $d) {
            $siteId = null;
            if ($country !== '') {
                $findSite->execute([$country, $d]);
                $existingId = (int) ($findSite->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    $siteId = $existingId;
                } else {
                    try {
                        $insSite->execute([
                            $d,
                            $country,
                            $language,
                            $region,
                            prospect_niches_for_new_site($d, $niche),
                            $notes,
                            $ownerId > 0 ? $ownerId : null,
                        ]);
                        $siteId = (int) db()->lastInsertId() ?: null;
                    } catch (PDOException $e) {
                        $findSite->execute([$country, $d]);
                        $siteId = (int) ($findSite->fetchColumn() ?: 0) ?: null;
                    }
                }
            }
            $insItem->execute([$batchId, $d, $siteId]);
            $inserted++;
        }

        $cnt = db()->prepare('SELECT COUNT(*) FROM prospect_batch_items WHERE batch_id=?');
        $cnt->execute([$batchId]);
        $total = (int) $cnt->fetchColumn();
        db()->prepare(
            'UPDATE prospect_batches SET site_count=?, updated_at=NOW() WHERE id=?'
        )->execute([$total, $batchId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (($inserted > 0 || $dbRemoved > 0) && function_exists('mark_admin_new_data')) {
        try {
            mark_admin_new_data('our_database');
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'ok' => true,
        'total' => $total,
        'removed' => $removed,
        'inserted' => $inserted,
        'db_removed' => $dbRemoved,
    ];
}

/**
 * Delete a history day. By default leaves Our database rows intact (policy A).
 * When $alsoRemoveFromDb is true, deletes linked prospect_sites for that day's domains.
 *
 * @return array{ok:bool,error?:string,db_removed?:int}
 */
function delete_prospect_batch(int $batchId, bool $alsoRemoveFromDb = false): array
{
    ensure_prospect_schema();
    $batch = get_prospect_batch($batchId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Site adding history day not found.'];
    }
    $dbRemoved = 0;
    db()->beginTransaction();
    try {
        if ($alsoRemoveFromDb) {
            $items = get_prospect_batch_items($batchId);
            $country = (string) ($batch['country'] ?? '');
            $findSite = db()->prepare(
                'SELECT id FROM prospect_sites WHERE country=? AND domain=? LIMIT 1'
            );
            foreach ($items as $item) {
                $siteId = (int) ($item['prospect_site_id'] ?? 0);
                $domain = normalize_domain((string) ($item['domain'] ?? ''));
                if ($siteId <= 0 && $country !== '' && $domain !== '') {
                    $findSite->execute([$country, $domain]);
                    $siteId = (int) ($findSite->fetchColumn() ?: 0);
                }
                if ($siteId > 0 && delete_prospect_site_by_id($siteId)) {
                    $dbRemoved++;
                }
            }
        }
        db()->prepare('DELETE FROM prospect_batches WHERE id=?')->execute([$batchId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'db_removed' => $dbRemoved];
}

function delete_prospect_site_by_id(int $id): ?array
{
    ensure_prospect_schema();
    $stmt = db()->prepare('SELECT * FROM prospect_sites WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    db()->prepare('DELETE FROM prospect_sites WHERE id=?')->execute([$id]);
    return $row;
}

/**
 * @param list<int> $ids
 * @return array{ok:bool,error?:string,removed:list<array{id:int,domain:string}>,count:int}
 */
function delete_prospect_sites_by_ids(string $country, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
    $snaps = [];
    $removed = [];
    foreach ($ids as $id) {
        $sel = db()->prepare('SELECT * FROM prospect_sites WHERE id=? LIMIT 1');
        $sel->execute([$id]);
        $snap = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$snap || (string) ($snap['country'] ?? '') !== $country) {
            continue;
        }
        $snaps[] = $snap;
        if (delete_prospect_site_by_id($id)) {
            $removed[] = ['id' => $id, 'domain' => (string) ($snap['domain'] ?? '')];
        }
    }
    if ($snaps !== [] && function_exists('sheet_history_push_remove')) {
        sheet_history_push_remove('prospect', $country, $snaps);
    }
    if ($removed === []) {
        return ['ok' => false, 'error' => 'No matching sites to remove.', 'removed' => [], 'count' => 0];
    }
    return ['ok' => true, 'removed' => $removed, 'count' => count($removed)];
}

/**
 * @param array<string,mixed> $snap
 * @return array{ok:bool,id?:int,already?:bool,error?:string}
 */
function restore_prospect_site_snapshot(array $snap): array
{
    ensure_prospect_schema();
    $country = (string) ($snap['country'] ?? '');
    $domain = (string) ($snap['domain'] ?? '');
    if ($domain === '') {
        return ['ok' => false, 'error' => 'Invalid site.'];
    }
    $dup = db()->prepare('SELECT id FROM prospect_sites WHERE country=? AND domain=? LIMIT 1');
    $dup->execute([$country, $domain]);
    $existingId = (int) $dup->fetchColumn();
    if ($existingId > 0) {
        return ['ok' => true, 'id' => $existingId, 'already' => true];
    }
    $wantId = (int) ($snap['id'] ?? 0);
    $url = (string) ($snap['url'] ?? '');
    $language = (string) ($snap['language'] ?? '');
    $region = (string) ($snap['region'] ?? '');
    $niche = (string) ($snap['niche'] ?? '');
    $notes = $snap['notes'] ?? null;
    $status = (string) ($snap['status'] ?? 'new');
    if (!in_array($status, ['new', 'contacting', 'replied', 'skipped'], true)) {
        $status = 'new';
    }
    $createdBy = $snap['created_by'] ?? null;
    $createdBy = $createdBy !== null && $createdBy !== '' ? (int) $createdBy : null;
    $created = trim((string) ($snap['created_at'] ?? ''));
    $created = $created !== '' ? $created : null;
    $cols = 'domain, url, country, language, region, niche, notes, status, created_by, created_at';
    $params = [$domain, $url, $country, $language, $region, $niche, $notes, $status, $createdBy, $created];
    $ph = '?,?,?,?,?,?,?,?,?,?';
    try {
        if ($wantId > 0) {
            $chk = db()->prepare('SELECT id FROM prospect_sites WHERE id=? LIMIT 1');
            $chk->execute([$wantId]);
            if (!(int) $chk->fetchColumn()) {
                db()->prepare("INSERT INTO prospect_sites (id, {$cols}) VALUES (?, {$ph})")->execute(array_merge([$wantId], $params));
                return ['ok' => true, 'id' => $wantId];
            }
        }
        db()->prepare("INSERT INTO prospect_sites ({$cols}) VALUES ({$ph})")->execute($params);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (PDOException $e) {
        $dup->execute([$country, $domain]);
        $existingId = (int) $dup->fetchColumn();
        if ($existingId > 0) {
            return ['ok' => true, 'id' => $existingId, 'already' => true];
        }
        return ['ok' => false, 'error' => 'Could not restore site.'];
    }
}

function list_admin_users(bool $activeOnly = true): array
{
    $sql = "SELECT * FROM users WHERE role='admin'";
    if ($activeOnly) {
        $sql .= ' AND is_active=1';
    }
    $sql .= ' ORDER BY full_name, username';
    return db()->query($sql)->fetchAll();
}

