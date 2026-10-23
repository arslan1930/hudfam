<?php
/**
 * HTTP smoke test against php -S 127.0.0.1:8080
 * Run: php tests/tests_http.php
 */
$base = getenv('TXF_BASE') ?: 'http://127.0.0.1:8080';
$cookie = sys_get_temp_dir() . '/txf_http_cookie.txt';
@unlink($cookie);

$errors = [];
$ok = [];
function pass(string $m): void { global $ok; $ok[] = $m; echo "OK  $m\n"; }
function fail(string $m): void { global $errors; $errors[] = $m; echo "FAIL $m\n"; }

function req(string $method, string $url, array $opts = []): array
{
    global $cookie;
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (isset($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
        'error' => '',
    ];
}

function location(array $res): string
{
    if (preg_match('/^Location:\s*(.+)$/mi', $res['headers'], $m)) {
        return trim($m[1]);
    }
    return '';
}

function extract_csrf(string $html): string
{
    if (preg_match('/name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([^"\']+)["\']/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function login_post(string $base, string $username, string $password): array
{
    $r = req('GET', $base . '/index.php?page=login');
    $csrf = extract_csrf($r['body']);
    $body = ['username' => $username, 'password' => $password];
    if ($csrf !== '') {
        $body['_csrf'] = $csrf;
    }
    return req('POST', $base . '/index.php?page=login', [
        'body' => http_build_query($body),
    ]);
}

// Login page
$r = req('GET', $base . '/index.php?page=login');
$csrf = extract_csrf($r['body']);
if ($r['status'] === 200 && str_contains($r['body'], 'name="username"') && $csrf !== '') {
    pass('login page');
} else {
    fail('login page status=' . $r['status'] . ' csrf=' . ($csrf !== '' ? 'yes' : 'no') . ' err=' . $r['error']);
}

// Bad login
$r = login_post($base, 'admin', 'wrong');
if ($r['status'] === 200 && (str_contains($r['body'], 'Invalid') || str_contains($r['body'], 'invalid') || str_contains($r['body'], 'Too many'))) {
    pass('bad login stays on form');
} else {
    fail('bad login unexpected status=' . $r['status']);
}

// Admin login
$r = login_post($base, 'admin', 'TestAdmin9x');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'admin_dashboard')) {
    pass('admin login redirect');
} else {
    fail('admin login status=' . $r['status'] . ' loc=' . $loc);
}

// Admin pages
foreach (
    [
        'admin_dashboard' => ['Our database', 'Extracted Sites', 'Emails data'],
        'admin_prospects' => ['Our database', 'Markets'],
        'admin_extracted' => ['Extracted Sites'],
        'admin_emails_data' => ['Emails data', 'Sites with emails - Admin'],
        'admin_departments' => ['Departments', 'Site Finding'],
        'admin_orders' => ['Order'],
        'admin_invoices' => ['Invoice'],
        'admin_users' => ['Users'],
        'admin_account' => ['Admin account'],
        'account_password' => ['Change password'],
        'forgot_password' => ['Forgot password'],
    ] as $page => $needles
) {
    $r = req('GET', $base . '/index.php?page=' . $page);
    // Logged-in users are redirected away from forgot_password
    if ($page === 'forgot_password' && $r['status'] >= 300 && $r['status'] < 400) {
        pass("page $page (redirect when logged in)");
        continue;
    }
    $bad = [];
    foreach ($needles as $n) {
        if (!str_contains($r['body'], $n)) {
            $bad[] = $n;
        }
    }
    if ($r['status'] === 200 && !$bad && !str_contains($r['body'], 'Fatal error') && !str_contains($r['body'], 'Warning:')) {
        pass("page $page");
    } else {
        fail("page $page status={$r['status']} missing=" . implode(',', $bad) . ' fatal=' . (str_contains($r['body'], 'Fatal error') ? 'yes' : 'no'));
    }
}

// Mobile nav markup
$r = req('GET', $base . '/index.php?page=admin_dashboard');
if (str_contains($r['body'], 'data-nav-toggle') && str_contains($r['body'], 'id="app-sidebar"')) {
    pass('mobile nav markup');
} else {
    fail('mobile nav markup missing');
}

// Assets
foreach (['/asset.php?f=css/app.css', '/asset.php?f=js/nav-shell.js', '/asset.php?f=js/sites-with-emails.js', '/asset.php?f=js/csrf.js'] as $path) {
    $r = req('GET', $base . $path);
    if ($r['status'] === 200 && strlen($r['body']) > 50) {
        pass('asset ' . $path);
    } else {
        fail('asset ' . $path . ' status=' . $r['status']);
    }
}

// install.php locked (app lock and/or .htaccess deny when config.php exists)
$r = req('GET', $base . '/install.php');
if ($r['status'] === 403 || str_contains($r['body'], 'Install locked') || str_contains($r['body'], 'Forbidden')) {
    pass('install.php locked');
} else {
    fail('install.php not locked status=' . $r['status']);
}

// upgrade.php: php -S may still serve it; Apache .htaccess denies after install.
$r = req('GET', $base . '/upgrade.php');
if ($r['status'] === 403 || str_contains($r['body'], 'Forbidden')) {
    pass('upgrade.php denied after install');
} elseif ($r['status'] === 200 && (str_contains($r['body'], 'Run upgrade') || str_contains($r['body'], 'Upgrade'))) {
    pass('upgrade.php admin access (dev server)');
} else {
    fail('upgrade.php unexpected status=' . $r['status']);
}

// Logout + team login
req('GET', $base . '/index.php?page=logout');
$r = login_post($base, 'teammate', 'TestTeam8z');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && (str_contains($loc, 'team_dashboard') || str_contains($loc, 'team_departments'))) {
    pass('team login redirect');
} else {
    fail('team login status=' . $r['status'] . ' loc=' . $loc);
}

foreach (
    [
        'team_dashboard' => ['Filter', 'Extracting'],
        'team_prospect_check' => ['Filter'],
        'team_extracting' => ['Extracting'],
        'team_sites_emails' => ['Sites with emails'],
        'team_admin_emails_delete' => ['Admin emails search'],
        'team_email_campaigns' => ['Campaign search'],
        'team_email_campaigns_drafts' => ['Campaign drafts'],
    ] as $page => $needles
) {
    $r = req('GET', $base . '/index.php?page=' . $page);
    $bad = [];
    foreach ($needles as $n) {
        if (!str_contains($r['body'], $n)) {
            $bad[] = $n;
        }
    }
    if ($r['status'] === 200 && !$bad && !str_contains($r['body'], 'Fatal error')) {
        pass("page $page");
    } else {
        fail("page $page status={$r['status']} missing=" . implode(',', $bad));
    }
}

// Dept-scoped finder cannot open extracting
req('GET', $base . '/index.php?page=logout');
$r = login_post($base, 'finder', 'DeptTest9x');
$r = req('GET', $base . '/index.php?page=team_prospect_check');
if ($r['status'] === 200 && (str_contains($r['body'], 'Filter') || str_contains($r['body'], 'Paste'))) {
    pass('finder can open Filter & add');
} else {
    fail('finder blocked from Filter & add status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_extracting');
$loc = location($r);
if (($r['status'] >= 300 && str_contains($loc, 'team_departments')) || str_contains($r['body'], 'only shows')) {
    pass('finder blocked from Extracting');
} else {
    if ($r['status'] >= 300 && $r['status'] < 400) {
        pass('finder blocked from Extracting (redirect)');
    } else {
        fail('finder unexpectedly opened Extracting status=' . $r['status'] . ' loc=' . $loc);
    }
}

echo "\n==== HTTP SUMMARY ====\n";
echo 'passed: ' . count($ok) . "\n";
echo 'failed: ' . count($errors) . "\n";
foreach ($errors as $e) {
    echo " - $e\n";
}
exit($errors ? 1 : 0);
