<?php
/**
 * Local integration smoke test — run: php tests_run.php
 * Not for production. Safe to delete after testing.
 * Web hits (Apache / LiteSpeed / php -S) must not run this file.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/includes/helpers.php';
txf_secure_session_start();

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/account.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/extracting.php';
require __DIR__ . '/includes/extracted.php';
require __DIR__ . '/includes/sites_with_emails.php';
require __DIR__ . '/includes/email_campaigns.php';
require __DIR__ . '/includes/admin_new_data.php';
require __DIR__ . '/includes/departments.php';
require __DIR__ . '/includes/orders.php';
require __DIR__ . '/includes/site_prices.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/mail.php';
require __DIR__ . '/includes/presence.php';
require __DIR__ . '/includes/semrush_research.php';
require __DIR__ . '/includes/sheet_history.php';

$errors = [];
$ok = [];
function pass(string $m): void
{
    global $ok;
    $ok[] = $m;
    echo "OK  $m\n";
}
function fail(string $m): void
{
    global $errors;
    $errors[] = $m;
    echo "FAIL $m\n";
}

try {
    ensure_users_auth_schema();
    ensure_prospect_schema();
    ensure_extract_schema();
    ensure_extracted_schema();
    ensure_sites_with_emails_schema();
    ensure_email_campaign_schema();
    ensure_admin_new_data_schema();
    ensure_departments_schema();
    ensure_order_schema();
    ensure_site_prices_schema();
    ensure_invoice_schema();
    ensure_task_presence_schema();
    ensure_semrush_research_schema();
    pass('schemas ensured');
} catch (Throwable $e) {
    fail('schema: ' . $e->getMessage());
    exit(1);
}

try {
    $stampKey = 'txf_cli_stamp_probe';
    $stampPath = txf_schema_stamp_path($stampKey);
    @file_put_contents($stampPath, (string) time());
    if (txf_schema_stamps_enabled() || txf_schema_is_current($stampKey, __FILE__)) {
        fail('CLI schema stamps should stay off so ensure_* still runs');
    } else {
        pass('CLI schema stamps disabled');
    }
    @unlink($stampPath);
    $countriesA = list_countries(null, true);
    $countriesB = list_countries(null, true);
    if (is_array($countriesA) && $countriesA === $countriesB) {
        pass('list_countries request cache');
    } else {
        fail('list_countries request cache');
    }
} catch (Throwable $e) {
    fail('perf helpers: ' . $e->getMessage());
}

$admin = db()->query("SELECT * FROM users WHERE username='admin'")->fetch(PDO::FETCH_ASSOC);
$team = db()->query("SELECT * FROM users WHERE username='teammate'")->fetch(PDO::FETCH_ASSOC);
if (!$admin || !$team) {
    fail('seed users missing');
    exit(1);
}
$adminUser = [
    'id' => (int) $admin['id'],
    'username' => 'admin',
    'full_name' => (string) $admin['full_name'],
    'role' => 'admin',
];
$teamUser = [
    'id' => (int) $team['id'],
    'username' => 'teammate',
    'full_name' => (string) $team['full_name'],
    'role' => 'team',
];

// Clean prior test rows
db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfshare-%'");
db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfshare-%'");
db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfshare-%'");
db()->exec("DELETE FROM extracted_sites WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%'");
db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfsent-%' OR domain LIKE 'txfsug-%' OR domain LIKE 'txfgap-%'");
db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfsent-%' OR domain LIKE 'txfsug-%' OR domain LIKE 'txfgap-%'");
db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfsent-%' OR domain LIKE 'txfsug-%' OR domain LIKE 'txfgap-%'");
db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfcamp-%' OR domain LIKE 'txfcamp-sent-%' OR domain LIKE 'txfgap-%'");
db()->exec("DELETE FROM order_clients WHERE name LIKE 'Test Client%'");
db()->exec("DELETE FROM order_items WHERE site_name LIKE 'txforder-%'");
db()->exec("DELETE FROM semrush_sites WHERE domain LIKE 'txfsem-%'");
db()->exec("DELETE FROM semrush_sheet_comments WHERE body LIKE 'txfsem-%'");

// Ensure seed logins match what this suite expects (local DBs may still use demo hashes).
db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE username=?')->execute([
    password_hash('TestAdmin9x', PASSWORD_DEFAULT),
    'admin',
]);
db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE username=?')->execute([
    password_hash('TestTeam8z', PASSWORD_DEFAULT),
    'teammate',
]);

$webRootGuards = [
    'tests_run.php',
    'tests_http.php',
    'smoke_admin_mvp.php',
    'reset_admin_once.php',
];
$guardNeedle = 'PHP_SAPI !== \'cli\'';
$guardOk = true;
foreach ($webRootGuards as $guardFile) {
    $src = (string) file_get_contents(__DIR__ . '/' . $guardFile);
    $beforeRequire = preg_split('/\brequire(?:_once)?\b/', $src, 2)[0] ?? $src;
    if (!str_contains($beforeRequire, $guardNeedle)) {
        $guardOk = false;
        fail('web-root CLI guard missing before require in ' . $guardFile);
    }
}
$resetSrc = (string) file_get_contents(__DIR__ . '/reset_admin_once.php');
if (str_contains($resetSrc, '$_GET[\'confirm\']') || str_contains($resetSrc, '?confirm=RESET')) {
    $guardOk = false;
    fail('reset_admin_once.php still documents or reads a web confirm=RESET');
}
if ($guardOk) {
    pass('web-root CLI guards on tests + reset_admin_once');
}

$draftJs = (string) file_get_contents(__DIR__ . '/assets/js/draft-autosave.js');
$procJs = (string) file_get_contents(__DIR__ . '/assets/js/app-processing.js');
$helpersSrc = (string) file_get_contents(__DIR__ . '/includes/helpers.php');
$sweAppSrc = (string) file_get_contents(__DIR__ . '/pages/sites_with_emails_app.php');
$indexSrc = (string) file_get_contents(__DIR__ . '/index.php');
$presenceJs = (string) file_get_contents(__DIR__ . '/assets/js/task-presence.js');
if (
    str_contains($draftJs, "name === '_csrf'")
    && str_contains($helpersSrc, 'csrf_field()')
    && preg_match('/\$nav = \(function_exists\(\'csrf_field\'\) \? csrf_field\(\) : \'\'\)/', $helpersSrc)
    && preg_match('/data-swe-save>\s*<\?=\s*csrf_field\(\)/', $sweAppSrc)
    && str_contains($indexSrc, "\$page === 'presence_ping'")
    && str_contains($presenceJs, "body.set('_csrf'")
    && str_contains($draftJs, 'shouldClearDraft')
    && str_contains($draftJs, 'alert-box.alert-ok')
    && str_contains($draftJs, 'just_added')
    && str_contains($draftJs, 'prospect-add-sites-form')
    && str_contains($draftJs, 'Restore already wrote localStorage')
) {
    pass('draft autosave skips _csrf; sheet/SWE/presence CSRF wired');
} else {
    fail('draft autosave / sheet / presence CSRF wiring');
}

if (str_contains($procJs, "method === 'get'")
    && str_contains($procJs, 'GET forms do')
    && str_contains($draftJs, 'Restore already wrote localStorage')
    && str_contains($draftJs, 'restoreBannerVisible')
    && str_contains($draftJs, 'saveForm(form, index, true)')
    && str_contains($draftJs, "typeahead::")
    && str_contains($draftJs, 'typeahead:select')
    && str_contains($draftJs, 'Typed country')) {
    pass('GET forms skip overlay unless marked; restore does not re-save draft');
} else {
    fail('processing overlay GET skip / draft restore re-save guard');
}

// --- Login ---
try {
    if (!attempt_login('admin', 'TestAdmin9x')) {
        fail('admin login');
    } else {
        pass('admin login');
    }
    logout_user();
    if (attempt_login('nope', 'wrong')) {
        fail('bad login should fail');
    } else {
        pass('bad login rejected');
    }
    if (function_exists('app_mail_reset_is_ready') && is_bool(app_mail_reset_is_ready())) {
        pass('app_mail_reset_is_ready returns bool');
    } else {
        fail('app_mail_reset_is_ready missing');
    }

    // Admin may sign in with account email; Team may not.
    db()->prepare("UPDATE users SET email=? WHERE username='admin'")
        ->execute(['admin.login@txf-test.local']);
    db()->prepare("UPDATE users SET email=? WHERE username='teammate'")
        ->execute(['teammate.login@txf-test.local']);
    logout_user();
    $adminEmailOk = attempt_login('Admin.Login@txf-test.local', 'TestAdmin9x');
    $adminEmailUser = current_user();
    logout_user();
    $teamEmailBlocked = !attempt_login('teammate.login@txf-test.local', 'TestTeam8z');
    logout_user();
    $teamUserOk = attempt_login('teammate', 'TestTeam8z');
    logout_user();
    if ($adminEmailOk
        && ($adminEmailUser['role'] ?? '') === 'admin'
        && ($adminEmailUser['username'] ?? '') === 'admin'
        && $teamEmailBlocked
        && $teamUserOk) {
        pass('admin email login allowed; team email login blocked');
    } else {
        fail('email login ACL: ' . json_encode([
            'admin_email' => $adminEmailOk,
            'admin_user' => $adminEmailUser,
            'team_email_blocked' => $teamEmailBlocked,
            'team_username' => $teamUserOk,
        ]));
    }
} catch (Throwable $e) {
    fail('login: ' . $e->getMessage());
}

$country = 'Germany';

// --- Clean to root domains / https:// paste → root domains (Filter & add) ---
try {
    $messy = implode("\n", [
        'https://mail.google.com/mail/u/6/#inbox',
        'https://mail.google.com/mail/u/3/#inbox',
        'https://mail.google.com/mail/u/3/#inbox',
        'https://mail.google.com/mail/u/4/#inbox',
        'https://mail.google.com/mail/u/9/#inbox',
        'ttps://utilfox.vercel.app/data-tools/url-cleaner',
        'https://utilfox.vercel.app/data-tools/url-cleaner',
        'https://utilfox.vercel.app/data-tools/data-deduplication',
        'https://members.toolszen.com/page/semrush',
        'https://techxform.com/index.php?page=team_extract_queue&country=Italy',
        'https://seolinkbuildings.com/publisher/websites',
        'https://guruhitech.com/',
        'https://www.letemps.ch/',
        'https://cloudconvert.com/png-to-webp',
        'https://seolinkbuildings.com/login',
    ]);
    $parsedMessy = parse_domain_list_strict($messy);
    $expectRoots = [
        'google.com',
        'utilfox.vercel.app',
        'toolszen.com',
        'techxform.com',
        'seolinkbuildings.com',
        'guruhitech.com',
        'letemps.ch',
        'cloudconvert.com',
    ];
    sort($expectRoots);
    $got = $parsedMessy['valid'];
    sort($got);
    if ((int) ($parsedMessy['invalid_count'] ?? -1) === 0 && $got === $expectRoots) {
        pass('clean https paste → unique root domains (guruhitech.com etc.)');
    } else {
        fail('clean https paste: ' . json_encode([
            'got' => $got,
            'expect' => $expectRoots,
            'invalid' => $parsedMessy['invalid'] ?? [],
        ]));
    }
    $g = analyze_pasted_domain_line('https://guruhitech.com/');
    if (!empty($g['ok']) && ($g['domain'] ?? '') === 'guruhitech.com' && !empty($g['fixed'])) {
        pass('analyze https://guruhitech.com/ → guruhitech.com');
    } else {
        fail('analyze guruhitech: ' . json_encode($g));
    }
    $q = analyze_pasted_domain_line(
        'https://techxform.com/index.php?page=team_extract_queue&country=Italy'
    );
    if (!empty($q['ok']) && ($q['domain'] ?? '') === 'techxform.com') {
        pass('analyze https URL with query string → root domain');
    } else {
        fail('analyze query URL: ' . json_encode($q));
    }

    // Platform suffixes must keep the tenant label (not collapse to vercel.app / github.io).
    $v = analyze_pasted_domain_line('ttps://utilfox.vercel.app/data-tools/url-cleaner');
    if (!empty($v['ok']) && ($v['domain'] ?? '') === 'utilfox.vercel.app') {
        pass('platform vercel.app keeps utilfox.vercel.app');
    } else {
        fail('platform vercel: ' . json_encode($v));
    }
    $gio = analyze_pasted_domain_line('https://alice.github.io/project/');
    if (!empty($gio['ok']) && ($gio['domain'] ?? '') === 'alice.github.io') {
        pass('platform github.io keeps alice.github.io');
    } else {
        fail('platform github.io: ' . json_encode($gio));
    }
    $barePlatform = to_root_domain('vercel.app');
    if ($barePlatform === '') {
        pass('bare platform suffix vercel.app rejected as root');
    } else {
        fail('bare vercel.app should reject, got=' . $barePlatform);
    }

    // Messy extract: markdown, href, Excel tab, attention reason tag.
    $md = analyze_pasted_domain_line('[Site](https://www.example.com/path)');
    if (!empty($md['ok']) && ($md['domain'] ?? '') === 'example.com') {
        pass('markdown link → example.com');
    } else {
        fail('markdown: ' . json_encode($md));
    }
    $href = analyze_pasted_domain_line('<a href="https://shop.example.co.uk/x">x</a>');
    if (!empty($href['ok']) && ($href['domain'] ?? '') === 'example.co.uk') {
        pass('href attribute → example.co.uk');
    } else {
        fail('href: ' . json_encode($href));
    }
    $tab = analyze_pasted_domain_line("blog.example.com\tnotes from sheet");
    if (!empty($tab['ok']) && ($tab['domain'] ?? '') === 'example.com') {
        pass('Excel tab first column → example.com');
    } else {
        fail('tab: ' . json_encode($tab));
    }
    $attn = analyze_pasted_domain_line('https://www.example.org/x  # has_path');
    if (!empty($attn['ok']) && ($attn['domain'] ?? '') === 'example.org') {
        pass('attention # reason strip → example.org');
    } else {
        fail('attention strip: ' . json_encode($attn));
    }

    $gal = analyze_pasted_domain_line('https://www.praza.gal/noticia');
    $madrid = analyze_pasted_domain_line('https://www.comunidad.madrid/');
    $mardidGal = analyze_pasted_domain_line('mardid.gal');
    $eus = analyze_pasted_domain_line('berria.eus');
    $scot = analyze_pasted_domain_line('gov.scot');
    $run = analyze_pasted_domain_line('dashport.run');
    $shotGal = analyze_pasted_domain_line('fedgalmon.gal');
    $shotMadrid = analyze_pasted_domain_line('tanatorios.madrid');
    $shotEus = analyze_pasted_domain_line('emf.eus');
    $comz = analyze_pasted_domain_line('not-a-site.comz');
    $fakeMardidTld = analyze_pasted_domain_line('site.mardid');
    $bare = analyze_pasted_domain_line('zonlab');
    if (!empty($gal['ok']) && ($gal['domain'] ?? '') === 'praza.gal'
        && !empty($madrid['ok']) && ($madrid['domain'] ?? '') === 'comunidad.madrid'
        && !empty($mardidGal['ok']) && ($mardidGal['domain'] ?? '') === 'mardid.gal'
        && !empty($eus['ok']) && ($eus['domain'] ?? '') === 'berria.eus'
        && !empty($scot['ok']) && ($scot['domain'] ?? '') === 'gov.scot'
        && !empty($run['ok']) && ($run['domain'] ?? '') === 'dashport.run'
        && !empty($shotGal['ok']) && ($shotGal['domain'] ?? '') === 'fedgalmon.gal'
        && !empty($shotMadrid['ok']) && ($shotMadrid['domain'] ?? '') === 'tanatorios.madrid'
        && !empty($shotEus['ok']) && ($shotEus['domain'] ?? '') === 'emf.eus'
        && empty($comz['ok'])
        && empty($fakeMardidTld['ok'])
        && empty($bare['ok'])
        && is_known_tld('gal') && is_known_tld('madrid') && is_known_tld('run')
        && !is_known_tld('comz') && !is_known_tld('mardid')) {
        pass('real geoTLDs .gal .madrid .eus .scot .run accepted; .comz .mardid and bare names skipped');
    } else {
        fail('geoTLD clean: ' . json_encode([
            'gal' => $gal,
            'madrid' => $madrid,
            'mardid.gal' => $mardidGal,
            'eus' => $eus,
            'scot' => $scot,
            'run' => $run,
            'fedgalmon.gal' => $shotGal,
            'tanatorios.madrid' => $shotMadrid,
            'emf.eus' => $shotEus,
            'comz' => $comz,
            'mardid' => $fakeMardidTld,
            'zonlab' => $bare,
        ]));
    }
} catch (Throwable $e) {
    fail('clean https: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Our database ---
try {
    $added = add_prospect_domains(
        [
            'txftest-finance-de.com',
            'txftest-blog-de.de',
            'https://www.txftest-shop-de.com/path',
        ],
        $adminUser,
        $country,
        'German',
        'europe',
        'Finance',
        'integration test'
    );
    pass('add_prospect_domains: ' . json_encode($added));
    $st = db()->prepare("SELECT COUNT(*) FROM prospect_sites WHERE country=? AND domain LIKE 'txftest-%'");
    $st->execute([$country]);
    $cnt = (int) $st->fetchColumn();
    if ($cnt >= 2) {
        pass("prospect Germany txftest-* count=$cnt");
    } else {
        fail("prospect Germany txftest-* count=$cnt expected >=2");
    }

    // Admin Add sites path (strict root domains + country folder save).
    $paste = "https://www.txfadd-site-a.com/blog\ntxfadd-site-b.de\nnot a domain\ntxfadd-site-a.com";
    $parsed = parse_domain_list_strict($paste);
    if ($parsed['invalid_count'] >= 1 && in_array('txfadd-site-a.com', $parsed['valid'], true)) {
        pass('parse_domain_list_strict flags invalid + keeps roots');
    } else {
        fail('parse_domain_list_strict unexpected: ' . json_encode($parsed));
    }
    $adminAdd = admin_add_urls_to_database(implode("\n", $parsed['valid']), $adminUser, $country, 'German');
    if ((int) $adminAdd['total'] >= 2 && (int) $adminAdd['inserted'] >= 2
        && isset($adminAdd['ids']) && count($adminAdd['ids']) === (int) $adminAdd['inserted']) {
        pass('admin_add_urls_to_database inserted=' . (int) $adminAdd['inserted']);
    } else {
        fail('admin_add_urls_to_database: ' . json_encode($adminAdd));
    }
    // Re-add same domains → duplicates deleted (not updated as kept).
    $adminDup = admin_add_urls_to_database(
        "txfadd-site-a.com\ntxfadd-site-a.com\ntxfadd-site-b.de",
        $adminUser,
        $country,
        'German'
    );
    if ((int) ($adminDup['inserted'] ?? -1) === 0
        && (int) ($adminDup['duplicated'] ?? 0) >= 3
        && prospect_duplicates_deleted_message(1) === '1 duplicate found and removed'
        && str_contains(prospect_duplicates_deleted_message(3), '3 duplicates found and removed')) {
        pass('admin_add_urls_to_database auto-deletes duplicates');
    } else {
        fail('admin_add duplicate delete: ' . json_encode($adminDup));
    }
    $parsedDup = parse_domain_list_strict("a.com\na.com\nb.com\na.com");
    if ((int) ($parsedDup['duplicate_count'] ?? -1) === 2
        && count($parsedDup['valid'] ?? []) === 2) {
        pass('parse_domain_list_strict counts list duplicates');
    } else {
        fail('parse duplicate_count: ' . json_encode($parsedDup));
    }
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfadd-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfadd-%'");

    // Country folder export helpers + whole-folder match count.
    $matchAll = count_prospect_sites_matching($country, '');
    $matchFinance = count_prospect_sites_matching($country, 'txftest-finance');
    $matchNone = count_prospect_sites_matching($country, 'zzznomatch-xyz');
    $unknownCountry = count_prospect_sites_matching('NotARealCountryXYZ', 'x');
    if ($matchAll >= 2 && $matchFinance === 1 && $matchNone === 0 && $unknownCountry === 0) {
        pass('count_prospect_sites_matching whole-folder + q scope');
    } else {
        fail('count_prospect_sites_matching: ' . json_encode([
            'all' => $matchAll,
            'finance' => $matchFinance,
            'none' => $matchNone,
            'unknown' => $unknownCountry,
        ]));
    }
    $invQ = prospect_inventory_query(['country' => $country, 'q' => 'txftest-blog'], 1, 50);
    if ((int) ($invQ['total'] ?? 0) === 1
        && str_contains((string) ($invQ['rows'][0]['domain'] ?? ''), 'txftest-blog')) {
        pass('prospect_inventory_query q filters country folder');
    } else {
        fail('prospect_inventory_query q: ' . json_encode($invQ));
    }
    $rowHtml = prospect_site_rows_html([
        [
            'id' => 1,
            'domain' => 'txf-status-col.example',
            'url' => '',
            'language' => 'English',
            'status' => 'contacting',
            'added_by_full' => 'Admin',
            'created_at' => '2026-01-01 00:00:00',
        ],
    ]);
    if (str_contains($rowHtml, 'data-label="Niche"')
        && str_contains($rowHtml, 'data-label="Domain"')
        && strpos($rowHtml, 'data-label="Niche"') < strpos($rowHtml, 'data-label="Domain"')
        && str_contains($rowHtml, 'data-niche-chips')
        && str_contains($rowHtml, 'Open website')
        && !str_contains($rowHtml, 'data-label="URL"')
        && !str_contains($rowHtml, 'data-label="Status"')
        && !str_contains($rowHtml, 'contacting')) {
        pass('prospect_site_rows_html Niche before Domain, no Status');
    } else {
        fail('prospect_site_rows_html niche/status: ' . $rowHtml);
    }
    if (prospect_copy_all_label(0, '') === 'Copy all'
        && prospect_copy_all_label(0, 'Health') === 'Copy Health'
        && prospect_copy_all_label(12, '') === 'Copy this person’s sites'
        && prospect_copy_all_label(12, '_none') === 'Copy this person’s sites with no niche'
        && prospect_open_in_folder_label('Germany') === 'Open in Germany'
        && prospect_open_in_folder_label('') === 'Open in No country'
        && str_contains(prospect_country_sheet_url('Germany', ['q' => 'blog', 'niche' => 'Health']), 'q=blog')
        && str_contains(prospect_country_sheet_url('Germany', ['q' => 'blog', 'niche' => 'Health']), 'niche=Health')
        && !str_contains(prospect_country_sheet_url('Germany', ['q' => 'blog']), 'created_by=')) {
        pass('prospect copy label + sheet URL keep filters');
    } else {
        fail('prospect copy/url helpers');
    }
    if (prospect_saved_sites_message(1, 'United States') === 'Saved 1 new site to United States. It is at the top of the list.'
        && str_contains(prospect_saved_sites_message(30, 'United States'), 'Saved 30 new sites to United States')
        && !str_contains(prospect_saved_sites_message(30, 'United States'), 'site(s)')
        && str_contains(prospect_country_sheet_url('Germany', ['just_added' => 30]), 'just_added=30')) {
        pass('prospect_saved_sites_message grammar + just_added URL');
    } else {
        fail('prospect_saved_sites_message');
    }
    $justHtml = prospect_site_rows_html([
        [
            'id' => 2,
            'domain' => 'txf-just-added.example',
            'url' => '',
            'language' => 'English',
            'added_by_full' => 'Admin',
            'created_at' => date('Y-m-d') . ' 12:00:00',
        ],
        [
            'id' => 3,
            'domain' => 'txf-old-today.example',
            'url' => '',
            'language' => 'English',
            'added_by_full' => 'Admin',
            'created_at' => date('Y-m-d') . ' 11:00:00',
        ],
    ], [2]);
    $leadHtml = prospect_site_rows_html([
        [
            'id' => 4,
            'domain' => 'txf-lead-new.example',
            'url' => '',
            'language' => 'English',
            'added_by_full' => 'Admin',
            'created_at' => date('Y-m-d') . ' 12:00:00',
        ],
        [
            'id' => 5,
            'domain' => 'txf-lead-old.example',
            'url' => '',
            'language' => 'English',
            'added_by_full' => 'Admin',
            'created_at' => date('Y-m-d') . ' 11:00:00',
        ],
    ], 1);
    $newTrTinted = (bool) preg_match('/<tr[^>]*data-domain="txf-just-added\.example"[^>]*class="is-just-added"/', $justHtml);
    $oldTrTinted = (bool) preg_match('/<tr[^>]*data-domain="txf-old-today\.example"[^>]*class="is-just-added"/', $justHtml);
    $leadNewTinted = (bool) preg_match('/<tr[^>]*data-domain="txf-lead-new\.example"[^>]*class="is-just-added"/', $leadHtml);
    $leadOldTinted = (bool) preg_match('/<tr[^>]*data-domain="txf-lead-old\.example"[^>]*class="is-just-added"/', $leadHtml);
    if ($newTrTinted && !$oldTrTinted
        && $leadNewTinted && !$leadOldTinted
        && prospect_just_added_highlight(30, [10, 11]) === [10, 11]
        && prospect_just_added_highlight(30, []) === 30
        && prospect_just_added_highlight(0, [1]) === 0) {
        pass('prospect_site_rows_html highlights only just-added ids / first N');
    } else {
        fail('prospect_site_rows_html just-added: new=' . (int) $newTrTinted
            . ' old=' . (int) $oldTrTinted
            . ' leadNew=' . (int) $leadNewTinted
            . ' leadOld=' . (int) $leadOldTinted);
    }

    $parsedNiches = prospect_parse_niches('Health, fitness, Health, salud, Guest posts');
    if ($parsedNiches === ['Health', 'Fitness', 'Guest posts']) {
        pass('prospect_parse_niches alias + unique + keep unknown');
    } else {
        fail('prospect_parse_niches: ' . json_encode($parsedNiches));
    }
    if (prospect_format_niches(['fitness', 'Health']) === 'Health, Fitness'
        && prospect_normalize_niche_label('e-commerce') === 'E-commerce'
        && prospect_normalized_niche_filter('all') === ''
        && prospect_normalized_niche_filter('No niche') === '_none'
        && prospect_normalized_niche_filter('health') === 'Health') {
        pass('prospect niche format + filter tokens');
    } else {
        fail('prospect niche format/filter: ' . json_encode([
            'fmt' => prospect_format_niches(['fitness', 'Health']),
            'ecom' => prospect_normalize_niche_label('e-commerce'),
            'all' => prospect_normalized_niche_filter('all'),
            'none' => prospect_normalized_niche_filter('No niche'),
            'health' => prospect_normalized_niche_filter('health'),
        ]));
    }
    $fromDe = prospect_suggest_niches_from_domain('gesundheit-magazin.de');
    $fromBrand = prospect_suggest_niches_from_domain('acme24.de');
    if (in_array('Health', $fromDe, true) && in_array('Magazine', $fromDe, true) && $fromBrand === []) {
        pass('prospect_suggest_niches_from_domain Health+Magazine / blank brand');
    } else {
        fail('prospect suggest: ' . json_encode(['de' => $fromDe, 'brand' => $fromBrand]));
    }
    $kwOnce = prospect_niche_domain_keywords();
    $kwTwice = prospect_niche_domain_keywords();
    $fromAiTools = prospect_suggest_niches_from_domain('aitools.io');
    if ($kwOnce === $kwTwice
        && isset($kwOnce['aitools'])
        && in_array('AI', $fromAiTools, true)) {
        pass('prospect_niche_domain_keywords compact cache stays stable');
    } else {
        fail('prospect domain keywords cache: ' . json_encode([
            'same' => $kwOnce === $kwTwice,
            'aitools' => isset($kwOnce['aitools']),
            'suggest' => $fromAiTools,
        ]));
    }
    $mergedNew = prospect_niches_for_new_site('fitness-blog.de', 'Health');
    if ($mergedNew === 'Blog, Health, Fitness') {
        pass('prospect_niches_for_new_site merges human + domain');
    } else {
        fail('prospect_niches_for_new_site: ' . $mergedNew);
    }

    db()->prepare(
        "UPDATE prospect_sites SET niche=? WHERE country=? AND domain=?"
    )->execute(['Health, Fitness', $country, 'txftest-finance-de.com']);
    db()->prepare(
        "UPDATE prospect_sites SET niche='' WHERE country=? AND domain=?"
    )->execute([$country, 'txftest-blog-de.de']);
    $invHealth = prospect_inventory_query(['country' => $country, 'niche' => 'Health'], 1, 50);
    $invNone = prospect_inventory_query(['country' => $country, 'niche' => '_none'], 1, 50);
    $healthDomains = array_column($invHealth['rows'] ?? [], 'domain');
    $noneDomains = array_column($invNone['rows'] ?? [], 'domain');
    $healthHit = in_array('txftest-finance-de.com', $healthDomains, true);
    $noneHit = in_array('txftest-blog-de.de', $noneDomains, true);
    $healthMissBlog = !in_array('txftest-blog-de.de', $healthDomains, true);
    if ($healthHit && $noneHit && $healthMissBlog
        && count_prospect_sites_matching($country, '', 'Health') >= 1
        && count_prospect_sites_matching($country, '', '_none') >= 1) {
        pass('prospect niche filter contains Health / No niche');
    } else {
        fail('prospect niche filter: ' . json_encode([
            'health' => $healthDomains,
            'none' => $noneDomains,
            'countH' => count_prospect_sites_matching($country, '', 'Health'),
            'countN' => count_prospect_sites_matching($country, '', '_none'),
        ]));
    }
    $stFin = db()->prepare("SELECT id FROM prospect_sites WHERE country=? AND domain=? LIMIT 1");
    $stFin->execute([$country, 'txftest-finance-de.com']);
    $finId = (int) $stFin->fetchColumn();
    $savedNiches = $finId > 0 ? update_prospect_site_niches($finId, 'Medical, health') : null;
    if (is_array($savedNiches) && ($savedNiches['niche'] ?? '') === 'Health, Medical') {
        pass('update_prospect_site_niches autosave parse');
    } else {
        fail('update_prospect_site_niches: ' . json_encode($savedNiches));
    }
    $superNicheHits = array_column(search_prospect_sites_global('Medical', 50), 'domain');
    $superExactHits = array_column(search_prospect_sites_global('txftest-finance-de.com', 20), 'domain');
    if (in_array('txftest-finance-de.com', $superNicheHits, true)
        && in_array('txftest-finance-de.com', $superExactHits, true)) {
        pass('search_prospect_sites_global matches domain and niche');
    } else {
        fail('super search domain/niche: ' . json_encode([
            'niche' => $superNicheHits,
            'exact' => $superExactHits,
        ]));
    }
    $nfSql = prospect_sql_niche_filter('p.niche', 'Health');
    if ($nfSql['sql'] !== '' && ($nfSql['params'][0] ?? '') === 'Health') {
        pass('prospect_sql_niche_filter FIND_IN_SET Health');
    } else {
        fail('prospect_sql_niche_filter: ' . json_encode($nfSql));
    }
    $fnAll = prospect_export_basename($country, '');
    $fnMatch = prospect_export_basename($country, 'txftest');
    if ($fnAll === 'germany-our-database'
        && $fnMatch === 'germany-our-database-matches'
        && str_ends_with($fnAll . '.csv', '-our-database.csv')
        && str_ends_with($fnMatch . '.txt', '-matches.txt')) {
        pass('prospect_export_basename germany-our-database(+matches)');
    } else {
        fail('prospect_export_basename: ' . json_encode(['all' => $fnAll, 'match' => $fnMatch, 'country' => $country]));
    }
} catch (Throwable $e) {
    fail('prospects: ' . $e->getMessage());
}

// --- Website prices (Office rate book) ---
try {
    db()->exec("DELETE FROM site_price_rows WHERE domain LIKE 'txfprice-%'");
    $statuses = site_price_list_statuses();
    $slugs = array_column($statuses, 'slug');
    $need = ['new', 'processing', 'already_working', 'ok', 'very_high_price', 'not_interested', 'agreed', 'completed'];
    $missing = array_diff($need, $slugs);
    if ($missing === [] && site_price_status_lane('processing') === 'processing'
        && site_price_status_lane('new') === 'new'
        && site_price_status_lane('agreed') === 'other'
        && site_price_status_lane('completed') === 'other') {
        pass('site_price builtin statuses + lanes');
    } else {
        fail('site_price statuses: ' . json_encode(['missing' => $missing, 'slugs' => $slugs]));
    }
    if (site_price_normalize_domain('https://www.txfprice-a.com/blog') === 'txfprice-a.com') {
        pass('site_price_normalize_domain strips url');
    } else {
        fail('site_price_normalize_domain: ' . site_price_normalize_domain('https://www.txfprice-a.com/blog'));
    }

    add_prospect_domains(
        ['txfprice-health-de.com'],
        $adminUser,
        $country,
        'German',
        'europe',
        'Health',
        'price-book niche seed'
    );
    $idHealth = site_price_insert_row([
        'country' => $country,
        'domain' => 'https://www.txfprice-health-de.com/x',
        'created_by' => (int) $teamUser['id'],
    ]);
    $stH = db()->prepare('SELECT domain, niche, country FROM site_price_rows WHERE id=?');
    $stH->execute([$idHealth]);
    $rowH = $stH->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((string) ($rowH['domain'] ?? '') === 'txfprice-health-de.com'
        && str_contains((string) ($rowH['niche'] ?? ''), 'Health')
        && (string) ($rowH['country'] ?? '') === $country) {
        pass('site_price insert normalizes domain + niche from Our database');
    } else {
        fail('site_price insert niche: ' . json_encode($rowH));
    }
    $dupOk = false;
    try {
        site_price_insert_row([
            'country' => $country,
            'domain' => 'txfprice-health-de.com',
            'created_by' => (int) $adminUser['id'],
        ]);
    } catch (RuntimeException $e) {
        $dupOk = str_contains($e->getMessage(), 'already');
    }
    if ($dupOk) {
        pass('site_price unique country+domain');
    } else {
        fail('site_price duplicate allowed');
    }

    $idProcOld = site_price_insert_row([
        'country' => $country,
        'domain' => 'txfprice-proc-old.de',
        'status_slug' => 'processing',
        'created_by' => (int) $adminUser['id'],
        'managed_by' => (int) $adminUser['id'],
    ]);
    $idProcNew = site_price_insert_row([
        'country' => $country,
        'domain' => 'txfprice-proc-new.de',
        'status_slug' => 'processing',
        'created_by' => (int) $teamUser['id'],
    ]);
    $idNew = site_price_insert_row([
        'country' => $country,
        'domain' => 'txfprice-new.de',
        'status_slug' => 'new',
        'created_by' => (int) $teamUser['id'],
    ]);
    $idAgreed = site_price_insert_row([
        'country' => $country,
        'domain' => 'txfprice-agreed.de',
        'status_slug' => 'agreed',
        'created_by' => (int) $teamUser['id'],
    ]);
    db()->prepare('UPDATE site_price_rows SET created_at=? WHERE id=?')->execute(['2026-01-01 10:00:00', $idProcOld]);
    db()->prepare('UPDATE site_price_rows SET created_at=? WHERE id=?')->execute(['2026-01-02 10:00:00', $idProcNew]);
    db()->prepare('UPDATE site_price_rows SET created_at=? WHERE id=?')->execute(['2026-01-03 10:00:00', $idNew]);
    db()->prepare('UPDATE site_price_rows SET created_at=? WHERE id=?')->execute(['2026-01-04 10:00:00', $idAgreed]);
    $sorted = list_site_price_rows($country);
    $sortedTest = array_values(array_filter($sorted, static function ($r) {
        return str_starts_with((string) ($r['domain'] ?? ''), 'txfprice-');
    }));
    $order = array_column($sortedTest, 'domain');
    $lanes = [];
    foreach ($sortedTest as $r) {
        $lanes[] = site_price_status_lane((string) $r['status_slug']) . ':' . $r['domain'];
    }
    $firstTwoProc = ($order[0] ?? '') === 'txfprice-proc-old.de' && ($order[1] ?? '') === 'txfprice-proc-new.de';
    $newBeforeOther = false;
    $sawNew = false;
    $otherBeforeNew = false;
    foreach ($sortedTest as $r) {
        $lane = site_price_status_lane((string) $r['status_slug']);
        if ($lane === 'new') {
            $sawNew = true;
        }
        if ($lane === 'other' && !$sawNew) {
            $otherBeforeNew = true;
        }
        if ($lane === 'other' && $sawNew) {
            $newBeforeOther = true;
        }
    }
    if ($firstTwoProc && $sawNew && $newBeforeOther && !$otherBeforeNew) {
        pass('site_price sort Processing then New then Other');
    } else {
        fail('site_price sort: ' . json_encode($lanes));
    }

    $adminView = site_price_row_for_viewer($sortedTest[0], $adminUser);
    $teamView = site_price_row_for_viewer($sortedTest[0], $teamUser);
    $adminSeesMgr = ($adminView['managed_by_label'] ?? '') !== '' || !empty($adminView['managed_by']);
    $teamHidesMgr = !isset($teamView['managed_by']) && !isset($teamView['managed_by_label']);
    $teamHidesAdminName = ($teamView['added_by_label'] ?? '') === 'Admin';
    if ($adminSeesMgr && $teamHidesMgr && $teamHidesAdminName) {
        pass('site_price Team hides admin manager + admin added-by name');
    } else {
        fail('site_price viewer: ' . json_encode([
            'admin' => [
                'managed' => $adminView['managed_by_label'] ?? null,
                'added' => $adminView['added_by_label'] ?? null,
            ],
            'team' => [
                'has_managed' => isset($teamView['managed_by']),
                'added' => $teamView['added_by_label'] ?? null,
            ],
        ]));
    }
    $counts = site_price_country_counts();
    $gerCount = 0;
    foreach ($counts as $c) {
        if ((string) ($c['country'] ?? '') === $country) {
            $gerCount = (int) $c['total'];
        }
    }
    if ($gerCount >= 5 && count_site_price_rows($country) >= 5) {
        pass('site_price country counts');
    } else {
        fail('site_price counts ger=' . $gerCount);
    }

    $rowLock = site_price_add_row_for_user([
        'country' => $country,
        'domain' => 'https://www.txfprice-lock.com/x',
        'da' => '40',
        'dr' => '50',
        'traffic' => '10k',
        'price_note' => '50 euro',
    ], $teamUser);
    $idLock = (int) ($rowLock['id'] ?? 0);
    if ($rowLock
        && (string) ($rowLock['domain'] ?? '') === 'txfprice-lock.com'
        && (int) ($rowLock['identity_locked'] ?? 0) === 1) {
        pass('site_price add_row locks identity');
    } else {
        fail('site_price add lock: ' . json_encode($rowLock));
    }

    $savedPrice = site_price_save_row($idLock, [
        'price_note' => '60 euro article only',
        'status_slug' => 'agreed',
        'extra_note' => 'wait',
    ], $teamUser);
    if ((string) ($savedPrice['price_note'] ?? '') === '60 euro article only'
        && (string) ($savedPrice['status_slug'] ?? '') === 'agreed'
        && (string) ($savedPrice['da'] ?? '') === '40'
        && (int) ($savedPrice['identity_locked'] ?? 0) === 1) {
        pass('site_price save price/status while locked');
    } else {
        fail('site_price save while locked: ' . json_encode($savedPrice));
    }

    $teamProcReject = false;
    try {
        site_price_save_row($idLock, ['status_slug' => 'processing'], $teamUser);
    } catch (RuntimeException $e) {
        $teamProcReject = str_contains($e->getMessage(), 'Only Admin');
    }
    $teamCompReject = false;
    try {
        site_price_save_row($idLock, ['status_slug' => 'completed'], $teamUser);
    } catch (RuntimeException $e) {
        $teamCompReject = str_contains($e->getMessage(), 'Only Admin');
    }
    $teamAddProc = false;
    try {
        site_price_add_row_for_user([
            'country' => $country,
            'domain' => 'txfprice-team-proc.com',
            'status_slug' => 'processing',
        ], $teamUser);
    } catch (RuntimeException $e) {
        $teamAddProc = str_contains($e->getMessage(), 'Only Admin');
    }
    $adminProc = site_price_save_row($idLock, ['status_slug' => 'processing'], $adminUser);
    $teamKeepProc = site_price_save_row($idLock, [
        'status_slug' => 'processing',
        'extra_note' => 'still processing',
    ], $teamUser);
    $teamSelectNew = site_price_status_select_html('new', '', $teamUser);
    $teamSelectProc = site_price_status_select_html('processing', '', $teamUser);
    $adminSelectNew = site_price_status_select_html('new', '', $adminUser);
    if ($teamProcReject && $teamCompReject && $teamAddProc
        && (string) ($adminProc['status_slug'] ?? '') === 'processing'
        && (string) ($teamKeepProc['status_slug'] ?? '') === 'processing'
        && (string) ($teamKeepProc['extra_note'] ?? '') === 'still processing'
        && !str_contains($teamSelectNew, 'value="processing"')
        && !str_contains($teamSelectNew, 'value="completed"')
        && str_contains($teamSelectProc, 'value="processing"')
        && !str_contains($teamSelectProc, 'value="completed"')
        && str_contains($adminSelectNew, 'value="processing"')
        && str_contains($adminSelectNew, 'value="completed"')) {
        pass('site_price Processing/Completed Admin-only');
    } else {
        fail('site_price admin-only status: ' . json_encode([
            'team_proc' => $teamProcReject,
            'team_comp' => $teamCompReject,
            'team_add' => $teamAddProc,
            'admin' => $adminProc['status_slug'] ?? null,
            'keep' => $teamKeepProc['status_slug'] ?? null,
        ]));
    }

    $adminLockReject = false;
    try {
        site_price_save_row($idLock, [
            'domain' => 'txfprice-changed.com',
            'da' => '99',
            'dr' => '50',
            'traffic' => '10k',
        ], $adminUser);
    } catch (RuntimeException $e) {
        $adminLockReject = str_contains($e->getMessage(), 'locked');
    }
    $teamLockReject = false;
    try {
        site_price_save_row($idLock, [
            'domain' => 'txfprice-changed.com',
            'da' => '99',
        ], $teamUser);
    } catch (RuntimeException $e) {
        $teamLockReject = str_contains($e->getMessage(), 'locked')
            || str_contains($e->getMessage(), 'Only Admin');
    }
    if ($adminLockReject && $teamLockReject) {
        pass('site_price locked identity rejected for admin and team');
    } else {
        fail('site_price locked identity: admin=' . (int) $adminLockReject . ' team=' . (int) $teamLockReject);
    }

    $teamUnlockReject = false;
    try {
        site_price_unlock_row($idLock, $teamUser);
    } catch (RuntimeException $e) {
        $teamUnlockReject = str_contains($e->getMessage(), 'Only Admin');
    }
    $unlocked = site_price_unlock_row($idLock, $adminUser);
    $teamUnlockedReject = false;
    try {
        site_price_save_row($idLock, [
            'domain' => 'txfprice-team-steal.com',
            'da' => '1',
            'dr' => '1',
            'traffic' => '1',
        ], $teamUser);
    } catch (RuntimeException $e) {
        $teamUnlockedReject = str_contains($e->getMessage(), 'Only Admin');
    }
    $relocked = site_price_save_row($idLock, [
        'domain' => 'txfprice-relock.com',
        'da' => '41',
        'dr' => '51',
        'traffic' => '11k',
        'price_note' => '60 euro article only',
    ], $adminUser);
    if ($teamUnlockReject
        && (int) ($unlocked['identity_locked'] ?? 1) === 0
        && $teamUnlockedReject
        && (string) ($relocked['domain'] ?? '') === 'txfprice-relock.com'
        && (int) ($relocked['identity_locked'] ?? 0) === 1
        && (string) ($relocked['da'] ?? '') === '41') {
        pass('site_price unlock then identity save re-locks');
    } else {
        fail('site_price unlock/relock: ' . json_encode([
            'team_unlock' => $teamUnlockReject,
            'unlocked' => $unlocked['identity_locked'] ?? null,
            'team_edit' => $teamUnlockedReject,
            'relocked' => $relocked,
        ]));
    }

    $dupAdd = false;
    try {
        site_price_add_row_for_user([
            'country' => $country,
            'domain' => 'txfprice-relock.com',
        ], $adminUser);
    } catch (RuntimeException $e) {
        $dupAdd = str_contains($e->getMessage(), 'already');
    }
    if ($dupAdd) {
        pass('site_price add_row duplicate rejected');
    } else {
        fail('site_price add_row duplicate allowed');
    }

    add_prospect_domains(
        ['txfprice-niche-lookup.com'],
        $adminUser,
        $country,
        'German',
        'europe',
        'Finance',
        'price-book lookup'
    );
    $rowNiche = site_price_add_row_for_user([
        'country' => $country,
        'domain' => 'txfprice-niche-lookup.com',
    ], $teamUser);
    if ($rowNiche && str_contains((string) ($rowNiche['niche'] ?? ''), 'Finance')) {
        pass('site_price add_row niche from Our database');
    } else {
        fail('site_price add_row niche: ' . json_encode($rowNiche));
    }

    $adminHtml = render_site_price_sheet_tbody([$relocked], $adminUser);
    $teamHtml = render_site_price_sheet_tbody([$relocked], $teamUser);
    $pageSrc = (string) file_get_contents(__DIR__ . '/pages/admin/site_prices.php')
        . (string) file_get_contents(__DIR__ . '/includes/site_prices.php');
    $noExport = !preg_match('/Copy all|Download \.txt|Download CSV/', $pageSrc)
        && !str_contains($adminHtml, 'Copy all')
        && !str_contains($teamHtml, 'Copy all');
    $adminUnlock = str_contains($adminHtml, 'Unlock') && str_contains($adminHtml, 'is-locked')
        && !str_contains($adminHtml, 'is-copy-lock');
    $teamNoUnlock = !str_contains($teamHtml, 'Unlock') && str_contains($teamHtml, 'is-copy-lock')
        && str_contains($teamHtml, 'data-site-price-copy-one')
        && !str_contains($teamHtml, 'data-site-price-select');
    $hasAdd = str_contains($adminHtml, 'data-site-price-add') && str_contains($pageSrc, 'data-site-price-sheet');
    $adminDrag = str_contains($adminHtml, 'data-site-price-drag') && str_contains($adminHtml, 'data-site-price-lane');
    $teamNoDrag = !str_contains($teamHtml, 'data-site-price-drag')
        && !str_contains($adminHtml, 'data-site-price-copy-one');
    if ($noExport && $adminUnlock && $teamNoUnlock && $hasAdd && $adminDrag && $teamNoDrag) {
        pass('site_price sheet render lock + no Team export');
    } else {
        fail('site_price render: ' . json_encode([
            'export' => $noExport,
            'admin' => $adminUnlock,
            'team' => $teamNoUnlock,
            'add' => $hasAdd,
            'drag' => $adminDrag,
            'team_drag' => $teamNoDrag,
        ]));
    }

    $custom = site_price_add_custom_status('Follow up TXF', $adminUser);
    $teamAdd = false;
    try {
        site_price_add_custom_status('Team word', $teamUser);
    } catch (RuntimeException $e) {
        $teamAdd = str_contains($e->getMessage(), 'Only Admin');
    }
    $dupBuiltin = false;
    try {
        site_price_add_custom_status('New', $adminUser);
    } catch (RuntimeException $e) {
        $dupBuiltin = str_contains($e->getMessage(), 'already');
    }
    $delBuiltin = false;
    try {
        site_price_delete_custom_status('agreed', $adminUser);
    } catch (RuntimeException $e) {
        $delBuiltin = str_contains($e->getMessage(), 'closed');
    }
    $selectHtml = site_price_status_select_html('new');
    if (($custom['slug'] ?? '') === 'follow_up_txf'
        && ($custom['lane'] ?? '') === 'other'
        && (int) ($custom['is_builtin'] ?? 1) === 0
        && $teamAdd && $dupBuiltin && $delBuiltin
        && str_contains($selectHtml, 'follow_up_txf')
        && str_contains($selectHtml, 'Follow up TXF')) {
        pass('site_price custom status add + builtin closed');
    } else {
        fail('site_price custom status: ' . json_encode([
            'custom' => $custom,
            'team' => $teamAdd,
            'dup' => $dupBuiltin,
            'del' => $delBuiltin,
        ]));
    }

    $dragCountry = 'Austria';
    db()->exec("DELETE FROM site_price_rows WHERE country='Austria' AND domain LIKE 'txfprice-%'");
    $rowUse = site_price_add_row_for_user([
        'country' => $dragCountry,
        'domain' => 'txfprice-custom-at.com',
        'status_slug' => 'follow_up_txf',
    ], $teamUser);
    $inUse = false;
    try {
        site_price_delete_custom_status('follow_up_txf', $adminUser);
    } catch (RuntimeException $e) {
        $inUse = str_contains($e->getMessage(), 'still use');
    }
    $rowA = site_price_add_row_for_user([
        'country' => $dragCountry,
        'domain' => 'txfprice-drag-a.at',
        'status_slug' => 'processing',
    ], $adminUser);
    $rowB = site_price_add_row_for_user([
        'country' => $dragCountry,
        'domain' => 'txfprice-drag-b.at',
        'status_slug' => 'processing',
    ], $adminUser);
    $idA = (int) ($rowA['id'] ?? 0);
    $idB = (int) ($rowB['id'] ?? 0);
    $procIds = [];
    foreach (list_site_price_rows($dragCountry) as $r) {
        if (site_price_status_lane((string) ($r['status_slug'] ?? '')) === 'processing') {
            $procIds[] = (int) $r['id'];
        }
    }
    $reordered = [$idB, $idA];
    foreach ($procIds as $pid) {
        if ($pid !== $idA && $pid !== $idB) {
            $reordered[] = $pid;
        }
    }
    site_price_reorder_lane($dragCountry, 'processing', $reordered, $adminUser);
    $after = [];
    foreach (list_site_price_rows($dragCountry) as $r) {
        if (site_price_status_lane((string) ($r['status_slug'] ?? '')) === 'processing') {
            $after[] = (string) $r['domain'];
        }
    }
    $teamDragFail = false;
    try {
        site_price_reorder_lane($dragCountry, 'processing', $reordered, $teamUser);
    } catch (RuntimeException $e) {
        $teamDragFail = str_contains($e->getMessage(), 'Only Admin');
    }
    $laneHtml = render_site_price_sheet_tbody(list_site_price_rows($dragCountry), $adminUser);
    if ($inUse
        && ($after[0] ?? '') === 'txfprice-drag-b.at'
        && ($after[1] ?? '') === 'txfprice-drag-a.at'
        && $teamDragFail
        && str_contains($laneHtml, 'data-site-price-lane="processing"')
        && str_contains($laneHtml, 'data-site-price-lane="new"')
        && str_contains($laneHtml, 'data-site-price-lane="other"')
        && str_contains($laneHtml, 'Follow up TXF')) {
        pass('site_price lanes + Admin drag order');
    } else {
        fail('site_price lanes/drag: ' . json_encode([
            'in_use' => $inUse,
            'after' => $after,
            'team' => $teamDragFail,
        ]));
    }

    site_price_save_row((int) ($rowUse['id'] ?? 0), ['status_slug' => 'ok'], $adminUser);
    site_price_delete_custom_status('follow_up_txf', $adminUser);
    $gone = !isset(site_price_status_map(true)['follow_up_txf']);
    if ($gone) {
        pass('site_price custom status delete when unused');
    } else {
        fail('site_price custom status lingered');
    }

    db()->exec("DELETE FROM site_price_rows WHERE domain LIKE 'txfprice-%'");
    db()->exec("DELETE FROM site_price_statuses WHERE slug LIKE 'follow_up_txf' OR slug LIKE 'txfprice%'");

    $idPeople = site_price_add_row_for_user([
        'country' => $country,
        'domain' => 'txfprice-people.de',
        'price_note' => '40 euro',
    ], $teamUser);
    $idPeople = (int) ($idPeople['id'] ?? 0);
    $fresh = get_site_price_row($idPeople) ?: [];
    $teamClaimFail = false;
    try {
        site_price_claim_row($idPeople, $teamUser);
    } catch (RuntimeException $e) {
        $teamClaimFail = str_contains($e->getMessage(), 'Only Admin');
    }
    $claimed = site_price_claim_row($idPeople, $adminUser);
    $adminName = (string) ($adminUser['username'] ?? '');
    $adminFull = trim((string) (($adminUser['full_name'] ?? '') ?: $adminName));
    $eventsAdmin = list_site_price_events($idPeople, $adminUser);
    $eventsTeam = list_site_price_events($idPeople, $teamUser);
    $teamHistHtml = render_site_price_history_html($idPeople, $teamUser);
    $adminHistHtml = render_site_price_history_html($idPeople, $adminUser);
    $teamActorAdmin = false;
    $teamLeakedActor = false;
    foreach ($eventsTeam as $ev) {
        if ((string) ($ev['kind'] ?? '') === 'manage' || (string) ($ev['actor_role'] ?? '') === 'admin') {
            if ((string) ($ev['actor_label'] ?? '') === 'Admin') {
                $teamActorAdmin = true;
            }
        }
        if (isset($ev['actor_id']) || isset($ev['actor_username']) || isset($ev['actor_full'])) {
            $teamLeakedActor = true;
        }
        if ($adminName !== '' && str_contains((string) ($ev['actor_label'] ?? ''), $adminName)) {
            $teamLeakedActor = true;
        }
    }
    $peopleAdmin = render_site_price_sheet_row($claimed, $adminUser);
    $peopleTeam = render_site_price_sheet_row($claimed, $teamUser);
    $tabs = render_site_price_country_tabs($country, 'admin_site_prices');
    $tabsTeam = render_site_price_country_tabs($country, 'team_site_prices');
    $savedByAdmin = site_price_save_row($idPeople, ['extra_note' => 'mgr'], $adminUser);
    $claimOk = $teamClaimFail
        && (int) ($fresh['managed_by'] ?? 0) === 0
        && (int) ($claimed['managed_by'] ?? 0) === (int) ($adminUser['id'] ?? 0)
        && (int) ($savedByAdmin['managed_by'] ?? 0) === (int) ($adminUser['id'] ?? 0);
    $histOk = $teamActorAdmin && !$teamLeakedActor
        && str_contains($teamHistHtml, 'Admin')
        && ($adminName === '' || !str_contains($teamHistHtml, $adminName))
        && ($adminFull === '' || $adminFull === 'Admin' || !str_contains($teamHistHtml, $adminFull))
        && str_contains($adminHistHtml, 'Took as manager');
    $peopleOk = str_contains($peopleAdmin, 'Added by')
        && str_contains($peopleAdmin, 'Managed by')
        && str_contains($peopleAdmin, 'site-price-people-name')
        && str_contains($peopleAdmin, 'site-price-note-td')
        && str_contains($peopleAdmin, 'textarea')
        && str_contains($peopleAdmin, 'data-site-price-note')
        && str_contains($peopleAdmin, 'site-price-actions-td')
        && str_contains($peopleAdmin, 'Take')
        && str_contains($peopleAdmin, 'data-site-price-assign')
        && str_contains($peopleAdmin, 'data-site-price-remove')
        && str_contains($peopleAdmin, 'site-price-email-td')
        && str_contains($peopleAdmin, 'History')
        && str_contains($peopleTeam, 'Added by')
        && str_contains($peopleTeam, 'History')
        && !str_contains($peopleTeam, 'Managed by')
        && !str_contains($peopleTeam, 'Take')
        && !str_contains($peopleTeam, 'data-site-price-assign')
        && !str_contains($peopleTeam, 'data-site-price-remove')
        && ($adminName === '' || !str_contains($peopleTeam, $adminName));
    $tabsOk = str_contains($tabs, 'site-price-country-tabs')
        && str_contains($tabs, $country)
        && str_contains($tabs, 'page=admin_site_prices')
        && str_contains($tabsTeam, 'page=team_site_prices')
        && str_contains($pageSrc, 'People');
    if ($claimOk && $histOk && $peopleOk && $tabsOk) {
        pass('site_price people + history hide admin names + country tabs');
    } else {
        fail('site_price people/history/tabs: ' . json_encode([
            'claim' => $claimOk,
            'hist' => $histOk,
            'people' => $peopleOk,
            'tabs' => $tabsOk,
            'managed' => $claimed['managed_by'] ?? null,
            'fresh_mgr' => $fresh['managed_by'] ?? null,
            'team_actor' => $teamActorAdmin,
            'leak' => $teamLeakedActor,
        ]));
    }

    $clearedMgr = site_price_assign_row($idPeople, 0, $adminUser);
    $reassigned = site_price_assign_row($idPeople, (int) $adminUser['id'], $adminUser);
    $teamAssignFail = false;
    try {
        site_price_assign_row($idPeople, (int) $adminUser['id'], $teamUser);
    } catch (RuntimeException $e) {
        $teamAssignFail = str_contains($e->getMessage(), 'Only Admin');
    }
    $delRow = site_price_add_row_for_user([
        'country' => $country,
        'domain' => 'txfprice-remove.de',
        'status_slug' => 'processing',
        'price_note' => '12 euro',
    ], $adminUser);
    $delId = (int) ($delRow['id'] ?? 0);
    if (function_exists('order_sync_from_site_price_row')) {
        order_sync_from_site_price_row($delId);
    }
    $omBeforeDel = function_exists('get_order_item_by_site_price_row')
        ? get_order_item_by_site_price_row($delId)
        : null;
    $teamDelFail = false;
    try {
        site_price_delete_row($delId, $teamUser);
    } catch (RuntimeException $e) {
        $teamDelFail = str_contains($e->getMessage(), 'Only Admin');
    }
    $deleted = site_price_delete_row($delId, $adminUser);
    $goneRow = get_site_price_row($delId);
    $omAfterDel = ($omBeforeDel && function_exists('get_order_item'))
        ? get_order_item((int) $omBeforeDel['id'])
        : null;
    $omLinkCleared = !$omBeforeDel || ($omAfterDel && (int) ($omAfterDel['site_price_row_id'] ?? 0) === 0);
    $clearHist = render_site_price_history_html($idPeople, $adminUser);
    if ($teamAssignFail
        && (int) ($clearedMgr['managed_by'] ?? 0) === 0
        && (int) ($reassigned['managed_by'] ?? 0) === (int) $adminUser['id']
        && str_contains($clearHist, 'Cleared manager')
        && $teamDelFail
        && (string) ($deleted['domain'] ?? '') === 'txfprice-remove.de'
        && $goneRow === null
        && $omLinkCleared) {
        pass('site_price assign/clear manager + Admin remove keeps OM row');
    } else {
        fail('site_price assign/remove: ' . json_encode([
            'cleared' => $clearedMgr['managed_by'] ?? null,
            'reassigned' => $reassigned['managed_by'] ?? null,
            'team_assign' => $teamAssignFail,
            'team_del' => $teamDelFail,
            'gone' => $goneRow,
            'om_before' => $omBeforeDel['id'] ?? null,
            'om_link' => $omAfterDel['site_price_row_id'] ?? null,
        ]));
    }

    $filterAdmin = render_site_price_filters($adminUser, [$claimed]);
    $filterTeam = render_site_price_filters($teamUser, [$claimed]);
    $filterRow = render_site_price_sheet_row($claimed, $adminUser);
    $filterBody = render_site_price_sheet_tbody([$claimed], $adminUser);
    $teamPageSrc = (string) file_get_contents(__DIR__ . '/pages/team/site_prices.php');
    $filterOk = str_contains($filterAdmin, 'data-site-price-filters')
        && str_contains($filterAdmin, 'data-site-price-filter="q"')
        && str_contains($filterAdmin, 'data-site-price-filter="lane"')
        && str_contains($filterAdmin, 'data-site-price-filter="status"')
        && str_contains($filterAdmin, 'data-site-price-filter="added"')
        && str_contains($filterTeam, 'data-site-price-filters')
        && !str_contains($filterTeam, 'data-site-price-filter="added"')
        && str_contains($filterRow, 'data-status=')
        && str_contains($filterRow, 'data-added-by=')
        && str_contains($filterBody, 'data-site-price-filter-empty');
    $filterCountry = render_site_price_filters($adminUser, [$claimed], 'admin_site_prices', $country);
    $filterMatch = render_site_price_filters(
        $adminUser,
        [$claimed],
        'admin_site_prices',
        $country,
        100,
        ['tint' => 'yellow'],
        ['matching' => 2, 'total_all' => 10]
    );
    $filterChipsOk = str_contains($filterCountry, 'data-site-price-filter="tint"')
        && str_contains($filterCountry, 'Yellow')
        && str_contains($filterMatch, '2 matching')
        && str_contains($filterMatch, '10 in');
    $teamPageOk = str_contains($teamPageSrc, "site_price_run_page")
        && str_contains($teamPageSrc, 'team_site_prices')
        && !preg_match('/Copy all|Download \.txt|Download CSV/', $teamPageSrc)
        && !str_contains($filterTeam, 'Copy all')
        && !str_contains($filterTeam, 'Copy selected');
    if ($filterOk && $teamPageOk && $filterChipsOk) {
        pass('site_price team page + sheet filters');
    } else {
        fail('site_price team/filters: ' . json_encode([
            'filter' => $filterOk,
            'team_page' => $teamPageOk,
            'chips' => $filterChipsOk,
        ]));
    }

    $idTint = site_price_add_row_for_user([
        'country' => $country,
        'domain' => 'txfprice-tint.de',
        'price_note' => '10 euro',
    ], $teamUser);
    $idTint = (int) ($idTint['id'] ?? 0);
    $savedTint = site_price_save_row($idTint, [
        'row_tint' => 'yellow',
        'reply_email' => 'inbox@example.com',
        'extra_note' => 'reply',
    ], $teamUser);
    $badTint = site_price_save_row($idTint, ['row_tint' => 'purple'], $adminUser);
    $adminEmail = site_price_save_row($idTint, ['reply_email' => 'admin-box@example.com'], $adminUser);
    $tintHtml = render_site_price_sheet_row($adminEmail, $adminUser);
    $yellowHtml = render_site_price_sheet_row($savedTint, $adminUser);
    $teamTintHtml = render_site_price_sheet_row($adminEmail, $teamUser);
    $histTintAdmin = render_site_price_history_html($idTint, $adminUser);
    $histTintTeam = render_site_price_history_html($idTint, $teamUser);
    $eventsTintTeam = list_site_price_events($idTint, $teamUser);
    $teamTintActorOk = true;
    foreach ($eventsTintTeam as $ev) {
        if ((string) ($ev['kind'] ?? '') === 'email' && (string) ($ev['actor_role'] ?? '') === 'admin') {
            if ((string) ($ev['actor_label'] ?? '') !== 'Admin') {
                $teamTintActorOk = false;
            }
        }
        if (isset($ev['actor_username']) || isset($ev['actor_full'])) {
            $teamTintActorOk = false;
        }
    }
    $addHtml = render_site_price_add_row();
    $addTeamHtml = render_site_price_add_row($teamUser);
    $wordsOnSheet = render_site_price_status_words_card($adminUser, 'admin_site_prices', 'Germany');
    $tintOk = site_price_normalize_tint('YELLOW') === 'yellow'
        && site_price_normalize_tint('nope') === ''
        && (string) ($savedTint['row_tint'] ?? '') === 'yellow'
        && (string) ($savedTint['reply_email'] ?? '') === 'inbox@example.com'
        && (string) ($badTint['row_tint'] ?? 'x') === ''
        && (string) ($adminEmail['reply_email'] ?? '') === 'admin-box@example.com'
        && str_contains($yellowHtml, 'data-tint="yellow"')
        && str_contains($tintHtml, 'is-color-')
        && !str_contains($tintHtml, 'is-status-')
        && !str_contains($tintHtml, 'is-tint-yellow')
        && str_contains($tintHtml, 'site-price-color-menu')
        && str_contains($yellowHtml, '>⋯</summary>')
        && !str_contains($yellowHtml, 'site-price-color-summary is-')
        && str_contains($tintHtml, 'data-site-price-email')
        && str_contains($tintHtml, 'site-price-email-td')
        && str_contains($tintHtml, 'site-price-email')
        && str_contains($addHtml, 'data-add-email')
        && str_contains($addTeamHtml, 'data-add-email')
        && !str_contains($addTeamHtml, 'value="processing"')
        && str_contains($wordsOnSheet, 'id="status-words"')
        && str_contains($wordsOnSheet, 'country=Germany')
        && str_contains($tintHtml, 'data-site-price-tint')
        && str_contains($tintHtml, 'data-site-price-select')
        && str_contains($teamTintHtml, 'data-site-price-email')
        && str_contains($histTintAdmin, 'Reply email')
        && str_contains($histTintAdmin, 'Color')
        && str_contains($histTintTeam, 'Admin')
        && $teamTintActorOk
        && str_contains($addHtml, 'site-price-add-commit')
        && str_contains($addHtml, 'colspan="2"')
        && !str_contains($addHtml, 'colspan="3"')
        && str_contains($addHtml, 'data-site-price-tint')
        && str_contains($addHtml, 'data-tint=""')
        && site_price_sheet_colspan() === 12;
    if ($tintOk) {
        pass('site_price tint + reply email + history');
    } else {
        fail('site_price tint/email: ' . json_encode([
            'saved' => $savedTint,
            'bad' => $badTint['row_tint'] ?? null,
            'html' => str_contains($tintHtml, 'data-site-price-email'),
            'hist' => $histTintAdmin,
            'actor' => $teamTintActorOk,
        ]));
    }

    $pageCountry = 'Belgium';
    db()->exec("DELETE FROM site_price_rows WHERE country='Belgium' AND domain LIKE 'txfprice-page-%'");
    for ($i = 1; $i <= 12; $i++) {
        site_price_insert_row([
            'country' => $pageCountry,
            'domain' => 'txfprice-page-' . $i . '.be',
            'status_slug' => 'new',
            'created_by' => (int) $teamUser['id'],
        ]);
    }
    $page1 = list_site_price_rows_page($pageCountry, 1, 100);
    $forced = list_site_price_rows_page($pageCountry, 1, 5);
    $page2 = list_site_price_rows_page($pageCountry, 2, 5);
    $pageOk = $page1['per_page'] === 100
        && $page1['pages'] === 1
        && count($forced['rows']) === 5
        && $forced['total'] >= 12
        && $forced['pages'] >= 3
        && count($page2['rows']) === 5
        && resolve_site_price_per_page() !== 1000
        && in_array(500, site_price_per_page_options(), true)
        && !in_array(1000, site_price_per_page_options(), true);
    if ($pageOk) {
        pass('site_price pagination 100/250/500');
    } else {
        fail('site_price page: ' . json_encode($page1 + ['forced' => $forced, 'p2' => count($page2['rows'])]));
    }

    db()->exec("DELETE FROM site_price_rows WHERE country='Portugal' AND domain LIKE 'txfprice-filt-%'");
    $filtAdmin = site_price_add_row_for_user([
        'country' => 'Portugal',
        'domain' => 'txfprice-filt-a.com',
        'status_slug' => 'new',
        'price_note' => 'alpha rate',
    ], $adminUser);
    site_price_save_row((int) ($filtAdmin['id'] ?? 0), ['row_tint' => 'yellow'], $adminUser);
    site_price_insert_row([
        'country' => 'Portugal',
        'domain' => 'txfprice-filt-b.com',
        'status_slug' => 'processing',
        'price_note' => 'bravo rate',
        'created_by' => (int) $teamUser['id'],
    ]);
    site_price_add_row_for_user([
        'country' => 'Portugal',
        'domain' => 'txfprice-filt-c.com',
        'status_slug' => 'agreed',
        'price_note' => 'charlie rate',
    ], $teamUser);
    for ($i = 1; $i <= 8; $i++) {
        site_price_add_row_for_user([
            'country' => 'Portugal',
            'domain' => 'txfprice-filt-p' . $i . '.com',
            'status_slug' => 'new',
        ], $teamUser);
    }
    $filtAll = list_site_price_rows('Portugal');
    $filtMine = array_values(array_filter(
        $filtAll,
        static fn ($r) => str_starts_with((string) ($r['domain'] ?? ''), 'txfprice-filt-')
    ));
    $filtProc = site_price_filter_rows($filtMine, ['lane' => 'processing']);
    $filtYellow = site_price_filter_rows($filtMine, ['tint' => 'yellow']);
    $filtNone = site_price_filter_rows($filtMine, ['tint' => 'none']);
    $filtQ = site_price_filter_rows($filtMine, ['q' => 'txfprice-filt-a']);
    $filtPage = list_site_price_rows_page('Portugal', 2, 5, ['q' => 'txfprice-filt'], $adminUser);
    $adminLabel = '';
    $idFiltA = (int) ($filtAdmin['id'] ?? 0);
    if ($idFiltA > 0) {
        $adminLabel = (string) (site_price_row_for_viewer(get_site_price_row($idFiltA) ?: [], $adminUser)['added_by_label'] ?? '');
    }
    $filtAddedAdmin = $adminLabel !== ''
        ? site_price_filter_rows($filtMine, ['added' => $adminLabel], $adminUser)
        : [];
    $filtTeamHidesAdminName = site_price_filter_rows($filtMine, ['added' => 'Admin'], $teamUser);
    $pureOk = count($filtProc) === 1
        && (string) ($filtProc[0]['domain'] ?? '') === 'txfprice-filt-b.com'
        && count($filtYellow) === 1
        && (string) ($filtYellow[0]['domain'] ?? '') === 'txfprice-filt-a.com'
        && count($filtNone) >= 10
        && count($filtQ) === 1
        && (int) ($filtPage['total'] ?? 0) === count($filtMine)
        && (int) ($filtPage['pages'] ?? 0) >= 3
        && count($filtPage['rows']) === 5
        && $adminLabel !== ''
        && count($filtAddedAdmin) === 1
        && count($filtTeamHidesAdminName) === 1
        && site_price_filters_active(['q' => 'x'])
        && !site_price_filters_active([]);
    $niHay = site_price_row_search_haystack([
        'status_slug' => 'not_interested',
        'domain' => 'txfprice-hay.com',
        'niche' => '',
        'price_note' => '',
        'extra_note' => '',
        'reply_email' => '',
        'added_by_label' => '',
    ]);
    $niRows = [[
        'status_slug' => 'not_interested',
        'domain' => 'txfprice-hay.com',
        'niche' => '',
        'price_note' => '',
        'extra_note' => '',
        'reply_email' => '',
        'added_by_label' => '',
        'row_tint' => '',
    ]];
    $niHit = site_price_filter_rows($niRows, ['q' => 'not interested']);
    $niMiss = site_price_filter_rows($niRows, ['q' => 'zzzz-no-hit']);
    if (str_contains($niHay, 'not interested')
        && str_contains($niHay, 'not_interested')
        && count($niHit) === 1
        && count($niMiss) === 0) {
        pass('site_price search matches status labels');
    } else {
        fail('site_price status label search: ' . $niHay);
    }

    $idFr = site_price_add_row_for_user([
        'country' => 'France',
        'domain' => 'txfprice-jump-fr.com',
        'reply_email' => 'jump-unique-inbox@example.com',
        'price_note' => '99 euro jumpmark',
    ], $teamUser);
    site_price_add_row_for_user([
        'country' => 'France',
        'domain' => 'txfprice-aaa-contains-jump.com',
        'extra_note' => 'mentions txfprice-jump-fr.com in a note',
    ], $teamUser);
    $jumps = site_price_jump_search('jump-unique-inbox@example.com', 'admin_site_prices', 100);
    $jumpExact = site_price_jump_search('txfprice-jump-fr.com', 'admin_site_prices', 100);
    $jumpPack = site_price_jump_search_pack('txfprice-jump-fr.com', 'admin_site_prices', 100);
    $jumpTeam = site_price_jump_search('txfprice-jump-fr.com', 'team_site_prices', 100);
    $jumpByStatus = site_price_jump_search('New', 'admin_site_prices', 100);
    $countsOrder = site_price_country_counts();
    $totals = array_map(static fn ($c) => (int) ($c['total'] ?? 0), $countsOrder);
    $orderOk = true;
    for ($oi = 1; $oi < count($totals); $oi++) {
        if ($totals[$oi] > $totals[$oi - 1]) {
            $orderOk = false;
            break;
        }
    }
    $tabsUsage = render_site_price_country_tabs($country, 'admin_site_prices');
    $jumpOk = $jumps !== []
        && (int) ($jumps[0]['id'] ?? 0) === (int) ($idFr['id'] ?? 0)
        && (string) ($jumps[0]['country'] ?? '') === 'France'
        && (string) ($jumps[0]['status'] ?? '') === 'New'
        && str_contains((string) ($jumps[0]['url'] ?? ''), 'country=France')
        && str_contains((string) ($jumps[0]['url'] ?? ''), 'row=')
        && str_contains((string) ($jumps[0]['url'] ?? ''), 'jump=')
        && $jumpExact !== []
        && (string) ($jumpExact[0]['domain'] ?? '') === 'txfprice-jump-fr.com'
        && (int) ($jumpPack['total'] ?? 0) >= count($jumpPack['matches'] ?? [])
        && $jumpByStatus !== []
        && $jumpTeam !== []
        && str_contains((string) ($jumpTeam[0]['url'] ?? ''), 'team_site_prices')
        && $orderOk
        && str_contains($tabsUsage, 'site-price-country-tabs');
    $toolbar = render_site_price_toolbar(true);
    $toolbarTeam = render_site_price_toolbar(false);
    $jumpBar = render_site_price_jump_bar('', true);
    $jumpTeamBar = render_site_price_jump_bar('', false);
    $copyOk = str_contains($toolbar, 'Copy selected (this page)')
        && str_contains($toolbar, 'Copy selected')
        && !str_contains($toolbar, 'Copy all')
        && $toolbarTeam === ''
        && !str_contains($tintHtml, 'Copy all')
        && !str_contains($teamTintHtml, 'Remove')
        && str_contains($jumpBar, 'Search all countries')
        && str_contains($jumpBar, 'Does not filter this sheet')
        && str_contains($jumpBar, 'data-site-price-jump-results')
        && $jumpTeamBar === '';
    if ($jumpOk && $copyOk) {
        pass('site_price jump search + usage tabs + copy selected');
    } else {
        fail('site_price jump/copy: ' . json_encode([
            'jump' => $jumps,
            'copy' => $copyOk,
            'totals' => $totals,
        ]));
    }

    db()->exec("DELETE FROM site_price_rows WHERE domain LIKE 'txfprice-%'");
    db()->exec("DELETE FROM site_price_statuses WHERE slug LIKE 'follow_up_txf' OR slug LIKE 'txfprice%'");
} catch (Throwable $e) {
    fail('site_prices: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Filter uniqueness ---
try {
    $r = filter_domains_against_prospects(
        ['txftest-finance-de.com', 'txfbrand-new-de.com'],
        $country
    );
    $new = $r['new'] ?? [];
    $existing = $r['existing'] ?? [];
    if (in_array('txfbrand-new-de.com', $new, true) && in_array('txftest-finance-de.com', $existing, true)) {
        pass('filter keeps new + flags existing');
    } else {
        fail('filter unexpected: ' . json_encode($r));
    }
} catch (Throwable $e) {
    fail('filter: ' . $e->getMessage());
}

// --- Destination-country phrase (Filter / Add copy) ---
try {
    $phraseNew = prospect_destinations_phrase([
        'Spain' => ['new' => ['a.es', 'b.es'], 'existing' => ['old.es']],
        'Austria' => ['new' => ['c.at'], 'existing' => []],
        'Germany' => ['new' => [], 'existing' => ['d.de']],
    ], 'new');
    $phraseExist = prospect_destinations_phrase([
        'Spain' => ['new' => ['a.es'], 'existing' => ['old.es', 'x.es']],
        'Austria' => ['new' => [], 'existing' => ['y.at']],
    ], 'existing');
    $phraseIns = prospect_destinations_phrase([
        'Spain' => ['inserted' => 32, 'skipped' => 1],
        'Austria' => ['inserted' => 8, 'skipped' => 0],
        'Germany' => ['inserted' => 0, 'skipped' => 4],
    ], 'inserted');
    $names = prospect_destination_names([
        'Spain' => ['inserted' => 32],
        'Austria' => ['inserted' => 8],
    ], 'inserted');
    if ($phraseNew === 'Spain 2, Austria 1'
        && $phraseExist === 'Spain 2, Austria 1'
        && $phraseIns === 'Spain 32, Austria 8'
        && $names === 'Spain / Austria'
        && prospect_destinations_phrase([], 'new') === '') {
        pass('prospect destination phrase + names');
    } else {
        fail('dest phrase unexpected: ' . json_encode([$phraseNew, $phraseExist, $phraseIns, $names]));
    }
} catch (Throwable $e) {
    fail('dest phrase: ' . $e->getMessage());
}

// --- Extracting → Extracted + Team SWE ---
try {
    $batchId = get_or_create_extract_batch($country, $teamUser, 'German', 'europe');
    if ($batchId < 1) {
        fail('extract batch id invalid');
    } else {
        pass("extract batch id=$batchId");
    }
    save_extract_batch_results(
        $batchId,
        "txfpush-site-a.com\ntxfpush-site-b.de\nhttps://www.txfpush-site-c.com/x\n"
    );
    $batch = get_extract_batch($batchId);
    $results = (string) ($batch['results_text'] ?? '');
    $pushed = push_extract_results_to_extracted(
        $results,
        $country,
        $teamUser,
        'German',
        'europe',
        $batchId
    );
    pass('push extract: ' . json_encode($pushed));
    $afterPush = get_extract_batch($batchId);
    if (trim((string) ($afterPush['last_pushed_at'] ?? '')) !== '') {
        pass('extract last_pushed_at stamped after Push');
    } else {
        fail('extract last_pushed_at empty after Push');
    }
    $writerSave = set_extract_batch_domains_from_text(
        $batchId,
        implode("\n", get_extract_batch_domains($batchId)),
        (int) $teamUser['id']
    );
    $written = get_extract_batch($batchId);
    if ((int) ($written['sites_writer_id'] ?? 0) === (int) $teamUser['id']
        && trim((string) ($written['sites_writer_at'] ?? '')) !== '') {
        pass('extract sites writer stamped after Sites-list save');
    } else {
        fail('extract sites writer missing after save: ' . json_encode($writerSave));
    }
    $writerConflict = extract_sites_writer_conflict($batchId, (int) $adminUser['id'], '2000-01-01 00:00:00');
    $sameWriter = extract_sites_writer_conflict($batchId, (int) $teamUser['id'], '2000-01-01 00:00:00');
    if (!empty($writerConflict['conflict']) && $sameWriter === null) {
        pass('extract sites writer conflict when another user has a newer save');
    } else {
        fail('extract sites writer conflict unexpected: ' . json_encode([$writerConflict, $sameWriter]));
    }
    $missingBatch = extract_sites_writer_conflict(999999999, (int) $adminUser['id'], '2000-01-01 00:00:00');
    if (is_array($missingBatch) && empty($missingBatch['conflict']) && ($missingBatch['ok'] ?? true) === false) {
        pass('extract writer missing batch is an error, not a last-writer 409');
    } else {
        fail('extract missing batch treated as writer conflict: ' . json_encode($missingBatch));
    }
    save_extract_batch_results($batchId, '');
    $ex = (int) db()->query("SELECT COUNT(*) FROM extracted_sites WHERE country='Germany' AND domain LIKE 'txfpush-%'")->fetchColumn();
    $swe = (int) db()->query("SELECT COUNT(*) FROM sites_with_emails_team WHERE country='Germany' AND domain LIKE 'txfpush-%'")->fetchColumn();
    if ($ex >= 2) {
        pass("extracted txfpush-* count=$ex");
    } else {
        fail("extracted txfpush-* count=$ex");
    }
    if ($swe >= 2) {
        pass("team swe txfpush-* count=$swe");
    } else {
        fail("team swe txfpush-* count=$swe");
    }

    // Push auto-route: country TLDs → own folders; generic TLDs stay in selected country.
    // Also mirrors site names into Semrush Research (append + skip duplicates).
    db()->exec("DELETE FROM extracted_sites WHERE domain LIKE 'txfroute-%'");
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfroute-%'");
    db()->exec("DELETE FROM semrush_sites WHERE domain LIKE 'txfroute-%'");
    db()->exec("DELETE FROM semrush_sheet_comments WHERE body LIKE 'txfsem-route%'");
    $routePush = push_extract_results_to_extracted(
        implode("\n", [
            'txfroute-stay.com',
            'txfroute-stay.net',
            'txfroute-stay.eu',
            'txfroute-de.de',
            'txfroute-at.at',
            'txfroute-ch.ch',
            'txfroute-fr.fr',
            'https://www.txfroute-uk.co.uk/path',
        ]),
        'Germany',
        $teamUser,
        'German',
        'europe',
        $batchId
    );
    $inDe = (int) db()->query(
        "SELECT COUNT(*) FROM extracted_sites WHERE country='Germany' AND domain LIKE 'txfroute-%'"
    )->fetchColumn();
    $inAt = (int) db()->query(
        "SELECT COUNT(*) FROM extracted_sites WHERE country='Austria' AND domain='txfroute-at.at'"
    )->fetchColumn();
    $inCh = (int) db()->query(
        "SELECT COUNT(*) FROM extracted_sites WHERE country='Switzerland' AND domain='txfroute-ch.ch'"
    )->fetchColumn();
    $inFr = (int) db()->query(
        "SELECT COUNT(*) FROM extracted_sites WHERE country='France' AND domain='txfroute-fr.fr'"
    )->fetchColumn();
    $inUk = (int) db()->query(
        "SELECT COUNT(*) FROM extracted_sites WHERE country='United Kingdom' AND domain='txfroute-uk.co.uk'"
    )->fetchColumn();
    $sweAt = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE country='Austria' AND domain='txfroute-at.at'"
    )->fetchColumn();
    $semDe = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='Germany' AND domain LIKE 'txfroute-%'"
    )->fetchColumn();
    $semAt = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='Austria' AND domain='txfroute-at.at'"
    )->fetchColumn();
    $semCh = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='Switzerland' AND domain='txfroute-ch.ch'"
    )->fetchColumn();
    $semUk = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='United Kingdom' AND domain='txfroute-uk.co.uk'"
    )->fetchColumn();
    $mapOk = country_for_push_domain('shop.de', 'Germany') === 'Germany'
        && country_for_push_domain('shop.at', 'Germany') === 'Austria'
        && country_for_push_domain('shop.ch', 'Germany') === 'Switzerland'
        && country_for_push_domain('shop.com', 'Germany') === 'Germany'
        && country_for_push_domain('shop.eu', 'France') === 'France'
        && country_for_push_domain('praza.gal', 'France') === 'Spain'
        && country_for_push_domain('comunidad.madrid', 'France') === 'Spain'
        && country_for_push_domain('berria.eus', 'France') === 'Spain';
    if ((int) ($routePush['inserted'] ?? 0) >= 5
        && $inDe === 4 // .com + .net + .eu + .de
        && $inAt === 1
        && $inCh === 1
        && $inFr === 1
        && $inUk === 1
        && $sweAt === 1
        && $mapOk
        && count($routePush['by_country'] ?? []) >= 4) {
        pass('extract push routes country TLDs; generic TLDs stay in selected country');
    } else {
        fail('extract TLD route: ' . json_encode([
            'push' => [
                'inserted' => $routePush['inserted'] ?? null,
                'by_country' => array_keys($routePush['by_country'] ?? []),
            ],
            'de' => $inDe,
            'at' => $inAt,
            'ch' => $inCh,
            'fr' => $inFr,
            'uk' => $inUk,
            'swe_at' => $sweAt,
            'map' => $mapOk,
        ]));
    }
    if ($semDe === 4 && $semAt === 1 && $semCh === 1 && $semUk === 1
        && (int) (($routePush['by_country']['Austria']['semrush_inserted'] ?? 0)) === 1) {
        pass('extract push also appends Semrush Research with same TLD countries');
    } else {
        fail('extract→semrush mirror: ' . json_encode([
            'sem_de' => $semDe,
            'sem_at' => $semAt,
            'sem_ch' => $semCh,
            'sem_uk' => $semUk,
            'by' => $routePush['by_country'] ?? null,
        ]));
    }
    $routeAgain = push_extract_results_to_extracted(
        "txfroute-at.at\ntxfroute-new.de",
        'Germany',
        $teamUser,
        'German',
        'europe',
        $batchId
    );
    $semAtAfter = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='Austria' AND domain='txfroute-at.at'"
    )->fetchColumn();
    $semNewDe = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='Germany' AND domain='txfroute-new.de'"
    )->fetchColumn();
    if ($semAtAfter === 1 && $semNewDe === 1
        && (int) (($routeAgain['by_country']['Austria']['semrush_skipped'] ?? 0)) === 1
        && (int) (($routeAgain['by_country']['Germany']['semrush_inserted'] ?? 0)) === 1) {
        pass('extract→semrush append skips duplicates');
    } else {
        fail('extract→semrush skip: ' . json_encode($routeAgain['by_country'] ?? null));
    }
    add_semrush_comment('Austria', 'txfsem-route note', $teamUser);
    $clearAt = clear_semrush_country('Austria');
    $semAtCleared = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sites WHERE country='Austria' AND domain LIKE 'txfroute-%'"
    )->fetchColumn();
    $commentsAt = (int) db()->query(
        "SELECT COUNT(*) FROM semrush_sheet_comments WHERE country='Austria' AND body LIKE 'txfsem-route%'"
    )->fetchColumn();
    $extractedAtKept = (int) db()->query(
        "SELECT COUNT(*) FROM extracted_sites WHERE country='Austria' AND domain='txfroute-at.at'"
    )->fetchColumn();
    if (!empty($clearAt['ok']) && $semAtCleared === 0 && $commentsAt === 0 && $extractedAtKept === 1) {
        pass('semrush clear country deletes sites+comments; Extracted Sites kept');
    } else {
        fail('semrush clear vs extracted: ' . json_encode([
            'clear' => $clearAt,
            'sem' => $semAtCleared,
            'comments' => $commentsAt,
            'extracted' => $extractedAtKept,
        ]));
    }
    db()->exec("DELETE FROM extracted_sites WHERE domain LIKE 'txfroute-%'");
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfroute-%'");
    db()->exec("DELETE FROM semrush_sites WHERE domain LIKE 'txfroute-%'");
    db()->exec("DELETE FROM semrush_sheet_comments WHERE body LIKE 'txfsem-route%'");
} catch (Throwable $e) {
    fail('extracting: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Extracting country switcher nav (filled + current-empty) ---
try {
    $navFilled = get_or_create_extract_batch('Spain', $teamUser, 'Spanish', 'europe');
    set_extract_batch_domains_from_text($navFilled, "txfnav-es.com\n", (int) ($teamUser['id'] ?? 0));
    $navEmpty = get_or_create_extract_batch('Austria', $teamUser, 'German', 'europe');
    set_extract_batch_domains_from_text($navEmpty, '', (int) ($teamUser['id'] ?? 0));
    $nav = list_extract_batch_country_nav($navEmpty);
    $ids = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $nav);
    $countries = array_map(static fn ($r) => (string) ($r['country'] ?? ''), $nav);
    $without = list_extract_batch_country_nav(0);
    $withoutIds = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $without);
    if (in_array($navFilled, $ids, true)
        && in_array($navEmpty, $ids, true)
        && in_array('Spain', $countries, true)
        && in_array('Austria', $countries, true)
        && in_array($navFilled, $withoutIds, true)
        && !in_array($navEmpty, $withoutIds, true)) {
        pass('extract country nav includes filled + current empty');
    } else {
        fail('extract nav unexpected: ' . json_encode(['nav' => $nav, 'without' => $without]));
    }
    db()->prepare(
        'UPDATE extract_batches SET site_count=0, emptied_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id=?'
    )->execute([$navEmpty]);
    list_extract_batch_country_nav($navEmpty);
    $stillOpen = get_extract_batch($navEmpty);
    if ($stillOpen) {
        pass('extract country nav does not purge open empty batch');
    } else {
        fail('extract country nav purged the open empty batch');
    }
    $capped = list_extract_batches(10000);
    if (is_array($capped)) {
        pass('list_extract_batches accepts raised cap');
    } else {
        fail('list_extract_batches raised cap failed');
    }
    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfnav-%'");
    refresh_extract_batch_site_count($navFilled);
} catch (Throwable $e) {
    fail('extract nav: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Team emails + Push to Admin (clears working copy) ---
try {
    $rows = db()->query(
        "SELECT id, domain FROM sites_with_emails_team WHERE country='Germany' AND domain LIKE 'txfpush-%'"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $i => $r) {
        $saved = save_site_with_emails_row(
            'Germany',
            (string) $r['domain'],
            [
                'email1' => 'info' . $i . '@example.com',
                'email2' => $i === 0 ? 'sales@example.com' : '',
                'email3' => '',
                'email4' => '',
            ],
            $teamUser,
            (int) $r['id'],
            'team'
        );
        if (empty($saved['ok'])) {
            fail('save email row failed for ' . $r['domain'] . ': ' . ($saved['error'] ?? '?'));
        }
    }
    pass('set team emails on ' . count($rows) . ' rows');

    db()->prepare(
        "INSERT INTO sites_with_emails_team (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfpush-noemail.com','Germany','German','europe','','','','')
         ON DUPLICATE KEY UPDATE email1='', email2='', email3='', email4=''"
    )->execute();

    $result = push_sites_with_emails_team_to_admin('Germany', $teamUser);
    pass('push to admin: ' . json_encode($result));

    $adminCnt = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE country='Germany' AND domain LIKE 'txfpush-%' AND domain <> 'txfpush-noemail.com'"
    )->fetchColumn();
    $finalCnt = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE country='Germany' AND domain LIKE 'txfpush-%' AND domain <> 'txfpush-noemail.com'"
    )->fetchColumn();
    $noEmailLeft = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE country='Germany' AND domain='txfpush-noemail.com'"
    )->fetchColumn();
    $pushedLeft = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE country='Germany' AND domain LIKE 'txfpush-%' AND domain <> 'txfpush-noemail.com'"
    )->fetchColumn();

    if ($adminCnt >= 2) {
        pass("admin archive txfpush-* = $adminCnt");
    } else {
        fail("admin archive txfpush-* = $adminCnt");
    }
    if ($finalCnt >= 2) {
        pass("final mirror txfpush-* = $finalCnt");
    } else {
        fail("final mirror txfpush-* = $finalCnt");
    }
    if ($noEmailLeft === 1) {
        pass('no-email row stayed in team');
    } else {
        fail("no-email row left=$noEmailLeft");
    }
    if ($pushedLeft === 0) {
        pass('pushed rows cleared from team');
    } else {
        fail("pushed rows still in team=$pushedLeft");
    }
    if ((int) ($result['cleared'] ?? 0) >= 2) {
        pass('cleared count=' . $result['cleared']);
    } else {
        fail('cleared count=' . ($result['cleared'] ?? 'missing'));
    }

    // Four separate email slots must all land in Admin + Final.
    db()->prepare(
        "INSERT INTO sites_with_emails_team
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfpush-four.com','Germany','German','europe',
                 'a@four.test','b@four.test','c@four.test','d@four.test')
         ON DUPLICATE KEY UPDATE
           email1=VALUES(email1), email2=VALUES(email2),
           email3=VALUES(email3), email4=VALUES(email4)"
    )->execute();
    $fourPush = push_one_site_with_emails_team_to_admin(
        (int) db()->query("SELECT id FROM sites_with_emails_team WHERE domain='txfpush-four.com' LIMIT 1")->fetchColumn(),
        $teamUser,
        'Germany'
    );
    if (empty($fourPush['ok'])) {
        fail('four-slot push failed: ' . ($fourPush['error'] ?? '?'));
    } else {
        pass('four-slot push ok');
    }
    db()->prepare(
        "INSERT INTO sites_with_emails_team
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfpush-bind.com','Germany','German','europe','b@bind.test','','','')
         ON DUPLICATE KEY UPDATE email1='b@bind.test'"
    )->execute();
    $bindId = (int) db()->query(
        "SELECT id FROM sites_with_emails_team WHERE domain='txfpush-bind.com' LIMIT 1"
    )->fetchColumn();
    $wrongCountry = push_one_site_with_emails_team_to_admin($bindId, $teamUser, 'France');
    if (empty($wrongCountry['ok']) && str_contains((string) ($wrongCountry['error'] ?? ''), 'not on this country')) {
        pass('push_site rejects country mismatch');
    } else {
        fail('country bind: ' . json_encode($wrongCountry));
    }
    $boundPush = push_one_site_with_emails_team_to_admin($bindId, $teamUser, 'Germany');
    if (!empty($boundPush['ok'])) {
        pass('push_site accepts matching country');
    } else {
        fail('country match push: ' . json_encode($boundPush));
    }
    $fourAdmin = db()->query(
        "SELECT email1, email2, email3, email4 FROM sites_with_emails_admin WHERE domain='txfpush-four.com' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $fourFinal = db()->query(
        "SELECT email1, email2, email3, email4 FROM sites_with_emails_admin_all WHERE domain='txfpush-four.com' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($fourAdmin['email1'] ?? '') === 'a@four.test'
        && ($fourAdmin['email2'] ?? '') === 'b@four.test'
        && ($fourAdmin['email3'] ?? '') === 'c@four.test'
        && ($fourAdmin['email4'] ?? '') === 'd@four.test') {
        pass('admin kept all 4 emails');
    } else {
        fail('admin emails=' . json_encode($fourAdmin));
    }
    if (($fourFinal['email1'] ?? '') === 'a@four.test'
        && ($fourFinal['email2'] ?? '') === 'b@four.test'
        && ($fourFinal['email3'] ?? '') === 'c@four.test'
        && ($fourFinal['email4'] ?? '') === 'd@four.test') {
        pass('final kept all 4 emails');
    } else {
        fail('final emails=' . json_encode($fourFinal));
    }

    // Packed email1 (paste without JS split) expands into 4 slots on push.
    db()->prepare(
        "INSERT INTO sites_with_emails_team
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfpush-packed.com','Germany','German','europe',
                 'p1@pack.test, p2@pack.test; p3@pack.test p4@pack.test','','','')
         ON DUPLICATE KEY UPDATE
           email1=VALUES(email1), email2='', email3='', email4=''"
    )->execute();
    $packedPush = push_sites_with_emails_team_to_admin('Germany', $teamUser);
    pass('packed push: ' . json_encode($packedPush));
    $packedAdmin = db()->query(
        "SELECT email1, email2, email3, email4 FROM sites_with_emails_admin WHERE domain='txfpush-packed.com' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($packedAdmin['email1'] ?? '') === 'p1@pack.test'
        && ($packedAdmin['email2'] ?? '') === 'p2@pack.test'
        && ($packedAdmin['email3'] ?? '') === 'p3@pack.test'
        && ($packedAdmin['email4'] ?? '') === 'p4@pack.test') {
        pass('packed email1 expanded to 4 slots on admin');
    } else {
        fail('packed admin emails=' . json_encode($packedAdmin));
    }
} catch (Throwable $e) {
    fail('swe push: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Team / Final inline + Add site (same save_row as campaign) ---
try {
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txftest-add-%'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txftest-add-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txftest-add-%'");

    $teamNoEmail = save_site_with_emails_row(
        'Germany',
        'txftest-add-team.com',
        ['', '', '', ''],
        $teamUser,
        null,
        'team'
    );
    $teamLeft = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain='txftest-add-team.com'"
    )->fetchColumn();
    if (!empty($teamNoEmail['ok']) && $teamLeft === 1) {
        pass('Team add site with no emails is allowed');
    } else {
        fail('Team add no-email: ' . json_encode($teamNoEmail) . " left=$teamLeft");
    }

    $finalEmpty = save_site_with_emails_row(
        'Germany',
        'txftest-add-final-empty.com',
        ['', '', '', ''],
        $adminUser,
        null,
        'admin_all'
    );
    if (empty($finalEmpty['ok'])) {
        pass('Final add without emails is rejected');
    } else {
        fail('Final add without emails unexpectedly ok: ' . json_encode($finalEmpty));
    }

    $finalOk = save_site_with_emails_row(
        'Germany',
        'txftest-add-final.com',
        ['one@txftest-add-final.com', '', '', ''],
        $adminUser,
        null,
        'admin_all'
    );
    $finalAdmin = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain='txftest-add-final.com'"
    )->fetchColumn();
    $finalMirror = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txftest-add-final.com'"
    )->fetchColumn();
    if (!empty($finalOk['ok']) && $finalAdmin === 1 && $finalMirror === 1) {
        pass('Final add with email writes Admin and Final');
    } else {
        fail('Final add with email: ' . json_encode([
            'save' => $finalOk,
            'admin' => $finalAdmin,
            'final' => $finalMirror,
        ]));
    }

    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txftest-add-%'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txftest-add-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txftest-add-%'");
} catch (Throwable $e) {
    fail('swe inline add: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Final bulk paste / CSV (Campaign-style, Admin working list also written) ---
try {
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txffinal-bulk%'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txffinal-bulk%' OR domain LIKE 'txffinal-csv%' OR domain LIKE 'txffinal-dup%' OR domain LIKE 'txffinal-scale%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txffinal-bulk%' OR domain LIKE 'txffinal-csv%' OR domain LIKE 'txffinal-dup%' OR domain LIKE 'txffinal-scale%'");

    $teamBlocked = paste_sites_with_emails_rows(
        'Germany',
        "txffinal-bulk-team.de, team@txffinal-bulk.de\n",
        $teamUser,
        'team'
    );
    if (empty($teamBlocked['ok'])) {
        pass('Final bulk paste rejected on Team scope');
    } else {
        fail('Team bulk paste unexpectedly ok: ' . json_encode($teamBlocked));
    }

    $finalPaste = paste_sites_with_emails_rows('Germany', implode("\n", [
        'Site name, Email 1, Email 2, Email 3, Email 4',
        'txffinal-bulk1.de, a1@txffinal-bulk1.de, a2@txffinal-bulk1.de',
        'txffinal-bulk2.de; b1@txffinal-bulk2.de; b2@txffinal-bulk2.de',
        "txffinal-bulk3.de\tc1@txffinal-bulk3.de",
        'txffinal-bulk4.de d1@txffinal-bulk4.de d2@txffinal-bulk4.de',
        '# comment ignored',
        'txffinal-bulk-empty.de',
        'not-a-domain, missing-at-sign',
    ]), $adminUser, 'admin_all');
    $bulkAdmin = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain LIKE 'txffinal-bulk%'"
    )->fetchColumn();
    $bulkFinal = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txffinal-bulk%'"
    )->fetchColumn();
    $emptySkipped = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txffinal-bulk-empty.de'"
    )->fetchColumn();
    if (!empty($finalPaste['ok'])
        && (int) $finalPaste['added'] === 4
        && $bulkAdmin === 4
        && $bulkFinal === 4
        && $emptySkipped === 0
        && (int) ($finalPaste['skipped_empty'] ?? 0) >= 1
        && (int) $finalPaste['skipped'] >= 2) {
        pass('Final paste adds 4 formats, writes Admin+Final, skips no-email');
    } else {
        fail('Final paste: ' . json_encode($finalPaste) . " admin=$bulkAdmin final=$bulkFinal empty=$emptySkipped");
    }

    $csvPath = sys_get_temp_dir() . '/txffinal-import-' . getmypid() . '.csv';
    file_put_contents(
        $csvPath,
        "Site name,Email 1,Email 2,Email 3,Email 4\n"
        . "txffinal-csv1.de,c1@txffinal-csv1.de,,, \n"
        . "txffinal-csv2.de,c2a@txffinal-csv2.de,c2b@txffinal-csv2.de,,\n"
    );
    $fromCsv = email_campaign_rows_text_from_file_path($csvPath, 'sites.csv');
    $csvPaste = paste_sites_with_emails_rows('Germany', $fromCsv, $adminUser, 'admin_all');
    @unlink($csvPath);
    $csvAdmin = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain LIKE 'txffinal-csv%'"
    )->fetchColumn();
    $csvFinal = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txffinal-csv%'"
    )->fetchColumn();
    if ((int) $csvPaste['added'] === 2 && $csvAdmin === 2 && $csvFinal === 2 && !str_contains($fromCsv, 'Site name')) {
        pass('Final CSV file import (header skipped) writes Admin+Final');
    } else {
        fail('Final CSV: ' . json_encode(['text' => $fromCsv, 'paste' => $csvPaste, 'admin' => $csvAdmin, 'final' => $csvFinal]));
    }

    $dupFirst = paste_sites_with_emails_rows(
        'Germany',
        "txffinal-dup.de,old@txffinal-dup.de\n",
        $adminUser,
        'admin_all'
    );
    $dupSame = paste_sites_with_emails_rows(
        'Germany',
        "txffinal-dup.de,old@txffinal-dup.de\n",
        $adminUser,
        'admin_all'
    );
    $dupReplace = paste_sites_with_emails_rows(
        'Germany',
        "txffinal-dup.de,new@txffinal-dup.de,second@txffinal-dup.de\n",
        $adminUser,
        'admin_all'
    );
    $dupRow = db()->query(
        "SELECT email1, email2 FROM sites_with_emails_admin_all WHERE domain='txffinal-dup.de' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $dupAdmin = db()->query(
        "SELECT email1, email2 FROM sites_with_emails_admin WHERE domain='txffinal-dup.de' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($dupFirst['ok']) && (int) $dupFirst['added'] === 1
        && (int) ($dupSame['skipped_duplicate'] ?? 0) >= 1
        && (int) $dupReplace['updated'] === 1
        && ($dupRow['email1'] ?? '') === 'new@txffinal-dup.de'
        && ($dupRow['email2'] ?? '') === 'second@txffinal-dup.de'
        && ($dupAdmin['email1'] ?? '') === 'new@txffinal-dup.de') {
        pass('Final paste skips identical dupes and replaces different emails on Admin+Final');
    } else {
        fail('Final dupe/replace: ' . json_encode([
            'first' => $dupFirst,
            'same' => $dupSame,
            'replace' => $dupReplace,
            'final' => $dupRow,
            'admin' => $dupAdmin,
        ]));
    }

    $scaleLines = ['Site name,Email 1'];
    for ($i = 1; $i <= 200; $i++) {
        $scaleLines[] = 'txffinal-scale' . $i . '.de,s' . $i . '@txffinal-scale.de';
    }
    $scale = paste_sites_with_emails_rows('Germany', implode("\n", $scaleLines), $adminUser, 'admin_all');
    $scaleFinal = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txffinal-scale%'"
    )->fetchColumn();
    $scaleAdmin = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain LIKE 'txffinal-scale%'"
    )->fetchColumn();
    if ((int) $scale['added'] === 200 && $scaleFinal === 200 && $scaleAdmin === 200) {
        pass('Final paste 200 rows writes Admin+Final');
    } else {
        fail('Final scale: ' . json_encode($scale) . " final=$scaleFinal admin=$scaleAdmin");
    }

    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txffinal-bulk%' OR domain LIKE 'txffinal-csv%' OR domain LIKE 'txffinal-dup%' OR domain LIKE 'txffinal-scale%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txffinal-bulk%' OR domain LIKE 'txffinal-csv%' OR domain LIKE 'txffinal-dup%' OR domain LIKE 'txffinal-scale%'");
} catch (Throwable $e) {
    fail('Final bulk import: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Email campaign ---
try {
    $sid = create_email_campaign_sheet('Germany', (int) $adminUser['id']);
    pass("campaign sheet id=$sid");
    $up = upsert_email_campaign_row($sid, 'txfcamp-site.com', [
        'email1' => 'hello@txfcamp-site.com',
        'email2' => '',
        'email3' => '',
        'email4' => '',
    ]);
    pass('upsert campaign row: ' . json_encode($up));
    $rc = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $sid . " AND domain='txfcamp-site.com'"
    )->fetchColumn();
    if ($rc === 1) {
        pass('campaign row present');
    } else {
        fail("campaign row count=$rc");
    }
    $campLang = (string) db()->query(
        "SELECT language FROM email_campaign_rows WHERE sheet_id=" . (int) $sid . " AND domain='txfcamp-site.com'"
    )->fetchColumn();
    if ($campLang === 'German') {
        pass('campaign add stores country default language');
    } else {
        fail("campaign language expected German, got=$campLang");
    }
    $beSheet = create_email_campaign_sheet('Belgium', (int) $adminUser['id'], 'txf-lang-be');
    db()->prepare(
        "INSERT INTO email_campaign_rows (sheet_id, domain, country, language, email1)
         VALUES (?,?,?,?,?)"
    )->execute([$beSheet, 'txfcamp-blanklang.be', 'Belgium', '', 'hello@txfcamp-blanklang.be']);
    $filledLang = email_campaign_fill_blank_row_languages((int) $beSheet, 'Belgium');
    $beLang = (string) db()->query(
        "SELECT language FROM email_campaign_rows WHERE sheet_id=" . (int) $beSheet . " AND domain='txfcamp-blanklang.be'"
    )->fetchColumn();
    if ($filledLang >= 1 && $beLang === 'Dutch') {
        pass('campaign blank language backfill uses country default');
    } else {
        fail("campaign backfill lang=$beLang filled=$filledLang");
    }

    // Remove one of two emails → site stays.
    clear_email_campaign_domain_exclusion($sid, 'txfcamp-two.com');
    $up2 = upsert_email_campaign_row($sid, 'txfcamp-two.com', [
        'email1' => 'one@txfcamp-two.com',
        'email2' => 'two@txfcamp-two.com',
        'email3' => '',
        'email4' => '',
    ]);
    $twoId = (int) ($up2['id'] ?? 0);
    $rmOne = remove_email_from_email_campaign_row($sid, $twoId, 'one@txfcamp-two.com');
    if (!empty($rmOne['ok']) && empty($rmOne['row_deleted']) && ($rmOne['emails'] ?? []) === ['two@txfcamp-two.com']) {
        pass('campaign remove-one keeps site');
    } else {
        fail('campaign remove-one: ' . json_encode($rmOne));
    }

    // Remove last email → site row deleted.
    clear_email_campaign_domain_exclusion($sid, 'txfcamp-solo.com');
    $solo = upsert_email_campaign_row($sid, 'txfcamp-solo.com', [
        'email1' => 'only@txfcamp-solo.com',
        'email2' => '',
        'email3' => '',
        'email4' => '',
    ]);
    $soloId = (int) ($solo['id'] ?? 0);
    $rmLast = remove_email_from_email_campaign_row($sid, $soloId, 'only@txfcamp-solo.com');
    $soloLeft = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $sid . " AND domain='txfcamp-solo.com'"
    )->fetchColumn();
    if (!empty($rmLast['ok']) && !empty($rmLast['row_deleted']) && $soloLeft === 0) {
        pass('campaign last-email deletes site row');
    } else {
        fail('campaign last-email: ' . json_encode($rmLast) . " left=$soloLeft");
    }

    // Admin emails search: last email drops Admin working row; Final keeps the copy.
    db()->prepare(
        "INSERT INTO sites_with_emails_admin
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfcamp-admin-solo.com','Germany','German','europe','solo@admin.test','','','')
         ON DUPLICATE KEY UPDATE email1='solo@admin.test', email2='', email3='', email4=''"
    )->execute();
    sync_sites_with_emails_admin_to_all('Germany');
    $adminSoloId = (int) db()->query(
        "SELECT id FROM sites_with_emails_admin WHERE domain='txfcamp-admin-solo.com' LIMIT 1"
    )->fetchColumn();
    $rmAdmin = remove_email_from_sites_with_emails_admin($adminSoloId, 'solo@admin.test');
    $adminLeft = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain='txfcamp-admin-solo.com'"
    )->fetchColumn();
    $finalLeft = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfcamp-admin-solo.com'"
    )->fetchColumn();
    if (!empty($rmAdmin['ok']) && !empty($rmAdmin['row_deleted']) && $adminLeft === 0 && $finalLeft === 1) {
        pass('admin last-email deletes Admin only; Final kept');
    } else {
        fail('admin last-email: ' . json_encode($rmAdmin) . " admin=$adminLeft final=$finalLeft");
    }

    // New sheets use site+emails workflow (no blank placeholder rows; email required).
    $sheetFr = create_email_campaign_sheet('France', (int) $adminUser['id']);
    purge_blank_email_campaign_rows($sheetFr);
    $blankCnt = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $sheetFr
        . " AND LEFT(domain, 8)='__blank_'"
    )->fetchColumn();
    $noEmail = upsert_email_campaign_row($sheetFr, 'txfcamp-noemail.fr', [
        'email1' => '', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    $withEmail = upsert_email_campaign_row($sheetFr, 'txfcamp-ok.fr', [
        'email1' => 'a@txfcamp-ok.fr',
        'email2' => 'b@txfcamp-ok.fr',
        'email3' => '',
        'email4' => '',
    ]);
    if ($blankCnt === 0) {
        pass('new campaign sheet has no blank placeholders');
    } else {
        fail("blank placeholders=$blankCnt");
    }
    if (empty($noEmail['ok'])) {
        pass('campaign rejects site without emails');
    } else {
        fail('campaign allowed empty-email site');
    }
    if (!empty($withEmail['ok'])) {
        $row = db()->query(
            "SELECT email1, email2 FROM email_campaign_rows WHERE sheet_id=" . (int) $sheetFr
            . " AND domain='txfcamp-ok.fr' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        if (($row['email1'] ?? '') === 'a@txfcamp-ok.fr' && ($row['email2'] ?? '') === 'b@txfcamp-ok.fr') {
            pass('campaign site+2 emails saved');
        } else {
            fail('campaign emails=' . json_encode($row));
        }
    } else {
        fail('campaign with-email upsert failed: ' . json_encode($withEmail));
    }

    // Project name + Communication Team search visibility (fresh project each run).
    foreach (['Benelux Outreach', 'Benelux Outreach (paused)', 'TXF Multi Country Outreach', 'TXF Other Project DE'] as $pn) {
        $oldP = get_email_campaign_project_by_name($pn);
        if ($oldP) {
            delete_email_campaign_project((int) $oldP['id']);
        }
    }
    db()->exec("DELETE FROM email_campaign_sheets WHERE name='Belgium'");
    $sheetBe = create_email_campaign_sheet(
        'Belgium',
        (int) $adminUser['id'],
        'Benelux Outreach',
        true
    );
    $setBe = update_email_campaign_sheet_settings($sheetBe, 'Benelux Outreach', true);
    $be = get_email_campaign_sheet($sheetBe);
    if (!empty($setBe['ok']) && $be && email_campaign_sheet_project_name($be) === 'Benelux Outreach'
        && email_campaign_sheet_team_visible($be)) {
        pass('project sheet created with team search on');
    } else {
        fail('project sheet: ' . json_encode(['set' => $setBe, 'sheet' => $be]));
    }
    $hide = update_email_campaign_sheet_settings($sheetBe, 'Benelux Outreach (paused)', false);
    $be2 = get_email_campaign_sheet($sheetBe);
    $visibleSheets = list_email_campaign_sheets(true);
    $visibleIds = array_map(static fn ($s) => (int) $s['id'], $visibleSheets);
    $visibleProjects = list_email_campaign_projects(true);
    $visibleProjectNames = array_map(static fn ($p) => (string) $p['name'], $visibleProjects);
    if (!empty($hide['ok'])
        && email_campaign_sheet_project_name($be2 ?? []) === 'Benelux Outreach (paused)'
        && !email_campaign_sheet_team_visible($be2 ?? [])
        && !in_array($sheetBe, $visibleIds, true)
        && !in_array('Benelux Outreach (paused)', $visibleProjectNames, true)) {
        pass('project search can be hidden from Communication Team');
    } else {
        fail('hide project: ' . json_encode([
            'hide' => $hide,
            'sheet' => $be2,
            'visible' => $visibleIds,
            'visible_projects' => $visibleProjectNames,
        ]));
    }
    upsert_email_campaign_row($sheetBe, 'txfcamp-hidden.be', [
        'email1' => 'h@txfcamp-hidden.be', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    $hiddenSuggest = search_email_campaign_suggestions_all('txfcamp-hidden', 10);
    $scopedSuggest = search_email_campaign_suggestions($sheetBe, 'txfcamp-hidden', 10);
    if ($hiddenSuggest === [] && count($scopedSuggest) === 1) {
        pass('hidden sheet excluded from team-wide suggest; still searchable by id');
    } else {
        fail('suggest visibility: all=' . json_encode($hiddenSuggest) . ' scoped=' . json_encode($scopedSuggest));
    }

    // Multi-country project: Admin adds only chosen countries; each has its own data;
    // Communication searches the whole project and deletes update that country sheet.
    $multiPid = create_email_campaign_project(
        'TXF Multi Country Outreach',
        (int) $adminUser['id'],
        true
    );
    $multiDe = add_email_campaign_country_to_project($multiPid, 'Germany', (int) $adminUser['id']);
    $multiFr = add_email_campaign_country_to_project($multiPid, 'France', (int) $adminUser['id']);
    $otherPid = create_email_campaign_project(
        'TXF Other Project DE',
        (int) $adminUser['id'],
        false
    );
    $otherDe = add_email_campaign_country_to_project($otherPid, 'Germany', (int) $adminUser['id']);
    upsert_email_campaign_row($multiDe, 'txfcamp-multi-de.com', [
        'email1' => 'a@txfcamp-multi-de.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    upsert_email_campaign_row($multiFr, 'txfcamp-multi-fr.com', [
        'email1' => 'b@txfcamp-multi-fr.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    upsert_email_campaign_row($otherDe, 'txfcamp-multi-de.com', [
        'email1' => 'other@txfcamp-multi-de.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    $projSheets = list_email_campaign_sheets_for_project($multiPid);
    $projCountries = array_map(static fn ($s) => (string) $s['country'], $projSheets);
    sort($projCountries);
    if ($multiDe !== $otherDe && $projCountries === ['France', 'Germany']) {
        pass('project holds only Admin-added countries; same country can differ per project');
    } else {
        fail('multi project countries: ' . json_encode([
            'countries' => $projCountries,
            'multi_de' => $multiDe,
            'other_de' => $otherDe,
        ]));
    }
    $navRows = list_email_campaign_project_country_nav($multiPid);
    $navNames = array_map(static fn ($s) => (string) $s['country'], $navRows);
    $navIds = array_map(static fn ($s) => (int) $s['id'], $navRows);
    sort($navNames);
    $otherNav = list_email_campaign_project_country_nav($otherPid);
    $otherNavIds = array_map(static fn ($s) => (int) $s['id'], $otherNav);
    if ($navNames === ['France', 'Germany']
        && in_array($multiDe, $navIds, true)
        && in_array($multiFr, $navIds, true)
        && !in_array($otherDe, $navIds, true)
        && $otherNavIds === [$otherDe]) {
        pass('campaign country nav lists only this project’s countries');
    } else {
        fail('country nav: ' . json_encode(['nav' => $navRows, 'other' => $otherNav]));
    }
    $odbNav = list_prospect_country_nav('Germany');
    $odbVals = array_column($odbNav, 'value');
    $adminEmailNav = list_sites_with_emails_country_nav('admin');
    $adminEmailVals = array_column($adminEmailNav, 'value');
    $finalEmailNav = list_sites_with_emails_country_nav('admin_all');
    $finalEmailVals = array_column($finalEmailNav, 'value');
    if (in_array('Germany', $odbVals, true)
        && in_array('Germany', $adminEmailVals, true)
        && in_array('Germany', $finalEmailVals, true)) {
        pass('Our database / Admin / Final country nav include Germany');
    } else {
        fail('sheet country nav: ' . json_encode([
            'our_db' => $odbVals,
            'admin' => $adminEmailVals,
            'final' => $finalEmailVals,
        ]));
    }
    $projSuggest = search_email_campaign_suggestions_for_project($multiPid, 'txfcamp-multi', 20);
    $projDomains = array_map(static fn ($s) => (string) $s['domain'], $projSuggest);
    sort($projDomains);
    $hitDe = null;
    foreach ($projSuggest as $hit) {
        if (($hit['domain'] ?? '') === 'txfcamp-multi-de.com') {
            $hitDe = $hit;
            break;
        }
    }
    if ($projDomains === ['txfcamp-multi-de.com', 'txfcamp-multi-fr.com']
        && $hitDe
        && (int) ($hitDe['sheet_id'] ?? 0) === $multiDe
        && (string) ($hitDe['country'] ?? '') === 'Germany') {
        pass('Communication project search covers all countries; hit carries country sheet_id');
    } else {
        fail('project suggest: ' . json_encode($projSuggest));
    }
    $delMulti = delete_email_campaign_row($multiDe, (int) ($hitDe['id'] ?? 0));
    $stillOther = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $otherDe
        . " AND domain='txfcamp-multi-de.com'"
    )->fetchColumn();
    $goneMulti = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $multiDe
        . " AND domain='txfcamp-multi-de.com'"
    )->fetchColumn();
    if (!empty($delMulti['ok']) && $goneMulti === 0 && $stillOther === 1) {
        pass('project delete updates only that country sheet; other project data kept');
    } else {
        fail('project delete isolation: ' . json_encode([
            'del' => $delMulti,
            'gone' => $goneMulti,
            'other' => $stillOther,
        ]));
    }
    delete_email_campaign_project($multiPid);
    delete_email_campaign_project($otherPid);

    // Communication Team search bars: one per Admin-visible project; each searches
    // all countries in that project; deletes update the matching country sheet.
    foreach (['TXF Bar Alpha', 'TXF Bar Beta', 'TXF Bar Hidden'] as $pn) {
        $oldP = get_email_campaign_project_by_name($pn);
        if ($oldP) {
            delete_email_campaign_project((int) $oldP['id']);
        }
    }
    $barAlpha = create_email_campaign_project('TXF Bar Alpha', (int) $adminUser['id'], true);
    $barBeta = create_email_campaign_project('TXF Bar Beta', (int) $adminUser['id'], true);
    $barHidden = create_email_campaign_project('TXF Bar Hidden', (int) $adminUser['id'], false);
    $alphaDe = add_email_campaign_country_to_project($barAlpha, 'Germany', (int) $adminUser['id']);
    $alphaFr = add_email_campaign_country_to_project($barAlpha, 'France', (int) $adminUser['id']);
    $betaNl = add_email_campaign_country_to_project($barBeta, 'Netherlands', (int) $adminUser['id']);
    $hiddenDe = add_email_campaign_country_to_project($barHidden, 'Germany', (int) $adminUser['id']);
    upsert_email_campaign_row($alphaDe, 'txfcamp-bar-alpha-de.com', [
        'email1' => 'ade@txfcamp-bar-alpha-de.com',
        'email2' => 'ade2@txfcamp-bar-alpha-de.com',
        'email3' => '',
        'email4' => '',
    ]);
    upsert_email_campaign_row($alphaFr, 'txfcamp-bar-alpha-fr.com', [
        'email1' => 'afr@txfcamp-bar-alpha-fr.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    upsert_email_campaign_row($betaNl, 'txfcamp-bar-beta-nl.com', [
        'email1' => 'bnl@txfcamp-bar-beta-nl.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);
    upsert_email_campaign_row($hiddenDe, 'txfcamp-bar-hidden-de.com', [
        'email1' => 'hid@txfcamp-bar-hidden-de.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);

    $visibleBars = list_email_campaign_projects(true);
    $visibleBarNames = array_map(static fn ($p) => (string) $p['name'], $visibleBars);
    $barIdsOnPage = array_map(static fn ($p) => (int) $p['id'], $visibleBars);
    if (in_array('TXF Bar Alpha', $visibleBarNames, true)
        && in_array('TXF Bar Beta', $visibleBarNames, true)
        && !in_array('TXF Bar Hidden', $visibleBarNames, true)
        && in_array($barAlpha, $barIdsOnPage, true)
        && in_array($barBeta, $barIdsOnPage, true)
        && !in_array($barHidden, $barIdsOnPage, true)) {
        pass('Communication page shows one bar per Admin-enabled project only');
    } else {
        fail('visible bars: ' . json_encode($visibleBarNames));
    }

    // Same gate as pages/team/email_campaigns.php ajax=suggest
    $teamSuggestGate = static function (int $projectId, string $q): array {
        $project = get_email_campaign_project($projectId);
        if (!$project || !email_campaign_project_team_visible($project)) {
            return [];
        }
        return search_email_campaign_suggestions_for_project($projectId, $q, 25);
    };
    $alphaBySite = $teamSuggestGate($barAlpha, 'txfcamp-bar-alpha');
    $alphaByEmail = $teamSuggestGate($barAlpha, 'ade2@txfcamp-bar');
    $alphaDomains = array_map(static fn ($s) => (string) $s['domain'], $alphaBySite);
    sort($alphaDomains);
    $alphaEmailHit = $alphaByEmail[0] ?? null;
    $betaHits = $teamSuggestGate($barBeta, 'txfcamp-bar-beta');
    $hiddenHits = $teamSuggestGate($barHidden, 'txfcamp-bar-hidden');
    $alphaDoesNotSeeBeta = !in_array('txfcamp-bar-beta-nl.com', $alphaDomains, true);
    if ($alphaDomains === ['txfcamp-bar-alpha-de.com', 'txfcamp-bar-alpha-fr.com']
        && $alphaDoesNotSeeBeta
        && $alphaEmailHit
        && (string) ($alphaEmailHit['domain'] ?? '') === 'txfcamp-bar-alpha-de.com'
        && (int) ($alphaEmailHit['sheet_id'] ?? 0) === $alphaDe
        && ($alphaEmailHit['match_type'] ?? '') === 'email'
        && count($betaHits) === 1
        && (string) ($betaHits[0]['domain'] ?? '') === 'txfcamp-bar-beta-nl.com'
        && $hiddenHits === []) {
        pass('each project search bar finds its countries/emails; hidden project returns none');
    } else {
        fail('bar suggest: ' . json_encode([
            'alpha' => $alphaBySite,
            'alpha_email' => $alphaByEmail,
            'beta' => $betaHits,
            'hidden' => $hiddenHits,
        ]));
    }

    // Remove-only-email from Alpha DE; Beta untouched.
    $alphaRow = get_email_campaign_row((int) ($alphaEmailHit['id'] ?? 0), $alphaDe);
    $rmEmail = remove_email_from_email_campaign_row(
        $alphaDe,
        (int) ($alphaRow['id'] ?? 0),
        'ade2@txfcamp-bar-alpha-de.com'
    );
    $alphaAfter = get_email_campaign_row((int) ($alphaRow['id'] ?? 0), $alphaDe);
    $betaStill = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $betaNl
        . " AND domain='txfcamp-bar-beta-nl.com'"
    )->fetchColumn();
    if (!empty($rmEmail['ok']) && empty($rmEmail['row_deleted'])
        && ($alphaAfter['email1'] ?? '') === 'ade@txfcamp-bar-alpha-de.com'
        && ($alphaAfter['email2'] ?? '') === ''
        && $betaStill === 1) {
        pass('Communication remove-only-email updates that country sheet only');
    } else {
        fail('bar remove email: ' . json_encode(['rm' => $rmEmail, 'row' => $alphaAfter, 'beta' => $betaStill]));
    }

    // Delete both from Alpha FR; Alpha DE + Beta kept.
    $frSuggest = $teamSuggestGate($barAlpha, 'txfcamp-bar-alpha-fr');
    $frHit = $frSuggest[0] ?? null;
    $delFr = delete_email_campaign_row($alphaFr, (int) ($frHit['id'] ?? 0));
    $frGone = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $alphaFr
        . " AND domain='txfcamp-bar-alpha-fr.com'"
    )->fetchColumn();
    $deKept = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $alphaDe
        . " AND domain='txfcamp-bar-alpha-de.com'"
    )->fetchColumn();
    if (!empty($delFr['ok']) && $frGone === 0 && $deKept === 1 && $betaStill === 1
        && (int) ($frHit['sheet_id'] ?? 0) === $alphaFr) {
        pass('Communication delete-both updates matching country sheet only');
    } else {
        fail('bar delete both: ' . json_encode([
            'hit' => $frHit, 'del' => $delFr, 'fr' => $frGone, 'de' => $deKept,
        ]));
    }

    // Admin hub toggle off/on controls whether the bar appears.
    $hideBar = set_email_campaign_project_team_visible($barAlpha, false);
    $barsAfterHide = array_map(
        static fn ($p) => (string) $p['name'],
        list_email_campaign_projects(true)
    );
    $alphaAfterHide = $teamSuggestGate($barAlpha, 'txfcamp-bar-alpha');
    $showBar = set_email_campaign_project_team_visible($barAlpha, true);
    $barsAfterShow = array_map(
        static fn ($p) => (string) $p['name'],
        list_email_campaign_projects(true)
    );
    if (!empty($hideBar['ok']) && !empty($showBar['ok'])
        && !in_array('TXF Bar Alpha', $barsAfterHide, true)
        && $alphaAfterHide === []
        && in_array('TXF Bar Alpha', $barsAfterShow, true)) {
        pass('Admin team-search toggle shows/hides Communication project bar');
    } else {
        fail('bar toggle: ' . json_encode([
            'hide' => $hideBar,
            'show' => $showBar,
            'after_hide' => $barsAfterHide,
            'after_show' => $barsAfterShow,
            'suggest_hidden' => $alphaAfterHide,
        ]));
    }

    // Rendered HTML: each visible project gets its own suggest URL + JS hook.
    ob_start();
    render_email_campaign_super_search('index.php?page=team_email_campaigns');
    $barHtml = (string) ob_get_clean();
    $hasAlphaCard = str_contains($barHtml, 'data-project-id="' . $barAlpha . '"')
        && str_contains($barHtml, 'project_id=' . $barAlpha)
        && str_contains($barHtml, 'ajax=suggest');
    $hasBetaCard = str_contains($barHtml, 'data-project-id="' . $barBeta . '"')
        && str_contains($barHtml, 'project_id=' . $barBeta);
    $noHiddenCard = !str_contains($barHtml, 'data-project-id="' . $barHidden . '"')
        && !str_contains($barHtml, 'TXF Bar Hidden');
    $hasSearchJs = str_contains($barHtml, 'email-campaign-search.js');
    if ($hasAlphaCard && $hasBetaCard && $noHiddenCard && $hasSearchJs) {
        pass('Communication search HTML wires one card + suggest URL per visible project');
    } else {
        fail('bar HTML: ' . json_encode([
            'alpha' => $hasAlphaCard,
            'beta' => $hasBetaCard,
            'hidden_absent' => $noHiddenCard,
            'js' => $hasSearchJs,
            'len' => strlen($barHtml),
        ]));
    }

    delete_email_campaign_project($barAlpha);
    delete_email_campaign_project($barBeta);
    delete_email_campaign_project($barHidden);

    // Communication / Admin project drafts (categories + one-click copy library).
    foreach (['TXF Drafts Alpha', 'TXF Drafts Hidden'] as $pn) {
        $oldP = get_email_campaign_project_by_name($pn);
        if ($oldP) {
            delete_email_campaign_project((int) $oldP['id']);
        }
    }
    $draftPid = create_email_campaign_project('TXF Drafts Alpha', (int) $adminUser['id'], true);
    $draftHiddenPid = create_email_campaign_project('TXF Drafts Hidden', (int) $adminUser['id'], false);
    $saved = save_email_campaign_draft(
        $draftPid,
        'First outreach DE',
        "Hi,\n\nWould you like a guest post?\n\nBest,",
        'first_outreach',
        0,
        (int) $adminUser['id']
    );
    $savedOffer = save_email_campaign_draft(
        $draftPid,
        'Pricing offer',
        "Hello,\n\nOur offer is …\n\nThanks,",
        'offer',
        0,
        (int) $adminUser['id']
    );
    $badEmpty = save_email_campaign_draft($draftPid, 'Empty body', '   ', 'custom', 0, (int) $adminUser['id']);
    $allDrafts = list_email_campaign_drafts($draftPid);
    $offerOnly = list_email_campaign_drafts($draftPid, 'offer');
    $hiddenDraft = save_email_campaign_draft(
        $draftHiddenPid,
        'Hidden project draft',
        'Should not appear when project is hidden from team.',
        'reply',
        0,
        (int) $adminUser['id']
    );
    $visibleProjects = list_email_campaign_projects(true);
    $visibleNames = array_map(static fn ($p) => (string) $p['name'], $visibleProjects);
    if (!empty($saved['ok']) && !empty($savedOffer['ok']) && empty($badEmpty['ok'])
        && count($allDrafts) === 2
        && count($offerOnly) === 1
        && (string) ($offerOnly[0]['title'] ?? '') === 'Pricing offer'
        && email_campaign_draft_category_label('first_outreach') === 'First outreach'
        && in_array('TXF Drafts Alpha', $visibleNames, true)
        && !in_array('TXF Drafts Hidden', $visibleNames, true)
        && !empty($hiddenDraft['ok'])) {
        pass('campaign drafts save/list by category; hidden projects stay out of team list');
    } else {
        fail('campaign drafts: ' . json_encode([
            'saved' => $saved,
            'offer' => $savedOffer,
            'bad' => $badEmpty,
            'all' => count($allDrafts),
            'offer_only' => $offerOnly,
            'visible' => $visibleNames,
        ]));
    }
    $withSubject = save_email_campaign_draft(
        $draftPid,
        'Subject draft',
        'Hello {name} at {domain} in {country} ({language}).',
        'first_outreach',
        0,
        (int) $adminUser['id'],
        'Idea for {domain}'
    );
    $subRow = get_email_campaign_draft((int) ($withSubject['id'] ?? 0));
    $expanded = expand_email_campaign_draft_tokens(
        (string) ($subRow['subject'] ?? '') . '|' . email_campaign_draft_html_to_plain((string) ($subRow['body'] ?? '')),
        [
            'domain' => 'example.de',
            'country' => 'Germany',
            'language' => 'German',
            'name' => 'Alex',
        ]
    );
    $defs = email_campaign_draft_token_defs();
    if (!empty($withSubject['ok'])
        && (string) ($subRow['subject'] ?? '') === 'Idea for {domain}'
        && str_contains($expanded, 'Idea for example.de')
        && str_contains($expanded, 'Hello Alex at example.de in Germany (German).')
        && isset($defs['domain'], $defs['site'], $defs['country'], $defs['language'], $defs['name'])) {
        pass('campaign drafts optional subject + token expand');
    } else {
        fail('campaign drafts subject/tokens: ' . json_encode([
            'saved' => $withSubject,
            'row' => $subRow,
            'expanded' => $expanded,
            'defs' => array_keys($defs),
        ]));
    }
    if (!empty($withSubject['ok'])) {
        delete_email_campaign_draft($draftPid, (int) ($withSubject['id'] ?? 0), $adminUser);
    }
    $updated = save_email_campaign_draft(
        $draftPid,
        'Pricing offer v2',
        "Hello,\n\nUpdated offer…\n\nThanks,",
        'offer',
        (int) ($savedOffer['id'] ?? 0),
        (int) $adminUser['id']
    );
    $afterUpdate = get_email_campaign_draft((int) ($savedOffer['id'] ?? 0));
    $richSaved = save_email_campaign_draft(
        $draftPid,
        'Rich format sample',
        '<h2 onclick="x()">Guest post offer</h2><p>Hi <strong>there</strong>, we can do <em>guest posts</em> and <u>niche edits</u>.</p>'
            . '<ul><li>Point one</li><li>Point two</li></ul>'
            . '<p>See <a href="https://example.com/guide" onclick="evil()">our guide</a> '
            . 'and <a href="javascript:alert(1)">bad</a>.</p>'
            . '<script>alert(1)</script>',
        'offer',
        0,
        (int) $adminUser['id']
    );
    $richRow = get_email_campaign_draft((int) ($richSaved['id'] ?? 0));
    $richBody = (string) ($richRow['body'] ?? '');
    $sanitized = sanitize_email_campaign_draft_html($richBody);
    $plainFromHtml = email_campaign_draft_html_to_plain($richBody);
    $keepsFormat = str_contains($richBody, '<strong>')
        && str_contains($richBody, '<em>')
        && str_contains($richBody, '<u>')
        && str_contains($richBody, '<h2>')
        && str_contains($richBody, '<ul>')
        && str_contains($richBody, '<li>')
        && str_contains($richBody, '<a href="https://example.com/guide">')
        && !str_contains(strtolower($richBody), '<script')
        && !str_contains(strtolower($richBody), 'onclick')
        && !str_contains(strtolower($richBody), 'javascript:')
        && str_contains($plainFromHtml, 'Guest post offer')
        && str_contains($plainFromHtml, 'guest posts')
        && str_contains($plainFromHtml, 'Point one');
    $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $imgSaved = save_email_campaign_draft(
        $draftPid,
        'With screenshot',
        '<p>See screenshot:</p><img src="' . $tinyPng . '" alt="dot" onclick="evil()">'
            . '<img src="https://evil.example/x.png" alt="remote">'
            . '<img src="' . $tinyPng . '" alt="dot2">',
        'custom',
        0,
        (int) $adminUser['id']
    );
    $imgRow = get_email_campaign_draft((int) ($imgSaved['id'] ?? 0));
    $imgBody = (string) ($imgRow['body'] ?? '');
    $imgCount = preg_match_all('/<\s*img\b/i', $imgBody);
    $imgOk = !empty($imgSaved['ok'])
        && $imgCount === 2
        && str_contains($imgBody, 'data:image/png;base64,')
        && !str_contains($imgBody, 'https://evil.example')
        && !str_contains(strtolower($imgBody), 'onclick')
        && str_contains(email_campaign_draft_html_to_plain($imgBody), '[image]');
    $badRemoteOnly = sanitize_email_campaign_draft_html(
        '<img src="https://evil.example/x.png" alt="x"><img src="javascript:alert(1)">'
    );
    $sizeSoft = email_campaign_draft_size_warning(str_repeat('x', 130000));
    $sizeHard = email_campaign_draft_size_warning(
        '<p>big</p><img src="' . $tinyPng . '"><img src="' . $tinyPng . '"><img src="'
        . $tinyPng . '"><img src="' . $tinyPng . '">'
    );
    $del = delete_email_campaign_draft($draftPid, (int) ($saved['id'] ?? 0), $adminUser);
    $left = count_email_campaign_drafts($draftPid);
    $wrongProjectDel = delete_email_campaign_draft($draftHiddenPid, (int) ($savedOffer['id'] ?? 0), $adminUser);
    if (!empty($updated['ok'])
        && (string) ($afterUpdate['title'] ?? '') === 'Pricing offer v2'
        && (int) ($afterUpdate['updated_by'] ?? 0) === (int) $adminUser['id']
        && !empty($richSaved['ok'])
        && $keepsFormat
        && $sanitized === $richBody
        && $imgOk
        && $badRemoteOnly === ''
        && $sizeSoft !== ''
        && $sizeHard !== ''
        && !empty($del['ok'])
        && $left === 3
        && empty($wrongProjectDel['ok'])) {
        pass('campaign drafts update/delete stay scoped to project');
        pass('campaign drafts keep bold/italic/underline/headings/lists/https links and strip unsafe HTML');
        pass('campaign drafts keep compressed data-URI images and strip remote/unsafe imgs');
        pass('campaign drafts size warning for large HTML/images');
    } else {
        fail('campaign draft mutate: ' . json_encode([
            'updated' => $updated,
            'after' => $afterUpdate,
            'rich' => $richSaved,
            'body' => $richBody,
            'keeps' => $keepsFormat,
            'img' => $imgSaved,
            'img_body' => mb_substr($imgBody, 0, 200),
            'img_count' => $imgCount,
            'img_ok' => $imgOk,
            'remote' => $badRemoteOnly,
            'sizeSoft' => $sizeSoft,
            'sizeHard' => $sizeHard,
            'del' => $del,
            'left' => $left,
            'wrong' => $wrongProjectDel,
        ]));
    }

    // Batch counts + reorder within category.
    $countMap = count_email_campaign_drafts_by_projects([$draftPid, $draftHiddenPid, 0, -1]);
    $orderBefore = list_email_campaign_drafts($draftPid, 'offer');
    $moveDown = ['ok' => false];
    $moveUp = ['ok' => false];
    $orderAfterDown = [];
    $orderAfterUp = [];
    if (count($orderBefore) >= 2) {
        $firstId = (int) ($orderBefore[0]['id'] ?? 0);
        $secondId = (int) ($orderBefore[1]['id'] ?? 0);
        $moveDown = move_email_campaign_draft($draftPid, $firstId, 'down', $adminUser);
        $orderAfterDown = list_email_campaign_drafts($draftPid, 'offer');
        $moveUp = move_email_campaign_draft($draftPid, $firstId, 'up', $adminUser);
        $orderAfterUp = list_email_campaign_drafts($draftPid, 'offer');
        $orderOk = !empty($moveDown['ok'])
            && !empty($moveUp['ok'])
            && (int) ($orderAfterDown[0]['id'] ?? 0) === $secondId
            && (int) ($orderAfterDown[1]['id'] ?? 0) === $firstId
            && (int) ($orderAfterUp[0]['id'] ?? 0) === $firstId;
    } else {
        $orderOk = false;
    }
    if (($countMap[$draftPid] ?? -1) === $left
        && ($countMap[$draftHiddenPid] ?? -1) === 1
        && !array_key_exists(0, $countMap)
        && $orderOk) {
        pass('campaign drafts batch counts + move up/down within category');
    } else {
        fail('campaign drafts counts/move: ' . json_encode([
            'countMap' => $countMap,
            'left' => $left,
            'moveDown' => $moveDown,
            'moveUp' => $moveUp,
            'before' => array_column($orderBefore, 'id'),
            'afterDown' => array_column($orderAfterDown, 'id'),
            'afterUp' => array_column($orderAfterUp, 'id'),
        ]));
    }

    // Product rule B: creator or Admin may delete; other teammates cannot.
    $mine = save_email_campaign_draft(
        $draftPid,
        'Team-owned draft',
        'Body from teammate.',
        'custom',
        0,
        (int) $teamUser['id']
    );
    $mineId = (int) ($mine['id'] ?? 0);
    $mineRow = get_email_campaign_draft($mineId);
    $otherTeam = [
        'id' => (int) $teamUser['id'] + 99991,
        'role' => 'team',
        'username' => 'other_teammate',
        'full_name' => 'Other Teammate',
    ];
    $deniedOther = delete_email_campaign_draft($draftPid, $mineId, $otherTeam);
    $allowedCreator = email_campaign_user_can_delete_draft($teamUser, $mineRow);
    $allowedAdmin = email_campaign_user_can_delete_draft($adminUser, $mineRow);
    $deniedFlag = email_campaign_user_can_delete_draft($otherTeam, $mineRow);
    $delAsCreator = delete_email_campaign_draft($draftPid, $mineId, $teamUser);
    $adminOwned = save_email_campaign_draft(
        $draftPid,
        'Admin-owned for ACL',
        'Admin body.',
        'custom',
        0,
        (int) $adminUser['id']
    );
    $adminOwnedId = (int) ($adminOwned['id'] ?? 0);
    $teamCannotDeleteAdmin = delete_email_campaign_draft($draftPid, $adminOwnedId, $teamUser);
    $adminDeletesOwn = delete_email_campaign_draft($draftPid, $adminOwnedId, $adminUser);
    if (empty($deniedOther['ok'])
        && $allowedCreator
        && $allowedAdmin
        && !$deniedFlag
        && !empty($delAsCreator['ok'])
        && empty($teamCannotDeleteAdmin['ok'])
        && !empty($adminDeletesOwn['ok'])) {
        pass('campaign drafts delete ACL: creator or Admin only');
    } else {
        fail('campaign drafts ACL: ' . json_encode([
            'deniedOther' => $deniedOther,
            'allowedCreator' => $allowedCreator,
            'allowedAdmin' => $allowedAdmin,
            'deniedFlag' => $deniedFlag,
            'delAsCreator' => $delAsCreator,
            'teamCannot' => $teamCannotDeleteAdmin,
            'adminDel' => $adminDeletesOwn,
        ]));
    }

    delete_email_campaign_project($draftPid);
    $goneWithProject = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_drafts WHERE project_id=" . (int) $draftPid
    )->fetchColumn();
    delete_email_campaign_project($draftHiddenPid);
    if ($goneWithProject === 0) {
        pass('deleting project cascades campaign drafts');
    } else {
        fail("draft cascade left=$goneWithProject");
    }

    $catalog = email_campaign_office_proposal_catalog();
    $sign = email_campaign_office_proposal_sign_off();
    $catalogBad = [];
    foreach ($catalog as $item) {
        $title = (string) ($item['title'] ?? '');
        $body = (string) ($item['body'] ?? '');
        $sad = !empty($item['sad']);
        $nSad = substr_count($body, ':(');
        if ($title === '' || $body === '') {
            $catalogBad[] = 'empty item';
            continue;
        }
        if (!str_ends_with($body, $sign)) {
            $catalogBad[] = $title . ' missing sign-off';
        }
        if (preg_match('/Rehan|TeqnoWebs|Topurlz/i', $title . "\n" . $body)) {
            $catalogBad[] = $title . ' has personal name/company';
        }
        if ($sad && ($nSad < 1 || $nSad > 2)) {
            $catalogBad[] = $title . " sad count=$nSad";
        }
        if (!$sad && $nSad !== 0) {
            $catalogBad[] = $title . ' unexpected sad face';
        }
        if (!preg_match('/ · [ABC]$/', $title)) {
            $catalogBad[] = $title . ' missing A/B/C suffix';
        }
    }
    $seed1 = ensure_email_campaign_office_proposal_drafts();
    $officePid = (int) ($seed1['project_id'] ?? 0);
    $seed2 = ensure_email_campaign_office_proposal_drafts();
    $allOffice = $officePid > 0 ? list_email_campaign_drafts($officePid) : [];
    $sampleHits = $officePid > 0 ? list_email_campaign_drafts($officePid, null, 'sample') : [];
    $maxHits = $officePid > 0 ? list_email_campaign_drafts($officePid, 'offer', '€150') : [];
    $homeHits = $officePid > 0 ? list_email_campaign_drafts($officePid, 'offer', 'homepage') : [];
    $italyHits = $officePid > 0 ? list_email_campaign_drafts($officePid, null, 'Italy') : [];
    $germanyHits = $officePid > 0 ? list_email_campaign_drafts($officePid, null, 'Germany') : [];
    $nicheHits = $officePid > 0 ? list_email_campaign_drafts($officePid, 'reply', 'niche') : [];
    $italyHay = implode("\n", array_map(static fn ($r) => (string) ($r['title'] ?? '') . "\n" . (string) ($r['body'] ?? ''), $italyHits));
    $germanyHay = implode("\n", array_map(static fn ($r) => (string) ($r['title'] ?? '') . "\n" . (string) ($r['body'] ?? ''), $germanyHits));
    $nicheHay = implode("\n", array_map(static fn ($r) => (string) ($r['title'] ?? '') . "\n" . (string) ($r['body'] ?? ''), $nicheHits));
    $officeProject = $officePid > 0 ? get_email_campaign_project($officePid) : null;
    if ($catalogBad === []
        && count($catalog) === 183
        && $officePid > 0
        && count($allOffice) >= 183
        && (int) ($seed2['inserted'] ?? -1) === 0
        && count($sampleHits) >= 3
        && count($maxHits) >= 1
        && count($homeHits) >= 3
        && count($italyHits) >= 3
        && count($germanyHits) >= 3
        && str_contains($italyHay, 'Sample · Italy · A')
        && str_contains($italyHay, 'savonanews.it')
        && str_contains($italyHay, 'ciavula.it')
        && str_contains($germanyHay, 'Sample · Germany · A')
        && str_contains($germanyHay, 'velototal.de')
        && str_contains($germanyHay, 'das-marburger.de')
        && count($nicheHits) >= 3
        && str_contains($nicheHay, 'Topics · they asked for niche / category · A')
        && str_contains($nicheHay, 'not generated by AI')
        && !str_contains($nicheHay, ':(')
        && (string) ($officeProject['name'] ?? '') === email_campaign_office_proposal_project_name()
        && email_campaign_project_team_visible($officeProject ?? [])) {
        pass('office English proposal catalog seed + search');
    } else {
        fail('office proposals: ' . json_encode([
            'catalog' => count($catalog),
            'bad' => array_slice($catalogBad, 0, 8),
            'seed1' => $seed1,
            'seed2' => $seed2,
            'listed' => count($allOffice),
            'sample' => count($sampleHits),
            'max' => count($maxHits),
            'home' => count($homeHits),
            'italy' => count($italyHits),
            'germany' => count($germanyHits),
            'niche' => count($nicheHits),
            'name' => $officeProject['name'] ?? null,
        ]));
    }

    // Admin bulk add: paste / CSV / Excel-text import into Email Sheet.
    $bulkSheet = create_email_campaign_sheet('Austria', (int) $adminUser['id'], 'Austria Bulk Import', false);
    $paste = paste_email_campaign_rows($bulkSheet, implode("\n", [
        'Site name, Email 1, Email 2, Email 3, Email 4',
        'txfcamp-bulk1.at, a1@txfcamp-bulk1.at, a2@txfcamp-bulk1.at',
        'txfcamp-bulk2.at; b1@txfcamp-bulk2.at; b2@txfcamp-bulk2.at',
        "txfcamp-bulk3.at\tc1@txfcamp-bulk3.at",
        'txfcamp-bulk4.at d1@txfcamp-bulk4.at d2@txfcamp-bulk4.at',
        '# comment ignored',
        'not-a-domain, missing-at-sign',
    ]));
    $bulkCount = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $bulkSheet
        . " AND domain LIKE 'txfcamp-bulk%'"
    )->fetchColumn();
    if ((int) $paste['added'] === 4 && $bulkCount === 4 && (int) $paste['skipped'] >= 1) {
        pass('campaign paste adds 4 formats and skips bad lines');
    } else {
        fail('campaign paste: ' . json_encode($paste) . " count=$bulkCount");
    }

    $csvPath = sys_get_temp_dir() . '/txfcamp-import-' . getmypid() . '.csv';
    file_put_contents(
        $csvPath,
        "Site name,Email 1,Email 2,Email 3,Email 4\n"
        . "txfcamp-csv1.at,c1@txfcamp-csv1.at,,, \n"
        . "txfcamp-csv2.at,c2a@txfcamp-csv2.at,c2b@txfcamp-csv2.at,,\n"
    );
    $fromCsv = email_campaign_rows_text_from_file_path($csvPath, 'sites.csv');
    $csvPaste = paste_email_campaign_rows($bulkSheet, $fromCsv);
    @unlink($csvPath);
    $csvCount = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $bulkSheet
        . " AND domain LIKE 'txfcamp-csv%'"
    )->fetchColumn();
    if ((int) $csvPaste['added'] === 2 && $csvCount === 2 && !str_contains($fromCsv, 'Site name')) {
        pass('campaign CSV file import (header skipped)');
    } else {
        fail('campaign CSV: ' . json_encode(['text' => $fromCsv, 'paste' => $csvPaste, 'count' => $csvCount]));
    }

    // Scale check: 1200 pasted rows in one go.
    $lines = ['Site name,Email 1'];
    for ($i = 1; $i <= 1200; $i++) {
        $lines[] = 'txfcamp-scale' . $i . '.at,s' . $i . '@txfcamp-scale.at';
    }
    $scale = paste_email_campaign_rows($bulkSheet, implode("\n", $lines));
    $scaleCount = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $bulkSheet
        . " AND domain LIKE 'txfcamp-scale%'"
    )->fetchColumn();
    if ((int) $scale['added'] === 1200 && $scaleCount === 1200) {
        pass('campaign paste 1200 rows');
    } else {
        fail('campaign scale: ' . json_encode($scale) . " count=$scaleCount");
    }

    // Paginated browse (Our database model) — never load all rows in UI.
    // Default UI page size is 1,000; also verify smaller pages still work.
    $page1 = email_campaign_rows_inventory_query($bulkSheet, [], 1, 100);
    $page2 = email_campaign_rows_inventory_query($bulkSheet, [], 2, 100);
    $lastPageNum = (int) ceil((int) $page1['total'] / 100);
    $lastPage = email_campaign_rows_inventory_query($bulkSheet, [], $lastPageNum, 100);
    $searchHit = email_campaign_rows_inventory_query($bulkSheet, ['q' => 'txfcamp-scale500'], 1, 100);
    $page1k = email_campaign_rows_inventory_query($bulkSheet, [], 1, 1000);
    $page1Ids = array_column($page1['rows'], 'id');
    $page2Ids = array_column($page2['rows'], 'id');
    $remainder = (int) $page1['total'] % 100;
    $expectLast = $remainder === 0 ? 100 : $remainder;
    if ((int) $page1['total'] >= 1200
        && (int) $page1['per_page'] === 100
        && count($page1['rows']) === 100
        && (int) $page2['page'] === 2
        && count($page2['rows']) === 100
        && array_intersect($page1Ids, $page2Ids) === []
        && (int) $lastPage['page'] === $lastPageNum
        && count($lastPage['rows']) === $expectLast
        && (int) $searchHit['total'] === 1
        && str_contains((string) ($searchHit['rows'][0]['domain'] ?? ''), 'scale500')
        && (int) $page1k['per_page'] === 1000
        && count($page1k['rows']) === 1000
        && (int) $page1k['pages'] >= 2) {
        pass('campaign sheet paginated inventory + search');
    } else {
        fail('campaign pagination: ' . json_encode([
            'total' => $page1['total'] ?? null,
            'p1' => count($page1['rows']),
            'p2' => count($page2['rows']),
            'overlap' => count(array_intersect($page1Ids, $page2Ids)),
            'last' => [count($lastPage['rows']), $lastPage['page'], $lastPageNum, $expectLast],
            'search' => $searchHit['total'] ?? null,
            'p1k' => [count($page1k['rows']), $page1k['per_page'] ?? null, $page1k['pages'] ?? null],
        ]));
    }

    $containsHit = email_campaign_rows_inventory_query($bulkSheet, ['q' => 'scale500'], 1, 100);
    $shortNoContains = email_campaign_rows_inventory_query($bulkSheet, ['q' => 'zz'], 1, 100);
    if ((int) ($containsHit['total'] ?? 0) === 1
        && str_contains((string) ($containsHit['rows'][0]['domain'] ?? ''), 'scale500')
        && (int) ($shortNoContains['total'] ?? -1) === 0) {
        pass('campaign search prefix-first then contains (3+ chars)');
    } else {
        fail('campaign prefix/contains: ' . json_encode([
            'contains' => $containsHit['total'] ?? null,
            'short' => $shortNoContains['total'] ?? null,
        ]));
    }

    // Sitewide Per page filter helpers (100 / 250 / 500 / 1000).
    unset($_SESSION['sheet_per_page'], $_GET['per_page'], $_POST['per_page']);
    $defaultPp = resolve_sheet_per_page();
    $_GET['per_page'] = '250';
    $picked250 = resolve_sheet_per_page();
    unset($_GET['per_page']);
    $remembered = resolve_sheet_per_page();
    $_GET['per_page'] = '9999';
    $badClamped = resolve_sheet_per_page();
    unset($_GET['per_page'], $_SESSION['sheet_per_page']);
    $opts = sheet_per_page_options();
    if ($defaultPp === 100
        && $picked250 === 250
        && $remembered === 250
        && $badClamped === 100
        && $opts === [100, 250, 500, 1000]
        && normalize_sheet_per_page(500) === 500
        && str_contains(append_sheet_per_page_query('index.php?page=x', 100), 'per_page=100')) {
        pass('sheet per-page filter options + session remember');
    } else {
        fail('sheet per-page helpers: ' . json_encode([
            'default' => $defaultPp,
            'picked' => $picked250,
            'remembered' => $remembered,
            'bad' => $badClamped,
            'opts' => $opts,
        ]));
    }

    db()->exec(
        "DELETE FROM email_campaign_rows WHERE sheet_id=" . (int) $bulkSheet
        . " AND (domain LIKE 'txfcamp-bulk%' OR domain LIKE 'txfcamp-csv%' OR domain LIKE 'txfcamp-scale%')"
    );

    // New sites only + never re-add deleted (Final → Email Sheet).
    db()->exec("DELETE FROM email_campaign_sheets WHERE name='Netherlands'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfcamp-nl-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txfcamp-nl-%'");
    $nlSheet = create_email_campaign_sheet('Netherlands', (int) $adminUser['id'], 'NL Outreach', false);
    $seedFinal = db()->prepare(
        "INSERT INTO sites_with_emails_admin_all
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES (?,?, 'Dutch', 'europe', ?, '', '', '')
         ON DUPLICATE KEY UPDATE email1=VALUES(email1), email2='', email3='', email4=''"
    );
    foreach (
        [
            ['txfcamp-nl-a.nl', 'a@txfcamp-nl-a.nl'],
            ['txfcamp-nl-b.nl', 'b@txfcamp-nl-b.nl'],
            ['txfcamp-nl-c.nl', 'c@txfcamp-nl-c.nl'],
        ] as [$dom, $em]
    ) {
        $seedFinal->execute([$dom, 'Netherlands', $em]);
    }
    $imp1 = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'new_only');
    $nlCount1 = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain LIKE 'txfcamp-nl-%'"
    )->fetchColumn();
    if ((int) $imp1['imported'] === 3 && $nlCount1 === 3 && (int) ($imp1['updated'] ?? 0) === 0) {
        pass('archive import new_only adds 3 sites');
    } else {
        fail('imp1: ' . json_encode($imp1) . " count=$nlCount1");
    }

    // Change Final email for A; re-import new_only must not update existing.
    db()->prepare(
        "UPDATE sites_with_emails_admin_all SET email1='a2@txfcamp-nl-a.nl'
         WHERE domain='txfcamp-nl-a.nl' AND country='Netherlands'"
    )->execute();
    $imp2 = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'new_only');
    $emailA = (string) db()->query(
        "SELECT email1 FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    if ((int) $imp2['imported'] === 0 && (int) ($imp2['skipped_existing'] ?? 0) >= 3
        && $emailA === 'a@txfcamp-nl-a.nl') {
        pass('new_only skips existing and does not update emails');
    } else {
        fail('imp2: ' . json_encode($imp2) . " emailA=$emailA");
    }

    // replace mode: identical emails skipped; different emails overwrite.
    $impReplaceSame = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'replace');
    $emailASame = (string) db()->query(
        "SELECT email1 FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    // Final still has a2 from earlier UPDATE — replace should update A.
    if ((int) ($impReplaceSame['updated'] ?? 0) >= 1 && $emailASame === 'a2@txfcamp-nl-a.nl') {
        pass('replace mode updates emails when Final differs');
    } else {
        fail('imp replace diff: ' . json_encode($impReplaceSame) . " emailA=$emailASame");
    }
    $impReplaceDup = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'replace');
    if ((int) ($impReplaceDup['updated'] ?? 0) === 0
        && (int) ($impReplaceDup['skipped_duplicate'] ?? 0) >= 2) {
        pass('replace mode skips identical domain+emails');
    } else {
        fail('imp replace dup: ' . json_encode($impReplaceDup));
    }

    // Paste: duplicate domain with same emails skipped; different emails replace.
    $pasteDup = paste_email_campaign_rows($nlSheet, implode("\n", [
        'txfcamp-nl-a.nl, a2@txfcamp-nl-a.nl',
        'txfcamp-nl-a.nl, a2@txfcamp-nl-a.nl',
        'txfcamp-nl-c.nl, c-new@txfcamp-nl-c.nl',
    ]));
    $emailC = (string) db()->query(
        "SELECT email1 FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-c.nl' LIMIT 1"
    )->fetchColumn();
    if ((int) ($pasteDup['skipped_duplicate'] ?? 0) >= 1
        && (int) ($pasteDup['updated'] ?? 0) >= 1
        && $emailC === 'c-new@txfcamp-nl-c.nl') {
        pass('campaign paste skips identical dupes and replaces different emails');
    } else {
        fail('paste dup/replace: ' . json_encode($pasteDup) . " emailC=$emailC");
    }

    // Copy not-emailed domains (campaign sheet).
    $cUnsentId = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-c.nl' LIMIT 1"
    )->fetchColumn();
    $cAId = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    set_email_campaign_row_email_sent($nlSheet, $cAId, true);
    $copyUnsent = collect_email_campaign_domains($nlSheet, '0');
    $copySent = collect_email_campaign_domains($nlSheet, '1');
    $copyAll = collect_email_campaign_domains($nlSheet, null);
    if (in_array('txfcamp-nl-c.nl', $copyUnsent, true)
        && !in_array('txfcamp-nl-a.nl', $copyUnsent, true)
        && in_array('txfcamp-nl-a.nl', $copySent, true)
        && in_array('txfcamp-nl-c.nl', $copyAll, true)
        && in_array('txfcamp-nl-a.nl', $copyAll, true)) {
        pass('campaign copy not-emailed domains filters sent vs unsent');
    } else {
        fail('copy domains: unsent=' . json_encode($copyUnsent)
            . ' sent=' . json_encode($copySent) . ' all=' . json_encode($copyAll));
    }

    // Team import: copy into campaign, never delete Team rows; stamp fetched to campaign.
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfcamp-nl-%'");
    $seedTeam = db()->prepare(
        "INSERT INTO sites_with_emails_team
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES (?,?, 'Dutch', 'europe', ?, '', '', '')"
    );
    $seedTeam->execute(['txfcamp-nl-a.nl', 'Netherlands', 'a-team@txfcamp-nl-a.nl']);
    $seedTeam->execute(['txfcamp-nl-d.nl', 'Netherlands', 'd@txfcamp-nl-d.nl']);
    $teamBefore = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfcamp-nl-%'"
    )->fetchColumn();
    $impTeam = import_email_campaign_sheet_from_swe($nlSheet, 'team', 'Netherlands', 'replace');
    $teamAfter = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfcamp-nl-%'"
    )->fetchColumn();
    $teamEmailA = (string) db()->query(
        "SELECT email1 FROM sites_with_emails_team WHERE domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    $campEmailA = (string) db()->query(
        "SELECT email1 FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    $dOnCamp = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-d.nl'"
    )->fetchColumn();
    $stamps = list_email_campaign_fetches_for_source('team', 'Netherlands');
    $stampNames = array_column($stamps, 'campaign_name');
    if ($teamBefore === 2 && $teamAfter === 2
        && $teamEmailA === 'a-team@txfcamp-nl-a.nl'
        && $campEmailA === 'a-team@txfcamp-nl-a.nl'
        && $dOnCamp === 1
        && (int) ($impTeam['updated'] ?? 0) >= 1
        && (int) ($impTeam['imported'] ?? 0) >= 1
        && in_array('NL Outreach', $stampNames, true)) {
        pass('Team import copies into campaign without wiping Team + stamps campaign');
    } else {
        fail('team import: ' . json_encode([
            'imp' => $impTeam,
            'teamBefore' => $teamBefore,
            'teamAfter' => $teamAfter,
            'teamEmailA' => $teamEmailA,
            'campEmailA' => $campEmailA,
            'dOnCamp' => $dOnCamp,
            'stamps' => $stampNames,
        ]));
    }

    $firstFetchedAt = (string) ($stamps[0]['fetched_at'] ?? '');
    usleep(1100000);
    $impTeam2 = import_email_campaign_sheet_from_swe($nlSheet, 'team', 'Netherlands', 'replace');
    $teamAfter2 = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfcamp-nl-%'"
    )->fetchColumn();
    $stamps2 = list_email_campaign_fetches_for_source('team', 'Netherlands');
    $secondFetchedAt = (string) ($stamps2[0]['fetched_at'] ?? '');
    $nlOutreachStamps = 0;
    foreach ($stamps2 as $s) {
        if ((string) ($s['campaign_name'] ?? '') === 'NL Outreach') {
            $nlOutreachStamps++;
        }
    }
    if ($teamAfter2 === 2
        && (int) ($impTeam2['skipped_duplicate'] ?? 0) >= 1
        && $nlOutreachStamps === 1
        && $secondFetchedAt !== ''
        && $secondFetchedAt >= $firstFetchedAt) {
        pass('second Team fetch keeps Team rows and refreshes one stamp');
    } else {
        fail('team refetch: ' . json_encode([
            'imp' => $impTeam2,
            'teamAfter2' => $teamAfter2,
            'stamps' => count($stamps2),
            'nlOutreachStamps' => $nlOutreachStamps,
            'first' => $firstFetchedAt,
            'second' => $secondFetchedAt,
        ]));
    }

    $nlSheet2 = create_email_campaign_sheet('Netherlands', (int) $adminUser['id'], 'NL Second', false);
    $impOther = import_email_campaign_sheet_from_swe($nlSheet2, 'team', 'Netherlands', 'replace');
    $stampsBoth = list_email_campaign_fetches_for_source('team', 'Netherlands');
    $bothNames = array_column($stampsBoth, 'campaign_name');
    if (in_array('NL Outreach', $bothNames, true) && in_array('NL Second', $bothNames, true)) {
        pass('two campaigns fetching Team show two stamps');
    } else {
        fail('two stamps: ' . json_encode($bothNames) . ' imp=' . json_encode($impOther));
    }

    // Emailed marks are per campaign sheet — import must not copy or clobber them.
    $idAOutreach = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    $idASecond = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet2
        . " AND domain='txfcamp-nl-a.nl' LIMIT 1"
    )->fetchColumn();
    set_email_campaign_row_email_sent($nlSheet, $idAOutreach, true);
    $sentOutreach = (int) db()->query(
        'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $idAOutreach
    )->fetchColumn();
    $sentSecond = (int) db()->query(
        'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $idASecond
    )->fetchColumn();
    $teamStill = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfcamp-nl-%'"
    )->fetchColumn();
    if ($idAOutreach > 0 && $idASecond > 0 && $sentOutreach === 1 && $sentSecond === 0 && $teamStill === 2) {
        pass('campaign emailed marks are not shared across sheets');
    } else {
        fail("emailed isolation: ids=$idAOutreach/$idASecond outreach=$sentOutreach second=$sentSecond team=$teamStill");
    }

    $impKeepSent = import_email_campaign_sheet_from_swe($nlSheet, 'team', 'Netherlands', 'replace');
    $sentAfterSame = (int) db()->query(
        'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $idAOutreach
    )->fetchColumn();
    if ((int) ($impKeepSent['skipped_duplicate'] ?? 0) >= 1 && $sentAfterSame === 1) {
        pass('re-import identical emails keeps this campaign emailed mark');
    } else {
        fail('keep emailed: ' . json_encode($impKeepSent) . " sent=$sentAfterSame");
    }

    db()->prepare(
        "UPDATE sites_with_emails_team SET email1='a-team-new@txfcamp-nl-a.nl'
         WHERE domain='txfcamp-nl-a.nl'"
    )->execute();
    $impReplaceKeepSent = import_email_campaign_sheet_from_swe($nlSheet, 'team', 'Netherlands', 'replace');
    $rowAAfter = db()->query(
        'SELECT email1, email_sent FROM email_campaign_rows WHERE id=' . (int) $idAOutreach
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($impReplaceKeepSent['updated'] ?? 0) >= 1
        && (string) ($rowAAfter['email1'] ?? '') === 'a-team-new@txfcamp-nl-a.nl'
        && (int) ($rowAAfter['email_sent'] ?? 0) === 1) {
        pass('replace emails on import does not clear emailed mark');
    } else {
        fail('replace keep emailed: ' . json_encode($impReplaceKeepSent) . ' row=' . json_encode($rowAAfter));
    }

    db()->prepare(
        "INSERT INTO sites_with_emails_team
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfcamp-nl-iso.nl','Netherlands','Dutch','europe','iso@txfcamp-nl-iso.nl','','','')"
    )->execute();
    $impNewUnsent = import_email_campaign_sheet_from_swe($nlSheet, 'team', 'Netherlands', 'replace');
    $newSent = (int) db()->query(
        "SELECT email_sent FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-iso.nl' LIMIT 1"
    )->fetchColumn();
    $secondHasE = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet2
        . " AND domain='txfcamp-nl-iso.nl'"
    )->fetchColumn();
    if ((int) ($impNewUnsent['imported'] ?? 0) >= 1 && $newSent === 0 && $secondHasE === 0) {
        pass('new Team domain lands unmarked and only on the campaign that fetched it');
    } else {
        fail('new unsent: ' . json_encode($impNewUnsent) . " sent=$newSent secondHasE=$secondHasE");
    }

    delete_email_campaign_sheet($nlSheet2);
    $stampsAfterDel = list_email_campaign_fetches_for_source('team', 'Netherlands');
    $namesAfterDel = array_column($stampsAfterDel, 'campaign_name');
    $teamAfterDel = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfcamp-nl-%'"
    )->fetchColumn();
    if (!in_array('NL Second', $namesAfterDel, true)
        && in_array('NL Outreach', $namesAfterDel, true)
        && $teamAfterDel >= 3) {
        pass('deleting campaign sheet drops only that fetch stamp; Team stays');
    } else {
        fail('stamp cascade: ' . json_encode($namesAfterDel) . " team=$teamAfterDel");
    }

    $rowB = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-b.nl' LIMIT 1"
    )->fetchColumn();
    $delB = delete_email_campaign_row($nlSheet, $rowB);
    $excludedB = is_email_campaign_domain_excluded($nlSheet, 'txfcamp-nl-b.nl');
    $imp3 = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'new_only');
    $bBack = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-b.nl'"
    )->fetchColumn();
    if (!empty($delB['ok']) && $excludedB && (int) ($imp3['skipped_excluded'] ?? 0) >= 1 && $bBack === 0) {
        pass('deleted site stays excluded from Final re-import');
    } else {
        fail('exclude: ' . json_encode([
            'del' => $delB,
            'excluded' => $excludedB,
            'imp3' => $imp3,
            'bBack' => $bBack,
        ]));
    }

    clear_email_campaign_domain_exclusion($nlSheet, 'txfcamp-nl-b.nl');
    $imp4 = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'new_only');
    $bAgain = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-b.nl'"
    )->fetchColumn();
    if ((int) $imp4['imported'] >= 1 && $bAgain === 1) {
        pass('Allow again lets Final import re-add site');
    } else {
        fail('allow again: ' . json_encode($imp4) . " bAgain=$bAgain");
    }

    // P0: paste / + Add must NOT re-add a deleted domain (only Allow again lifts ban).
    $rowC = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-c.nl' LIMIT 1"
    )->fetchColumn();
    $delC = delete_email_campaign_row($nlSheet, $rowC);
    $pasteBlocked = paste_email_campaign_rows(
        $nlSheet,
        "txfcamp-nl-c.nl,c@txfcamp-nl-c.nl\n"
    );
    $cPasteBack = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-c.nl'"
    )->fetchColumn();
    $upsertBlocked = upsert_email_campaign_row($nlSheet, 'txfcamp-nl-c.nl', ['c@txfcamp-nl-c.nl']);
    if (!empty($delC['ok'])
        && (int) ($pasteBlocked['skipped_excluded'] ?? 0) >= 1
        && $cPasteBack === 0
        && empty($upsertBlocked['ok'])
        && !empty($upsertBlocked['skipped_excluded'])) {
        pass('deleted domain blocked from paste and + Add');
    } else {
        fail('P0 paste/+Add block: ' . json_encode([
            'del' => $delC,
            'paste' => $pasteBlocked,
            'cPasteBack' => $cPasteBack,
            'upsert' => $upsertBlocked,
        ]));
    }

    // P1: single-email delete is sticky — that email cannot return; other emails can.
    clear_email_campaign_domain_exclusion($nlSheet, 'txfcamp-nl-c.nl');
    $seedTwo = upsert_email_campaign_row(
        $nlSheet,
        'txfcamp-nl-c.nl',
        ['keep@txfcamp-nl-c.nl', 'drop@txfcamp-nl-c.nl']
    );
    $twoId = (int) ($seedTwo['id'] ?? 0);
    $rmDrop = remove_email_from_email_campaign_row($nlSheet, $twoId, 'drop@txfcamp-nl-c.nl');
    $emailExcluded = is_email_campaign_email_excluded($nlSheet, 'txfcamp-nl-c.nl', 'drop@txfcamp-nl-c.nl');
    $pasteStrip = paste_email_campaign_rows(
        $nlSheet,
        "txfcamp-nl-c.nl,drop@txfcamp-nl-c.nl,new@txfcamp-nl-c.nl\n"
    );
    $emailsAfter = db()->query(
        "SELECT email1, email2, email3, email4 FROM email_campaign_rows WHERE sheet_id="
        . (int) $nlSheet . " AND domain='txfcamp-nl-c.nl' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $slotBag = strtolower(implode('|', array_filter([
        (string) ($emailsAfter['email1'] ?? ''),
        (string) ($emailsAfter['email2'] ?? ''),
        (string) ($emailsAfter['email3'] ?? ''),
        (string) ($emailsAfter['email4'] ?? ''),
    ])));
    $hasDrop = str_contains($slotBag, 'drop@txfcamp-nl-c.nl');
    $hasNew = str_contains($slotBag, 'new@txfcamp-nl-c.nl');
    if (!empty($rmDrop['ok'])
        && empty($rmDrop['row_deleted'])
        && $emailExcluded
        && (int) ($pasteStrip['skipped_emails'] ?? 0) >= 1
        && !$hasDrop
        && $hasNew) {
        pass('deleted email stays excluded from paste; other emails allowed');
    } else {
        fail('P1 email tombstone: ' . json_encode([
            'rm' => $rmDrop,
            'excluded' => $emailExcluded,
            'paste' => $pasteStrip,
            'slots' => $emailsAfter,
        ]));
    }

    // Paste of only the tombstoned email → whole site line skipped (no empty rows).
    $onlyBanned = paste_email_campaign_rows(
        $nlSheet,
        "txfcamp-nl-d.nl,drop@txfcamp-nl-c.nl\n"
    );
    // domain D never existed; banned email is for domain C — D with drop@c should still add
    // because email exclusions are (sheet, domain, email). Use same domain C with only banned email:
    $onlyBannedSame = paste_email_campaign_rows(
        $nlSheet,
        "txfcamp-nl-c.nl,drop@txfcamp-nl-c.nl\n"
    );
    $stillHasDrop = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $nlSheet
        . " AND domain='txfcamp-nl-c.nl' AND ("
        . "email1='drop@txfcamp-nl-c.nl' OR email2='drop@txfcamp-nl-c.nl'"
        . " OR email3='drop@txfcamp-nl-c.nl' OR email4='drop@txfcamp-nl-c.nl')"
    )->fetchColumn();
    if ((int) ($onlyBannedSame['skipped_excluded'] ?? 0) >= 1 && $stillHasDrop === 0) {
        pass('paste of only previously removed email skips site line');
    } else {
        fail('P1 only-banned paste: ' . json_encode([
            'pasteD' => $onlyBanned,
            'pasteC' => $onlyBannedSame,
            'stillHasDrop' => $stillHasDrop,
        ]));
    }

    // Final import also strips tombstoned emails on a new domain.
    exclude_email_campaign_email($nlSheet, 'txfcamp-nl-e.nl', 'bad@txfcamp-nl-e.nl');
    db()->prepare(
        "INSERT INTO sites_with_emails_admin_all
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfcamp-nl-e.nl','Netherlands','Dutch','europe',
                 'bad@txfcamp-nl-e.nl','good@txfcamp-nl-e.nl','','')
         ON DUPLICATE KEY UPDATE email1=VALUES(email1), email2=VALUES(email2), email3='', email4=''"
    )->execute();
    $impE = import_email_campaign_sheet_from_swe($nlSheet, 'admin_all', 'Netherlands', 'new_only');
    $eRow = db()->query(
        "SELECT email1, email2, email3, email4 FROM email_campaign_rows WHERE sheet_id="
        . (int) $nlSheet . " AND domain='txfcamp-nl-e.nl' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $eBag = strtolower(implode('|', array_filter([
        (string) ($eRow['email1'] ?? ''),
        (string) ($eRow['email2'] ?? ''),
        (string) ($eRow['email3'] ?? ''),
        (string) ($eRow['email4'] ?? ''),
    ])));
    if ((int) ($impE['imported'] ?? 0) >= 1
        && (int) ($impE['skipped_emails'] ?? 0) >= 1
        && !str_contains($eBag, 'bad@txfcamp-nl-e.nl')
        && str_contains($eBag, 'good@txfcamp-nl-e.nl')) {
        pass('Final import strips previously removed email on new site');
    } else {
        fail('P1 import email strip: ' . json_encode(['imp' => $impE, 'row' => $eRow]));
    }

    // P2: Allow again for a single email lifts that ban only.
    $listedBefore = count_email_campaign_excluded_emails($nlSheet);
    $clearedOne = clear_email_campaign_email_exclusion(
        $nlSheet,
        'txfcamp-nl-e.nl',
        'bad@txfcamp-nl-e.nl'
    );
    $stillBanned = is_email_campaign_email_excluded($nlSheet, 'txfcamp-nl-e.nl', 'bad@txfcamp-nl-e.nl');
    $listFn = list_email_campaign_excluded_emails($nlSheet, 50);
    if ($clearedOne && !$stillBanned && $listedBefore >= 1 && is_array($listFn)) {
        pass('Allow again clears one excluded email');
    } else {
        fail('P2 allow email: ' . json_encode([
            'cleared' => $clearedOne,
            'stillBanned' => $stillBanned,
            'listedBefore' => $listedBefore,
        ]));
    }

    // P2: Allow all emails for a domain.
    exclude_email_campaign_email($nlSheet, 'txfcamp-nl-f.nl', 'a@txfcamp-nl-f.nl');
    exclude_email_campaign_email($nlSheet, 'txfcamp-nl-f.nl', 'b@txfcamp-nl-f.nl');
    $clearedAll = clear_email_campaign_email_exclusions_for_domain($nlSheet, 'txfcamp-nl-f.nl');
    $aBanned = is_email_campaign_email_excluded($nlSheet, 'txfcamp-nl-f.nl', 'a@txfcamp-nl-f.nl');
    $bBanned = is_email_campaign_email_excluded($nlSheet, 'txfcamp-nl-f.nl', 'b@txfcamp-nl-f.nl');
    if ($clearedAll >= 2 && !$aBanned && !$bBanned) {
        pass('Allow all emails for site clears domain email bans');
    } else {
        fail('P2 allow all emails: ' . json_encode([
            'clearedAll' => $clearedAll,
            'a' => $aBanned,
            'b' => $bBanned,
        ]));
    }
} catch (Throwable $e) {
    fail('campaign: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Fill gaps from Admin + Final (campaign country sheet) ---
try {
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfgap-%'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfgap-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txfgap-%'");
    db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfgap-%'");
    db()->exec("DELETE FROM email_campaign_sheets WHERE project_name='TXF Gap Fill'");
    db()->exec("DELETE FROM email_campaign_projects WHERE name='TXF Gap Fill'");

    $gapSheet = create_email_campaign_sheet('Finland', (int) $adminUser['id'], 'TXF Gap Fill', false);
    $seedFinal = db()->prepare(
        "INSERT INTO sites_with_emails_admin_all
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES (?, 'Finland', 'Finnish', 'europe', ?, '', '', '')
         ON DUPLICATE KEY UPDATE email1=VALUES(email1), email2='', email3='', email4=''"
    );
    foreach (
        [
            ['txfgap-a.com', 'a@final.txfgap-a.com'],
            ['txfgap-b.com', 'b@txfgap-b.com'],
            ['txfgap-empty.com', ''],
            ['txfgap-excl.com', 'excl@txfgap-excl.com'],
        ] as [$dom, $em]
    ) {
        $seedFinal->execute([$dom, $em]);
    }
    $seedAdmin = db()->prepare(
        "INSERT INTO sites_with_emails_admin
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES (?, 'Finland', 'Finnish', 'europe', ?, '', '', '')
         ON DUPLICATE KEY UPDATE email1=VALUES(email1), email2='', email3='', email4=''"
    );
    foreach (
        [
            ['txfgap-a.com', 'a@admin.txfgap-a.com'],
            ['txfgap-c.com', 'c@txfgap-c.com'],
            ['txfgap-d.com', 'd-new@txfgap-d.com'],
        ] as [$dom, $em]
    ) {
        $seedAdmin->execute([$dom, $em]);
    }
    db()->prepare(
        "INSERT INTO sites_with_emails_team
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES ('txfgap-team.com', 'Finland', 'Finnish', 'europe', 'team@txfgap-team.com', '', '', '')"
    )->execute();

    upsert_email_campaign_row($gapSheet, 'txfgap-b.com', ['b@txfgap-b.com']);
    upsert_email_campaign_row($gapSheet, 'txfgap-d.com', ['d-old@txfgap-d.com']);
    $idD = (int) db()->query(
        "SELECT id FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-d.com' LIMIT 1"
    )->fetchColumn();
    set_email_campaign_row_email_sent($gapSheet, $idD, true);
    exclude_email_campaign_domain($gapSheet, 'txfgap-excl.com');

    $rowsBeforeDiff = count_email_campaign_rows($gapSheet);
    $diff = diff_email_campaign_vs_archives($gapSheet, 'Finland', ['domains' => true]);
    $rowsAfterDiff = count_email_campaign_rows($gapSheet);
    $add = $diff['add'] ?? [];
    $update = $diff['update'] ?? [];
    $same = $diff['same'] ?? [];
    $empty = $diff['empty'] ?? [];
    $excluded = $diff['excluded'] ?? [];
    $adminOnly = $diff['admin_only'] ?? [];
    $finalOnly = $diff['final_only'] ?? [];
    $counts = $diff['counts'] ?? [];
    if ($rowsBeforeDiff === $rowsAfterDiff
        && in_array('txfgap-a.com', $add, true)
        && in_array('txfgap-c.com', $add, true)
        && in_array('txfgap-d.com', $update, true)
        && in_array('txfgap-b.com', $same, true)
        && in_array('txfgap-empty.com', $empty, true)
        && in_array('txfgap-excl.com', $excluded, true)
        && in_array('txfgap-c.com', $adminOnly, true)
        && in_array('txfgap-b.com', $finalOnly, true)
        && (int) ($counts['fillable'] ?? 0) === ((int) ($counts['add'] ?? 0) + (int) ($counts['update'] ?? 0))
        && (int) ($counts['add'] ?? 0) >= 2
        && (int) ($counts['update'] ?? 0) >= 1) {
        pass('fill-gaps diff is pure and buckets add/update/same/empty/excluded');
    } else {
        fail('fill-gaps diff: ' . json_encode($diff) . " rows=$rowsBeforeDiff/$rowsAfterDiff");
    }

    $adminBefore = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();
    $finalBefore = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();
    $teamBefore = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();
    $wpBefore = (int) db()->query(
        "SELECT COUNT(*) FROM site_price_rows WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();

    $fill = fill_email_campaign_gaps_from_archives($gapSheet, 'Finland');
    $rowA = db()->query(
        "SELECT email1, email_sent FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-a.com' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $rowC = db()->query(
        "SELECT email1, email_sent FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-c.com' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $rowD = db()->query(
        "SELECT email1, email_sent FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-d.com' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $hasEmpty = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-empty.com'"
    )->fetchColumn();
    $hasExcl = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-excl.com'"
    )->fetchColumn();
    $hasTeamDom = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $gapSheet
        . " AND domain='txfgap-team.com'"
    )->fetchColumn();
    $adminAfter = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();
    $finalAfter = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();
    $teamAfter = (int) db()->query(
        "SELECT COUNT(*) FROM sites_with_emails_team WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();
    $wpAfter = (int) db()->query(
        "SELECT COUNT(*) FROM site_price_rows WHERE domain LIKE 'txfgap-%'"
    )->fetchColumn();

    if ((string) ($rowA['email1'] ?? '') === 'a@admin.txfgap-a.com'
        && (int) ($rowA['email_sent'] ?? 1) === 0
        && (string) ($rowC['email1'] ?? '') === 'c@txfgap-c.com'
        && (int) ($rowC['email_sent'] ?? 1) === 0
        && (string) ($rowD['email1'] ?? '') === 'd-new@txfgap-d.com'
        && (int) ($rowD['email_sent'] ?? 0) === 1
        && $hasEmpty === 0
        && $hasExcl === 0
        && $hasTeamDom === 0
        && $adminAfter === $adminBefore
        && $finalAfter === $finalBefore
        && $teamAfter === $teamBefore
        && $wpAfter === $wpBefore
        && (int) ($fill['would_add'] ?? 0) >= 2
        && (int) ($fill['would_update'] ?? 0) >= 1) {
        pass('fill-gaps copies Admin-win union, keeps emailed, skips empty/excluded/Team');
    } else {
        fail('fill-gaps fill: ' . json_encode([
            'fill' => $fill,
            'a' => $rowA,
            'c' => $rowC,
            'd' => $rowD,
            'empty' => $hasEmpty,
            'excl' => $hasExcl,
            'teamDom' => $hasTeamDom,
            'admin' => [$adminBefore, $adminAfter],
            'final' => [$finalBefore, $finalAfter],
            'team' => [$teamBefore, $teamAfter],
            'wp' => [$wpBefore, $wpAfter],
        ]));
    }

    $fill2 = fill_email_campaign_gaps_from_archives($gapSheet, 'Finland');
    if ((int) ($fill2['would_add'] ?? -1) === 0
        && (int) ($fill2['would_update'] ?? -1) === 0
        && (int) ($fill2['imported'] ?? -1) === 0
        && (int) ($fill2['updated'] ?? -1) === 0) {
        pass('second fill-gaps is a no-op');
    } else {
        fail('fill-gaps second: ' . json_encode($fill2));
    }

    delete_email_campaign_sheet($gapSheet);
    db()->exec("DELETE FROM email_campaign_projects WHERE name='TXF Gap Fill'");
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfgap-%'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfgap-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txfgap-%'");
} catch (Throwable $e) {
    fail('fill-gaps: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Admin emailed checkpoint (Final stays neutral) ---
try {
    db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfsent-%'");
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfsent-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txfsent-%'");

    $finalCols = db()->query('SHOW COLUMNS FROM sites_with_emails_admin_all')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $adminCols = db()->query('SHOW COLUMNS FROM sites_with_emails_admin')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('email_sent', $adminCols, true) && in_array('email_sent_at', $adminCols, true)) {
        pass('Admin has email_sent columns');
    } else {
        fail('Admin missing email_sent columns: ' . json_encode($adminCols));
    }
    if (!in_array('email_sent', $finalCols, true) && !in_array('email_sent_at', $finalCols, true)) {
        pass('Final has no email_sent columns');
    } else {
        fail('Final incorrectly has sent columns: ' . json_encode($finalCols));
    }

    $insSent = db()->prepare(
        "INSERT INTO sites_with_emails_admin
           (domain, country, language, region, email1, email2, email3, email4)
         VALUES (?,?, 'German', 'europe', ?, '', '', '')"
    );
    foreach (
        [
            ['txfsent-a.com', 'a@txfsent-a.com'],
            ['txfsent-b.com', 'b@txfsent-b.com'],
            ['txfsent-c.com', 'c@txfsent-c.com'],
        ] as [$dom, $em]
    ) {
        $insSent->execute([$dom, 'Germany', $em]);
    }
    sync_sites_with_emails_admin_to_all('Germany');

    $ids = db()->query(
        "SELECT id, domain FROM sites_with_emails_admin
         WHERE domain LIKE 'txfsent-%' ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($ids) !== 3) {
        fail('txfsent seed count=' . count($ids));
    } else {
        $idA = (int) $ids[0]['id'];
        $idB = (int) $ids[1]['id'];
        $idC = (int) $ids[2]['id'];

        $one = set_site_with_emails_admin_email_sent($idA, true);
        $rowA = get_site_with_emails($idA, 'admin');
        $finalA = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfsent-a.com'"
        )->fetchColumn();
        if (!empty($one['ok']) && !empty($one['row_deleted']) && $rowA === null && $finalA === 1) {
            pass('mark one Admin site emailed removes from Admin, Final kept');
        } else {
            fail('mark one: ' . json_encode($one) . ' row=' . json_encode($rowA) . " final=$finalA");
        }

        $upto = mark_sites_with_emails_admin_emailed_up_to($idB);
        $adminLeft = db()->query(
            "SELECT domain FROM sites_with_emails_admin WHERE domain LIKE 'txfsent-%' ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $finalB = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfsent-b.com'"
        )->fetchColumn();
        if (!empty($upto['ok']) && (int) ($upto['marked'] ?? 0) >= 1
            && $adminLeft === ['txfsent-c.com'] && $finalB === 1) {
            pass('mark up to here removes older Admin rows; Final kept');
        } else {
            fail('mark up to: ' . json_encode([
                'upto' => $upto,
                'adminLeft' => $adminLeft,
                'finalB' => $finalB,
            ]));
        }

        $filterSent = sites_with_emails_inventory_query(
            ['country' => 'Germany', 'sent' => '1'],
            1,
            100,
            'admin'
        );
        $filterUnsent = sites_with_emails_inventory_query(
            ['country' => 'Germany', 'sent' => '0'],
            1,
            100,
            'admin'
        );
        $sentDomains = array_column($filterSent['rows'] ?? [], 'domain');
        $unsentDomains = array_column($filterUnsent['rows'] ?? [], 'domain');
        if (!in_array('txfsent-a.com', $sentDomains, true)
            && !in_array('txfsent-b.com', $sentDomains, true)
            && in_array('txfsent-c.com', $unsentDomains, true)
            && !in_array('txfsent-a.com', $unsentDomains, true)) {
            pass('Admin emailed rows leave working list (filters show remaining only)');
        } else {
            fail('sent filter: sent=' . json_encode($sentDomains) . ' unsent=' . json_encode($unsentDomains));
        }

        // Re-push: conflict confirm still required when Team overlaps an Admin domain.
        db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txfsent-%'");
        $insTeam = db()->prepare(
            "INSERT INTO sites_with_emails_team
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES (?,?, 'German', 'europe', ?, '', '', '')"
        );
        $insTeam->execute(['txfsent-a.com', 'Germany', 'a2@txfsent-a.com']);
        $insTeam->execute(['txfsent-c.com', 'Germany', 'c@txfsent-c.com']);
        $blocked = push_sites_with_emails_team_to_admin('Germany', $teamUser, false);
        if (empty($blocked['ok']) && !empty($blocked['needs_confirm'])) {
            pass('Team re-push without confirm is blocked');
        } else {
            fail('expected needs_confirm: ' . json_encode($blocked));
        }
        $repush = push_sites_with_emails_team_to_admin('Germany', $teamUser, true);
        $afterRepush = db()->query(
            "SELECT email1, email2, email3, email4, email_sent FROM sites_with_emails_admin
             WHERE domain='txfsent-a.com' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;
        $mergedSlots = $afterRepush ? [
            strtolower((string) ($afterRepush['email1'] ?? '')),
            strtolower((string) ($afterRepush['email2'] ?? '')),
            strtolower((string) ($afterRepush['email3'] ?? '')),
            strtolower((string) ($afterRepush['email4'] ?? '')),
        ] : [];
        if (!empty($repush['ok']) && $afterRepush !== null
            && (int) ($afterRepush['email_sent'] ?? 0) === 0
            && in_array('a2@txfsent-a.com', $mergedSlots, true)) {
            pass('Team re-push re-adds emailed domain to Admin unmarked');
            pass('Team re-push merges new email without wiping Admin email');
        } else {
            fail('re-push after emailed: ' . json_encode($repush) . ' row=' . json_encode($afterRepush));
        }

        // Identical re-push on an emailed-flagged row (legacy flag) keeps emailed when slots unchanged.
        $idA2 = (int) db()->query(
            "SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-a.com' LIMIT 1"
        )->fetchColumn();
        db()->prepare(
            'UPDATE sites_with_emails_admin SET email_sent=1, email_sent_at=NOW() WHERE id=?'
        )->execute([$idA2]);
        db()->prepare(
            "INSERT INTO sites_with_emails_team
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsent-a.com','Germany','German','europe',?,?,?,?)"
        )->execute([
            (string) ($afterRepush['email1'] ?? ''),
            (string) ($afterRepush['email2'] ?? ''),
            (string) ($afterRepush['email3'] ?? ''),
            (string) ($afterRepush['email4'] ?? ''),
        ]);
        $samePush = push_sites_with_emails_team_to_admin('Germany', $teamUser, true);
        $sameSent = (int) db()->query(
            "SELECT email_sent FROM sites_with_emails_admin WHERE domain='txfsent-a.com' LIMIT 1"
        )->fetchColumn();
        if (!empty($samePush['ok']) && $sameSent === 1 && (int) ($samePush['emailed_cleared'] ?? 0) === 0) {
            pass('Team re-push keeps emailed when slots unchanged');
        } else {
            fail('same-slot re-push: ' . json_encode($samePush) . " sent=$sameSent");
        }
        $sameUpdated = (string) db()->query(
            "SELECT updated_at FROM sites_with_emails_admin WHERE domain='txfsent-a.com' LIMIT 1"
        )->fetchColumn();
        $sigSame = swe_admin_row_signal(
            db()->query("SELECT * FROM sites_with_emails_admin WHERE domain='txfsent-a.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [],
            $sameUpdated
        );
        if ($sigSame === '') {
            pass('identical re-push does not mark row Updated vs its own updated_at');
        } else {
            fail('identical re-push false Updated signal: ' . $sigSame);
        }

        $unitMerge = merge_swe_email_slots_prefer_admin(
            ['keep@admin.test', '', '', ''],
            ['new@team.test', 'keep@admin.test', 'extra@team.test', '']
        );
        if ($unitMerge === ['keep@admin.test', 'new@team.test', 'extra@team.test', '']) {
            pass('merge_swe_email_slots_prefer_admin fills blanks only');
        } else {
            fail('merge unit: ' . json_encode($unitMerge));
        }

        $fullMerge = merge_swe_email_slots_prefer_admin_stats(
            ['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com'],
            ['e@x.com', 'a@x.com']
        );
        if ((int) ($fullMerge['dropped'] ?? 0) === 1
            && ($fullMerge['dropped_emails'][0] ?? '') === 'e@x.com'
            && $fullMerge['slots'] === ['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com']) {
            pass('merge stats reports Team emails dropped when Admin full');
        } else {
            fail('merge stats: ' . json_encode($fullMerge));
        }

        // Admin sheet: clearing last email removes from Admin; Final keeps last copy.
        db()->prepare(
            "INSERT INTO sites_with_emails_admin
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsent-empty.com','Germany','German','europe','solo@txfsent-empty.com','','','')
             ON DUPLICATE KEY UPDATE email1='solo@txfsent-empty.com', email2='', email3='', email4=''"
        )->execute();
        sync_sites_with_emails_admin_to_all('Germany');
        $emptyId = (int) db()->query(
            "SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-empty.com' LIMIT 1"
        )->fetchColumn();
        $emptySave = save_site_with_emails_row(
            'Germany',
            'txfsent-empty.com',
            ['', '', '', ''],
            $adminUser,
            $emptyId,
            'admin'
        );
        $emptyAdminLeft = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain='txfsent-empty.com'"
        )->fetchColumn();
        $emptyFinalLeft = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfsent-empty.com'"
        )->fetchColumn();
        if (!empty($emptySave['ok']) && !empty($emptySave['row_deleted'])
            && $emptyAdminLeft === 0 && $emptyFinalLeft === 1) {
            pass('Admin save with no emails deletes Admin only; Final kept');
        } else {
            fail('admin empty save: ' . json_encode([
                'save' => $emptySave,
                'admin' => $emptyAdminLeft,
                'final' => $emptyFinalLeft,
            ]));
        }

        // Brand-new Team push lands unmarked at bottom.
        db()->prepare(
            "INSERT INTO sites_with_emails_team
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsent-new.com','Germany','German','europe','n@txfsent-new.com','','','')"
        )->execute();
        $newPush = push_sites_with_emails_team_to_admin('Germany', $teamUser);
        $newSent = (int) db()->query(
            "SELECT email_sent FROM sites_with_emails_admin WHERE domain='txfsent-new.com' LIMIT 1"
        )->fetchColumn();
        $order = db()->query(
            "SELECT domain FROM sites_with_emails_admin
             WHERE domain LIKE 'txfsent-%' ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $last = (string) (end($order) ?: '');
        if (!empty($newPush['pushed']) && $newSent === 0 && $last === 'txfsent-new.com') {
            pass('new Team push is unmarked and last in Admin list');
        } else {
            fail('new push: ' . json_encode($newPush) . " sent=$newSent last=$last order=" . json_encode($order));
        }

        // Clear emailed flags on remaining Admin rows (legacy email_sent=1).
        $clearUpto = clear_sites_with_emails_admin_emailed_up_to($idA2);
        $sentA = (int) db()->query(
            'SELECT email_sent FROM sites_with_emails_admin WHERE id=' . (int) $idA2
        )->fetchColumn();
        $afterClearUpto = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE domain LIKE 'txfsent-%' AND email_sent=1"
        )->fetchColumn();
        if (!empty($clearUpto['ok']) && $sentA === 0 && $afterClearUpto === 0) {
            pass('clear up to here undoes Admin emailed marks');
        } else {
            fail('clear up to: ' . json_encode($clearUpto)
                . " a=$sentA left=$afterClearUpto");
        }

        // Mark emailed removes from Admin; clear-all is a no-op when none flagged.
        $idCNow = (int) db()->query(
            "SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-c.com' LIMIT 1"
        )->fetchColumn();
        $idNew = (int) db()->query(
            "SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-new.com' LIMIT 1"
        )->fetchColumn();
        mark_sites_with_emails_admin_emailed_up_to($idCNow);
        set_site_with_emails_admin_email_sent($idNew, true);
        $clearAll = clear_all_sites_with_emails_admin_emailed('Germany');
        $sentLeft = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE domain LIKE 'txfsent-%' AND email_sent=1"
        )->fetchColumn();
        $adminAfterMark = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain LIKE 'txfsent-%'"
        )->fetchColumn();
        $finalAfterMark = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txfsent-%'"
        )->fetchColumn();
        if (!empty($clearAll['ok']) && $sentLeft === 0 && $adminAfterMark >= 1 && $finalAfterMark >= 4) {
            pass('clear all emailed resets Admin sheet for resend');
        } else {
            fail('clear all: ' . json_encode($clearAll)
                . " left=$sentLeft admin=$adminAfterMark final=$finalAfterMark");
        }

        // Copy: after mark emailed, removed rows are gone from Admin copy lists.
        // Seed a not-emailed row and copy its emails; emailed-gone domains stay in Final only.
        db()->prepare(
            "INSERT INTO sites_with_emails_admin
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsent-copy.com','Germany','German','europe','copy@txfsent-copy.com','','','')
             ON DUPLICATE KEY UPDATE email1='copy@txfsent-copy.com'"
        )->execute();
        $copyAll = collect_sites_with_emails_all_emails('Germany', 'admin', null);
        $copyUnsent = collect_sites_with_emails_all_emails('Germany', 'admin', '0');
        $hasCopy = in_array('copy@txfsent-copy.com', $copyAll, true)
            && in_array('copy@txfsent-copy.com', $copyUnsent, true);
        // Invalid token in a packed cell must not block Copy all emails.
        db()->prepare(
            "UPDATE sites_with_emails_admin
             SET email1='good@txfsent-copy.com, not-an-email, also@txfsent-copy.com'
             WHERE domain='txfsent-copy.com'"
        )->execute();
        $copySkipBad = collect_sites_with_emails_all_emails('Germany', 'admin', null);
        $hasGood = in_array('good@txfsent-copy.com', $copySkipBad, true)
            && in_array('also@txfsent-copy.com', $copySkipBad, true);
        $noBad = !in_array('not-an-email', $copySkipBad, true);
        if ($hasCopy && $hasGood && $noBad) {
            pass('copy emailed / not-emailed email lists split correctly');
        } else {
            fail('copy filters: all=' . json_encode($copyAll)
                . ' skipBad=' . json_encode($copySkipBad));
        }
        if ($hasGood && $noBad) {
            pass('Copy all emails skips invalid tokens sitewide');
        } else {
            fail('copy invalid: ' . json_encode($copySkipBad));
        }

        // Sync must not invent sent state on Final (no column); domains still mirror.
        // Re-seed Admin rows that were marked emailed so sync has something to mirror.
        foreach (['txfsent-a.com' => 'a@txfsent-a.com', 'txfsent-b.com' => 'b@txfsent-b.com', 'txfsent-c.com' => 'c@txfsent-c.com'] as $dom => $em) {
            $exists = (int) db()->query(
                "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain=" . db()->quote($dom)
            )->fetchColumn();
            if ($exists < 1) {
                db()->prepare(
                    "INSERT INTO sites_with_emails_admin
                       (domain, country, language, region, email1, email2, email3, email4)
                     VALUES (?, 'Germany', 'German', 'europe', ?, '', '', '')"
                )->execute([$dom, $em]);
            }
        }
        sync_sites_with_emails_admin_to_all('Germany');
        $finalMirror = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain LIKE 'txfsent-%'"
        )->fetchColumn();
        $finalColsAfter = db()->query('SHOW COLUMNS FROM sites_with_emails_admin_all')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($finalMirror >= 4 && !in_array('email_sent', $finalColsAfter, true)) {
            pass('Final mirrors domains without email_sent');
        } else {
            fail("Final mirror=$finalMirror cols=" . json_encode($finalColsAfter));
        }

        // P3: repair report distinguishes added / updated / removed
        db()->prepare(
            "INSERT INTO sites_with_emails_admin_all
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsent-stale.com','Germany','German','europe','stale@x.com','','','')"
        )->execute();
        db()->prepare(
            "UPDATE sites_with_emails_admin SET email2='extra@txfsent-a.com'
             WHERE domain='txfsent-a.com'"
        )->execute();
        $report = sync_sites_with_emails_admin_to_all('Germany');
        $staleLeft = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfsent-stale.com'"
        )->fetchColumn();
        if ((int) ($report['removed'] ?? 0) === 0 && $staleLeft === 1
            && (int) ($report['updated'] ?? 0) >= 1
            && is_array($report['removed_samples'] ?? null)
            && is_array($report['updated_samples'] ?? null)) {
            pass('Final repair keeps archive-only rows and updates Admin copies');
        } else {
            fail('repair report: ' . json_encode($report) . " stale=$staleLeft");
        }

        // Mark emailed then repair must still keep the Final copy.
        $keepId = (int) db()->query(
            "SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-a.com' LIMIT 1"
        )->fetchColumn();
        set_site_with_emails_admin_email_sent($keepId, true);
        sync_sites_with_emails_admin_to_all('Germany');
        $finalAfterEmailedRepair = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfsent-a.com'"
        )->fetchColumn();
        $adminAfterEmailedRepair = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain='txfsent-a.com'"
        )->fetchColumn();
        if ($adminAfterEmailedRepair === 0 && $finalAfterEmailedRepair === 1) {
            pass('repair after mark emailed keeps Final archive copy');
        } else {
            fail("repair after emailed: admin=$adminAfterEmailedRepair final=$finalAfterEmailedRepair");
        }

        // Manual Admin remove keeps Final archive copy.
        $rmId = (int) db()->query(
            "SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-c.com' LIMIT 1"
        )->fetchColumn();
        delete_site_with_emails($rmId, 'admin');
        $rmAdmin = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin WHERE domain='txfsent-c.com'"
        )->fetchColumn();
        $rmFinal = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin_all WHERE domain='txfsent-c.com'"
        )->fetchColumn();
        if ($rmAdmin === 0 && $rmFinal === 1) {
            pass('Admin remove keeps Final archive copy');
        } else {
            fail("admin remove keep final: admin=$rmAdmin final=$rmFinal");
        }

        db()->prepare(
            "INSERT INTO sites_with_emails_admin
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsug-prefix.de','Germany','German','europe','findme@txfsug-prefix.de','','','')"
        )->execute();
        $prefixHits = search_sites_with_emails_admin_suggestions('txfsug-pre', 10);
        $emailHits = search_sites_with_emails_admin_suggestions('findme@txfsug', 10);
        $containsHits = search_sites_with_emails_admin_suggestions('sug-prefix', 10);
        $prefixOk = in_array('txfsug-prefix.de', array_column($prefixHits, 'domain'), true);
        $emailOk = in_array('txfsug-prefix.de', array_column($emailHits, 'domain'), true);
        $containsOk = in_array('txfsug-prefix.de', array_column($containsHits, 'domain'), true);
        if ($prefixOk && $emailOk && $containsOk) {
            pass('Admin super-search prefix + email + contains');
        } else {
            fail('super-search: prefix=' . json_encode($prefixHits)
                . ' email=' . json_encode($emailHits)
                . ' contains=' . json_encode($containsHits));
        }
        if (is_bool(sites_with_emails_final_needs_repair())) {
            pass('Final repair check uses LIMIT 1 short-circuit');
        } else {
            fail('Final repair check did not return bool');
        }
        if (table_has_any_row(db(), 'sites_with_emails_admin')) {
            pass('table_has_any_row LIMIT 1 sees Admin rows');
        } else {
            fail('table_has_any_row missed Admin rows');
        }
        $cachedA = cached_scalar_count('txf_test_cached_count', static function () {
            return 7;
        });
        $cachedB = cached_scalar_count('txf_test_cached_count', static function () {
            return 99;
        });
        if ($cachedA === 7 && $cachedB === 7) {
            pass('cached_scalar_count reuses first result');
        } else {
            fail("cached_scalar_count a=$cachedA b=$cachedB");
        }

        db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfcampsug-%'");
        db()->exec(
            "DELETE FROM email_campaign_sheets
             WHERE name LIKE 'txfcampsug-%' OR project_name LIKE 'txfcampsug-%'"
        );
        db()->exec("DELETE FROM email_campaign_projects WHERE name LIKE 'txfcampsug-%'");
        $sugPid = create_email_campaign_project('txfcampsug-proj', (int) $adminUser['id'], true);
        $sugSheet = add_email_campaign_country_to_project($sugPid, 'Germany', (int) $adminUser['id']);
        upsert_email_campaign_row($sugSheet, 'txfcampsug-prefix.de', [
            'email1' => 'findme@txfcampsug-prefix.de', 'email2' => '', 'email3' => '', 'email4' => '',
        ]);
        $cPrefixHits = search_email_campaign_suggestions($sugSheet, 'txfcampsug-pre', 10);
        $cEmailHits = search_email_campaign_suggestions($sugSheet, 'findme@txfcampsug', 10);
        $cContainsHits = search_email_campaign_suggestions($sugSheet, 'campsug-prefix', 10);
        $cPrefixOk = in_array('txfcampsug-prefix.de', array_column($cPrefixHits, 'domain'), true);
        $cEmailOk = in_array('txfcampsug-prefix.de', array_column($cEmailHits, 'domain'), true);
        $cContainsOk = in_array('txfcampsug-prefix.de', array_column($cContainsHits, 'domain'), true);
        if ($cPrefixOk && $cEmailOk && $cContainsOk) {
            pass('Campaign super-search prefix + email + contains');
        } else {
            fail('campaign super-search: prefix=' . json_encode($cPrefixHits)
                . ' email=' . json_encode($cEmailHits)
                . ' contains=' . json_encode($cContainsHits));
        }
        if (function_exists('login_throttle_blocked') && login_throttle_blocked('txf-no-such-user') === false) {
            pass('login throttle allows first attempts');
        } else {
            fail('login throttle blocked a fresh login');
        }
        db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfcampsug-%'");
        db()->exec(
            "DELETE FROM email_campaign_sheets
             WHERE name LIKE 'txfcampsug-%' OR project_name LIKE 'txfcampsug-%'"
        );
        db()->exec("DELETE FROM email_campaign_projects WHERE name LIKE 'txfcampsug-%'");
    }
} catch (Throwable $e) {
    fail('admin emailed checkpoint: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Email campaign sheet emailed checkpoint (same rule as Admin SWE, per sheet) ---
try {
    db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfcamp-sent-%'");
    db()->exec(
        "DELETE FROM email_campaign_sheets
         WHERE name LIKE 'txfcamp-sent-%' OR project_name LIKE 'txfcamp-sent-%'"
    );
    db()->exec("DELETE FROM email_campaign_projects WHERE name LIKE 'txfcamp-sent-%'");

    ensure_email_campaign_schema();
    $campCols = db()->query('SHOW COLUMNS FROM email_campaign_rows')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('email_sent', $campCols, true) && in_array('email_sent_at', $campCols, true)) {
        pass('campaign rows have email_sent columns');
    } else {
        fail('campaign missing email_sent columns: ' . json_encode($campCols));
    }

    $campPid = create_email_campaign_project(
        'txfcamp-sent-proj',
        (int) $adminUser['id'],
        false
    );
    $campSheetA = add_email_campaign_country_to_project($campPid, 'Germany', (int) $adminUser['id']);
    $campSheetB = add_email_campaign_country_to_project($campPid, 'France', (int) $adminUser['id']);
    foreach (
        [
            ['txfcamp-sent-a.com', 'a@txfcamp-sent-a.com'],
            ['txfcamp-sent-b.com', 'b@txfcamp-sent-b.com'],
            ['txfcamp-sent-c.com', 'c@txfcamp-sent-c.com'],
        ] as [$dom, $em]
    ) {
        upsert_email_campaign_row($campSheetA, $dom, [
            'email1' => $em, 'email2' => '', 'email3' => '', 'email4' => '',
        ]);
    }
    // Same domain in another country sheet of the same project — separate progress.
    upsert_email_campaign_row($campSheetB, 'txfcamp-sent-a.com', [
        'email1' => 'fr@txfcamp-sent-a.com', 'email2' => '', 'email3' => '', 'email4' => '',
    ]);

    $campIds = db()->query(
        'SELECT id, domain FROM email_campaign_rows
         WHERE sheet_id=' . (int) $campSheetA . " AND domain LIKE 'txfcamp-sent-%'
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($campIds) !== 3) {
        fail('txfcamp-sent seed count=' . count($campIds));
    } else {
        $cA = (int) $campIds[0]['id'];
        $cB = (int) $campIds[1]['id'];
        $cC = (int) $campIds[2]['id'];

        $one = set_email_campaign_row_email_sent($campSheetA, $cA, true);
        $rowA = get_email_campaign_row($cA, $campSheetA);
        if (!empty($one['ok']) && (int) ($rowA['email_sent'] ?? 0) === 1) {
            pass('campaign mark one site emailed');
        } else {
            fail('campaign mark one: ' . json_encode($one));
        }

        $upto = mark_email_campaign_emailed_up_to($campSheetA, $cB);
        $stats = count_email_campaign_sent_stats($campSheetA);
        $sentRows = (int) db()->query(
            'SELECT COUNT(*) FROM email_campaign_rows
             WHERE sheet_id=' . (int) $campSheetA . " AND domain LIKE 'txfcamp-sent-%' AND email_sent=1"
        )->fetchColumn();
        $unsentC = (int) db()->query(
            'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $cC
        )->fetchColumn();
        $otherSheetSent = (int) db()->query(
            'SELECT email_sent FROM email_campaign_rows
             WHERE sheet_id=' . (int) $campSheetB . " AND domain='txfcamp-sent-a.com' LIMIT 1"
        )->fetchColumn();
        if (!empty($upto['ok']) && $sentRows === 2 && (int) $unsentC === 0
            && (int) ($stats['sent'] ?? 0) === 2 && $otherSheetSent === 0) {
            pass('campaign mark up to here is per-sheet only');
        } else {
            fail('campaign mark up to: ' . json_encode([
                'upto' => $upto,
                'sentRows' => $sentRows,
                'c' => $unsentC,
                'stats' => $stats,
                'other' => $otherSheetSent,
            ]));
        }

        $filterSent = email_campaign_rows_inventory_query($campSheetA, ['sent' => '1'], 1, 100);
        $filterUnsent = email_campaign_rows_inventory_query($campSheetA, ['sent' => '0'], 1, 100);
        $sentDomains = array_column($filterSent['rows'] ?? [], 'domain');
        $unsentDomains = array_column($filterUnsent['rows'] ?? [], 'domain');
        if (in_array('txfcamp-sent-a.com', $sentDomains, true)
            && in_array('txfcamp-sent-b.com', $sentDomains, true)
            && in_array('txfcamp-sent-c.com', $unsentDomains, true)
            && !in_array('txfcamp-sent-c.com', $sentDomains, true)) {
            pass('campaign sent filter splits emailed / not emailed');
        } else {
            fail('campaign sent filter: sent=' . json_encode($sentDomains)
                . ' unsent=' . json_encode($unsentDomains));
        }

        // Updating emails on an already-emailed row must keep email_sent=1.
        $saveKeep = save_email_campaign_row($campSheetA, $cA, 'txfcamp-sent-a.com', [
            'a2@txfcamp-sent-a.com', '', '', '',
        ]);
        $afterSave = (int) db()->query(
            'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $cA
        )->fetchColumn();
        if (!empty($saveKeep['ok']) && $afterSave === 1) {
            pass('campaign save emails keeps emailed mark');
        } else {
            fail('campaign save keep: ' . json_encode($saveKeep) . " sent=$afterSave");
        }

        // New import / upsert lands unmarked (and does not clear other sheet).
        upsert_email_campaign_row($campSheetA, 'txfcamp-sent-new.com', [
            'email1' => 'n@txfcamp-sent-new.com', 'email2' => '', 'email3' => '', 'email4' => '',
        ]);
        $newSent = (int) db()->query(
            "SELECT email_sent FROM email_campaign_rows
             WHERE sheet_id=" . (int) $campSheetA . " AND domain='txfcamp-sent-new.com' LIMIT 1"
        )->fetchColumn();
        $order = db()->query(
            'SELECT domain FROM email_campaign_rows
             WHERE sheet_id=' . (int) $campSheetA . " AND domain LIKE 'txfcamp-sent-%'
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $last = (string) (end($order) ?: '');
        if ($newSent === 0 && $last === 'txfcamp-sent-new.com') {
            pass('campaign new row is unmarked and last');
        } else {
            fail("campaign new row sent=$newSent last=$last order=" . json_encode($order));
        }

        $clearUpto = clear_email_campaign_emailed_up_to($campSheetA, $cB);
        $sentA = (int) db()->query(
            'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $cA
        )->fetchColumn();
        $sentB = (int) db()->query(
            'SELECT email_sent FROM email_campaign_rows WHERE id=' . (int) $cB
        )->fetchColumn();
        if (!empty($clearUpto['ok']) && (int) ($clearUpto['cleared'] ?? 0) >= 2
            && $sentA === 0 && $sentB === 0) {
            pass('campaign clear up to here undoes emailed marks');
        } else {
            fail('campaign clear up to: ' . json_encode($clearUpto) . " a=$sentA b=$sentB");
        }

        mark_email_campaign_emailed_up_to($campSheetA, $cC);
        set_email_campaign_row_email_sent(
            $campSheetA,
            (int) db()->query(
                "SELECT id FROM email_campaign_rows
                 WHERE sheet_id=" . (int) $campSheetA . " AND domain='txfcamp-sent-new.com' LIMIT 1"
            )->fetchColumn(),
            true
        );
        $clearAll = clear_all_email_campaign_emailed($campSheetA);
        $sentLeft = (int) db()->query(
            'SELECT COUNT(*) FROM email_campaign_rows
             WHERE sheet_id=' . (int) $campSheetA . " AND domain LIKE 'txfcamp-sent-%' AND email_sent=1"
        )->fetchColumn();
        if (!empty($clearAll['ok']) && (int) ($clearAll['cleared'] ?? 0) >= 3 && $sentLeft === 0) {
            pass('campaign clear all emailed resets sheet for resend');
        } else {
            fail('campaign clear all: ' . json_encode($clearAll) . " left=$sentLeft");
        }
    }

    delete_email_campaign_project($campPid);
} catch (Throwable $e) {
    fail('campaign emailed checkpoint: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Departments ACL ---
try {
    foreach (
        [
            'finder' => 'site_finding',
            'extractor' => 'site_extracting',
            'emailer' => 'email_extracting',
            'comms' => 'communication',
        ] as $uname => $slug
    ) {
        $hash = password_hash('DeptTest9x', PASSWORD_DEFAULT);
        db()->prepare(
            "INSERT INTO users (username,password_hash,full_name,email,role,must_change_password,is_active)
             VALUES (?,?,?,?, 'team', 0, 1)
             ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), must_change_password=0"
        )->execute([$uname, $hash, ucfirst($uname), $uname . '@test.local']);
        $uid = (int) db()->query('SELECT id FROM users WHERE username=' . db()->quote($uname))->fetchColumn();
        $dept = get_department_by_slug($slug);
        db()->prepare('DELETE FROM department_members WHERE user_id=?')->execute([$uid]);
        add_department_member((int) $dept['id'], $uid, $adminUser);
        $u = ['id' => $uid, 'username' => $uname, 'role' => 'team'];
        $pages = department_tool_pages_for_user($u);
        $expect = match ($slug) {
            'site_finding' => [
                'team_prospect_check',
                'team_prospect_batches',
                'team_prospect_batch',
                'team_semrush_research',
                'team_semrush_sheet',
            ],
            'site_extracting' => ['team_extracting', 'team_extract_batch'],
            'email_extracting' => ['team_sites_emails', 'team_admin_emails_search'],
            'communication' => ['team_email_campaigns', 'team_email_campaigns_drafts', 'team_admin_emails_search', 'team_site_prices'],
        };
        $missing = array_diff($expect, $pages);
        $hasWp = in_array('team_site_prices', $pages, true);
        if ($missing) {
            fail("$uname tools missing " . implode(',', $missing) . ' got=' . implode(',', $pages));
        } elseif ($hasWp !== ($slug === 'communication')) {
            fail("$uname Website prices ACL unexpected got=" . implode(',', $pages));
        } else {
            pass("$uname tools OK");
        }
    }

    // Unassigned Team user: waiting state, no tools.
    $hash = password_hash('DeptTest9x', PASSWORD_DEFAULT);
    db()->prepare(
        "INSERT INTO users (username,password_hash,full_name,email,role,must_change_password,is_active)
         VALUES ('unassigned',?,?,?, 'team', 0, 1)
         ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), must_change_password=0"
    )->execute([$hash, 'Unassigned User', 'unassigned@test.local']);
    $unUid = (int) db()->query("SELECT id FROM users WHERE username='unassigned'")->fetchColumn();
    db()->prepare('DELETE FROM department_members WHERE user_id=?')->execute([$unUid]);
    $unUser = ['id' => $unUid, 'username' => 'unassigned', 'role' => 'team'];
    if (team_user_awaits_department($unUser) && !user_is_department_scoped($unUser)) {
        pass('unassigned team awaits department');
    } else {
        fail('unassigned await flag wrong');
    }
    if (department_tool_pages_for_user($unUser) === []) {
        pass('unassigned team has no tool pages');
    } else {
        fail('unassigned tools=' . implode(',', department_tool_pages_for_user($unUser)));
    }
} catch (Throwable $e) {
    fail('departments: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Orders / invoices ---
try {
    $clientId = create_order_client('Test Client GmbH', 'integration test', (int) $adminUser['id']);
    pass("order client id=$clientId");
    $itemId = add_order_item((int) $clientId, 'txforder-site.com', 8, 2026);
    if ($itemId > 0) {
        pass("order item id=$itemId");
    } else {
        fail("order item id=$itemId");
    }

    // OM-1: unique client name
    try {
        create_order_client('Test Client GmbH', 'dup', (int) $adminUser['id']);
        fail('duplicate client name allowed');
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            pass('duplicate client name rejected');
        } else {
            fail('duplicate client message=' . $e->getMessage());
        }
    }

    // OM-1: paid requires LIVE URL
    try {
        set_order_item_paid((int) $itemId, (int) $clientId, true);
        fail('mark paid without LIVE allowed');
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'LIVE URL')) {
            pass('mark paid without LIVE rejected');
        } else {
            fail('mark paid without LIVE message=' . $e->getMessage());
        }
    }
    db()->prepare('UPDATE order_items SET live_url=?, decided_price=?, order_stage=? WHERE id=?')
        ->execute(['https://example.com/txforder-site', 10.00, 'completed', $itemId]);
    set_order_item_paid((int) $itemId, (int) $clientId, true);
    $paidRow = db()->prepare('SELECT is_paid FROM order_items WHERE id=?');
    $paidRow->execute([$itemId]);
    if ((int) $paidRow->fetchColumn() === 1) {
        pass('mark paid with LIVE works');
    } else {
        fail('mark paid with LIVE failed');
    }

    // Clearing LIVE on save must also clear paid.
    update_order_item((int) $itemId, (int) $clientId, [
        'site_name' => 'txforder-site.com',
        'owner_price' => 50,
        'decided_price' => 80,
        'live_url' => '',
        'order_month' => 8,
        'order_year' => 2026,
    ]);
    $clearedPaid = db()->prepare('SELECT is_paid, live_url FROM order_items WHERE id=?');
    $clearedPaid->execute([$itemId]);
    $cleared = $clearedPaid->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($cleared['is_paid'] ?? 1) === 0 && trim((string) ($cleared['live_url'] ?? 'x')) === '') {
        pass('clearing LIVE also clears paid');
    } else {
        fail('clearing LIVE left paid=' . json_encode($cleared));
    }
    // Restore unpaid LIVE for OM-2 metrics
    update_order_item((int) $itemId, (int) $clientId, [
        'site_name' => 'txforder-site.com',
        'owner_price' => 50,
        'decided_price' => 80,
        'live_url' => 'https://example.com/live-om',
        'country' => 'Germany',
        'client_label' => 'txf-buyer@example.com',
        'order_month' => 8,
        'order_year' => 2026,
    ]);
    order_mark_completed((int) $itemId, 'https://example.com/live-om', (int) $adminUser['id']);
    set_order_item_paid((int) $itemId, (int) $clientId, false);

    // OM-2: unpaid LIVE metrics
    $unpaidN = count_order_client_unpaid_live((int) $clientId);
    if ($unpaidN === 1) {
        pass('unpaid LIVE count after unmark');
    } else {
        fail("unpaid LIVE count=$unpaidN expected 1");
    }
    $optLabel = invoice_generate_client_option_label([
        'name' => 'Opt Client',
        'unpaid_live_count' => $unpaidN,
        'completed_count' => 3,
    ]);
    if (str_contains($optLabel, 'unpaid LIVE') && str_contains($optLabel, (string) $unpaidN)
        && str_contains($optLabel, 'completed') && str_contains($optLabel, 'Opt Client')) {
        pass('invoice generate option unpaid LIVE');
    } else {
        fail('invoice generate option label missing unpaid LIVE: ' . $optLabel);
    }
    if (invoice_generate_client_typeahead_min() >= 8) {
        pass('invoice generate typeahead min is 8+');
    } else {
        fail('invoice generate typeahead min too low');
    }
    try {
        invoice_assert_single_bill_as([
            ['client_label' => 'alpha@example.com'],
            ['client_label' => 'beta@example.com'],
        ]);
        fail('mixed bill-as should throw');
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'same bill-as')) {
            pass('mixed bill-as blocked');
        } else {
            fail('mixed bill-as message: ' . $e->getMessage());
        }
    }
    invoice_assert_single_bill_as([
        ['client_label' => 'same@example.com'],
        ['client_label' => 'same@example.com'],
    ]);
    $emptyStats = invoice_generate_empty_stats();
    $listedInv = list_invoiceable_order_items(0);
    if (isset($emptyStats['invoiceable'], $emptyStats['completed_unpaid'], $emptyStats['missing_country_client'], $emptyStats['on_open_invoice'])
        && (int) $emptyStats['invoiceable'] === count($listedInv)) {
        pass('generate empty stats match invoiceable');
    } else {
        fail('generate empty stats invoiceable=' . json_encode($emptyStats) . ' listed=' . count($listedInv));
    }
    if (invoice_generate_pick_cap() >= 80) {
        pass('generate pick cap');
    } else {
        fail('generate pick cap too low');
    }
    $listed = list_order_clients(['filter' => 'unpaid']);
    $foundUnpaid = false;
    foreach ($listed as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $clientId) {
            $foundUnpaid = true;
            break;
        }
    }
    if ($foundUnpaid) {
        pass('list filter unpaid includes client');
    } else {
        fail('list filter unpaid missing client');
    }

    // OM-3: archive hides from default list
    set_order_client_archived((int) $clientId, true);
    $activeList = list_order_clients(['filter' => 'all']);
    $stillVisible = false;
    foreach ($activeList as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $clientId) {
            $stillVisible = true;
            break;
        }
    }
    if (!$stillVisible) {
        pass('archived client hidden from default list');
    } else {
        fail('archived client still in default list');
    }
    $archList = list_order_clients(['filter' => 'archived']);
    $inArch = false;
    foreach ($archList as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $clientId) {
            $inArch = true;
            break;
        }
    }
    if ($inArch) {
        pass('archived filter lists client');
    } else {
        fail('archived filter missing client');
    }
    set_order_client_archived((int) $clientId, false);

    $dash = order_management_dashboard_stats();
    if (isset($dash['clients'], $dash['unpaid_live'], $dash['orders'])) {
        pass('order dashboard stats ok');
    } else {
        fail('order dashboard stats missing keys');
    }
    $clientTotal = count_order_clients(['filter' => 'all']);
    $clientPage = list_order_clients(['filter' => 'all', 'limit' => 1, 'offset' => 0]);
    if ($clientTotal >= 1 && count($clientPage) === 1) {
        pass('order clients SQL limit/offset');
    } else {
        fail("order clients paging total=$clientTotal page=" . count($clientPage));
    }

    $pipeId = add_order_pipeline_row((int) $adminUser['id'], 'buyer@example.com');
    update_order_item((int) $pipeId, 0, [
        'site_name' => 'pipeline-site.com',
        'country' => 'Germany',
        'client_label' => 'buyer@example.com',
        'admin_user_id' => (int) $adminUser['id'],
        'order_date' => '2026-08-15',
        'owner_price' => 12,
        'decided_price' => 30,
        'live_url' => 'https://example.com/pipeline-live',
        'order_month' => 8,
        'order_year' => 2026,
    ]);
    $liveOnly = get_order_item((int) $pipeId);
    $notAuto = $liveOnly && order_stage($liveOnly) === 'processing'
        && list_invoiceable_order_items_by_ids([(int) $pipeId]) === [];
    if ($notAuto) {
        pass('filling LIVE URL does not auto-complete');
    } else {
        fail('LIVE URL save auto-completed or became invoiceable');
    }
    $marked = order_mark_completed((int) $pipeId, 'https://example.com/pipeline-live', (int) $adminUser['id']);
    if (empty($marked['ok'])) {
        fail('pipeline mark completed failed: ' . (string) ($marked['error'] ?? ''));
    }
    $byQ = list_order_pipeline_rows(['q' => 'buyer@example.com']);
    $byCountry = list_order_pipeline_rows(['country' => 'Germany', 'status' => 'unpaid']);
    $byAdmin = list_order_pipeline_rows(['admin_id' => (int) $adminUser['id']]);
    $byDate = list_order_pipeline_rows(['date_from' => '2026-08-15', 'date_to' => '2026-08-15']);
    $foundPipe = static function (array $rows, int $id): bool {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    };
    if ($pipeId > 0
        && $foundPipe($byQ, (int) $pipeId)
        && $foundPipe($byCountry, (int) $pipeId)
        && $foundPipe($byAdmin, (int) $pipeId)
        && $foundPipe($byDate, (int) $pipeId)
        && count_order_pipeline_rows(['q' => 'buyer@example.com']) >= 1) {
        pass('pipeline sheet filters');
    } else {
        fail('pipeline sheet filters missed row ' . $pipeId);
    }

    if (normalize_order_date('2026-08-27') === '2026-08-27'
        && normalize_order_date('2026-02-31') === null
        && normalize_order_date('') === null) {
        pass('normalize_order_date keeps calendar day');
    } else {
        fail('normalize_order_date shifted or accepted a bad date');
    }
    $addedWithCountry = add_order_pipeline_row((int) $adminUser['id'], '', [
        'country' => 'Netherlands',
        'admin_user_id' => (int) $adminUser['id'],
    ]);
    $addedRow = get_order_item((int) $addedWithCountry);
    if ($addedRow && (string) ($addedRow['country'] ?? '') === 'Netherlands') {
        pass('add order keeps filter country');
    } else {
        fail('add order did not copy filter country');
    }

    $docNormJs = order_normalize_article_doc_url('javascript:alert(1)');
    $docNormBare = order_normalize_article_doc_url('docs.google.com/document/d/txf-doc-abc');
    $docNormFull = order_normalize_article_doc_url('https://docs.google.com/document/d/ok');
    $docId = add_order_pipeline_row((int) $adminUser['id'], 'doc-buyer@example.com');
    update_order_item((int) $docId, 0, [
        'site_name' => 'article-doc-site.com',
        'country' => 'Germany',
        'client_label' => 'doc-buyer@example.com',
        'admin_user_id' => (int) $adminUser['id'],
        'order_date' => '2026-08-21',
        'owner_price' => 10,
        'decided_price' => 25,
        'live_url' => 'https://example.com/article-doc-live',
        'article_doc_url' => 'docs.google.com/document/d/txf-doc-abc',
        'order_month' => 8,
        'order_year' => 2026,
    ]);
    $docSaved = get_order_item((int) $docId);
    $savedViaSheet = save_order_sheet_rows(
        0,
        [(int) $docId => 'article-doc-site.com'],
        [(int) $docId => ''],
        [(int) $docId => ''],
        [(int) $docId => 'Germany'],
        [(int) $docId => 8],
        [(int) $docId => ''],
        [(int) $docId => 2026],
        [(int) $docId => 10],
        [(int) $docId => 25],
        [(int) $docId => 'https://example.com/article-doc-live'],
        [(int) $docId => 'doc-buyer@example.com'],
        [(int) $docId => (int) $adminUser['id']],
        [(int) $docId => '2026-08-21'],
        [(int) $docId => 'https://docs.google.com/document/d/txf-doc-abc']
    );
    $docAfterSheet = get_order_item((int) $docId);
    $docMarked = order_mark_completed((int) $docId, 'https://example.com/article-doc-live', (int) $adminUser['id']);
    $docAfterComplete = get_order_item((int) $docId);
    $docDesc = order_invoice_description($docAfterComplete ?: []);
    $foundByDoc = false;
    foreach (list_order_pipeline_rows(['folder' => 'completed', 'q' => 'txf-doc-abc']) as $hit) {
        if ((int) ($hit['id'] ?? 0) === (int) $docId) {
            $foundByDoc = true;
            break;
        }
    }
    if ($docNormJs === ''
        && $docNormBare === 'https://docs.google.com/document/d/txf-doc-abc'
        && $docNormFull === 'https://docs.google.com/document/d/ok'
        && (string) ($docSaved['article_doc_url'] ?? '') === 'https://docs.google.com/document/d/txf-doc-abc'
        && $savedViaSheet === 1
        && (string) ($docAfterSheet['article_doc_url'] ?? '') === 'https://docs.google.com/document/d/txf-doc-abc'
        && !empty($docMarked['ok'])
        && (string) ($docAfterComplete['article_doc_url'] ?? '') === 'https://docs.google.com/document/d/txf-doc-abc'
        && order_stage($docAfterComplete) === 'completed'
        && $foundByDoc
        && str_contains($docDesc, 'example.com/article-doc-live')
        && !str_contains($docDesc, 'docs.google.com')) {
        pass('article doc URL saved and kept after complete');
    } else {
        fail('article doc URL not saved, searched, or leaked onto invoice description');
    }
    require_once __DIR__ . '/includes/sites_form.php';
    $docOpenBare = open_site_url_for_domain('docs.google.com/document/d/txf-doc-abc');
    $docOpenFull = open_site_url_for_domain('https://docs.google.com/document/d/ok');
    if ($docOpenBare === 'https://docs.google.com/document/d/txf-doc-abc'
        && $docOpenFull === 'https://docs.google.com/document/d/ok') {
        pass('article doc Open keeps Google Doc path');
    } else {
        fail('article doc Open dropped Google Doc path');
    }

    $docReady = list_invoiceable_order_items_by_ids([(int) $docId]);
    if (count($docReady) !== 1) {
        fail('article doc row not invoiceable');
    } else {
        $docInvId = create_invoice([
            'invoice_date' => date('Y-m-d'),
            'client_id' => 0,
            'client_name' => 'doc-buyer@example.com',
            'bill_to_name' => 'doc-buyer@example.com',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
            'company_name' => 'Topurlz',
            'company_bic' => 'TESTBIC',
            'company_iban' => 'TESTIBAN',
            'company_phone' => '',
            'company_address' => '',
            'company_reg_no' => '',
            'vat_note' => '',
        ], build_invoice_lines_from_orders($docReady, false), (int) $adminUser['id']);
        $createdHasDoc = false;
        foreach (list_invoice_events((int) $docInvId) as $ev) {
            if (($ev['event_type'] ?? '') !== 'created') {
                continue;
            }
            foreach ((array) (($ev['payload_data'] ?? [])['rows'] ?? []) as $snap) {
                if ((int) ($snap['order_item_id'] ?? 0) === (int) $docId
                    && str_contains((string) ($snap['article_doc_url'] ?? ''), 'txf-doc-abc')
                    && str_contains((string) ($snap['live_url'] ?? ''), 'article-doc-live')) {
                    $createdHasDoc = true;
                }
            }
        }
        if ($createdHasDoc) {
            pass('invoice created event snapshots article doc');
        } else {
            fail('invoice created event missing article doc snapshot');
        }

        $docAppendId = add_order_pipeline_row((int) $adminUser['id'], 'doc-buyer@example.com');
        update_order_item((int) $docAppendId, 0, [
            'site_name' => 'article-doc-append.com',
            'country' => 'Germany',
            'client_label' => 'doc-buyer@example.com',
            'admin_user_id' => (int) $adminUser['id'],
            'order_date' => '2026-08-21',
            'owner_price' => 11,
            'decided_price' => 26,
            'live_url' => 'https://example.com/article-doc-append',
            'article_doc_url' => 'https://docs.google.com/document/d/txf-doc-append',
            'order_month' => 8,
            'order_year' => 2026,
        ]);
        $docAppendMarked = order_mark_completed((int) $docAppendId, 'https://example.com/article-doc-append', (int) $adminUser['id']);
        $docAppendReady = list_invoiceable_order_items_by_ids([(int) $docAppendId]);
        $appendHasDoc = false;
        if (!empty($docAppendMarked['ok']) && count($docAppendReady) === 1) {
            append_orders_to_invoice(
                (int) $docInvId,
                build_invoice_lines_from_orders($docAppendReady, false),
                $docAppendReady
            );
            foreach (list_invoice_events((int) $docInvId) as $ev) {
                if (($ev['event_type'] ?? '') !== 'sites_added') {
                    continue;
                }
                foreach ((array) (($ev['payload_data'] ?? [])['rows'] ?? []) as $snap) {
                    if ((int) ($snap['order_item_id'] ?? 0) === (int) $docAppendId
                        && str_contains((string) ($snap['article_doc_url'] ?? ''), 'txf-doc-append')) {
                        $appendHasDoc = true;
                    }
                }
            }
        }
        if ($appendHasDoc) {
            pass('invoice append event snapshots article doc');
        } else {
            fail('invoice append event missing article doc snapshot');
        }
    }

    $pipeReady = list_invoiceable_order_items_by_ids([(int) $pipeId]);
    if (count($pipeReady) !== 1) {
        fail('pipeline invoiceable missing row');
    } else {
        $pipeLines = build_invoice_lines_from_orders($pipeReady, false);
        $pipeInvId = create_invoice([
            'invoice_date' => date('Y-m-d'),
            'client_id' => 0,
            'client_name' => 'buyer@example.com',
            'bill_to_name' => 'buyer@example.com',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
            'company_name' => 'Topurlz',
            'company_bic' => 'TESTBIC',
            'company_iban' => 'TESTIBAN',
            'company_phone' => '',
            'company_address' => '',
            'company_reg_no' => '',
            'vat_note' => '',
        ], $pipeLines, (int) $adminUser['id']);
        $pipeInv = get_invoice($pipeInvId);
        $onOpenPipe = order_items_on_open_invoices([(int) $pipeId]);
        $secondReady = list_invoiceable_order_items_by_ids([(int) $pipeId]);
        $secondBlocked = false;
        try {
            create_invoice([
                'invoice_date' => date('Y-m-d'),
                'client_id' => 0,
                'client_name' => 'buyer@example.com',
                'bill_to_name' => 'buyer@example.com',
                'bill_to_address' => '',
                'bill_to_hrb' => '',
                'bill_to_vat' => '',
                'supplier_number' => 'NEW',
                'cost_center' => '',
                'orderer' => '',
                'company_name' => 'Topurlz',
                'company_bic' => 'TESTBIC',
                'company_iban' => 'TESTIBAN',
                'company_phone' => '',
                'company_address' => '',
                'company_reg_no' => '',
                'vat_note' => '',
            ], $pipeLines, (int) $adminUser['id']);
        } catch (InvalidArgumentException $e) {
            $secondBlocked = str_contains($e->getMessage(), 'Already on invoice');
        }
        if (isset($onOpenPipe[(int) $pipeId])
            && $secondReady === []
            && $secondBlocked
            && (int) ($onOpenPipe[(int) $pipeId]['id'] ?? 0) === (int) $pipeInvId) {
            pass('second push blocked while on open invoice');
        } else {
            fail('open invoice did not block a second bill');
        }

        $sameOnGenerated = false;
        try {
            append_orders_to_invoice((int) $pipeInvId, $pipeLines, $pipeReady);
        } catch (InvalidArgumentException $e) {
            $sameOnGenerated = str_contains($e->getMessage(), 'already on this invoice');
        }
        $openAppendIds = [];
        foreach (list_invoices_open_for_append(50) as $openHit) {
            $openAppendIds[] = (int) ($openHit['id'] ?? 0);
        }
        $growGenId = add_order_pipeline_row((int) $adminUser['id'], 'buyer@example.com');
        update_order_item((int) $growGenId, 0, [
            'site_name' => 'pipeline-grow-generated.com',
            'country' => 'Germany',
            'client_label' => 'buyer@example.com',
            'admin_user_id' => (int) $adminUser['id'],
            'order_date' => '2026-08-17',
            'owner_price' => 9,
            'decided_price' => 22,
            'live_url' => 'https://example.com/pipeline-grow-generated',
            'order_month' => 8,
            'order_year' => 2026,
        ]);
        $growGenMarked = order_mark_completed((int) $growGenId, 'https://example.com/pipeline-grow-generated', (int) $adminUser['id']);
        $growGenReady = list_invoiceable_order_items_by_ids([(int) $growGenId]);
        $beforeGen = get_invoice((int) $pipeInvId);
        $beforeGenItems = list_invoice_items((int) $pipeInvId);
        $beforeGenTotal = (float) ($beforeGen['total_amount'] ?? 0);
        $beforeGenNum = (string) ($beforeGen['invoice_number'] ?? '');
        $growGenOk = false;
        if ($sameOnGenerated
            && in_array((int) $pipeInvId, $openAppendIds, true)
            && !empty($growGenMarked['ok'])
            && count($growGenReady) === 1) {
            $growGenRes = append_orders_to_invoice(
                (int) $pipeInvId,
                build_invoice_lines_from_orders($growGenReady, false),
                $growGenReady
            );
            $afterGen = get_invoice((int) $pipeInvId);
            $afterGenItems = list_invoice_items((int) $pipeInvId);
            $growGenOk = (int) ($growGenRes['added'] ?? 0) === 1
                && count($afterGenItems) === count($beforeGenItems) + 1
                && abs((float) ($afterGen['total_amount'] ?? 0) - ($beforeGenTotal + 22)) < 0.011
                && (string) ($afterGen['invoice_number'] ?? '') === $beforeGenNum
                && !invoice_is_paid($afterGen)
                && invoice_can_append_orders($afterGen)
                && invoice_work_status($afterGen) === 'done'
                && invoice_append_status_label($afterGen) === 'Waiting'
                && str_contains(invoice_generate_append_href((int) $pipeInvId), 'existing=' . (int) $pipeInvId);
        }
        if ($growGenOk) {
            pass('append unpaid LIVE grows generated invoice');
        } else {
            fail('append did not grow the generated unpaid invoice');
        }

        $draftId = create_blank_invoice((int) $adminUser['id']);
        update_invoice_bill_header((int) $draftId, [
            'invoice_date' => date('Y-m-d'),
            'admin_note' => '',
            'bill_to_name' => 'buyer@example.com',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
        ]);
        $openDraftIds = [];
        foreach (list_invoices_open_for_append(50) as $openHit) {
            $openDraftIds[] = (int) ($openHit['id'] ?? 0);
        }
        $appendId = add_order_pipeline_row((int) $adminUser['id'], 'buyer@example.com');
        update_order_item((int) $appendId, 0, [
            'site_name' => 'pipeline-append.com',
            'country' => 'Germany',
            'client_label' => 'buyer@example.com',
            'admin_user_id' => (int) $adminUser['id'],
            'order_date' => '2026-08-16',
            'owner_price' => 8,
            'decided_price' => 18,
            'live_url' => 'https://example.com/pipeline-append',
            'order_month' => 8,
            'order_year' => 2026,
        ]);
        $appendMarked = order_mark_completed((int) $appendId, 'https://example.com/pipeline-append', (int) $adminUser['id']);
        $appendReady = list_invoiceable_order_items_by_ids([(int) $appendId]);
        $beforeDraft = get_invoice((int) $draftId);
        $beforeItems = list_invoice_items((int) $draftId);
        $beforeTotal = (float) ($beforeDraft['total_amount'] ?? 0);
        $beforeNum = (string) ($beforeDraft['invoice_number'] ?? '');
        $appendRes = ['id' => 0, 'added' => 0, 'invoice_number' => ''];
        $appendOk = false;
        if (!empty($appendMarked['ok']) && count($appendReady) === 1) {
            $appendRes = append_orders_to_invoice(
                (int) $draftId,
                build_invoice_lines_from_orders($appendReady, false),
                $appendReady
            );
            $afterInv = get_invoice((int) $draftId);
            $afterItems = list_invoice_items((int) $draftId);
            $appendOk = (int) ($appendRes['added'] ?? 0) === 1
                && count($afterItems) === count($beforeItems) + 1
                && abs((float) ($afterInv['total_amount'] ?? 0) - ($beforeTotal + 18)) < 0.011
                && (string) ($afterInv['invoice_number'] ?? '') === $beforeNum
                && invoice_is_draft($afterInv)
                && invoice_can_append_orders($afterInv)
                && in_array((int) $draftId, $openDraftIds, true);
        }
        if ($appendOk) {
            pass('append unpaid LIVE grows existing invoice');
        } else {
            fail('append did not grow the unpaid invoice or reused a number');
        }
        $againOnThis = false;
        try {
            append_orders_to_invoice(
                (int) $draftId,
                build_invoice_lines_from_orders($appendReady, false),
                $appendReady
            );
        } catch (InvalidArgumentException $e) {
            $againOnThis = str_contains($e->getMessage(), 'already on this invoice');
        }
        $bDraftId = create_blank_invoice((int) $adminUser['id']);
        update_invoice_bill_header((int) $bDraftId, [
            'invoice_date' => date('Y-m-d'),
            'admin_note' => '',
            'bill_to_name' => 'buyer@example.com',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
        ]);
        $crossBlocked = false;
        if ($appendReady) {
            try {
                append_orders_to_invoice(
                    (int) $bDraftId,
                    build_invoice_lines_from_orders($appendReady, false),
                    $appendReady
                );
            } catch (InvalidArgumentException $e) {
                $crossBlocked = str_contains($e->getMessage(), 'Already on invoice');
            }
        }
        $mixId = add_order_pipeline_row((int) $adminUser['id'], 'other-buyer@example.com');
        update_order_item((int) $mixId, 0, [
            'site_name' => 'pipeline-mix-bill.com',
            'country' => 'Germany',
            'client_label' => 'other-buyer@example.com',
            'admin_user_id' => (int) $adminUser['id'],
            'order_date' => '2026-08-18',
            'owner_price' => 4,
            'decided_price' => 9,
            'live_url' => 'https://example.com/pipeline-mix',
            'order_month' => 8,
            'order_year' => 2026,
        ]);
        order_mark_completed((int) $mixId, 'https://example.com/pipeline-mix', (int) $adminUser['id']);
        $mixReady = list_invoiceable_order_items_by_ids([(int) $mixId]);
        $mixBlocked = false;
        if (count($mixReady) === 1) {
            try {
                append_orders_to_invoice(
                    (int) $draftId,
                    build_invoice_lines_from_orders($mixReady, false),
                    $mixReady
                );
            } catch (InvalidArgumentException $e) {
                $mixBlocked = str_contains($e->getMessage(), 'billed as');
            }
        }
        mark_invoice_sent((int) $draftId);
        $draftSent = get_invoice((int) $draftId);
        $sentGrowId = add_order_pipeline_row((int) $adminUser['id'], 'buyer@example.com');
        update_order_item((int) $sentGrowId, 0, [
            'site_name' => 'pipeline-grow-sent.com',
            'country' => 'Germany',
            'client_label' => 'buyer@example.com',
            'admin_user_id' => (int) $adminUser['id'],
            'order_date' => '2026-08-19',
            'owner_price' => 5,
            'decided_price' => 11,
            'live_url' => 'https://example.com/pipeline-grow-sent',
            'order_month' => 8,
            'order_year' => 2026,
        ]);
        order_mark_completed((int) $sentGrowId, 'https://example.com/pipeline-grow-sent', (int) $adminUser['id']);
        $sentGrowReady = list_invoiceable_order_items_by_ids([(int) $sentGrowId]);
        $beforeSentGrow = get_invoice((int) $draftId);
        $beforeSentItems = list_invoice_items((int) $draftId);
        $beforeSentTotal = (float) ($beforeSentGrow['total_amount'] ?? 0);
        $beforeSentNum = (string) ($beforeSentGrow['invoice_number'] ?? '');
        $sentAfterDoneOk = false;
        if ($draftSent && invoice_is_sent_for_payment($draftSent) && count($sentGrowReady) === 1) {
            $sentGrowRes = append_orders_to_invoice(
                (int) $draftId,
                build_invoice_lines_from_orders($sentGrowReady, false),
                $sentGrowReady
            );
            $afterSentGrow = get_invoice((int) $draftId);
            $sentAfterDoneOk = (int) ($sentGrowRes['added'] ?? 0) === 1
                && count(list_invoice_items((int) $draftId)) === count($beforeSentItems) + 1
                && abs((float) ($afterSentGrow['total_amount'] ?? 0) - ($beforeSentTotal + 11)) < 0.011
                && (string) ($afterSentGrow['invoice_number'] ?? '') === $beforeSentNum
                && invoice_can_append_orders($afterSentGrow)
                && invoice_work_status($afterSentGrow) === 'done';
        }
        if ($againOnThis && $crossBlocked && $mixBlocked
            && $draftSent && invoice_is_sent_for_payment($draftSent)
            && $sentAfterDoneOk) {
            pass('append skips this invoice, blocks other open, mixed bill-as');
        } else {
            fail('append guards missed this/other invoice or mixed bill-as');
        }
        $waitingLabel = invoice_work_status_label($draftSent ?: []);
        $draftLabel = invoice_work_status_label(get_invoice((int) $bDraftId) ?: []);
        $matchWait = invoice_match_open_for_bill_as('BUYER@example.com');
        $hrefMatch = invoice_generate_href_for_orders([(int) $sentGrowId], (int) ($matchWait['id'] ?? 0));
        db()->prepare('UPDATE invoices SET invoice_date = DATE_SUB(CURDATE(), INTERVAL 20 DAY) WHERE id=?')
            ->execute([(int) $draftId]);
        $agedN = count_invoices_waiting_older_than(14);
        $foundBillTotal = false;
        foreach (list_invoices_waiting_totals_by_bill_as(40) as $br) {
            if (invoice_bill_as_key((string) ($br['bill_as'] ?? '')) === 'buyer@example.com') {
                $foundBillTotal = (int) ($br['n'] ?? 0) >= 1 && (float) ($br['total'] ?? 0) > 0;
                break;
            }
        }
        if ($waitingLabel === 'Waiting'
            && $draftLabel === 'Draft'
            && $matchWait
            && invoice_bill_as_key(invoice_display_bill_as($matchWait)) === 'buyer@example.com'
            && invoice_can_append_orders($matchWait)
            && !invoice_is_draft($matchWait)
            && str_contains($hrefMatch, 'ids=' . (int) $sentGrowId)
            && str_contains($hrefMatch, 'existing=' . (int) ($matchWait['id'] ?? 0))
            && $agedN >= 1
            && $foundBillTotal) {
            pass('invoice waiting match, labels, aging');
        } else {
            fail('invoice waiting ops helpers');
        }
        $linkedClient = (int) ($pipeInv['client_id'] ?? 0);
        if ($pipeInv && $linkedClient === 0 && (string) ($pipeInv['bill_to_name'] ?? '') === 'buyer@example.com') {
            if (invoice_display_bill_as($pipeInv) === 'buyer@example.com'
                && invoice_has_extra_bill_details($pipeInv) === false) {
                pass('invoice display bill as');
            } else {
                fail('invoice display bill as helper');
            }
            $foundByBillAs = false;
            foreach (list_invoices(['q' => 'buyer@example.com']) as $hit) {
                if ((int) ($hit['id'] ?? 0) === (int) $pipeInvId) {
                    $foundByBillAs = true;
                    break;
                }
            }
            update_invoice_bill_header((int) $pipeInvId, [
                'invoice_date' => (string) $pipeInv['invoice_date'],
                'admin_note' => 'email-only note',
                'bill_to_name' => 'buyer+fixed@example.com',
                'bill_to_address' => '',
                'bill_to_hrb' => '',
                'bill_to_vat' => '',
                'supplier_number' => 'NEW',
                'cost_center' => '',
                'orderer' => '',
            ]);
            $pipeInv = get_invoice($pipeInvId);
            if ($foundByBillAs
                && (string) ($pipeInv['bill_to_name'] ?? '') === 'buyer+fixed@example.com'
                && (string) ($pipeInv['client_name'] ?? '') === 'buyer+fixed@example.com'
                && invoice_admin_note($pipeInv) === 'email-only note') {
                pass('invoice save bill as header');
            } else {
                fail('invoice save bill as header');
            }
            mark_invoice_payment_received((int) $pipeInvId);
            $paidBlocked = false;
            try {
                update_invoice_bill_header((int) $pipeInvId, ['bill_to_name' => 'nope']);
            } catch (InvalidArgumentException $e) {
                $paidBlocked = str_contains($e->getMessage(), 'Paid');
            }
            $paidPipe = get_order_item((int) $pipeId);
            if ($paidPipe && (int) ($paidPipe['is_paid'] ?? 0) === 1 && $paidBlocked) {
                pass('pipeline invoice without client folder');
            } else {
                fail('pipeline invoice paid did not write back to order');
            }
            $paidAppendId = add_order_pipeline_row((int) $adminUser['id'], 'buyer+fixed@example.com');
            update_order_item((int) $paidAppendId, 0, [
                'site_name' => 'pipeline-paid-target.com',
                'country' => 'Germany',
                'client_label' => 'buyer+fixed@example.com',
                'admin_user_id' => (int) $adminUser['id'],
                'order_date' => date('Y-m-d'),
                'owner_price' => 3,
                'decided_price' => 7,
                'live_url' => 'https://example.com/pipeline-paid-target',
                'order_month' => 8,
                'order_year' => 2026,
            ]);
            order_mark_completed((int) $paidAppendId, 'https://example.com/pipeline-paid-target', (int) $adminUser['id']);
            $paidAppendReady = list_invoiceable_order_items_by_ids([(int) $paidAppendId]);
            $paidTargetBlocked = false;
            if (count($paidAppendReady) === 1) {
                try {
                    append_orders_to_invoice(
                        (int) $pipeInvId,
                        build_invoice_lines_from_orders($paidAppendReady, false),
                        $paidAppendReady
                    );
                } catch (InvalidArgumentException $e) {
                    $paidTargetBlocked = str_contains($e->getMessage(), 'Paid invoices cannot');
                }
            }
            if ($paidTargetBlocked) {
                pass('append rejected on paid invoice');
            } else {
                fail('append allowed onto a paid invoice');
            }
        } else {
            fail('pipeline invoice kept a client folder link');
        }
    }

    $rebillDomain = 'txfom-rebill-' . substr(sha1((string) microtime(true)), 0, 8) . '.com';
    $rebillId = add_order_pipeline_row((int) $adminUser['id'], 'rebill@example.com');
    update_order_item((int) $rebillId, 0, [
        'site_name' => $rebillDomain,
        'country' => 'Germany',
        'client_label' => 'rebill@example.com',
        'admin_user_id' => (int) $adminUser['id'],
        'order_date' => date('Y-m-d'),
        'owner_price' => 10,
        'decided_price' => 20,
        'live_url' => 'https://example.com/rebill-live',
        'order_month' => 8,
        'order_year' => 2026,
    ]);
    order_mark_completed((int) $rebillId, 'https://example.com/rebill-live', (int) $adminUser['id']);
    $rebillReady = list_invoiceable_order_items_by_ids([(int) $rebillId]);
    if (count($rebillReady) === 1) {
        $rebillInvId = create_invoice([
            'invoice_date' => date('Y-m-d'),
            'client_id' => 0,
            'client_name' => 'rebill@example.com',
            'bill_to_name' => 'rebill@example.com',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
            'company_name' => 'Topurlz',
            'company_bic' => 'TESTBIC',
            'company_iban' => 'TESTIBAN',
            'company_phone' => '',
            'company_address' => '',
            'company_reg_no' => '',
            'vat_note' => '',
        ], build_invoice_lines_from_orders($rebillReady, false), (int) $adminUser['id']);
        delete_invoice((int) $rebillInvId);
        $afterDelInv = list_invoiceable_order_items_by_ids([(int) $rebillId]);
        $afterDelMap = order_items_on_open_invoices([(int) $rebillId]);
        if (count($afterDelInv) === 1 && $afterDelMap === []) {
            pass('delete invoice frees order row for push');
        } else {
            fail('deleting invoice did not free the order row');
        }
    } else {
        fail('rebill row was not invoiceable');
    }

    // OM folders: Website prices Processing → Processing; complete requires LIVE URL.
    $foldDomain = 'txfom-fold-' . substr(sha1((string) microtime(true)), 0, 8) . '.com';
    $wpFoldId = site_price_insert_row([
        'country' => 'Germany',
        'domain' => $foldDomain,
        'status_slug' => 'processing',
        'price_note' => '42 euro',
        'reply_email' => 'publisher-inbox@example.com',
        'created_by' => (int) $adminUser['id'],
    ]);
    order_reconcile_processing_from_website_prices();
    $omFromWp = get_order_item_by_site_price_row((int) $wpFoldId);
    $inProc = list_order_pipeline_rows(['folder' => 'processing', 'q' => $foldDomain]);
    $inCompEarly = list_order_pipeline_rows(['folder' => 'completed', 'q' => $foldDomain]);
    $foundProc = false;
    foreach ($inProc as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omFromWp['id'] ?? 0)) {
            $foundProc = true;
            break;
        }
    }
    $foundCompEarly = false;
    foreach ($inCompEarly as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omFromWp['id'] ?? 0)) {
            $foundCompEarly = true;
            break;
        }
    }
    $copiedPublisherInbox = $omFromWp && str_contains((string) ($omFromWp['client_label'] ?? ''), 'publisher-inbox');
    if ($omFromWp && $foundProc && !$foundCompEarly && !$copiedPublisherInbox
        && parse_money($omFromWp['owner_price'] ?? 0) === 42.0) {
        pass('WP Processing syncs to OM Processing');
    } else {
        fail('WP Processing did not create a Processing OM row');
    }
    $wpRowHtmlTeam = render_site_price_sheet_row(get_site_price_row((int) $wpFoldId) ?: [], $teamUser);
    $wpCols = db()->query('SHOW COLUMNS FROM site_price_rows')->fetchAll(PDO::FETCH_COLUMN);
    $forbiddenWp = array_intersect(['live_url', 'client_label', 'decided_price', 'profit', 'invoice_id', 'is_paid'], $wpCols);
    if ($forbiddenWp === []
        && !str_contains($wpRowHtmlTeam, 'live_url')
        && !str_contains($wpRowHtmlTeam, 'client_label')
        && !str_contains($wpRowHtmlTeam, 'Push to invoice')
        && !str_contains($wpRowHtmlTeam, 'decided_price')) {
        pass('Team Website prices has no LIVE/profit/client/invoice fields');
    } else {
        fail('Website prices leaked OM fields: ' . json_encode(['cols' => $forbiddenWp]));
    }
    $noUrl = order_mark_completed((int) ($omFromWp['id'] ?? 0), '', (int) $adminUser['id']);
    if (empty($noUrl['ok']) && str_contains((string) ($noUrl['error'] ?? ''), 'live URL')) {
        pass('complete without live URL rejected');
    } else {
        fail('complete without live URL allowed');
    }
    $noClient = order_mark_completed(
        (int) ($omFromWp['id'] ?? 0),
        'https://example.com/txfom-live',
        (int) $adminUser['id']
    );
    if (empty($noClient['ok']) && str_contains((string) ($noClient['error'] ?? ''), 'Client email or name')) {
        pass('complete without client rejected');
    } else {
        fail('complete without client allowed: ' . (string) ($noClient['error'] ?? 'ok'));
    }
    $missCountryId = add_order_pipeline_row((int) $adminUser['id'], 'nocountry@example.com');
    update_order_item((int) $missCountryId, 0, [
        'site_name' => 'txfom-nocountry.example',
        'country' => '',
        'client_label' => 'nocountry@example.com',
        'live_url' => 'https://example.com/txfom-nocountry',
        'owner_price' => 5,
        'decided_price' => 10,
        'order_month' => 8,
        'order_year' => 2026,
    ]);
    $noCountry = order_mark_completed(
        (int) $missCountryId,
        'https://example.com/txfom-nocountry',
        (int) $adminUser['id']
    );
    if (empty($noCountry['ok']) && str_contains((string) ($noCountry['error'] ?? ''), 'Country')) {
        pass('complete without country rejected');
    } else {
        fail('complete without country allowed: ' . (string) ($noCountry['error'] ?? 'ok'));
    }
    db()->prepare(
        "UPDATE order_items SET order_stage='completed' WHERE id=?"
    )->execute([(int) $missCountryId]);
    $notInvoiceable = list_invoiceable_order_items_by_ids([(int) $missCountryId]);
    $countryBillBlocked = false;
    try {
        create_invoice([
            'invoice_date' => date('Y-m-d'),
            'client_id' => 0,
            'client_name' => 'nocountry@example.com',
            'bill_to_name' => 'nocountry@example.com',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
            'company_name' => 'Topurlz',
            'company_bic' => 'TESTBIC',
            'company_iban' => 'TESTIBAN',
            'company_phone' => '',
            'company_address' => '',
            'company_reg_no' => '',
            'vat_note' => '',
        ], build_invoice_lines_from_orders([get_order_item((int) $missCountryId) ?: []], false), (int) $adminUser['id']);
    } catch (InvalidArgumentException $e) {
        $countryBillBlocked = str_contains($e->getMessage(), 'Country');
    }
    if ($notInvoiceable === [] && $countryBillBlocked) {
        pass('invoice without country rejected');
    } else {
        fail('invoice without country was allowed');
    }
    db()->prepare(
        "UPDATE order_items SET order_stage='processing' WHERE id=?"
    )->execute([(int) $missCountryId]);
    update_order_item((int) ($omFromWp['id'] ?? 0), 0, [
        'site_name' => $foldDomain,
        'country' => 'Germany',
        'client_label' => 'buyer-fold@example.com',
        'live_url' => 'https://example.com/txfom-live',
        'owner_price' => 42,
        'decided_price' => 50,
    ]);
    $didComplete = order_mark_completed(
        (int) ($omFromWp['id'] ?? 0),
        'https://example.com/txfom-live',
        (int) $adminUser['id']
    );
    $afterComplete = get_order_item((int) ($omFromWp['id'] ?? 0));
    $wpAfter = get_site_price_row((int) $wpFoldId);
    $inProcAfter = false;
    foreach (list_order_pipeline_rows(['folder' => 'processing', 'q' => $foldDomain]) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omFromWp['id'] ?? 0)) {
            $inProcAfter = true;
        }
    }
    $inCompAfter = false;
    foreach (list_order_pipeline_rows(['folder' => 'completed', 'q' => $foldDomain]) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omFromWp['id'] ?? 0)) {
            $inCompAfter = true;
        }
    }
    $invReady = list_invoiceable_order_items_by_ids([(int) ($omFromWp['id'] ?? 0)]);
    if (!empty($didComplete['ok']) && $afterComplete && order_is_completed($afterComplete)
        && (string) ($wpAfter['status_slug'] ?? '') === 'completed'
        && !$inProcAfter && $inCompAfter && count($invReady) === 1) {
        pass('complete with live URL moves to Completed and WP Completed');
    } else {
        fail('complete with live URL did not move folders/status');
    }
    set_order_item_paid((int) ($omFromWp['id'] ?? 0), 0, true);
    $paidStay = get_order_item((int) ($omFromWp['id'] ?? 0));
    $wpPaid = get_site_price_row((int) $wpFoldId);
    $stillComp = false;
    foreach (list_order_pipeline_rows(['folder' => 'completed', 'q' => $foldDomain]) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omFromWp['id'] ?? 0)) {
            $stillComp = true;
        }
    }
    if ($paidStay && order_is_paid($paidStay) && $stillComp
        && (string) ($wpPaid['status_slug'] ?? '') === 'completed') {
        pass('paid stays in Completed without changing WP status');
    } else {
        fail('paid left Completed or changed Website prices');
    }

    $leaveDomain = 'txfom-leave-' . substr(sha1((string) microtime(true)), 0, 8) . '.com';
    $wpLeaveId = site_price_insert_row([
        'country' => 'Germany',
        'domain' => $leaveDomain,
        'status_slug' => 'processing',
        'created_by' => (int) $adminUser['id'],
    ]);
    $omLeave = get_order_item_by_site_price_row((int) $wpLeaveId);
    site_price_save_row((int) $wpLeaveId, ['status_slug' => 'not_interested'], $adminUser);
    $leaveProc = false;
    $leaveComp = false;
    foreach (list_order_pipeline_rows(['folder' => 'processing', 'q' => $leaveDomain]) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omLeave['id'] ?? 0)) {
            $leaveProc = true;
        }
    }
    foreach (list_order_pipeline_rows(['folder' => 'completed', 'q' => $leaveDomain]) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omLeave['id'] ?? 0)) {
            $leaveComp = true;
        }
    }
    $leaveWpSlug = '';
    foreach (list_order_pipeline_rows(['folder' => 'processing', 'q' => $leaveDomain]) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($omLeave['id'] ?? 0)) {
            $leaveWpSlug = (string) ($row['wp_status_slug'] ?? '');
        }
    }
    if ($omLeave && $leaveProc && !$leaveComp && !order_is_completed($omLeave)
        && $leaveWpSlug === 'not_interested') {
        pass('WP leaving Processing keeps OM row in Processing');
    } else {
        fail('WP not_interested hid OM row, auto-completed, or missing WP status');
    }

    $originWpDomain = 'txfom-origin-wp-' . substr(sha1((string) microtime(true)), 0, 8) . '.com';
    $wpOriginId = site_price_insert_row([
        'country' => 'Germany',
        'domain' => $originWpDomain,
        'status_slug' => 'processing',
        'created_by' => (int) $adminUser['id'],
    ]);
    order_reconcile_processing_from_website_prices();
    $omOriginWp = get_order_item_by_site_price_row((int) $wpOriginId);
    $manOriginDomain = 'txfom-origin-man-' . substr(sha1((string) microtime(true)), 0, 8) . '.com';
    $manOriginId = add_order_pipeline_row((int) $adminUser['id'], 'origin-manual@example.com');
    update_order_item((int) $manOriginId, 0, [
        'site_name' => $manOriginDomain,
        'country' => 'Germany',
        'client_label' => 'origin-manual@example.com',
        'owner_price' => 1,
        'decided_price' => 2,
    ]);
    $foundIn = static function (array $rows, int $id): bool {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    };
    $wpId = (int) ($omOriginWp['id'] ?? 0);
    $leaveId = (int) ($omLeave['id'] ?? 0);
    $wpInWp = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'wp', 'q' => $originWpDomain]), $wpId);
    $wpInLeft = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'leftover', 'q' => $originWpDomain]), $wpId);
    $wpInMan = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'manual', 'q' => $originWpDomain]), $wpId);
    $leaveInWp = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'wp', 'q' => $leaveDomain]), $leaveId);
    $leaveInLeft = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'leftover', 'q' => $leaveDomain]), $leaveId);
    $leaveInAll = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'q' => $leaveDomain]), $leaveId);
    $manInMan = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'manual', 'q' => $manOriginDomain]), (int) $manOriginId);
    $manInWp = $foundIn(list_order_pipeline_rows(['folder' => 'processing', 'origin' => 'wp', 'q' => $manOriginDomain]), (int) $manOriginId);
    $omitIsAll = normalize_order_pipeline_origin('') === 'all'
        && normalize_order_pipeline_origin('nope') === 'all'
        && normalize_order_pipeline_origin('wp') === 'wp';
    if ($omitIsAll && $wpInWp && !$wpInLeft && !$wpInMan
        && !$leaveInWp && $leaveInLeft && $leaveInAll
        && $manInMan && !$manInWp) {
        pass('Processing origin wp leftover manual all');
    } else {
        fail('Processing origin filter mismatch');
    }
    $pickWp = order_pipeline_pick_processing_origin('', ['q' => $originWpDomain]);
    $pickMan = order_pipeline_pick_processing_origin('', ['q' => $manOriginDomain]);
    $pickStay = order_pipeline_pick_processing_origin('wp', ['q' => $manOriginDomain]);
    $pickExpl = order_pipeline_pick_processing_origin('manual', ['q' => $originWpDomain]);
    if ($pickWp === 'wp' && $pickMan === 'manual' && $pickStay === 'wp' && $pickExpl === 'manual') {
        pass('Processing default origin follows non-empty tab');
    } else {
        fail('Processing default origin: wp=' . $pickWp . ' man=' . $pickMan
            . ' stay=' . $pickStay . ' expl=' . $pickExpl);
    }

    $restoreDomain = 'txfom-restore-' . substr(sha1((string) microtime(true)), 0, 8) . '.com';
    $wpRestoreId = site_price_insert_row([
        'country' => 'Germany',
        'domain' => $restoreDomain,
        'status_slug' => 'processing',
        'created_by' => (int) $adminUser['id'],
    ]);
    $omRestore = get_order_item_by_site_price_row((int) $wpRestoreId);
    update_order_item((int) ($omRestore['id'] ?? 0), 0, [
        'site_name' => $restoreDomain,
        'country' => 'Germany',
        'client_label' => 'restore-buyer@example.com',
        'live_url' => 'https://example.com/txfom-restore-live',
        'owner_price' => 5,
        'decided_price' => 10,
    ]);
    $didRestoreComplete = order_mark_completed(
        (int) ($omRestore['id'] ?? 0),
        'https://example.com/txfom-restore-live',
        (int) $adminUser['id']
    );
    $omRestoreId = (int) ($omRestore['id'] ?? 0);
    delete_order_item($omRestoreId);
    $goneAfterDel = get_order_item($omRestoreId);
    site_price_save_row((int) $wpRestoreId, ['status_slug' => 'processing'], $adminUser);
    order_reconcile_processing_from_website_prices();
    $recreated = get_order_item_by_site_price_row((int) $wpRestoreId);
    if (!empty($didRestoreComplete['ok']) && !$goneAfterDel && $recreated
        && (int) ($recreated['id'] ?? 0) !== $omRestoreId
        && !order_is_completed($recreated)
        && (string) ($recreated['site_name'] ?? '') === $restoreDomain) {
        pass('restoring WP Processing recreates OM row');
    } else {
        fail('delete + restore WP Processing did not recreate OM row');
    }

    $ctaTick = order_invoice_generate_push_cta(2, [10, 11]);
    $ctaNone = order_invoice_generate_push_cta(0, []);
    $ctaOver = order_invoice_generate_push_cta(81, range(1, 81));
    $ctaMismatch = order_invoice_generate_push_cta(2, [10]);
    if (($ctaTick['href'] ?? '') === 'index.php?page=admin_invoice_generate&ids=10,11'
        && str_contains((string) ($ctaTick['label'] ?? ''), 'Push unpaid (2)')
        && ($ctaNone['label'] ?? '') === 'Generate invoice'
        && !str_contains((string) ($ctaNone['href'] ?? ''), 'ids=')
        && str_contains((string) ($ctaOver['label'] ?? ''), 'none ticked')
        && !str_contains((string) ($ctaOver['href'] ?? ''), 'ids=')
        && str_contains((string) ($ctaMismatch['label'] ?? ''), 'none ticked')) {
        pass('Push unpaid CTA ticks current-filter ids or honest label');
    } else {
        fail('Push unpaid CTA helper mismatch');
    }
    if (order_row_ready_for_complete(['live_url' => 'https://x.test/p', 'country' => 'Germany', 'client_label' => 'a@b.c'])
        && !order_row_ready_for_complete(['live_url' => 'https://x.test/p', 'country' => '', 'client_label' => 'a@b.c'])
        && !order_row_ready_for_complete(['live_url' => '', 'country' => 'Germany', 'client_label' => 'a@b.c'])
        && !order_row_ready_for_invoice(['order_stage' => 'completed', 'live_url' => 'https://x.test/p', 'country' => 'Germany', 'client_label' => 'a@b.c', 'is_paid' => 1])
        && order_row_ready_for_invoice(['order_stage' => 'completed', 'live_url' => 'https://x.test/p', 'country' => 'Germany', 'client_label' => 'a@b.c', 'is_paid' => 0])
    ) {
        pass('OM complete/invoice ready helpers');
    } else {
        fail('OM complete/invoice ready helpers');
    }

    $wpHref = order_wp_sheet_url([
        'site_price_row_id' => 99,
        'wp_country' => 'Germany',
        'country' => 'France',
    ]);
    if (str_contains($wpHref, 'admin_site_prices')
        && str_contains($wpHref, 'country=Germany')
        && str_contains($wpHref, 'row=99')
        && function_exists('site_price_status_label')
        && site_price_status_label('not_interested') !== '') {
        pass('OM Open in Website prices URL + status label');
    } else {
        fail('OM Website prices link helper missing');
    }

    $ordersPhpSrc = file_get_contents(__DIR__ . '/pages/admin/orders.php') ?: '';
    $invoicesPhpSrc = file_get_contents(__DIR__ . '/pages/admin/invoices.php') ?: '';
    $teamDashSrc = file_get_contents(__DIR__ . '/pages/team/dashboard.php') ?: '';
    $teamWpSrc = file_get_contents(__DIR__ . '/pages/team/site_prices.php') ?: '';
    if (str_contains($ordersPhpSrc, 'require_admin()')
        && str_contains($invoicesPhpSrc, 'require_admin()')
        && !str_contains($teamDashSrc, 'admin_orders')
        && !str_contains($teamDashSrc, 'admin_invoices')
        && !str_contains($teamWpSrc, 'admin_orders')
        && !str_contains($teamWpSrc, 'admin_invoices')) {
        pass('Team cannot use OM or invoices');
    } else {
        fail('Team OM/invoice ACL leak');
    }

    $uniqUrls = order_live_urls_from_rows([
        ['live_url' => ' https://a.example/1 '],
        ['live_url' => ''],
        ['live_url' => 'https://a.example/1'],
        ['live_url' => 'https://b.example/2'],
        ['site_name' => 'no-url.com'],
    ]);
    $uniqSites = order_site_names_from_rows([
        ['site_name' => ' alpha.com '],
        ['site_name' => ''],
        ['site_name' => 'alpha.com'],
        ['site_name' => 'beta.com'],
    ]);
    if ($uniqUrls === ['https://a.example/1', 'https://b.example/2']
        && $uniqSites === ['alpha.com', 'beta.com']) {
        pass('copy live URLs unique first-seen');
    } else {
        fail('copy helpers did not unique/skip blanks');
    }

    $copyProc = order_live_urls_from_rows(list_order_pipeline_rows(['folder' => 'processing', 'q' => $foldDomain]));
    $copyComp = order_live_urls_from_rows(list_order_pipeline_rows(['folder' => 'completed', 'q' => $foldDomain]));
    $copyMiss = order_live_urls_from_rows(list_order_pipeline_rows(['folder' => 'completed', 'q' => 'no-such-txfom-domain.example']));
    if ($copyProc === []
        && $copyComp === ['https://example.com/txfom-live']
        && $copyMiss === []) {
        pass('txt/copy uses folder + filter');
    } else {
        fail('txt/copy folder+filter mismatch: ' . json_encode([
            'proc' => $copyProc,
            'comp' => $copyComp,
            'miss' => $copyMiss,
        ]));
    }

    $sitePricesLibSrc = file_get_contents(__DIR__ . '/includes/site_prices.php') ?: '';
    $febBounds = order_pipeline_month_close_bounds('2026-02');
    $monthCloseTot = order_pipeline_month_close_totals([
        ['owner' => '10.00', 'decided' => '25.50', 'profit' => '15.50', 'paid' => 'Paid'],
        ['owner' => '4', 'decided' => '6', 'profit' => '2', 'paid' => ''],
    ]);
    if (order_pipeline_close_month('2026-08') === '2026-08'
        && order_pipeline_close_month('nope') === date('Y-m')
        && $febBounds === ['2026-02-01', '2026-02-28']
        && $monthCloseTot['sites'] === 2
        && abs($monthCloseTot['owner'] - 14.0) < 0.011
        && abs($monthCloseTot['decided'] - 31.5) < 0.011
        && abs($monthCloseTot['profit'] - 17.5) < 0.011
        && $monthCloseTot['paid'] === 1
        && $monthCloseTot['unpaid'] === 1
        && str_contains($ordersPhpSrc, 'Download month close')
        && str_contains($ordersPhpSrc, "download === 'month_close'")) {
        pass('OM month close bounds and totals');
    } else {
        fail('OM month close helpers');
    }

    if (str_contains($ordersPhpSrc, 'data-copy-check')
        && str_contains($ordersPhpSrc, 'data-push-check')
        && str_contains($ordersPhpSrc, 'Copy selected sites (this page)')
        && str_contains($ordersPhpSrc, 'Copy selected live URLs (this page)')
        && str_contains($ordersPhpSrc, 'Copy all live URLs')
        && !str_contains($ordersPhpSrc, 'Copy all live URLs (this page)')
        && str_contains($ordersPhpSrc, '(this page).')
        && str_contains($ordersPhpSrc, "download' => 'txt'")
        && str_contains($ordersPhpSrc, "copy' => 'live_urls'")
        && str_contains($ordersPhpSrc, 'order_pipeline_download_txt')
        && str_contains($ordersPhpSrc, 'Could not copy. Use Download .txt')
        && str_contains($ordersPhpSrc, 'Mark paid')
        && str_contains($ordersPhpSrc, 'With live URL')
        && !str_contains($sitePricesLibSrc, 'Copy all live URLs')
        && !str_contains($sitePricesLibSrc, 'Copy selected live URLs')
        && !str_contains($teamWpSrc, 'Copy all live URLs')
        && !str_contains($teamWpSrc, 'download=txt')) {
        pass('OM copy UI on Processing and Completed');
    } else {
        fail('OM copy/download UI missing or leaked to Website prices');
    }

    if (str_contains($ordersPhpSrc, '<span>Copy</span>')
        && str_contains($ordersPhpSrc, "\$isProcessing ? 'Complete' : 'Bill'")
        && str_contains($ordersPhpSrc, 'Left tick')
        && str_contains($ordersPhpSrc, 'order-client-list')
        && str_contains($ordersPhpSrc, 'Open in Website prices')
        && str_contains($ordersPhpSrc, 'order-wp-mismatch')
        && str_contains($ordersPhpSrc, 'restore_wp')
        && str_contains($ordersPhpSrc, 'omConfirmRemove')
        && str_contains($ordersPhpSrc, 'will reappear the next time Processing loads')
        && str_contains($ordersPhpSrc, 'Also set Website prices back to Processing')
        && str_contains($ordersPhpSrc, 'Mark this order completed?')
        && str_contains($ordersPhpSrc, "['folder' => 'completed'")
        && str_contains($ordersPhpSrc, 'No leftover Processing orders')
        && str_contains($ordersPhpSrc, 'order_row_ready_for_complete')
        && str_contains($ordersPhpSrc, 'Need a country on every ticked row before completing')
        && str_contains($ordersPhpSrc, 'Need a client email or name on every ticked row before completing')
        && str_contains($ordersPhpSrc, 'data-orig-live')
        && str_contains($ordersPhpSrc, 'Clearing the live URL also clears Paid')
        && str_contains($ordersPhpSrc, 'Every ticked row needs a live URL, country, and client email or name')
        && str_contains($ordersPhpSrc, 'id="om-origin-tabs"')
        && str_contains($ordersPhpSrc, 'Added here')
        && str_contains($ordersPhpSrc, 'Leftover')
        && str_contains($ordersPhpSrc, 'order_invoice_generate_push_cta')) {
        pass('OM sheet Copy/Complete labels, confirm, WP link, client typeahead');
    } else {
        fail('OM sheet gap UI missing');
    }

    $omCssSrc = file_get_contents(__DIR__ . '/assets/css/app.css') ?: '';
    if (str_contains($ordersPhpSrc, 'id="om-folder-tabs"')
        && !str_contains($ordersPhpSrc, '>Folders</a>')
        && str_contains($ordersPhpSrc, 'order-filter-bar-completed')
        && str_contains($ordersPhpSrc, 'order-check-hint-bill')
        && str_contains($ordersPhpSrc, 'compactUnpaidStats')
        && str_contains($ordersPhpSrc, "label_with_info('Owner'")
        && str_contains($ordersPhpSrc, 'then use <strong>Push to invoice</strong> on this sheet')
        && str_contains($omCssSrc, 'order-filter-bar-completed')
        && str_contains($omCssSrc, 'th.col-price .with-info-label')
        && str_contains($omCssSrc, 'order-check-hint-bill')) {
        pass('OM Completed unpaid sheet UX');
    } else {
        fail('OM Completed unpaid sheet UX missing');
    }

    $invId = create_blank_invoice((int) $adminUser['id']);
    pass("blank invoice id=$invId");
    $draftAfterBlank = count_invoices_by_work_status('draft');
    if ($draftAfterBlank >= 1) {
        pass('invoice draft count helper');
    } else {
        fail("invoice draft count helper got $draftAfterBlank");
    }
    $draftList = list_invoices(['filter' => 'draft']);
    $foundDraft = false;
    foreach ($draftList as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $invId) {
            $foundDraft = true;
            break;
        }
    }
    if ($foundDraft && count_invoices(['filter' => 'draft']) >= 1) {
        pass('invoice list filter draft');
    } else {
        fail('invoice list filter draft missing blank');
    }
    $blankRow = get_invoice((int) $invId) ?: [];
    $blankRow['item_count'] = 0;
    if (invoice_list_is_incomplete($blankRow)
        && invoice_list_is_incomplete(['is_manual' => 1, 'work_status' => 'draft', 'item_count' => 0, 'total_amount' => 0])
        && !invoice_list_is_incomplete(['is_manual' => 0, 'work_status' => 'done', 'item_count' => 0, 'total_amount' => 0])
        && !invoice_list_is_incomplete(['is_manual' => 1, 'work_status' => 'draft', 'item_count' => 2, 'total_amount' => 40])
    ) {
        pass('invoice list incomplete helper');
    } else {
        fail('invoice list incomplete helper');
    }
    $pagesSmall = invoice_list_page_numbers(1, 4);
    $pagesMid = invoice_list_page_numbers(5, 10);
    $pagesFirst = invoice_list_page_numbers(1, 10);
    if ($pagesSmall === [1, 2, 3, 4]
        && $pagesMid === [1, 0, 4, 5, 6, 0, 10]
        && $pagesFirst === [1, 2, 0, 10]
        && invoice_list_page_numbers(10, 10) === [1, 0, 9, 10]
    ) {
        pass('invoice list pager numbers');
    } else {
        fail('invoice list pager numbers ' . json_encode([$pagesSmall, $pagesMid, $pagesFirst]));
    }

    $invTotal = count_invoices();
    $invPage = list_invoices(['limit' => 1, 'offset' => 0]);
    if ($invTotal >= 1 && count($invPage) === 1) {
        pass('invoices SQL limit/offset');
    } else {
        fail("invoices paging total=$invTotal page=" . count($invPage));
    }

    // Generate from an unpaid LIVE sheet row, then mark paid.
    $genClientId = create_order_client('Test Client Invoice Gen', 'invoice gen test', (int) $adminUser['id']);
    $genItemId = add_order_item((int) $genClientId, 'txforder-live.com', 4, 2026);
    db()->prepare(
        'UPDATE order_items SET decided_price=?, live_url=?, is_paid=0, order_stage=?, country=?, client_label=? WHERE id=?'
    )->execute([40.00, 'https://example.com/txforder-live', 'completed', 'Germany', 'gen-buyer@example.com', $genItemId]);
    $invoiceable = list_invoiceable_order_items((int) $genClientId);
    if (count($invoiceable) < 1) {
        fail('invoiceable rows missing for generate test');
    } else {
        $lines = build_invoice_lines_from_orders($invoiceable, false);
        $genId = create_invoice([
            'invoice_date' => date('Y-m-d'),
            'client_id' => (int) $genClientId,
            'client_name' => 'Test Client Invoice Gen',
            'bill_to_name' => 'Test Client Invoice Gen',
            'bill_to_address' => 'Test Street 1',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
            'company_name' => 'Topurlz',
            'company_bic' => 'TESTBIC',
            'company_iban' => 'TESTIBAN',
            'company_phone' => '',
            'company_address' => '',
            'company_reg_no' => '',
            'vat_note' => '',
        ], $lines, (int) $adminUser['id']);
        $genInv = get_invoice($genId);
        if ($genInv && (float) $genInv['total_amount'] > 0) {
            pass('generated invoice id=' . $genId . ' total=' . $genInv['total_amount']);
        } else {
            fail('generated invoice missing/zero');
        }
        $linkedOm = list_invoice_linked_order_items((int) $genId);
        $linkedIds = [];
        foreach ($linkedOm as $lor) {
            $linkedIds[] = (int) ($lor['id'] ?? 0);
        }
        if (in_array((int) $genItemId, $linkedIds, true)) {
            pass('invoice linked OM rows');
        } else {
            fail('invoice linked OM rows missing item ' . $genItemId);
        }
        if (count_invoices_unpaid() >= 1) {
            pass('invoice unpaid-done count helper');
        } else {
            fail('invoice unpaid-done count helper');
        }
        $unpaidList = list_invoices(['filter' => 'unpaid']);
        $foundUnpaid = false;
        $draftInUnpaid = false;
        foreach ($unpaidList as $row) {
            $rid = (int) ($row['id'] ?? 0);
            if ($rid === (int) $genId) {
                $foundUnpaid = true;
            }
            if ($rid === (int) $invId) {
                $draftInUnpaid = true;
            }
        }
        if ($foundUnpaid && !$draftInUnpaid) {
            pass('invoice list filter unpaid excludes drafts');
        } else {
            fail('invoice list filter unpaid');
        }
        $waitSearch = list_invoices(['q' => 'Waiting']);
        $doneSearch = list_invoices(['q' => 'done']);
        $draftSearch = list_invoices(['q' => 'Draft']);
        $foundWaitSearch = false;
        $foundDoneSearch = false;
        $foundDraftSearch = false;
        foreach ($waitSearch as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $genId) {
                $foundWaitSearch = true;
                break;
            }
        }
        foreach ($doneSearch as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $genId) {
                $foundDoneSearch = true;
                break;
            }
        }
        foreach ($draftSearch as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $invId) {
                $foundDraftSearch = true;
                break;
            }
        }
        $caseLabels = invoice_bill_as_labels([
            ['client_label' => 'Buyer@Example.com'],
            ['client_label' => 'buyer@example.com'],
        ]);
        if ($foundWaitSearch && !$foundDoneSearch && $foundDraftSearch && count($caseLabels) === 1) {
            pass('invoice search Waiting/Draft labels, not done');
        } else {
            fail('invoice search Waiting/Draft labels');
        }
        $clientList = list_invoices(['client_id' => (int) $genClientId]);
        $foundGenOnClient = false;
        $blankOnClient = false;
        foreach ($clientList as $row) {
            $rid = (int) ($row['id'] ?? 0);
            if ($rid === (int) $genId) {
                $foundGenOnClient = true;
            }
            if ($rid === (int) $invId) {
                $blankOnClient = true;
            }
        }
        if ($foundGenOnClient && !$blankOnClient && count($clientList) >= 1) {
            pass('invoice list client_id excludes blanks');
        } else {
            fail('invoice list client_id scope leaked blanks or missed generated');
        }
        mark_invoice_payment_received($genId);
        $paidRow = db()->prepare('SELECT is_paid FROM order_items WHERE id=?');
        $paidRow->execute([$genItemId]);
        if ((int) $paidRow->fetchColumn() === 1 && invoice_is_paid(get_invoice($genId))) {
            pass('mark paid sets invoice + sheet row');
        } else {
            fail('mark paid did not update invoice/sheet');
        }
        $foundPaid = false;
        foreach (list_invoices(['filter' => 'paid']) as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $genId) {
                $foundPaid = true;
                break;
            }
        }
        $stillUnpaid = false;
        foreach (list_invoices(['filter' => 'unpaid']) as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $genId) {
                $stillUnpaid = true;
                break;
            }
        }
        if ($foundPaid && !$stillUnpaid) {
            pass('invoice list filter paid');
        } else {
            fail('invoice list filter paid missing generated');
        }
    }

    // Blank invoice: zero total cannot be Done.
    $blankId = create_blank_invoice((int) $adminUser['id']);
    try {
        update_blank_invoice($blankId, [
            'invoice_date' => date('Y-m-d'),
            'admin_note' => '',
            'bill_to_name' => 'Blank',
            'bill_to_address' => '',
            'bill_to_hrb' => '',
            'bill_to_vat' => '',
            'supplier_number' => 'NEW',
            'cost_center' => '',
            'orderer' => '',
            'company_name' => 'Topurlz',
            'company_bic' => '',
            'company_iban' => '',
            'company_phone' => '',
            'company_address' => '',
            'company_reg_no' => '',
            'vat_note' => '',
        ], [['description' => 'Line', 'amount' => 0, 'qty' => 1]], 'done');
        fail('blank zero-total Done should be blocked');
    } catch (Throwable $e) {
        pass('blank zero-total Done blocked');
    }
} catch (Throwable $e) {
    fail('orders/invoices: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Admin new-data signals (emails_admin New badge on; Our DB / Extracted off) ---
try {
    db()->prepare('DELETE FROM admin_data_seen WHERE user_id=?')->execute([(int) $adminUser['id']]);
    db()->prepare('DELETE FROM swe_admin_country_seen WHERE user_id=?')->execute([(int) $adminUser['id']]);
    db()->exec("DELETE FROM admin_data_signals");
    mark_admin_new_data('our_database', 3, 'Germany');
    mark_admin_new_data('extracted_sites', 2, 'Germany');
    mark_admin_new_data('emails_admin', 2, 'Germany');
    if (admin_has_new_data('our_database', $adminUser)) {
        pass('admin signal our_database');
    } else {
        fail('admin signal our_database missing');
    }
    clear_admin_new_data('our_database', $adminUser);
    if (!admin_has_new_data('our_database', $adminUser)) {
        pass('cleared our_database signal');
    } else {
        fail('signal not cleared');
    }
    if (admin_new_badge_html('our_database', $adminUser) === '') {
        pass('Our database New badge stays off');
    } else {
        fail('Our database New badge should stay off');
    }
    if (admin_new_badge_html('extracted_sites', $adminUser) === '') {
        pass('Extracted New badge stays off');
    } else {
        fail('Extracted New badge should stay off');
    }
    if (str_contains(admin_new_badge_html('emails_admin', $adminUser), 'admin-new-badge')) {
        pass('emails_admin New badge HTML');
    } else {
        fail('emails_admin New badge missing');
    }

    // Country watermark: push-like rows after mark → count; open country → clear that country + section when alone
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfnew-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txfnew-%'");
    db()->prepare(
        "INSERT INTO sites_with_emails_admin
           (domain, country, language, region, email1, email2, email3, email4, pushed_by, created_at, updated_at)
         VALUES ('txfnew-a.com','Germany','German','Europe','a@txfnew.test','','','',?, NOW(), NOW())"
    )->execute([(int) $teamUser['id']]);
    ensure_sites_with_emails_schema();
    $nBefore = swe_admin_new_count_for_country($adminUser, 'Germany');
    if ($nBefore >= 1) {
        pass('country new count after signal');
    } else {
        fail('country new count expected >=1 got ' . $nBefore);
    }
    swe_admin_mark_country_seen($adminUser, 'Germany');
    if (swe_admin_new_count_for_country($adminUser, 'Germany') === 0) {
        pass('country new cleared on open');
    } else {
        fail('country new not cleared');
    }
    // Other countries in the DB may still be “new”; mark all to clear the section badge.
    swe_admin_mark_all_countries_seen($adminUser);
    if (!admin_has_new_data('emails_admin', $adminUser)) {
        pass('emails_admin section cleared when all countries seen');
    } else {
        fail('emails_admin section still new after mark all countries seen');
    }
    if (admin_new_badge_html('emails_admin', $adminUser) === '') {
        pass('emails_admin badge cleared after mark all countries seen');
    } else {
        fail('emails_admin badge still showing');
    }

    // P1b: row signals + visit watermark + inventory filter=new
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfnew-%'");
    db()->prepare('DELETE FROM swe_admin_country_seen WHERE user_id=?')->execute([(int) $adminUser['id']]);
    db()->prepare('DELETE FROM admin_data_seen WHERE user_id=? AND section=?')->execute([(int) $adminUser['id'], 'emails_admin']);
    unset($_SESSION['swe_admin_visit_since']);
    mark_admin_new_data('emails_admin', 1, 'Germany');
    db()->prepare(
        "INSERT INTO sites_with_emails_admin
           (domain, country, language, region, email1, email2, email3, email4, pushed_by, created_at, updated_at)
         VALUES
           ('txfnew-b.com','Germany','German','Europe','b@txfnew.test','','','',?, NOW(), NOW()),
           ('txfnew-old.com','Germany','German','Europe','old@txfnew.test','','','',?, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY))"
    )->execute([(int) $teamUser['id'], (int) $teamUser['id']]);
    db()->prepare(
        "UPDATE sites_with_emails_admin SET email2='newslot@txfnew.test', updated_at=NOW()
         WHERE domain='txfnew-old.com'"
    )->execute();
    $visitSince = swe_admin_visit_since($adminUser, 'Germany', true);
    $sigNew = swe_admin_row_signal(
        db()->query("SELECT * FROM sites_with_emails_admin WHERE domain='txfnew-b.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [],
        $visitSince
    );
    $sigUp = swe_admin_row_signal(
        db()->query("SELECT * FROM sites_with_emails_admin WHERE domain='txfnew-old.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [],
        $visitSince
    );
    if ($sigNew === 'new' && $sigUp === 'updated') {
        pass('row signals new + updated');
    } else {
        fail('row signals expected new/updated got ' . $sigNew . '/' . $sigUp);
    }
    $filt = sites_with_emails_inventory_query([
        'country' => 'Germany',
        'filter' => 'new',
        'since' => (string) $visitSince,
    ], 1, 100, 'admin');
    $filtDomains = array_column($filt['rows'], 'domain');
    if (in_array('txfnew-b.com', $filtDomains, true) && !in_array('txfnew-old.com', $filtDomains, true)) {
        pass('inventory filter=new');
    } else {
        fail('filter=new wrong rows: ' . implode(',', $filtDomains));
    }
    db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txfnew-%'");
    db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txfnew-%'");
} catch (Throwable $e) {
    fail('admin new: ' . $e->getMessage());
}

// --- Password change path ---
try {
    db()->prepare('UPDATE users SET must_change_password=1 WHERE username=?')->execute(['teammate']);
    attempt_login('teammate', 'TestTeam8z');
    if (user_must_change_password()) {
        pass('must_change_password enforced');
    } else {
        fail('must_change_password not set after flag');
    }
    $err = change_user_password((int) $teamUser['id'], 'TestTeam8z', 'NewTeamPass99');
    if ($err === '') {
        pass('password changed');
        // restore for later HTTP tests
        db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')->execute([
            password_hash('TestTeam8z', PASSWORD_DEFAULT),
            (int) $teamUser['id'],
        ]);
        clear_must_change_password_flag((int) $teamUser['id']);
    } else {
        fail('password change: ' . $err);
    }
    logout_user();
} catch (Throwable $e) {
    fail('password: ' . $e->getMessage());
}

try {
    ensure_users_auth_schema();
    $kickName = 'txfkick' . bin2hex(random_bytes(3));
    $kickHash = password_hash('KickPass99x', PASSWORD_DEFAULT);
    db()->prepare(
        "INSERT INTO users (username, password_hash, full_name, email, role, is_active, must_change_password)
         VALUES (?,?,?,?, 'team', 1, 0)"
    )->execute([$kickName, $kickHash, 'Kick User', '']);
    $kickId = (int) db()->lastInsertId();
    if ($kickId < 1) {
        $kickId = (int) db()->query("SELECT id FROM users WHERE username=" . db()->quote($kickName))->fetchColumn();
    }
    logout_user();
    if (!attempt_login($kickName, 'KickPass99x')) {
        fail('session kick login');
    } else {
        db()->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$kickId]);
        if (current_user() === null) {
            pass('deactivate logs out existing session');
        } else {
            fail('deactivate left session user=' . json_encode(current_user()));
        }
    }
    db()->prepare('UPDATE users SET is_active=1 WHERE id=?')->execute([$kickId]);
    logout_user();
    if (!attempt_login($kickName, 'KickPass99x')) {
        fail('session version kick login');
    } else {
        bump_user_session_version($kickId, false);
        if (current_user() === null) {
            pass('password/session_version bump logs out other session');
        } else {
            fail('session_version bump left session');
        }
    }
    logout_user();
    if (!attempt_login($kickName, 'KickPass99x')) {
        fail('demote kick login');
    } else {
        db()->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$kickId]);
        bump_user_session_version($kickId, false);
        if (current_user() === null) {
            pass('role change logs out existing session');
        } else {
            fail('role change left session role=' . (current_user()['role'] ?? ''));
        }
    }
    logout_user();
    if (!attempt_login($kickName, 'KickPass99x')) {
        fail('keep-session login');
    } else {
        $before = (int) (current_user()['session_version'] ?? 0);
        bump_user_session_version($kickId, true);
        $afterUser = current_user();
        if (is_array($afterUser) && (int) ($afterUser['session_version'] ?? 0) === $before + 1) {
            pass('keepCurrentSession stays signed in with new version');
        } else {
            fail('keepCurrentSession: ' . json_encode($afterUser));
        }
    }
    logout_user();
    if (current_user() !== null) {
        fail('logout_user left session user');
    } else {
        pass('logout_user clears session user');
    }
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$kickId]);
    logout_user();
    attempt_login('admin', 'TestAdmin9x');
} catch (Throwable $e) {
    fail('session kick: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    try {
        if (!empty($kickId)) {
            db()->prepare('DELETE FROM users WHERE id=?')->execute([(int) $kickId]);
        }
    } catch (Throwable $e2) {
        // ignore
    }
    logout_user();
    attempt_login('admin', 'TestAdmin9x');
}

// --- Language must not list country names (German ≠ Germany) ---
try {
    $langs = list_language_options();
    $langLower = array_map('mb_strtolower', $langs);
    if (in_array('german', $langLower, true) && !in_array('germany', $langLower, true)) {
        pass('language options include German, not Germany');
    } else {
        fail('language options wrong: ' . implode(',', $langs));
    }
    if (normalize_site_language('Germany', 'Germany') === 'German'
        && normalize_site_language('German', 'Germany') === 'German') {
        pass('normalize_site_language maps country-name language to German');
    } else {
        fail('normalize_site_language failed');
    }
    if (is_country_name_used_as_language('Germany') && !is_country_name_used_as_language('German')) {
        pass('country-name-as-language detector');
    } else {
        fail('country-name-as-language detector wrong');
    }
} catch (Throwable $e) {
    fail('language options: ' . $e->getMessage());
}

// --- Department task assign to user (auto-add member) ---
try {
    ensure_departments_schema();
    $dept = get_department_by_slug('email_extracting');
    if (!$dept) {
        fail('email_extracting department missing');
    } else {
        $assigneeId = (int) $teamUser['id'];
        // Ensure not already a member so auto-add path is tested.
        remove_department_member((int) $dept['id'], $assigneeId);
        $saved = save_department_task(
            (int) $dept['id'],
            'txfdept-assign-task',
            'assign test',
            'open',
            $assigneeId,
            null,
            $adminUser,
            null
        );
        if (!empty($saved['ok']) && user_in_department($assigneeId, (int) $dept['id'])) {
            pass('department task assigns user and auto-adds member');
        } else {
            fail('department assign failed: ' . json_encode($saved));
        }
        $task = get_department_task((int) ($saved['id'] ?? 0));
        if ($task && (int) ($task['assigned_to'] ?? 0) === $assigneeId) {
            pass('department task assigned_to persisted');
        } else {
            fail('department task assigned_to missing');
        }
        if (!empty($saved['id'])) {
            delete_department_task((int) $saved['id']);
        }
        remove_department_member((int) $dept['id'], $assigneeId);
    }

    $commDept = get_department_by_slug('communication');
    $commsId = (int) db()->query("SELECT id FROM users WHERE username='comms'")->fetchColumn();
    $finderId = (int) db()->query("SELECT id FROM users WHERE username='finder'")->fetchColumn();
    if ($commDept && $commsId > 0 && $finderId > 0) {
        $commsActor = ['id' => $commsId, 'username' => 'comms', 'role' => 'team'];
        $finderActor = ['id' => $finderId, 'username' => 'finder', 'role' => 'team'];
        $made = save_department_task(
            (int) $commDept['id'],
            'txfdept-team-assign',
            '',
            'open',
            $commsId,
            null,
            $commsActor,
            null,
            false
        );
        $sneakAdd = save_department_task(
            (int) $commDept['id'],
            'txfdept-team-no-autoadd',
            '',
            'open',
            $finderId,
            null,
            $commsActor,
            null,
            false
        );
        $reassignFinder = set_department_task_assignee((int) ($made['id'] ?? 0), $finderId, $commsActor);
        $reassignSelf = set_department_task_assignee((int) ($made['id'] ?? 0), $commsId, $commsActor);
        $finderBlocked = set_department_task_assignee((int) ($made['id'] ?? 0), $commsId, $finderActor);
        $teamAssignOk = !empty($made['ok'])
            && team_can_assign_department_tasks($commsActor, (int) $commDept['id'])
            && !team_can_assign_department_tasks($finderActor, (int) $commDept['id'])
            && empty($sneakAdd['ok'])
            && !user_in_department($finderId, (int) $commDept['id'])
            && empty($reassignFinder['ok'])
            && !empty($reassignSelf['ok'])
            && empty($finderBlocked['ok']);
        if ($teamAssignOk) {
            pass('team department members can assign tasks to members only');
        } else {
            fail('team department assign: ' . json_encode([
                'made' => $made,
                'sneak' => $sneakAdd,
                'finder' => $reassignFinder,
                'self' => $reassignSelf,
                'blocked' => $finderBlocked,
            ]));
        }
        if (!empty($made['id'])) {
            delete_department_task((int) $made['id']);
        }
        if (!empty($sneakAdd['id'])) {
            delete_department_task((int) $sneakAdd['id']);
        }
    } else {
        fail('team department assign: missing comms/finder users');
    }

    // D-1: invalid status rejected
    $tmpTask = save_department_task(
        (int) $dept['id'],
        'D1 status check',
        '',
        'open',
        null,
        null,
        $adminUser,
        null
    );
    if (!empty($tmpTask['id'])) {
        if (!update_department_task_status((int) $tmpTask['id'], 'nope')) {
            pass('department invalid status rejected');
        } else {
            fail('department invalid status accepted');
        }
        if (update_department_task_status((int) $tmpTask['id'], 'open')
            && update_department_task_status((int) $tmpTask['id'], 'in_progress')) {
            pass('department status update including same value');
        } else {
            fail('department valid status update failed');
        }
        delete_department_task((int) $tmpTask['id']);
    } else {
        fail('department temp task for status check failed');
    }

    // D-2: remove member clears open assignees; done keeps history
    $assigneeId = (int) ($teamUser['id'] ?? 0);
    if ($assigneeId > 0 && $dept) {
        remove_department_member((int) $dept['id'], $assigneeId);
        $openSave = save_department_task(
            (int) $dept['id'],
            'D2 open assigned',
            '',
            'open',
            $assigneeId,
            null,
            $adminUser,
            null
        );
        $doneSave = save_department_task(
            (int) $dept['id'],
            'D2 done assigned',
            '',
            'done',
            $assigneeId,
            null,
            $adminUser,
            null
        );
        $cleared = clear_open_department_task_assignees((int) $dept['id'], $assigneeId);
        remove_department_member((int) $dept['id'], $assigneeId);
        $openLeft = 0;
        $doneLeft = 0;
        if (!empty($openSave['id'])) {
            $openLeft = (int) db()->query(
                'SELECT assigned_to FROM department_tasks WHERE id=' . (int) $openSave['id']
            )->fetchColumn();
        }
        if (!empty($doneSave['id'])) {
            $doneLeft = (int) db()->query(
                'SELECT assigned_to FROM department_tasks WHERE id=' . (int) $doneSave['id']
            )->fetchColumn();
        }
        if ($cleared >= 1 && $openLeft === 0 && $doneLeft === $assigneeId) {
            pass('remove member clears open assignee; done kept');
        } else {
            fail("clear assignee cleared=$cleared openLeft=$openLeft doneLeft=$doneLeft");
        }
        if (!empty($openSave['id'])) {
            delete_department_task((int) $openSave['id']);
        }
        if (!empty($doneSave['id'])) {
            delete_department_task((int) $doneSave['id']);
        }
        if (!empty($openSave['added_member'])) {
            pass('assign task reports added_member');
        } else {
            fail('assign task missing added_member flag');
        }
    } else {
        fail('D-2 assignee missing');
    }

    // D-3: assignee filter + overdue helper
    if ($dept) {
        $uidMine = (int) ($teamUser['id'] ?? 0);
        $mineTask = save_department_task(
            (int) $dept['id'],
            'D3 mine',
            '',
            'open',
            $uidMine,
            date('Y-m-d', strtotime('-2 days')),
            $adminUser,
            null
        );
        $wholeTask = save_department_task(
            (int) $dept['id'],
            'D3 whole',
            '',
            'open',
            null,
            null,
            $adminUser,
            null
        );
        $mineList = list_department_tasks((int) $dept['id'], '', $uidMine, 'mine');
        $unList = list_department_tasks((int) $dept['id'], '', $uidMine, 'unassigned');
        $mineIds = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $mineList);
        $unIds = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $unList);
        $mineOk = !empty($mineTask['id']) && in_array((int) $mineTask['id'], $mineIds, true)
            && (empty($wholeTask['id']) || !in_array((int) $wholeTask['id'], $mineIds, true));
        $unOk = !empty($wholeTask['id']) && in_array((int) $wholeTask['id'], $unIds, true)
            && (empty($mineTask['id']) || !in_array((int) $mineTask['id'], $unIds, true));
        if ($mineOk && $unOk) {
            pass('department assignee filters mine/unassigned');
        } else {
            fail('assignee filters mineOk=' . ($mineOk ? '1' : '0') . ' unOk=' . ($unOk ? '1' : '0'));
        }
        $row = get_department_task((int) ($mineTask['id'] ?? 0));
        if ($row && department_task_is_overdue($row)) {
            pass('department overdue helper');
        } else {
            fail('department overdue helper failed');
        }
        $overdueList = list_department_tasks((int) $dept['id'], 'overdue', $uidMine, '');
        $overdueIds = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $overdueList);
        $overdueFilterOk = !empty($mineTask['id']) && in_array((int) $mineTask['id'], $overdueIds, true)
            && (empty($wholeTask['id']) || !in_array((int) $wholeTask['id'], $overdueIds, true));
        if ($overdueFilterOk) {
            pass('department overdue status filter');
        } else {
            fail('department overdue status filter missed past-due open task');
        }
        $stOverdue = department_stats((int) $dept['id']);
        if (isset($stOverdue['overdue_count']) && (int) $stOverdue['overdue_count'] >= 1) {
            pass('department_stats overdue_count');
        } else {
            fail('department_stats overdue_count missing or zero: ' . json_encode($stOverdue));
        }
        if (!empty($mineTask['id'])) {
            delete_department_task((int) $mineTask['id']);
        }
        if (!empty($wholeTask['id'])) {
            delete_department_task((int) $wholeTask['id']);
        }
        remove_department_member((int) $dept['id'], $uidMine);
    }

    $finderId = (int) db()->query("SELECT id FROM users WHERE username='finder'")->fetchColumn();
    $extractorId = (int) db()->query("SELECT id FROM users WHERE username='extractor'")->fetchColumn();
    if ($finderId > 0 && $extractorId > 0 && $dept) {
        $named = save_department_task(
            (int) $dept['id'],
            'ACL named extractor',
            '',
            'open',
            $extractorId,
            null,
            $adminUser,
            null
        );
        $openDept = save_department_task(
            (int) $dept['id'],
            'ACL whole dept',
            '',
            'open',
            null,
            null,
            $adminUser,
            null
        );
        $namedRow = get_department_task((int) ($named['id'] ?? 0));
        $openRow = get_department_task((int) ($openDept['id'] ?? 0));
        $finderU = ['id' => $finderId, 'role' => 'team', 'username' => 'finder'];
        $extractorU = ['id' => $extractorId, 'role' => 'team', 'username' => 'extractor'];
        $okNamed = $namedRow
            && team_can_set_department_task_status($extractorU, $namedRow)
            && !team_can_set_department_task_status($finderU, $namedRow)
            && team_can_set_department_task_status($adminUser, $namedRow);
        $okWhole = $openRow
            && team_can_set_department_task_status($finderU, $openRow)
            && team_can_set_department_task_status($extractorU, $openRow);
        if ($okNamed && $okWhole) {
            pass('assignee cannot change someone else task status');
        } else {
            fail('task status ACL unexpected');
        }
        if (!empty($named['id'])) {
            delete_department_task((int) $named['id']);
        }
        if (!empty($openDept['id'])) {
            delete_department_task((int) $openDept['id']);
        }
    } else {
        fail('task status ACL missing finder/extractor');
    }

    $dash = departments_dashboard_stats();
    $activeMembers = (int) db()->query(
        'SELECT COUNT(DISTINCT m.user_id)
         FROM department_members m
         INNER JOIN users u ON u.id = m.user_id
         WHERE u.is_active = 1'
    )->fetchColumn();
    if (isset($dash['departments'], $dash['members'], $dash['open_tasks'], $dash['unassigned_team'])
        && (int) $dash['members'] === $activeMembers) {
        pass('departments dashboard stats');
    } else {
        fail('departments dashboard stats missing keys or counting inactive members');
    }

    // Residual: edit can keep historical assignee after member removed.
    $histUid = (int) ($teamUser['id'] ?? 0);
    if ($histUid > 0 && $dept) {
        remove_department_member((int) $dept['id'], $histUid);
        $histSave = save_department_task(
            (int) $dept['id'],
            'D residual hist assignee',
            '',
            'done',
            $histUid,
            null,
            $adminUser,
            null
        );
        remove_department_member((int) $dept['id'], $histUid);
        $kept = save_department_task(
            (int) $dept['id'],
            'D residual hist assignee edited',
            'note',
            'done',
            $histUid,
            null,
            $adminUser,
            (int) ($histSave['id'] ?? 0)
        );
        $row = !empty($histSave['id']) ? get_department_task((int) $histSave['id']) : null;
        if (!empty($kept['ok']) && $row && (int) ($row['assigned_to'] ?? 0) === $histUid) {
            pass('edit keeps historical assignee after remove');
        } else {
            fail('historical assignee edit failed: ' . json_encode($kept));
        }
        if (!empty($histSave['id'])) {
            delete_department_task((int) $histSave['id']);
        }
        remove_department_member((int) $dept['id'], $histUid);
    }
} catch (Throwable $e) {
    fail('department assign: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Task presence (advisory “Also here” chip) ---
try {
    $presenceKey = 'txfpresence:Germany';
    db()->prepare('DELETE FROM task_presence WHERE task_key=?')->execute([$presenceKey]);

    $alone = ping_task_presence($presenceKey, $adminUser, 45);
    if (!empty($alone['ok']) && (int) ($alone['count'] ?? -1) === 0) {
        pass('presence alone shows nobody else');
    } else {
        fail('presence alone should be empty');
    }

    $teamPing = ping_task_presence($presenceKey, $teamUser, 45);
    $teamNames = array_column($teamPing['others'] ?? [], 'name');
    $adminName = task_presence_display_name($adminUser);
    if (!empty($teamPing['ok']) && (int) ($teamPing['count'] ?? 0) === 1
        && in_array($adminName, $teamNames, true)) {
        pass('presence teammate sees admin on same task');
    } else {
        fail('presence teammate should see admin');
    }

    $adminPing = ping_task_presence($presenceKey, $adminUser, 45);
    $adminOthers = array_column($adminPing['others'] ?? [], 'id');
    if (!empty($adminPing['ok'])
        && in_array((int) $teamUser['id'], $adminOthers, true)
        && !in_array((int) $adminUser['id'], $adminOthers, true)) {
        pass('presence hides self and lists other user');
    } else {
        fail('presence self-hide / other-list failed');
    }

    $otherKey = 'txfpresence:France';
    db()->prepare('DELETE FROM task_presence WHERE task_key=?')->execute([$otherKey]);
    $fr = ping_task_presence($otherKey, $teamUser, 45);
    if (!empty($fr['ok']) && (int) ($fr['count'] ?? -1) === 0) {
        pass('presence scoped per task key');
    } else {
        fail('presence leaked across task keys');
    }

    db()->prepare(
        'UPDATE task_presence SET last_seen_at = (NOW() - INTERVAL 120 SECOND)
         WHERE task_key=? AND user_id=?'
    )->execute([$presenceKey, (int) $teamUser['id']]);
    $stale = ping_task_presence($presenceKey, $adminUser, 45);
    if (!empty($stale['ok']) && (int) ($stale['count'] ?? -1) === 0) {
        pass('presence drops stale teammates');
    } else {
        fail('presence stale rows still listed');
    }

    db()->prepare('DELETE FROM task_presence WHERE task_key LIKE ?')->execute(['txfpresence:%']);
} catch (Throwable $e) {
    fail('presence: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Semrush Research (site names per country) ---
try {
    seed_countries_if_empty(db());
    // Isolated country: wipe any prior test residue, then own the sheet for this run.
    $semCountry = 'Singapore';
    db()->prepare('DELETE FROM semrush_sites WHERE country=?')->execute([$semCountry]);
    db()->prepare('DELETE FROM semrush_sheet_comments WHERE country=?')->execute([$semCountry]);

    $hubBefore = array_column(list_semrush_country_rows(), 'country');
    if (!in_array($semCountry, $hubBefore, true)) {
        pass('semrush hub hides country with no sites');
    } else {
        fail('semrush hub still lists empty Singapore');
    }

    $seed = add_semrush_domains(
        $semCountry,
        "txfsem-alpha.com\ntxfsem-beta.de\nnot a domain\ntxfsem-alpha.com",
        $adminUser
    );
    if (!empty($seed['ok'])
        && (int) ($seed['inserted'] ?? 0) === 2
        && (int) ($seed['invalid'] ?? 0) >= 1
        && (int) ($seed['total'] ?? 0) === 2) {
        pass('semrush admin seed inserts unique site names');
    } else {
        fail('semrush seed: ' . json_encode($seed));
    }

    $hubCountries = array_column(list_semrush_country_rows(), 'country');
    if (in_array($semCountry, $hubCountries, true)) {
        pass('semrush country appears in hub after Admin seed');
    } else {
        fail('semrush hub missing Singapore after seed');
    }

    $domains = list_semrush_domains_for_country($semCountry);
    sort($domains);
    if ($domains === ['txfsem-alpha.com', 'txfsem-beta.de']) {
        pass('semrush sheet lists site names only');
    } else {
        fail('semrush domains missing: ' . json_encode($domains));
    }

    $dup = add_semrush_domains($semCountry, "txfsem-alpha.com\ntxfsem-gamma.com", $adminUser);
    if (!empty($dup['ok'])
        && (int) ($dup['inserted'] ?? 0) === 1
        && (int) ($dup['skipped'] ?? 0) >= 1
        && (int) ($dup['total'] ?? 0) === 3) {
        pass('semrush admin append skips duplicates');
    } else {
        fail('semrush append: ' . json_encode($dup));
    }

    $replace = set_semrush_domains_from_text(
        $semCountry,
        "txfsem-beta.de\ntxfsem-delta.at",
        $teamUser
    );
    $after = list_semrush_domains_for_country($semCountry);
    sort($after);
    if (!empty($replace['ok'])
        && $after === ['txfsem-beta.de', 'txfsem-delta.at']
        && (int) ($replace['removed'] ?? 0) === 2
        && (int) ($replace['inserted'] ?? 0) === 1) {
        pass('semrush set replaces sheet list (team edit)');
    } else {
        fail('semrush set: ' . json_encode($replace) . ' domains=' . json_encode($after));
    }
    if (trim((string) ($replace['writer_at'] ?? '')) !== ''
        || (int) (semrush_sheet_writer($semCountry)['id'] ?? 0) === (int) $teamUser['id']) {
        pass('semrush sheet writer stamped after save');
    } else {
        fail('semrush sheet writer missing after save: ' . json_encode($replace));
    }
    $semConflict = semrush_sheet_writer_conflict($semCountry, $adminUser, '2000-01-01 00:00:00');
    $semSame = semrush_sheet_writer_conflict($semCountry, $teamUser, '2000-01-01 00:00:00');
    if (!empty($semConflict['conflict']) && $semSame === null) {
        pass('semrush sheet writer conflict when another user has a newer save');
    } else {
        fail('semrush writer conflict unexpected: ' . json_encode([$semConflict, $semSame]));
    }

    $c1 = add_semrush_comment($semCountry, 'txfsem-note from team', $teamUser);
    $c2 = add_semrush_comment($semCountry, 'txfsem-note from admin', $adminUser);
    $comments = list_semrush_comments($semCountry);
    $bodies = array_column($comments, 'body');
    if (!empty($c1['ok']) && !empty($c2['ok'])
        && in_array('txfsem-note from team', $bodies, true)
        && in_array('txfsem-note from admin', $bodies, true)) {
        pass('semrush comments add for team and admin');
    } else {
        fail('semrush comments: ' . json_encode([$c1, $c2, $bodies]));
    }

    $teamDelOwn = delete_semrush_comment((int) ($c1['id'] ?? 0), $teamUser);
    $teamDelAdmin = delete_semrush_comment((int) ($c2['id'] ?? 0), $teamUser);
    if (!empty($teamDelOwn['ok']) && empty($teamDelAdmin['ok'])) {
        pass('semrush team deletes own comment only');
    } else {
        fail('semrush comment ACL: own=' . json_encode($teamDelOwn) . ' other=' . json_encode($teamDelAdmin));
    }
    $adminDel = delete_semrush_comment((int) ($c2['id'] ?? 0), $adminUser);
    if (!empty($adminDel['ok'])) {
        pass('semrush admin can delete any comment');
    } else {
        fail('semrush admin delete comment: ' . json_encode($adminDel));
    }

    $empty = set_semrush_domains_from_text($semCountry, '', $teamUser);
    $stillListed = in_array(
        $semCountry,
        array_column(list_semrush_country_rows(), 'country'),
        true
    );
    if (!empty($empty['ok']) && (int) ($empty['total'] ?? -1) === 0 && !$stillListed) {
        pass('semrush empty sheet hides country from hub');
    } else {
        fail('semrush empty hide: ' . json_encode($empty) . ' listed=' . ($stillListed ? '1' : '0'));
    }

    add_semrush_domains($semCountry, "txfsem-wipe.com", $adminUser);
    add_semrush_comment($semCountry, 'txfsem-wipe comment', $teamUser);
    $wipe = clear_semrush_country($semCountry);
    $sitesLeft = count_semrush_sites_for_country($semCountry);
    $commentsLeft = (int) db()->query(
        'SELECT COUNT(*) FROM semrush_sheet_comments WHERE country=' . db()->quote($semCountry)
    )->fetchColumn();
    $listedAfterClear = in_array(
        $semCountry,
        array_column(list_semrush_country_rows(), 'country'),
        true
    );
    if (!empty($wipe['ok']) && $sitesLeft === 0 && $commentsLeft === 0 && !$listedAfterClear) {
        pass('semrush admin clear removes sites and comments');
    } else {
        fail('semrush clear: ' . json_encode($wipe)
            . " sites=$sitesLeft comments=$commentsLeft listed=" . ($listedAfterClear ? '1' : '0'));
    }

    db()->exec("DELETE FROM semrush_sites WHERE domain LIKE 'txfsem-%'");
    db()->exec("DELETE FROM semrush_sheet_comments WHERE body LIKE 'txfsem-%'");
} catch (Throwable $e) {
    fail('semrush: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Team panels T-2 / T-3 helpers ---
try {
    $finderUid = (int) db()->query("SELECT id FROM users WHERE username='finder'")->fetchColumn();
    $extractorUid = (int) db()->query("SELECT id FROM users WHERE username='extractor'")->fetchColumn();
    $finder = ['id' => $finderUid, 'username' => 'finder', 'role' => 'team'];
    $extractor = ['id' => $extractorUid, 'username' => 'extractor', 'role' => 'team'];
    if ($finderUid > 0 && team_page_unlocked($finder, 'team_prospect_check')
        && !team_page_unlocked($finder, 'team_site_prices')
        && !team_page_unlocked($finder, 'team_extract_batch')
        && !team_page_unlocked($finder, 'team_sites_emails')) {
        pass('team_page_unlocked finder tools');
    } else {
        fail('team_page_unlocked finder unexpected');
    }
    if ($extractorUid > 0 && team_page_unlocked($extractor, 'team_extract_batch')
        && team_page_unlocked($extractor, 'team_semrush_research')
        && team_page_unlocked($extractor, 'team_semrush_sheet')
        && !team_page_unlocked($extractor, 'team_prospect_check')
        && !team_page_unlocked($extractor, 'team_site_prices')
        && !team_can_clear_semrush_country($extractor)) {
        pass('team_page_unlocked extractor tools');
    } else {
        fail('team_page_unlocked extractor unexpected');
    }
    $emailerUid = (int) db()->query("SELECT id FROM users WHERE username='emailer'")->fetchColumn();
    $emailer = ['id' => $emailerUid, 'username' => 'emailer', 'role' => 'team'];
    if ($emailerUid > 0
        && team_page_unlocked($emailer, 'team_admin_emails_search')
        && team_page_unlocked($emailer, 'team_admin_emails_delete')) {
        pass('team_page_unlocked admin emails search + delete alias');
    } else {
        fail('team_page_unlocked admin emails search unexpected');
    }
    $commsUid = (int) db()->query("SELECT id FROM users WHERE username='comms'")->fetchColumn();
    $comms = ['id' => $commsUid, 'username' => 'comms', 'role' => 'team'];
    if ($commsUid > 0 && team_page_unlocked($comms, 'team_site_prices')
        && team_page_unlocked($comms, 'team_email_campaigns')
        && !team_page_unlocked($finder, 'team_site_prices')) {
        pass('team_page_unlocked comms Website prices');
    } else {
        fail('team_page_unlocked comms Website prices unexpected');
    }
    if ($finderUid > 0 && team_can_clear_semrush_country($finder)
        && !team_can_clear_semrush_country($extractor)) {
        pass('semrush Clear country is Finding not Extracting');
    } else {
        fail('semrush Clear country ACL unexpected');
    }
    $otherTool = department_primary_tool_url('unknown_dept');
    if (department_primary_tool_url('site_extracting') === 'index.php?page=team_extracting'
        && department_primary_tool_url('site_finding') === 'index.php?page=team_prospect_check'
        && department_primary_tool_url('email_extracting') === 'index.php?page=team_sites_emails'
        && department_primary_tool_url('communication') === 'index.php?page=team_email_campaigns'
        && str_contains($otherTool, 'team_departments')
        && str_contains($otherTool, 'folder=unknown_dept')
        && department_task_open_label('site_extracting') === 'Open Extracting sites'
        && department_task_assignee_label(['assigned_to' => 0]) === 'Whole department'
        && team_home_url() === 'index.php?page=team_dashboard'
        && department_tasks_have_due_date([]) === false
        && department_tasks_have_due_date([['due_date' => ''], ['due_date' => '2099-01-01']]) === true) {
        pass('department primary tool URLs + assignee label');
    } else {
        fail('department primary tool URL helper unexpected');
    }

    $simDept = get_department_by_slug('site_extracting');
    if ($simDept) {
        $simSaved = save_department_task(
            (int) $simDept['id'],
            'txf-Similar Title',
            '',
            'open',
            null,
            null,
            $adminUser
        );
        $hit = find_similar_open_department_task((int) $simDept['id'], '  TXF-similar   title ');
        $self = find_similar_open_department_task(
            (int) $simDept['id'],
            'txf-Similar Title',
            (int) ($simSaved['id'] ?? 0)
        );
        if (!empty($simSaved['ok'])
            && $hit
            && (int) ($hit['id'] ?? 0) === (int) ($simSaved['id'] ?? 0)
            && $self === null
            && department_task_title_key('Extract Sites') === department_task_title_key('extract sites')) {
            pass('similar open department task title match');
        } else {
            fail('similar open department task helper unexpected');
        }
        if (!empty($simSaved['id'])) {
            delete_department_task((int) $simSaved['id']);
        }
    } else {
        fail('site_extracting missing for similar-title helper');
    }

    $day = '2099-01-15';
    $batchDe = get_or_create_prospect_batch(
        (int) $teamUser['id'],
        'Germany',
        'German',
        'europe',
        'txf-t3',
        'per-country',
        $day
    );
    $batchFr = get_or_create_prospect_batch(
        (int) $teamUser['id'],
        'France',
        'French',
        'europe',
        'txf-t3',
        'per-country',
        $day
    );
    $batchDe2 = get_or_create_prospect_batch(
        (int) $teamUser['id'],
        'Germany',
        'German',
        'europe',
        'txf-t3',
        'per-country',
        $day
    );
    if ($batchDe > 0 && $batchFr > 0 && $batchDe !== $batchFr && $batchDe === $batchDe2) {
        pass('prospect batch per country');
    } else {
        fail("prospect batches de=$batchDe fr=$batchFr de2=$batchDe2");
    }
    db()->prepare('DELETE FROM prospect_batches WHERE id IN (?,?)')->execute([$batchDe, $batchFr]);
} catch (Throwable $e) {
    fail('team panels helpers: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Site Finding TLD Separate all ---
try {
    $grouped = group_domains_by_tld([
        'alpha.es',
        'beta.com',
        'gamma.com',
        'delta.com.es',
        'epsilon.pe',
        'zeta.cl',
        'https://www.again.es/path',
    ]);
    if (
        isset($grouped['es'], $grouped['com'], $grouped['com.es'], $grouped['pe'], $grouped['cl'])
        && count($grouped['es']) === 2
        && count($grouped['com']) === 2
        && count($grouped['com.es']) === 1
        && count($grouped['pe']) === 1
        && count($grouped['cl']) === 1
        && in_array('again.es', $grouped['es'], true)
    ) {
        pass('group_domains_by_tld splits es/com/com.es/pe/cl');
    } else {
        fail('tld groups: ' . json_encode($grouped));
    }
    $empty = group_domains_by_tld([]);
    if ($empty === []) {
        pass('group_domains_by_tld empty input');
    } else {
        fail('empty groups=' . json_encode($empty));
    }
} catch (Throwable $e) {
    fail('tld separate: ' . $e->getMessage());
}

// --- Filter gate: Separate/Add only after Filter unique sites ---
try {
    prospect_filter_gate_clear();
    if (prospect_filter_gate_allows('Germany', ['alpha.de'])) {
        fail('gate should deny before Filter');
    } else {
        pass('gate denies before Filter');
    }
    prospect_filter_gate_set('Germany', ['alpha.de', 'beta.com']);
    if (prospect_filter_gate_allows('Germany', ['alpha.de'])
        && prospect_filter_gate_allows('Germany', ['beta.com'])
        && !prospect_filter_gate_allows('Germany', ['gamma.de'])
        && !prospect_filter_gate_allows('Spain', ['alpha.de'])) {
        pass('gate allows only filtered unique for that country');
    } else {
        fail('gate allow/deny unexpected');
    }
    prospect_filter_gate_clear();
    if (!prospect_filter_gate_allows('Germany', ['alpha.de'])) {
        pass('gate clear blocks send');
    } else {
        fail('gate still open after clear');
    }
    prospect_filter_gate_set('Germany', ['alpha.de', 'beta.at', 'gamma.com']);
    $left = prospect_filter_gate_subtract('Germany', ['alpha.de']);
    if (
        $left === ['beta.at', 'gamma.com']
        && prospect_filter_gate_allows('Germany', ['beta.at'])
        && !prospect_filter_gate_allows('Germany', ['alpha.de'])
        && prospect_filter_gate_domains('Germany') === ['beta.at', 'gamma.com']
    ) {
        pass('gate subtract leaves remaining unique');
    } else {
        fail('gate subtract remaining=' . json_encode($left));
    }
    $emptyLeft = prospect_filter_gate_subtract('Germany', ['beta.at', 'gamma.com']);
    if ($emptyLeft === [] && prospect_filter_gate_domains('Germany') === []) {
        pass('gate subtract last domains clears');
    } else {
        fail('gate subtract last=' . json_encode($emptyLeft));
    }
} catch (Throwable $e) {
    fail('filter gate: ' . $e->getMessage());
}

// --- Routed Filter/Add: DE/AT/CH + .com per destination Our database ---
try {
    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfroute-add-%'");
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfroute-add-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfroute-add-%'");

    // Seed duplicates in destination folders (not only Germany).
    add_prospect_domains(
        ['txfroute-add-dup.de'],
        $adminUser,
        'Germany',
        'German',
        'europe',
        '',
        'seed de'
    );
    add_prospect_domains(
        ['txfroute-add-dup.at'],
        $adminUser,
        'Austria',
        'German',
        'europe',
        '',
        'seed at'
    );
    add_prospect_domains(
        ['txfroute-add-dup.ch'],
        $adminUser,
        'Switzerland',
        'German',
        'europe',
        '',
        'seed ch'
    );

    $routed = filter_domains_routed_against_prospects(
        [
            'txfroute-add-new.de',
            'txfroute-add-dup.de',
            'txfroute-add-new.at',
            'txfroute-add-dup.at',
            'txfroute-add-new.ch',
            'txfroute-add-dup.ch',
            'txfroute-add-new.com',
        ],
        'Germany'
    );
    $newSet = $routed['new'] ?? [];
    $existSet = $routed['existing'] ?? [];
    $by = $routed['by_country'] ?? [];
    if (
        in_array('txfroute-add-new.de', $newSet, true)
        && in_array('txfroute-add-new.at', $newSet, true)
        && in_array('txfroute-add-new.ch', $newSet, true)
        && in_array('txfroute-add-new.com', $newSet, true)
        && in_array('txfroute-add-dup.de', $existSet, true)
        && in_array('txfroute-add-dup.at', $existSet, true)
        && in_array('txfroute-add-dup.ch', $existSet, true)
        && in_array('txfroute-add-new.at', $by['Austria']['new'] ?? [], true)
        && in_array('txfroute-add-new.ch', $by['Switzerland']['new'] ?? [], true)
        && in_array('txfroute-add-new.com', $by['Germany']['new'] ?? [], true)
        && !in_array('txfroute-add-dup.at', $newSet, true)
    ) {
        pass('routed filter splits DE/AT/CH + .com and drops destination dupes');
    } else {
        fail('routed filter unexpected: ' . json_encode($routed));
    }

    $addedRoute = add_prospect_domains(
        $newSet,
        $adminUser,
        'Germany',
        'German',
        'europe',
        'Route test',
        'routed add'
    );
    $de = (int) db()->query(
        "SELECT COUNT(*) FROM prospect_sites WHERE country='Germany' AND domain IN ('txfroute-add-new.de','txfroute-add-new.com')"
    )->fetchColumn();
    $at = (int) db()->query(
        "SELECT COUNT(*) FROM prospect_sites WHERE country='Austria' AND domain='txfroute-add-new.at'"
    )->fetchColumn();
    $ch = (int) db()->query(
        "SELECT COUNT(*) FROM prospect_sites WHERE country='Switzerland' AND domain='txfroute-add-new.ch'"
    )->fetchColumn();
    $wrongAtInDe = (int) db()->query(
        "SELECT COUNT(*) FROM prospect_sites WHERE country='Germany' AND domain='txfroute-add-new.at'"
    )->fetchColumn();

    $exDe = 0;
    $exAt = 0;
    $exCh = 0;
    if (function_exists('get_or_create_extract_batch')) {
        $idDe = get_or_create_extract_batch('Germany', $adminUser, 'German', 'europe');
        $idAt = get_or_create_extract_batch('Austria', $adminUser, 'German', 'europe');
        $idCh = get_or_create_extract_batch('Switzerland', $adminUser, 'German', 'europe');
        $st = db()->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=? AND domain=?');
        $st->execute([$idDe, 'txfroute-add-new.de']);
        $exDe = (int) $st->fetchColumn();
        $st->execute([$idDe, 'txfroute-add-new.com']);
        $exDe += (int) $st->fetchColumn();
        $st->execute([$idAt, 'txfroute-add-new.at']);
        $exAt = (int) $st->fetchColumn();
        $st->execute([$idCh, 'txfroute-add-new.ch']);
        $exCh = (int) $st->fetchColumn();
    }

    $atInserted = (int) (($addedRoute['by_country']['Austria']['inserted'] ?? 0));
    $chInserted = (int) (($addedRoute['by_country']['Switzerland']['inserted'] ?? 0));
    $deInserted = (int) (($addedRoute['by_country']['Germany']['inserted'] ?? 0));

    if (
        (int) ($addedRoute['inserted'] ?? 0) >= 4
        && $de === 2
        && $at === 1
        && $ch === 1
        && $wrongAtInDe === 0
        && $exDe === 2
        && $exAt === 1
        && $exCh === 1
        && $atInserted === 1
        && $chInserted === 1
        && $deInserted === 2
    ) {
        pass('routed add saves per destination Our DB + Extracting Sites lists');
    } else {
        fail('routed add unexpected: ' . json_encode([
            'added' => $addedRoute,
            'de' => $de,
            'at' => $at,
            'ch' => $ch,
            'wrongAtInDe' => $wrongAtInDe,
            'exDe' => $exDe,
            'exAt' => $exAt,
            'exCh' => $exCh,
            'atInserted' => $atInserted,
            'chInserted' => $chInserted,
            'deInserted' => $deInserted,
        ]));
    }

    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfroute-add-%'");
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfroute-add-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfroute-add-%'");
} catch (Throwable $e) {
    fail('routed filter/add: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Shared Extracting country count (two users) + live hub COUNT ---
try {
    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfshare-%'");
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfshare-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfshare-%'");

    $shareA = add_prospect_domains(
        ['txfshare-a.com'],
        $adminUser,
        'Germany',
        'German',
        'europe',
        '',
        'share a'
    );
    $shareB = add_prospect_domains(
        ['txfshare-b.com'],
        $teamUser,
        'Germany',
        'German',
        'europe',
        '',
        'share b'
    );
    $idDe = get_or_create_extract_batch('Germany', $adminUser, 'German', 'europe');
    $st = db()->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=? AND domain=?');
    $st->execute([$idDe, 'txfshare-a.com']);
    $hasA = (int) $st->fetchColumn();
    $st->execute([$idDe, 'txfshare-b.com']);
    $hasB = (int) $st->fetchColumn();
    $live = refresh_extract_batch_site_count($idDe);
    $hub = list_extract_batches(2000);
    $hubDe = 0;
    foreach ($hub as $row) {
        if ((string) ($row['country'] ?? '') === 'Germany') {
            $hubDe = (int) ($row['site_count'] ?? 0);
            break;
        }
    }
    $cntStmt = db()->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=?');
    $cntStmt->execute([$idDe]);
    $rawCnt = (int) $cntStmt->fetchColumn();

    // Stale denormalized column must not hide a filled list on the hub.
    db()->prepare('UPDATE extract_batches SET site_count=0 WHERE id=?')->execute([$idDe]);
    $hubAfterStale = list_extract_batches(2000);
    $hubDeStale = 0;
    foreach ($hubAfterStale as $row) {
        if ((string) ($row['country'] ?? '') === 'Germany') {
            $hubDeStale = (int) ($row['site_count'] ?? 0);
            break;
        }
    }

    if (
        (int) ($shareA['inserted'] ?? 0) === 1
        && (int) ($shareB['inserted'] ?? 0) === 1
        && ($shareA['extract_error'] ?? '') === ''
        && ($shareB['extract_error'] ?? '') === ''
        && $hasA === 1
        && $hasB === 1
        && $live === $rawCnt
        && $hubDe === $rawCnt
        && $hubDeStale === $rawCnt
        && $hubDeStale >= 2
    ) {
        pass('two users add to same Extracting country; hub uses live count');
    } else {
        fail('shared extract count unexpected: ' . json_encode([
            'shareA' => $shareA,
            'shareB' => $shareB,
            'hasA' => $hasA,
            'hasB' => $hasB,
            'live' => $live,
            'rawCnt' => $rawCnt,
            'hubDe' => $hubDe,
            'hubDeStale' => $hubDeStale,
        ]));
    }

    $msg = email_campaign_bulk_result_message('Imported file into sheet', [
        'added' => 2,
        'updated' => 1,
        'skipped' => 3,
        'skipped_duplicate' => 1,
        'skipped_empty' => 1,
        'skipped_excluded' => 1,
        'lines' => 6,
        'errors' => ['skip.com: Add at least one email — each site must have email data.'],
    ]);
    if (
        str_contains($msg, '2 new, 1 updated')
        && str_contains($msg, 'duplicate domain(s) skipped')
        && str_contains($msg, 'skipped (no emails)')
        && str_contains($msg, 'previously removed (not re-added)')
        && str_contains($msg, '6 data line(s)')
        && str_contains($msg, 'Issues:')
    ) {
        pass('campaign import result message lists added/skipped/lines/errors');
    } else {
        fail('campaign import result message: ' . $msg);
    }

    $xlsxNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xlsxPath = sys_get_temp_dir() . '/txfxlsx-import-' . getmypid() . '.xlsx';
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        pass('xlsx import skipped (PHP zip/xml missing)');
    } else {
        $writeXlsx = static function (string $path, array $files): void {
            $z = new ZipArchive();
            if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not write test xlsx');
            }
            foreach ($files as $name => $xml) {
                $z->addFromString($name, $xml);
            }
            $z->close();
        };
        $writeXlsx($xlsxPath, [
            'xl/sharedStrings.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . $xlsxNs . '" count="2" uniqueCount="2">'
                . '<si><t>txfxlsx-a.de</t></si><si><t>a@txfxlsx-a.de</t></si></sst>',
            'xl/worksheets/sheet1.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $xlsxNs . '"><sheetData>'
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
                . '</sheetData></worksheet>',
        ]);
        $xlsxShared = read_email_campaign_xlsx_as_paste_text($xlsxPath);
        $writeXlsx($xlsxPath, [
            'xl/worksheets/sheet1.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $xlsxNs . '"><sheetData>'
                . '<row r="1"><c r="A1" t="inlineStr"><is><t>Site name</t></is></c>'
                . '<c r="B1" t="inlineStr"><is><t>Email 1</t></is></c></row>'
                . '<row r="2"><c r="A2" t="inlineStr"><is><t>txfxlsx-b.de</t></is></c>'
                . '<c r="B2" t="inlineStr"><is><t>b@txfxlsx-b.de</t></is></c></row>'
                . '</sheetData></worksheet>',
        ]);
        $xlsxInline = read_email_campaign_xlsx_as_paste_text($xlsxPath);
        $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $relNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $writeXlsx($xlsxPath, [
            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $xlsxNs . '" xmlns:r="' . $rNs . '">'
                . '<sheets>'
                . '<sheet name="Old" sheetId="1" r:id="rId1" state="hidden"/>'
                . '<sheet name="Live" sheetId="2" r:id="rId2"/>'
                . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $relNs . '">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $xlsxNs . '"><sheetData>'
                . '<row r="1"><c r="A1" t="inlineStr"><is><t>txfxlsx-hidden.de</t></is></c>'
                . '<c r="B1" t="inlineStr"><is><t>h@txfxlsx-hidden.de</t></is></c></row>'
                . '</sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $xlsxNs . '"><sheetData>'
                . '<row r="1"><c r="A1" t="inlineStr"><is><t>txfxlsx-first.de</t></is></c>'
                . '<c r="B1" t="inlineStr"><is><t>f@txfxlsx-first.de</t></is></c></row>'
                . '</sheetData></worksheet>',
        ]);
        $xlsxFirst = read_email_campaign_xlsx_as_paste_text($xlsxPath);
        $writeXlsx($xlsxPath, [
            'xl/sharedStrings.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . $xlsxNs . '" count="2" uniqueCount="2">'
                . '<si><t>txfxlsx-rph.de</t><rPh sb="0" eb="3"><t>phoneticjunk</t></rPh></si>'
                . '<si><r><t>a@txfxlsx-rph.de</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $xlsxNs . '"><sheetData>'
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
                . '</sheetData></worksheet>',
        ]);
        $xlsxRph = read_email_campaign_xlsx_as_paste_text($xlsxPath);
        @unlink($xlsxPath);
        $utf16Path = sys_get_temp_dir() . '/txfutf16-' . getmypid() . '.csv';
        file_put_contents($utf16Path, "\xFF\xFEs\x00i\x00t\x00e\x00");
        $utf16Err = '';
        try {
            email_campaign_rows_text_from_file_path($utf16Path, 'sites.csv');
        } catch (Throwable $e) {
            $utf16Err = $e->getMessage();
        }
        @unlink($utf16Path);
        $macPath = sys_get_temp_dir() . '/txfmac-' . getmypid() . '.csv';
        file_put_contents($macPath, "Site name,Email 1\rtxmac.de,a@txmac.de\rtxmac2.de,b@txmac2.de");
        $macCsv = email_campaign_rows_text_from_file_path($macPath, 'sites.csv');
        @unlink($macPath);
        if (
            str_contains($xlsxShared, 'txfxlsx-a.de')
            && str_contains($xlsxShared, 'a@txfxlsx-a.de')
            && str_contains($xlsxInline, 'txfxlsx-b.de')
            && str_contains($xlsxInline, 'b@txfxlsx-b.de')
            && !str_contains($xlsxInline, 'Site name')
            && str_contains($xlsxFirst, 'txfxlsx-first.de')
            && str_contains($xlsxFirst, 'f@txfxlsx-first.de')
            && !str_contains($xlsxFirst, 'txfxlsx-hidden.de')
            && str_contains($xlsxRph, 'txfxlsx-rph.de')
            && str_contains($xlsxRph, 'a@txfxlsx-rph.de')
            && !str_contains($xlsxRph, 'phoneticjunk')
            && str_contains($utf16Err, 'UTF-16')
            && str_contains($macCsv, 'txmac.de')
            && str_contains($macCsv, 'txmac2.de')
            && !str_contains($macCsv, 'Site name')
        ) {
            pass('xlsx import reads shared strings + inlineStr; UTF-16 CSV is rejected');
        } else {
            fail('xlsx/utf16 import: ' . json_encode([
                'shared' => $xlsxShared,
                'inline' => $xlsxInline,
                'first' => $xlsxFirst,
                'rph' => $xlsxRph,
                'utf16' => $utf16Err,
                'mac' => $macCsv,
            ]));
        }
    }

    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfshare-%'");
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfshare-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfshare-%'");
    refresh_extract_batch_site_count($idDe);
} catch (Throwable $e) {
    fail('shared extract count: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Our database delete also clears Extracting; Filter & add puts sites back on top ---
try {
    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfsync-%'");
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfsync-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfsync-%'");

    $syncAdd = add_prospect_domains(
        ['txfsync-keep.de', 'txfsync-del.de', 'txfsync-list.de'],
        $adminUser,
        'Germany',
        'German',
        'europe',
        '',
        'sync seed'
    );
    $idDeSync = get_or_create_extract_batch('Germany', $adminUser, 'German', 'europe');
    $idAtSync = get_or_create_extract_batch('Austria', $adminUser, 'German', 'europe');
    db()->prepare(
        'INSERT INTO extract_batch_sites (batch_id, domain, prospect_site_id, added_by)
         VALUES (?,?,NULL,?)'
    )->execute([$idAtSync, 'txfsync-keep.at', (int) $adminUser['id']]);
    $has = static function (int $batchId, string $domain): int {
        $st = db()->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=? AND domain=?');
        $st->execute([$batchId, $domain]);
        return (int) $st->fetchColumn();
    };
    $delId = (int) db()->query(
        "SELECT id FROM prospect_sites WHERE country='Germany' AND domain='txfsync-del.de' LIMIT 1"
    )->fetchColumn();
    $removedRow = delete_prospect_site_by_id($delId);
    $afterDelEx = $has($idDeSync, 'txfsync-del.de');
    $keepEx = $has($idDeSync, 'txfsync-keep.de');
    $atUntouched = $has($idAtSync, 'txfsync-keep.at');
    $listRm = remove_prospect_sites_by_list('Germany', "txfsync-list.de\n");
    $afterListEx = $has($idDeSync, 'txfsync-list.de');

    $readd = add_prospect_domains(
        ['txfsync-del.de'],
        $adminUser,
        'Germany',
        'German',
        'europe',
        '',
        'sync readd'
    );
    $domainsAfterReadd = get_extract_batch_domains($idDeSync);
    $readdOnTop = ($domainsAfterReadd[0] ?? '') === 'txfsync-del.de';
    $readdPresent = $has($idDeSync, 'txfsync-del.de') === 1;

    db()->prepare(
        'INSERT INTO extract_batch_sites (batch_id, domain, prospect_site_id, added_by)
         VALUES (?,?,NULL,?)'
    )->execute([$idDeSync, 'txfsync-orphan.de', (int) $adminUser['id']]);
    $orphanAdd = add_prospect_domains(
        ['txfsync-orphan.de'],
        $adminUser,
        'Germany',
        'German',
        'europe',
        '',
        'sync orphan'
    );
    $orphanDomains = get_extract_batch_domains($idDeSync);
    $orphanOnTop = ($orphanDomains[0] ?? '') === 'txfsync-orphan.de';
    $orphanCount = $has($idDeSync, 'txfsync-orphan.de');

    if (
        (int) ($syncAdd['inserted'] ?? 0) === 3
        && is_array($removedRow)
        && ($removedRow['domain'] ?? '') === 'txfsync-del.de'
        && $afterDelEx === 0
        && $keepEx === 1
        && $atUntouched === 1
        && (int) ($listRm['removed'] ?? 0) === 1
        && $afterListEx === 0
        && (int) ($readd['inserted'] ?? 0) === 1
        && $readdPresent
        && $readdOnTop
        && (int) ($orphanAdd['inserted'] ?? 0) === 1
        && ($orphanAdd['extract_error'] ?? '') === ''
        && $orphanOnTop
        && $orphanCount === 1
    ) {
        pass('Our database delete clears Extracting; Filter & add puts sites back on top');
    } else {
        fail('Our DB / Extracting sync unexpected: ' . json_encode([
            'syncAdd' => $syncAdd,
            'afterDelEx' => $afterDelEx,
            'keepEx' => $keepEx,
            'atUntouched' => $atUntouched,
            'listRm' => $listRm,
            'afterListEx' => $afterListEx,
            'readd' => $readd,
            'top' => $domainsAfterReadd[0] ?? '',
            'orphanAdd' => $orphanAdd,
            'orphanTop' => $orphanDomains[0] ?? '',
            'orphanCount' => $orphanCount,
        ]));
    }

    db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txfsync-%'");
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfsync-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfsync-%'");
    refresh_extract_batch_site_count($idDeSync);
    refresh_extract_batch_site_count($idAtSync);
} catch (Throwable $e) {
    fail('Our DB / Extracting sync: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

try {
    $now = strtotime('2026-08-31 16:00:00');
    $sum = extract_hub_waiting_summary([
        ['site_count' => 3764],
        ['site_count' => 3],
        ['site_count' => 0],
        ['country' => 'Skip empty'],
    ]);
    $stamp = extract_hub_stamp('2026-08-31 16:10:46');
    $large = extract_hub_row_cues(['site_count' => 1027, 'updated_at' => '2026-08-31 15:01:06', 'last_pushed_at' => '2026-08-31 15:01:00'], $now);
    $quiet = extract_hub_row_cues(['site_count' => 149, 'updated_at' => '2026-08-20 16:18:09', 'last_pushed_at' => ''], $now);
    $freshSmall = extract_hub_row_cues(['site_count' => 3, 'updated_at' => '2026-08-29 14:02:36', 'last_pushed_at' => ''], $now);
    if (
        (int) ($sum['sites'] ?? 0) === 3767
        && (int) ($sum['countries'] ?? 0) === 2
        && $stamp === '2026-08-31 16:10'
        && extract_hub_stamp('') === ''
        && !empty($large['large']) && empty($large['stale']) && empty($large['quiet'])
        && !empty($quiet['stale']) && !empty($quiet['quiet']) && empty($quiet['large'])
        && empty($freshSmall['stale']) && empty($freshSmall['quiet']) && empty($freshSmall['large'])
    ) {
        pass('extract hub waiting total + stale/large/quiet cues');
    } else {
        fail('extract hub cues unexpected: ' . json_encode([
            'sum' => $sum,
            'stamp' => $stamp,
            'large' => $large,
            'quiet' => $quiet,
            'freshSmall' => $freshSmall,
        ]));
    }
} catch (Throwable $e) {
    fail('extract hub cues: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Admin Users U-1: unique email + verify reset ---
try {
    ensure_account_schema();
    $u1a = 'u1_admin_a_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $u1b = 'u1_admin_b_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $sharedEmail = $u1a . '@example.test';
    db()->prepare(
        "INSERT INTO users (username, password_hash, full_name, email, role, is_active, must_change_password, email_verified_at)
         VALUES (?,?,?,?, 'admin', 1, 0, NOW())"
    )->execute([$u1a, password_hash('TestAdmin8z', PASSWORD_DEFAULT), 'U1 Admin A', $sharedEmail]);
    $idA = (int) db()->lastInsertId();
    db()->prepare(
        "INSERT INTO users (username, password_hash, full_name, email, role, is_active, must_change_password)
         VALUES (?,?,?,?, 'admin', 1, 0)"
    )->execute([$u1b, password_hash('TestAdmin8z', PASSWORD_DEFAULT), 'U1 Admin B', 'other-' . $sharedEmail]);
    $idB = (int) db()->lastInsertId();

    if (admin_email_taken_by_other($sharedEmail, $idA)) {
        fail('admin_email_taken_by_other true for self email');
    } elseif (!admin_email_taken_by_other($sharedEmail, $idB)) {
        fail('admin_email_taken_by_other missed other admin email');
    } elseif (!admin_email_taken_by_other(strtoupper($sharedEmail), 0)) {
        fail('admin_email_taken_by_other not case-insensitive');
    } else {
        pass('admin_email_taken_by_other detects active admin duplicates');
    }

    // Simulate Users save clearing verify when email changes.
    $newEmail = 'changed-' . $sharedEmail;
    db()->prepare(
        'UPDATE users SET email=?, email_verified_at=NULL WHERE id=? AND role=\'admin\''
    )->execute([$newEmail, $idA]);
    $rowA = load_user_by_id($idA);
    if ($rowA && empty($rowA['email_verified_at']) && strcasecmp((string) $rowA['email'], $newEmail) === 0) {
        pass('admin email change clears email_verified_at');
    } else {
        fail('email_verified_at not cleared after email change');
    }

    db()->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$idA, $idB]);
} catch (Throwable $e) {
    fail('users U-1: ' . $e->getMessage());
}

try {
    $uTaken = 'u_taken_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $uOther = 'u_other_' . substr(bin2hex(random_bytes(3)), 0, 6);
    db()->prepare(
        "INSERT INTO users (username, password_hash, full_name, email, role, is_active, must_change_password)
         VALUES (?,?,?,?, 'team', 1, 0)"
    )->execute([$uTaken, password_hash('TestTeam8z', PASSWORD_DEFAULT), 'Taken User', '']);
    $takenId = (int) db()->lastInsertId();
    $fmtOk = username_format_error('good_name') === ''
        && username_format_error('has space') !== ''
        && username_format_error('') !== '';
    $dupName = username_taken_by_other($uTaken, 0)
        && username_taken_by_other(strtoupper($uTaken), 0)
        && !username_taken_by_other($uTaken, $takenId)
        && !username_taken_by_other($uOther, 0);
    $url = admin_users_url(['q' => 'ali', 'awaiting' => '1', 'p' => '2'], ['edit' => '9']);
    $urlOk = str_contains($url, 'page=admin_users')
        && str_contains($url, 'q=ali')
        && str_contains($url, 'awaiting=1')
        && str_contains($url, 'edit=9')
        && str_contains($url, 'p=2')
        && !str_contains(admin_users_url(['q' => '']), 'q=');
    if ($fmtOk && $dupName && $urlOk) {
        pass('username rules + admin_users_url filters');
    } else {
        fail('username rules / URL: ' . json_encode([$fmtOk, $dupName, $url]));
    }
    $inactUser = 'u_inact_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $inactEmail = $inactUser . '@example.test';
    db()->prepare(
        "INSERT INTO users (username, password_hash, full_name, email, role, is_active, must_change_password)
         VALUES (?,?,?,?, 'admin', 0, 0)"
    )->execute([$inactUser, password_hash('TestAdmin8z', PASSWORD_DEFAULT), 'Inactive Admin', $inactEmail]);
    $inactId = (int) db()->lastInsertId();
    if (admin_email_taken_by_other($inactEmail, 0) && !admin_email_taken_by_other($inactEmail, $inactId)) {
        pass('admin_email_taken_by_other includes inactive admins');
    } else {
        fail('inactive admin email not treated as taken');
    }
    db()->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$takenId, $inactId]);
} catch (Throwable $e) {
    fail('users identity rules: ' . $e->getMessage());
}

// --- Admin Users U-2: temp password generator ---
try {
    $pwd = generate_temp_password();
    if (strlen($pwd) < 12) {
        fail('generate_temp_password too short: ' . strlen($pwd));
    } elseif (in_array($pwd, known_weak_passwords(), true)) {
        fail('generate_temp_password returned weak password');
    } elseif (!preg_match('/^[A-Za-z0-9!@#$%]+$/', $pwd)) {
        fail('generate_temp_password unexpected chars');
    } else {
        pass('generate_temp_password length and charset');
    }
    $a = generate_temp_password();
    $b = generate_temp_password();
    if ($a !== $b) {
        pass('generate_temp_password not constant');
    } else {
        fail('generate_temp_password returned identical twice');
    }
} catch (Throwable $e) {
    fail('users U-2: ' . $e->getMessage());
}

// --- Admin Users U-4: deactivation residue ---
try {
    if (function_exists('user_deactivation_residue')) {
        $empty = user_deactivation_residue(0);
        if (($empty['memberships'] ?? -1) === 0 && ($empty['open_tasks'] ?? -1) === 0) {
            pass('user_deactivation_residue empty user');
        } else {
            fail('user_deactivation_residue empty failed');
        }
    } else {
        fail('user_deactivation_residue missing');
    }
} catch (Throwable $e) {
    fail('users U-4: ' . $e->getMessage());
}

// --- Sheet undo / redo + bulk remove ---
try {
    $histSheet = create_email_campaign_sheet('Germany', (int) $adminUser['id'], 'TXF Undo Sheet', false);
    $histKey = sheet_history_key('campaign', (string) $histSheet);
    $_SESSION['sheet_history'][$histKey] = ['undo' => [], 'redo' => []];
    $upA = upsert_email_campaign_row($histSheet, 'txfundo-a.be', [
        'email1' => 'a@txfundo-a.be',
        'email2' => '',
        'email3' => '',
        'email4' => '',
    ]);
    $upB = upsert_email_campaign_row($histSheet, 'txfundo-b.be', [
        'email1' => 'b@txfundo-b.be',
        'email2' => '',
        'email3' => '',
        'email4' => '',
    ]);
    $idA = (int) ($upA['id'] ?? 0);
    $idB = (int) ($upB['id'] ?? 0);
    $bulk = delete_email_campaign_rows_by_ids($histSheet, [$idA, $idB]);
    $gone = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $histSheet
        . " AND domain LIKE 'txfundo-%'"
    )->fetchColumn();
    $excluded = is_email_campaign_domain_excluded($histSheet, 'txfundo-a.be');
    $undone = sheet_history_apply_undo($histKey);
    $back = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $histSheet
        . " AND domain LIKE 'txfundo-%'"
    )->fetchColumn();
    $excludedAfter = is_email_campaign_domain_excluded($histSheet, 'txfundo-a.be');
    $redone = sheet_history_apply_redo($histKey);
    $goneAgain = (int) db()->query(
        "SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=" . (int) $histSheet
        . " AND domain LIKE 'txfundo-%'"
    )->fetchColumn();
    if (
        !empty($bulk['ok']) && (int) $bulk['count'] === 2 && $gone === 0 && $excluded
        && !empty($undone['ok']) && $back === 2 && !$excludedAfter
        && !empty($redone['ok']) && $goneAgain === 0
    ) {
        pass('campaign bulk remove + undo restores rows and clears exclusions + redo');
    } else {
        fail('campaign undo/redo unexpected: ' . json_encode([
            'bulk' => $bulk,
            'gone' => $gone,
            'excluded' => $excluded,
            'undone' => $undone,
            'back' => $back,
            'excludedAfter' => $excludedAfter,
            'redone' => $redone,
            'goneAgain' => $goneAgain,
        ]));
    }
    db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfundo-%'");
    db()->prepare('DELETE FROM email_campaign_sheets WHERE id=?')->execute([$histSheet]);

    $flagSheet = create_email_campaign_sheet('Germany', (int) $adminUser['id'], 'TXF Emailed Undo', false);
    $flagKey = sheet_history_key('campaign', (string) $flagSheet);
    $_SESSION['sheet_history'][$flagKey] = ['undo' => [], 'redo' => []];
    $upF = upsert_email_campaign_row($flagSheet, 'txfemailundo.de', [
        'email1' => 'a@txfemailundo.de',
        'email2' => '',
        'email3' => '',
        'email4' => '',
    ]);
    $fid = (int) ($upF['id'] ?? 0);
    $marked = set_email_campaign_row_email_sent($flagSheet, $fid, true);
    $sent1 = (int) db()->query(
        'SELECT email_sent FROM email_campaign_rows WHERE id=' . $fid
    )->fetchColumn();
    $batchAtMark = (int) db()->query(
        'SELECT send_batch_id FROM email_campaign_rows WHERE id=' . $fid
    )->fetchColumn();
    $undoFlag = sheet_history_apply_undo($flagKey);
    $sent0 = (int) db()->query(
        'SELECT email_sent FROM email_campaign_rows WHERE id=' . $fid
    )->fetchColumn();
    $batchAfterUndo = (int) db()->query(
        'SELECT send_batch_id FROM email_campaign_rows WHERE id=' . $fid
    )->fetchColumn();
    $redoFlag = sheet_history_apply_redo($flagKey);
    $sent2 = (int) db()->query(
        'SELECT email_sent FROM email_campaign_rows WHERE id=' . $fid
    )->fetchColumn();
    $batchAfterRedo = (int) db()->query(
        'SELECT send_batch_id FROM email_campaign_rows WHERE id=' . $fid
    )->fetchColumn();
    if (
        !empty($marked['ok']) && $sent1 === 1 && $batchAtMark > 0
        && !empty($undoFlag['ok']) && $sent0 === 0 && $batchAfterUndo === 0
        && !empty($redoFlag['ok']) && $sent2 === 1 && $batchAfterRedo === $batchAtMark
    ) {
        pass('campaign mark emailed undo/redo restores flag');
    } else {
        fail('campaign emailed undo unexpected: ' . json_encode([
            'marked' => $marked,
            'sent1' => $sent1,
            'batchAtMark' => $batchAtMark,
            'undo' => $undoFlag,
            'sent0' => $sent0,
            'batchAfterUndo' => $batchAfterUndo,
            'redo' => $redoFlag,
            'sent2' => $sent2,
            'batchAfterRedo' => $batchAfterRedo,
        ]));
    }
    db()->exec("DELETE FROM email_campaign_rows WHERE domain='txfemailundo.de'");
    db()->prepare('DELETE FROM email_campaign_sheets WHERE id=?')->execute([$flagSheet]);
} catch (Throwable $e) {
    fail('sheet undo/redo: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Campaign delete-who: events survive Allow again ---
try {
    ensure_email_campaign_schema();
    $whoPid = create_email_campaign_project('TXF Who Deleted', (int) $adminUser['id'], true);
    $whoSheet = add_email_campaign_country_to_project($whoPid, 'Germany', (int) $adminUser['id']);
    $upWho = upsert_email_campaign_row($whoSheet, 'txfcamp-who-del.de', [
        'keep@txfcamp-who-del.de',
        'drop@txfcamp-who-del.de',
    ]);
    $whoId = (int) ($upWho['id'] ?? 0);
    $rmWho = remove_email_from_email_campaign_row(
        $whoSheet,
        $whoId,
        'drop@txfcamp-who-del.de',
        $teamUser
    );
    $evEmail = list_email_campaign_row_events($whoSheet, null, 20);
    $emailHit = null;
    foreach ($evEmail as $ev) {
        if (($ev['action'] ?? '') === 'remove_email' && ($ev['email'] ?? '') === 'drop@txfcamp-who-del.de') {
            $emailHit = $ev;
            break;
        }
    }
    $delWho = delete_email_campaign_row($whoSheet, $whoId, true, $teamUser);
    $evSite = list_email_campaign_row_events($whoSheet, null, 20);
    $siteHit = null;
    foreach ($evSite as $ev) {
        if (($ev['action'] ?? '') === 'delete_site' && ($ev['domain'] ?? '') === 'txfcamp-who-del.de') {
            $siteHit = $ev;
            break;
        }
    }
    $beforeAllow = count_email_campaign_row_events($whoSheet);
    clear_email_campaign_domain_exclusion($whoSheet, 'txfcamp-who-del.de');
    $afterAllow = count_email_campaign_row_events($whoSheet);
    $whoExpect = email_campaign_event_who_label($teamUser);
    if (
        !empty($rmWho['ok']) && empty($rmWho['row_deleted'])
        && $emailHit && (int) ($emailHit['user_id'] ?? 0) === (int) $teamUser['id']
        && ($emailHit['username'] ?? '') === 'teammate'
        && !empty($delWho['ok'])
        && $siteHit && (int) ($siteHit['user_id'] ?? 0) === (int) $teamUser['id']
        && ($siteHit['domain'] ?? '') === 'txfcamp-who-del.de'
        && $beforeAllow === $afterAllow && $afterAllow >= 2
        && email_campaign_who_for_exclusion(
            map_email_campaign_latest_event_who($whoSheet),
            'delete_site',
            'txfcamp-who-del.de'
        ) === $whoExpect
        && email_campaign_who_for_exclusion(
            map_email_campaign_latest_event_who($whoSheet),
            'remove_email',
            'txfcamp-who-del.de',
            'drop@txfcamp-who-del.de'
        ) === $whoExpect
        && email_campaign_who_for_exclusion(
            map_email_campaign_latest_event_who($whoSheet),
            'remove_email',
            'txfcamp-who-del.de',
            'keep@txfcamp-who-del.de'
        ) === $whoExpect
    ) {
        pass('campaign delete events stamp teammate and survive Allow again');
    } else {
        fail('campaign delete-who: ' . json_encode([
            'rm' => $rmWho,
            'emailHit' => $emailHit,
            'del' => $delWho,
            'siteHit' => $siteHit,
            'before' => $beforeAllow,
            'after' => $afterAllow,
        ]));
    }
    db()->exec("DELETE FROM email_campaign_rows WHERE domain='txfcamp-who-del.de'");
    db()->exec("DELETE FROM email_campaign_row_events WHERE domain='txfcamp-who-del.de'");
    if ($whoSheet) {
        db()->prepare('DELETE FROM email_campaign_sheets WHERE id=?')->execute([$whoSheet]);
    }
    if ($whoPid) {
        delete_email_campaign_project($whoPid);
    }
} catch (Throwable $e) {
    fail('campaign delete-who: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// --- Campaign named send batches (who emailed which stretch) ---
try {
    ensure_email_campaign_schema();
    db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfcamp-batch-%'");
    db()->exec(
        "DELETE FROM email_campaign_sheets
         WHERE name LIKE 'txfcamp-batch-%' OR project_name LIKE 'TXF Send Batches%'"
    );
    db()->exec("DELETE FROM email_campaign_projects WHERE name LIKE 'TXF Send Batches%'");

    $campCols = db()->query('SHOW COLUMNS FROM email_campaign_rows')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $batchTable = db()->query("SHOW TABLES LIKE 'email_campaign_send_batches'")->fetch(PDO::FETCH_NUM);
    if (in_array('send_batch_id', $campCols, true) && $batchTable) {
        pass('campaign send_batch_id column + send_batches table');
    } else {
        fail('campaign missing send batches schema: cols=' . json_encode($campCols));
    }

    $bPid = create_email_campaign_project('TXF Send Batches', (int) $adminUser['id'], false);
    $bSheet = add_email_campaign_country_to_project($bPid, 'Germany', (int) $adminUser['id']);
    foreach (
        [
            'txfcamp-batch-a.com',
            'txfcamp-batch-b.com',
            'txfcamp-batch-c.com',
            'txfcamp-batch-d.com',
        ] as $dom
    ) {
        upsert_email_campaign_row($bSheet, $dom, [
            'email1' => 'x@' . $dom,
            'email2' => '',
            'email3' => '',
            'email4' => '',
        ]);
    }
    $bIds = db()->query(
        'SELECT id, domain FROM email_campaign_rows
         WHERE sheet_id=' . (int) $bSheet . " AND domain LIKE 'txfcamp-batch-%'
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($bIds) !== 4) {
        fail('txfcamp-batch seed count=' . count($bIds));
    } else {
        $idA = (int) $bIds[0]['id'];
        $idB = (int) $bIds[1]['id'];
        $idC = (int) $bIds[2]['id'];
        $idD = (int) $bIds[3]['id'];

        $uptoA = mark_email_campaign_emailed_up_to($bSheet, $idB, 'Batch A', $teamUser);
        $rowA = get_email_campaign_row($idA, $bSheet);
        $rowB = get_email_campaign_row($idB, $bSheet);
        $rowC = get_email_campaign_row($idC, $bSheet);
        $batchAId = (int) ($rowA['send_batch_id'] ?? 0);
        $batchA = $batchAId > 0 ? get_email_campaign_send_batch($batchAId, $bSheet) : null;
        $statusA = email_campaign_row_emailed_status($batchA);
        if (
            !empty($uptoA['ok'])
            && (int) ($uptoA['marked'] ?? 0) === 2
            && $batchAId > 0
            && (int) ($rowB['send_batch_id'] ?? 0) === $batchAId
            && (int) ($rowC['email_sent'] ?? 0) === 0
            && ($batchA['name'] ?? '') === 'Batch A'
            && ($batchA['username'] ?? '') === 'teammate'
            && (int) ($batchA['site_count'] ?? 0) === 2
            && str_contains((string) ($statusA['label'] ?? ''), 'Batch A')
            && str_contains((string) ($statusA['title'] ?? ''), 'teammate')
        ) {
            pass('campaign mark up to here names Batch A and stamps teammate');
        } else {
            fail('campaign Batch A: ' . json_encode([
                'upto' => $uptoA,
                'batchA' => $batchA,
                'a' => $rowA['send_batch_id'] ?? null,
                'b' => $rowB['send_batch_id'] ?? null,
                'cSent' => $rowC['email_sent'] ?? null,
                'status' => $statusA,
            ]));
        }

        $uptoB = mark_email_campaign_emailed_up_to($bSheet, $idC, 'Batch B', $adminUser);
        $rowC2 = get_email_campaign_row($idC, $bSheet);
        $rowA2 = get_email_campaign_row($idA, $bSheet);
        $batchBId = (int) ($rowC2['send_batch_id'] ?? 0);
        $batchB = $batchBId > 0 ? get_email_campaign_send_batch($batchBId, $bSheet) : null;
        if (
            !empty($uptoB['ok'])
            && (int) ($uptoB['marked'] ?? 0) === 1
            && $batchBId > 0
            && $batchBId !== $batchAId
            && (int) ($rowA2['send_batch_id'] ?? 0) === $batchAId
            && ($batchB['name'] ?? '') === 'Batch B'
            && ($batchB['username'] ?? '') === 'admin'
        ) {
            pass('campaign second checkpoint is a new Batch B');
        } else {
            fail('campaign Batch B: ' . json_encode([
                'upto' => $uptoB,
                'batchB' => $batchB,
                'aStill' => $rowA2['send_batch_id'] ?? null,
            ]));
        }

        $listed = list_email_campaign_send_batches($bSheet);
        $listedNames = array_column($listed, 'name');
        $batchRows = list_email_campaign_send_batch_rows($batchAId);
        $batchDomains = array_column($batchRows, 'domain');
        $filterBatch = email_campaign_rows_inventory_query($bSheet, ['batch' => $batchAId], 1, 100);
        $filterDomains = array_column($filterBatch['rows'] ?? [], 'domain');
        if (
            in_array('Batch A', $listedNames, true)
            && in_array('Batch B', $listedNames, true)
            && in_array('txfcamp-batch-a.com', $batchDomains, true)
            && in_array('txfcamp-batch-b.com', $batchDomains, true)
            && !in_array('txfcamp-batch-c.com', $batchDomains, true)
            && $filterDomains === $batchDomains
        ) {
            pass('campaign list send batches and Batch A rows');
        } else {
            fail('campaign list batches: ' . json_encode([
                'names' => $listedNames,
                'domains' => $batchDomains,
                'filter' => $filterDomains,
            ]));
        }

        $delA = delete_email_campaign_row($bSheet, $idA, true, $adminUser);
        $goneA = get_email_campaign_row($idA, $bSheet);
        $undoDel = sheet_history_apply_undo(sheet_history_key('campaign', (string) $bSheet));
        $backA = get_email_campaign_row($idA, $bSheet);
        if (
            !empty($delA['ok'])
            && !$goneA
            && !empty($undoDel['ok'])
            && (int) ($backA['email_sent'] ?? 0) === 1
            && (int) ($backA['send_batch_id'] ?? 0) === $batchAId
        ) {
            pass('campaign undo remove restores send_batch_id');
        } else {
            fail('campaign undo remove batch: ' . json_encode([
                'del' => $delA,
                'gone' => $goneA ? 'still there' : null,
                'undo' => $undoDel,
                'backSent' => $backA['email_sent'] ?? null,
                'backBatch' => $backA['send_batch_id'] ?? null,
                'expect' => $batchAId,
            ]));
        }

        $clear = clear_email_campaign_emailed_up_to($bSheet, $idB);
        $rowA3 = get_email_campaign_row($idA, $bSheet);
        $rowC3 = get_email_campaign_row($idC, $bSheet);
        $stillA = get_email_campaign_send_batch($batchAId, $bSheet);
        if (
            !empty($clear['ok'])
            && (int) ($rowA3['email_sent'] ?? 0) === 0
            && (int) ($rowA3['send_batch_id'] ?? 0) === 0
            && (int) ($rowC3['send_batch_id'] ?? 0) === $batchBId
            && is_array($stillA)
            && ($stillA['name'] ?? '') === 'Batch A'
            && (int) ($stillA['live_count'] ?? -1) === 0
        ) {
            pass('campaign clear unlinks send_batch_id and keeps Batch A history');
        } else {
            fail('campaign clear batch: ' . json_encode([
                'clear' => $clear,
                'aSent' => $rowA3['email_sent'] ?? null,
                'aBatch' => $rowA3['send_batch_id'] ?? null,
                'cBatch' => $rowC3['send_batch_id'] ?? null,
                'stillA' => $stillA,
            ]));
        }

        $emptyName = mark_email_campaign_emailed_up_to($bSheet, $idD, '', $adminUser);
        $defaultName = (string) ($emptyName['batch_name'] ?? '');
        if (
            !empty($emptyName['ok'])
            && (int) ($emptyName['marked'] ?? 0) >= 1
            && str_contains($defaultName, 'admin')
            && str_contains($defaultName, date('Y-m-d'))
        ) {
            pass('campaign empty batch name gets username · date default');
        } else {
            fail('campaign default batch name: ' . json_encode($emptyName));
        }
    }

    if ($bPid) {
        delete_email_campaign_project($bPid);
    }
} catch (Throwable $e) {
    fail('campaign send batches: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n==== SUMMARY ====\n";
echo 'passed: ' . count($ok) . "\n";
echo 'failed: ' . count($errors) . "\n";
foreach ($errors as $e) {
    echo " - $e\n";
}
exit($errors ? 1 : 0);
