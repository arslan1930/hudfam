<?php

/**
 * Our database helpers — unique domains (no prices).
 * Team Filter & add checks uniqueness against prospect_sites.
 * Admin Add sites saves directly (no uniqueness preview).
 */

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

function domain_public_suffix(string $host): string
{
    $host = strtolower(trim($host));
    $parts = array_values(array_filter(explode('.', $host), static fn ($p) => $p !== ''));
    $n = count($parts);
    if ($n < 2) {
        return '';
    }
    $two = $parts[$n - 2] . '.' . $parts[$n - 1];
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
    $s = preg_replace('/^[\s\'"\[<\(]+/', '', $s) ?? $s;
    $s = preg_replace('/[\s\'"\]>\)]+$/', '', $s) ?? $s;

    // Prefer parse_url for full https://…/path?#… pastes (Filter & add Clean errors).
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
                $valid[$a['domain']] = true;
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
          niche VARCHAR(255) NOT NULL DEFAULT '',
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
          niche VARCHAR(255) NOT NULL DEFAULT '',
          notes TEXT,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_user_batch_date (user_id, batch_date),
          INDEX (batch_date),
          INDEX (user_id),
          CONSTRAINT fk_pbatch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
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
 * Get or create a dated batch for a user (one row per user per calendar day).
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
    $stmt = db()->prepare('SELECT id FROM prospect_batches WHERE user_id=? AND batch_date=? LIMIT 1');
    $stmt->execute([$userId, $date]);
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
 * Insert new prospect domains into old inventory + dated batch (both sides).
 *
 * @return array{inserted:int,skipped:int,batch_id:int|null,extract_batch_id:int|null}
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
    $country = $canon['name'];
    if ($region === '') {
        $region = $canon['region'];
    }
    if ($language === '') {
        $language = $canon['language'];
    }
    if (function_exists('normalize_site_language')) {
        $language = normalize_site_language($language, $country);
    }

    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    // Team/admin shared insert path: never insert domains already in this country folder.
    $check = filter_domains_against_prospects($domains, $country);
    $toAdd = $check['new'];
    $skipped = count($check['existing']);
    if (!$toAdd) {
        return ['inserted' => 0, 'skipped' => $skipped, 'batch_id' => null, 'extract_batch_id' => null];
    }
    // Defensive: ignore any domain that somehow remained in $domains but is not "new".
    $toAdd = array_values(array_intersect($toAdd, $domains));
    if (!$toAdd) {
        return ['inserted' => 0, 'skipped' => $skipped, 'batch_id' => null, 'extract_batch_id' => null];
    }

    $batchId = get_or_create_prospect_batch(
        (int) $user['id'],
        $country,
        $language,
        $region,
        $niche,
        $notes
    );

    $ins = db()->prepare(
        'INSERT INTO prospect_sites (domain, country, language, region, niche, notes, status, created_by)
         VALUES (?,?,?,?,?,?,\'new\',?)'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $inserted = 0;
    /** @var list<array{domain:string,prospect_site_id:int|null}> $insertedRows */
    $insertedRows = [];
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($toAdd as $d) {
            try {
                $ins->execute([$d, $country, $language, $region, $niche, $notes, $user['id']]);
                $siteId = (int) db()->lastInsertId();
                $insItem->execute([$batchId, $d, $siteId ?: null]);
                $inserted++;
                $insertedRows[] = ['domain' => $d, 'prospect_site_id' => $siteId ?: null];
            } catch (PDOException $e) {
                $skipped++;
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
        )->execute([$siteCount, $country, $language, $region, $niche, $notes, $batchId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    // Path 2: also copy new sites into Extracting sites → Sites list (per country batch).
    $extractBatchId = null;
    if ($insertedRows) {
        if (!function_exists('add_domains_to_extract_sites')) {
            require_once __DIR__ . '/extracting.php';
        }
        try {
            $extract = add_domains_to_extract_sites($insertedRows, $user, $country, $language, $region);
            $extractBatchId = !empty($extract['batch_id']) ? (int) $extract['batch_id'] : null;
        } catch (Throwable $e) {
            // Inventory insert already succeeded — do not fail the whole add.
            $extractBatchId = null;
        }
    }

    if ($inserted > 0 && function_exists('mark_admin_new_data')) {
        mark_admin_new_data('our_database', $inserted, $country);
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'batch_id' => $batchId,
        'extract_batch_id' => $extractBatchId,
    ];
}

/**
 * Admin: paste URLs into one country’s database (no uniqueness preview).
 *
 * @return array{inserted:int,updated:int,total:int,batch_id:int|null,country:string}
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
            'Remove invalid lines first (use Clean errors). Paste root domains only, e.g. example.com or my-site.co.uk — no https, paths, or subdomains.'
        );
    }
    /** @var array<string,string> $rows domain => url (empty for root-domain paste) */
    $rows = [];
    foreach ($parsed['valid'] as $domain) {
        $rows[$domain] = '';
    }

    if ($rows === []) {
        return ['inserted' => 0, 'updated' => 0, 'total' => 0, 'batch_id' => null, 'country' => $country];
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
         VALUES (?,?,?,?,?,\'\',\'\',\'new\',?)
         ON DUPLICATE KEY UPDATE
           url = IF(VALUES(url) <> \'\', VALUES(url), url),
           language = IF(VALUES(language) <> \'\', VALUES(language), language),
           region = IF(VALUES(region) <> \'\', VALUES(region), region),
           updated_at = NOW()'
    );
    $insItem = db()->prepare(
        'INSERT INTO prospect_batch_items (batch_id, domain, prospect_site_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE prospect_site_id=VALUES(prospect_site_id)'
    );
    $findId = db()->prepare(
        'SELECT id FROM prospect_sites WHERE TRIM(country)=? AND domain=? LIMIT 1'
    );

    $inserted = 0;
    $updated = 0;
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($rows as $domain => $url) {
            $findId->execute([$country, $domain]);
            $beforeId = (int) $findId->fetchColumn();
            $ins->execute([$domain, $url, $country, $language, $region, $user['id']]);
            if ($beforeId > 0) {
                $updated++;
                $siteId = $beforeId;
            } else {
                $inserted++;
                $siteId = (int) db()->lastInsertId();
                if ($siteId <= 0) {
                    $findId->execute([$country, $domain]);
                    $siteId = (int) $findId->fetchColumn();
                }
            }
            $insItem->execute([$batchId, $domain, $siteId ?: null]);
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

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'total' => count($rows),
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

function list_prospect_batches(?int $userId = null, int $limit = 60, string $roleFilter = ''): array
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
    $sql .= ' ORDER BY b.batch_date DESC, b.id DESC LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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
        $where[] = '(p.domain LIKE ? OR p.niche LIKE ? OR p.notes LIKE ?)';
        array_push($params, $like, $like, $like);
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
         WHERE p.domain LIKE ? OR p.url LIKE ? OR p.domain LIKE ? OR p.url LIKE ?
         ORDER BY
           CASE
             WHEN p.domain = ? THEN 0
             WHEN p.domain = ? THEN 1
             WHEN p.domain LIKE ? THEN 2
             ELSE 3
           END,
           p.country ASC,
           p.domain ASC
         LIMIT {$limit}"
    );
    $exact = $root !== '' ? $root : $q;
    $stmt->execute([
        $like,
        $like,
        $rootLike,
        $rootLike,
        $exact,
        $q,
        $exact . '%',
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
    $niche = trim($niche);
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
                            $niche,
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

function list_admin_users(bool $activeOnly = true): array
{
    $sql = "SELECT * FROM users WHERE role='admin'";
    if ($activeOnly) {
        $sql .= ' AND is_active=1';
    }
    $sql .= ' ORDER BY full_name, username';
    return db()->query($sql)->fetchAll();
}

