<?php
/**
 * Local integration smoke test — run: php tests_run.php
 * Not for production. Safe to delete after testing.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/extracting.php';
require __DIR__ . '/includes/extracted.php';
require __DIR__ . '/includes/sites_with_emails.php';
require __DIR__ . '/includes/email_campaigns.php';
require __DIR__ . '/includes/admin_new_data.php';
require __DIR__ . '/includes/departments.php';
require __DIR__ . '/includes/orders.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/presence.php';
require __DIR__ . '/includes/semrush_research.php';

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
    ensure_invoice_schema();
    ensure_task_presence_schema();
    ensure_semrush_research_schema();
    pass('schemas ensured');
} catch (Throwable $e) {
    fail('schema: ' . $e->getMessage());
    exit(1);
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
db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%'");
db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%'");
db()->exec("DELETE FROM extract_batch_sites WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%'");
db()->exec("DELETE FROM extracted_sites WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%'");
db()->exec("DELETE FROM sites_with_emails_team WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfsent-%'");
db()->exec("DELETE FROM sites_with_emails_admin WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfsent-%'");
db()->exec("DELETE FROM sites_with_emails_admin_all WHERE domain LIKE 'txftest-%' OR domain LIKE 'txfpush-%' OR domain LIKE 'txfbrand-%' OR domain LIKE 'txfcamp-%' OR domain LIKE 'txfsent-%'");
db()->exec("DELETE FROM email_campaign_rows WHERE domain LIKE 'txfcamp-%' OR domain LIKE 'txfcamp-sent-%'");
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

// --- Clean errors / https:// paste → root domains (Filter & add) ---
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
        'vercel.app',
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
    if ((int) $adminAdd['total'] >= 2 && (int) $adminAdd['inserted'] >= 2) {
        pass('admin_add_urls_to_database inserted=' . (int) $adminAdd['inserted']);
    } else {
        fail('admin_add_urls_to_database: ' . json_encode($adminAdd));
    }
    db()->exec("DELETE FROM prospect_batch_items WHERE domain LIKE 'txfadd-%'");
    db()->exec("DELETE FROM prospect_sites WHERE domain LIKE 'txfadd-%'");
} catch (Throwable $e) {
    fail('prospects: ' . $e->getMessage());
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
        && country_for_push_domain('shop.eu', 'France') === 'France';
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
        $teamUser
    );
    if (empty($fourPush['ok'])) {
        fail('four-slot push failed: ' . ($fourPush['error'] ?? '?'));
    } else {
        pass('four-slot push ok');
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

    // Remove one of two emails → site stays.
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

    // Admin emails search: last email also drops Admin + Final.
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
    if (!empty($rmAdmin['ok']) && !empty($rmAdmin['row_deleted']) && $adminLeft === 0 && $finalLeft === 0) {
        pass('admin last-email deletes Admin+Final row');
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
        '<h2 onclick="x()">Guest post offer</h2><p>Hi <strong>there</strong>, we can do <em>guest posts</em> and <u>niche edits</u>.</p><script>alert(1)</script><a href="https://evil.example">click</a>',
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
        && !str_contains(strtolower($richBody), '<script')
        && !str_contains(strtolower($richBody), 'onclick')
        && !str_contains(strtolower($richBody), '<a ')
        && str_contains($plainFromHtml, 'Guest post offer')
        && str_contains($plainFromHtml, 'guest posts');
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
    $del = delete_email_campaign_draft($draftPid, (int) ($saved['id'] ?? 0));
    $left = count_email_campaign_drafts($draftPid);
    $wrongProjectDel = delete_email_campaign_draft($draftHiddenPid, (int) ($savedOffer['id'] ?? 0));
    if (!empty($updated['ok'])
        && (string) ($afterUpdate['title'] ?? '') === 'Pricing offer v2'
        && !empty($richSaved['ok'])
        && $keepsFormat
        && $sanitized === $richBody
        && $imgOk
        && $badRemoteOnly === ''
        && !empty($del['ok'])
        && $left === 3
        && empty($wrongProjectDel['ok'])) {
        pass('campaign drafts update/delete stay scoped to project');
        pass('campaign drafts keep bold/italic/underline/headings and strip unsafe HTML');
        pass('campaign drafts keep compressed data-URI images and strip remote/unsafe imgs');
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
            'del' => $del,
            'left' => $left,
            'wrong' => $wrongProjectDel,
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
    if ($defaultPp === 1000
        && $picked250 === 250
        && $remembered === 250
        && $badClamped === 1000
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
} catch (Throwable $e) {
    fail('campaign: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
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
        if (!empty($one['ok']) && (int) ($rowA['email_sent'] ?? 0) === 1) {
            pass('mark one Admin site emailed');
        } else {
            fail('mark one: ' . json_encode($one));
        }

        $upto = mark_sites_with_emails_admin_emailed_up_to($idB);
        $stats = count_sites_with_emails_sent_stats('Germany');
        $sentRows = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE domain LIKE 'txfsent-%' AND email_sent=1"
        )->fetchColumn();
        $unsentC = (int) db()->query(
            "SELECT email_sent FROM sites_with_emails_admin WHERE id=" . (int) $idC
        )->fetchColumn();
        if (!empty($upto['ok']) && $sentRows === 2 && (int) $unsentC === 0
            && (int) ($stats['sent'] ?? 0) >= 2) {
            pass('mark up to here leaves newer rows unmarked');
        } else {
            fail('mark up to: ' . json_encode([
                'upto' => $upto,
                'sentRows' => $sentRows,
                'c' => $unsentC,
                'stats' => $stats,
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
        if (in_array('txfsent-a.com', $sentDomains, true)
            && in_array('txfsent-b.com', $sentDomains, true)
            && in_array('txfsent-c.com', $unsentDomains, true)
            && !in_array('txfsent-c.com', $sentDomains, true)) {
            pass('Admin sent filter splits emailed / not emailed');
        } else {
            fail('sent filter: sent=' . json_encode($sentDomains) . ' unsent=' . json_encode($unsentDomains));
        }

        // Re-push updating an already-emailed Admin row must keep email_sent=1.
        db()->prepare(
            "INSERT INTO sites_with_emails_team
               (domain, country, language, region, email1, email2, email3, email4)
             VALUES ('txfsent-a.com','Germany','German','europe','a2@txfsent-a.com','','','')"
        )->execute();
        $repush = push_sites_with_emails_team_to_admin('Germany', $teamUser);
        $afterRepush = (int) db()->query(
            "SELECT email_sent FROM sites_with_emails_admin WHERE domain='txfsent-a.com' LIMIT 1"
        )->fetchColumn();
        if (!empty($repush['updated']) && $afterRepush === 1) {
            pass('Team re-push keeps Admin emailed mark');
        } else {
            fail('re-push sent flag: ' . json_encode($repush) . " sent=$afterRepush");
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

        // Clear up to here — redo a stretch (Admin only).
        $clearUpto = clear_sites_with_emails_admin_emailed_up_to($idB);
        $sentA = (int) db()->query(
            'SELECT email_sent FROM sites_with_emails_admin WHERE id=' . (int) $idA
        )->fetchColumn();
        $sentB = (int) db()->query(
            'SELECT email_sent FROM sites_with_emails_admin WHERE id=' . (int) $idB
        )->fetchColumn();
        $afterClearUpto = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE domain LIKE 'txfsent-%' AND email_sent=1"
        )->fetchColumn();
        if (!empty($clearUpto['ok']) && (int) ($clearUpto['cleared'] ?? 0) >= 2
            && $sentA === 0 && $sentB === 0 && $afterClearUpto === 0) {
            pass('clear up to here undoes Admin emailed marks');
        } else {
            fail('clear up to: ' . json_encode($clearUpto)
                . " a=$sentA b=$sentB left=$afterClearUpto");
        }

        // Redo mark, then clear all emailed for a full resend restart.
        mark_sites_with_emails_admin_emailed_up_to($idC);
        set_site_with_emails_admin_email_sent(
            (int) db()->query("SELECT id FROM sites_with_emails_admin WHERE domain='txfsent-new.com' LIMIT 1")->fetchColumn(),
            true
        );
        $clearAll = clear_all_sites_with_emails_admin_emailed('Germany');
        $sentLeft = (int) db()->query(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE domain LIKE 'txfsent-%' AND email_sent=1"
        )->fetchColumn();
        if (!empty($clearAll['ok']) && (int) ($clearAll['cleared'] ?? 0) >= 3 && $sentLeft === 0) {
            pass('clear all emailed resets Admin sheet for resend');
        } else {
            fail('clear all: ' . json_encode($clearAll) . " left=$sentLeft");
        }

        // Copy filters: not emailed vs emailed (Admin only).
        set_site_with_emails_admin_email_sent($idA, true);
        set_site_with_emails_admin_email_sent($idB, true);
        set_site_with_emails_admin_email_sent($idC, false);
        $copySent = collect_sites_with_emails_all_emails('Germany', 'admin', '1');
        $copyUnsent = collect_sites_with_emails_all_emails('Germany', 'admin', '0');
        $hasA = in_array('a2@txfsent-a.com', $copySent, true) || in_array('a@txfsent-a.com', $copySent, true);
        $hasCUnsent = in_array('c@txfsent-c.com', $copyUnsent, true);
        $cNotInSent = !in_array('c@txfsent-c.com', $copySent, true);
        if ($hasA && $hasCUnsent && $cNotInSent) {
            pass('copy emailed / not-emailed email lists split correctly');
        } else {
            fail('copy filters: sent=' . json_encode($copySent) . ' unsent=' . json_encode($copyUnsent));
        }

        // Sync must not invent sent state on Final (no column); domains still mirror.
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
            'email_extracting' => ['team_sites_emails', 'team_admin_emails_delete'],
            'communication' => ['team_email_campaigns', 'team_email_campaigns_drafts', 'team_admin_emails_delete'],
        };
        $missing = array_diff($expect, $pages);
        if ($missing) {
            fail("$uname tools missing " . implode(',', $missing) . ' got=' . implode(',', $pages));
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
    db()->prepare('UPDATE order_items SET live_url=?, decided_price=? WHERE id=?')
        ->execute(['https://example.com/txforder-site', 10.00, $itemId]);
    set_order_item_paid((int) $itemId, (int) $clientId, true);
    $paidRow = db()->prepare('SELECT is_paid FROM order_items WHERE id=?');
    $paidRow->execute([$itemId]);
    if ((int) $paidRow->fetchColumn() === 1) {
        pass('mark paid with LIVE works');
    } else {
        fail('mark paid with LIVE failed');
    }
    set_order_item_paid((int) $itemId, (int) $clientId, false);

    $invId = create_blank_invoice((int) $adminUser['id']);
    pass("blank invoice id=$invId");

    // Generate from an unpaid LIVE sheet row, then mark paid.
    $genClientId = create_order_client('Test Client Invoice Gen', 'invoice gen test', (int) $adminUser['id']);
    $genItemId = add_order_item((int) $genClientId, 'txforder-live.com', 4, 2026);
    db()->prepare(
        'UPDATE order_items SET decided_price=?, live_url=?, is_paid=0 WHERE id=?'
    )->execute([40.00, 'https://example.com/txforder-live', $genItemId]);
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
        mark_invoice_payment_received($genId);
        $paidRow = db()->prepare('SELECT is_paid FROM order_items WHERE id=?');
        $paidRow->execute([$genItemId]);
        if ((int) $paidRow->fetchColumn() === 1 && invoice_is_paid(get_invoice($genId))) {
            pass('mark paid sets invoice + sheet row');
        } else {
            fail('mark paid did not update invoice/sheet');
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

// --- Admin new-data signals (UI badges removed sitewide; helpers still work) ---
try {
    db()->prepare('DELETE FROM admin_data_seen WHERE user_id=?')->execute([(int) $adminUser['id']]);
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
        pass('New badge UI disabled sitewide');
    } else {
        fail('New badge HTML still rendered');
    }
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

echo "\n==== SUMMARY ====\n";
echo 'passed: ' . count($ok) . "\n";
echo 'failed: ' . count($errors) . "\n";
foreach ($errors as $e) {
    echo " - $e\n";
}
exit($errors ? 1 : 0);
