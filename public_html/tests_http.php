<?php
/**
 * HTTP smoke test against php -S 127.0.0.1:8080
 * php tests_http.php
 *
 * Uses PHP streams (allow_url_fopen) — no ext-curl required.
 *
 * Expects seeded users from tests_run.php (admin/teammate + finder/extractor/emailer).
 * Unassigned teammate sees the waiting dashboard; dept users unlock their tools.
 */
$base = getenv('TXF_BASE') ?: 'http://127.0.0.1:8080';
$cookie = sys_get_temp_dir() . '/txf_http_cookie.txt';
@unlink($cookie);

$errors = [];
$ok = [];
function pass(string $m): void { global $ok; $ok[] = $m; echo "OK  $m\n"; }
function fail(string $m): void { global $errors; $errors[] = $m; echo "FAIL $m\n"; }

/**
 * Load cookies for $url from a simple jar file (one Set-Cookie line per entry).
 *
 * @return list<string> Cookie header pairs name=value
 */
function http_cookie_header_for(string $url, string $jarPath): array
{
    if (!is_file($jarPath)) {
        return [];
    }
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    if ($path === '') {
        $path = '/';
    }
    $pairs = [];
    $raw = (string) file_get_contents($jarPath);
    foreach (preg_split('/\R+/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        // domain \t flag \t path \t secure \t expires \t name \t value
        $parts = explode("\t", $line);
        if (count($parts) < 7) {
            continue;
        }
        [$cDomain, , $cPath, , $expires, $name, $value] = $parts;
        $expires = (int) $expires;
        if ($expires > 0 && $expires < time()) {
            continue;
        }
        $cDomain = strtolower(ltrim($cDomain, '.'));
        $hostL = strtolower($host);
        if ($cDomain !== '' && $hostL !== $cDomain && !str_ends_with($hostL, '.' . $cDomain)) {
            continue;
        }
        if ($cPath !== '' && $cPath !== '/' && !str_starts_with($path, $cPath)) {
            continue;
        }
        if ($name !== '') {
            $pairs[$name] = $name . '=' . $value;
        }
    }
    return array_values($pairs);
}

/**
 * Persist Set-Cookie headers into a Netscape-ish jar (tab-separated).
 */
function http_store_set_cookies(string $url, string $setCookieHeaders, string $jarPath): void
{
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    $defaultPath = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    if ($defaultPath === '' || !str_starts_with($defaultPath, '/')) {
        $defaultPath = '/';
    }
    // Keep directory path only
    if (!str_ends_with($defaultPath, '/')) {
        $defaultPath = (string) preg_replace('#/[^/]*$#', '/', $defaultPath);
    }
    if ($defaultPath === '') {
        $defaultPath = '/';
    }

    $existing = [];
    if (is_file($jarPath)) {
        foreach (preg_split('/\R+/', (string) file_get_contents($jarPath)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 7) {
                $existing[$parts[5] . "\t" . $parts[2] . "\t" . $parts[0]] = $line;
            }
        }
    }

    if (preg_match_all('/^Set-Cookie:\s*([^\r\n]+)/mi', $setCookieHeaders, $matches)) {
        foreach ($matches[1] as $cookieLine) {
            $segments = array_map('trim', explode(';', $cookieLine));
            if ($segments === [] || !str_contains($segments[0], '=')) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', array_shift($segments), 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            $cDomain = $host;
            $cPath = $defaultPath;
            $expires = 0;
            $secure = 'FALSE';
            foreach ($segments as $attr) {
                if (!str_contains($attr, '=')) {
                    if (strcasecmp($attr, 'secure') === 0) {
                        $secure = 'TRUE';
                    }
                    continue;
                }
                [$ak, $av] = array_pad(explode('=', $attr, 2), 2, '');
                $ak = strtolower(trim($ak));
                $av = trim($av);
                if ($ak === 'domain' && $av !== '') {
                    $cDomain = ltrim($av, '.');
                } elseif ($ak === 'path' && $av !== '') {
                    $cPath = $av;
                } elseif ($ak === 'expires' && $av !== '') {
                    $ts = strtotime($av);
                    $expires = $ts !== false ? $ts : 0;
                } elseif ($ak === 'max-age' && is_numeric($av)) {
                    $expires = time() + (int) $av;
                }
            }
            $key = $name . "\t" . $cPath . "\t" . $cDomain;
            if ($value === '' && $expires > 0 && $expires < time()) {
                unset($existing[$key]);
                continue;
            }
            $existing[$key] = implode("\t", [
                $cDomain,
                'TRUE',
                $cPath,
                $secure,
                (string) $expires,
                $name,
                $value,
            ]);
        }
    }

    $out = "# Netscape HTTP Cookie File\n";
    foreach ($existing as $line) {
        $out .= $line . "\n";
    }
    file_put_contents($jarPath, $out);
}

/**
 * HTTP request via streams (no ext-curl). Does not follow redirects.
 *
 * @param array{headers?:list<string>,body?:string} $opts
 * @return array{status:int,headers:string,body:string,error:string}
 */
function req(string $method, string $url, array $opts = []): array
{
    global $cookie;
    $method = strtoupper($method);
    $headers = $opts['headers'] ?? [];
    $body = $opts['body'] ?? null;

    $cookiePairs = http_cookie_header_for($url, $cookie);
    if ($cookiePairs !== []) {
        $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
    }
    if ($body !== null && $method === 'POST') {
        $hasContentType = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $headers[] = 'Content-Length: ' . (string) strlen((string) $body);
    }

    $headerBlock = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerBlock,
            'content' => $body !== null ? (string) $body : '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 30,
        ],
    ]);

    $http_response_header = [];
    $rawBody = @file_get_contents($url, false, $context);
    $respHeaders = $http_response_header;
    if ($rawBody === false && $respHeaders === []) {
        return [
            'status' => 0,
            'headers' => '',
            'body' => '',
            'error' => 'request failed (is php -S running on ' . $url . '?)',
        ];
    }
    if ($rawBody === false) {
        $rawBody = '';
    }

    $status = 0;
    $headerText = '';
    if ($respHeaders) {
        $headerText = implode("\r\n", $respHeaders) . "\r\n";
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $respHeaders[0], $m)) {
            $status = (int) $m[1];
        }
        http_store_set_cookies($url, $headerText, $cookie);
    }

    return [
        'status' => $status,
        'headers' => $headerText,
        'body' => $rawBody,
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

// Login page
$r = req('GET', $base . '/index.php?page=login');
if ($r['status'] === 200 && str_contains($r['body'], 'name="username"')) {
    pass('login page');
} else {
    fail('login page status=' . $r['status'] . ' err=' . $r['error']);
}

// Bad login
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'admin', 'password' => 'wrong']),
]);
if ($r['status'] === 200 && (str_contains($r['body'], 'Invalid') || str_contains($r['body'], 'invalid'))) {
    pass('bad login stays on form');
} else {
    fail('bad login unexpected status=' . $r['status']);
}

// Admin login
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'admin', 'password' => 'TestAdmin9x']),
]);
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'admin_dashboard')) {
    pass('admin login redirect');
} else {
    fail('admin login status=' . $r['status'] . ' loc=' . $loc);
}

// Admin pages (extracted hub redirects into folder=extracted_sites)
foreach (
    [
        'admin_dashboard' => ['Our database', 'Extracted Sites', 'Emails data'],
        'admin_prospects' => ['Our database', 'Markets'],
        'admin_extracted&folder=extracted_sites' => ['Extracted Sites'],
        'admin_emails_data' => ['Emails data', 'Sites with emails - Admin'],
        'admin_departments' => ['Departments', 'Site Finding'],
        'admin_orders' => ['Order'],
        'admin_invoices' => ['Invoice'],
        'admin_users' => ['Users'],
        'account_password' => ['Change password'],
    ] as $page => $needles
) {
    $r = req('GET', $base . '/index.php?page=' . $page);
    $bad = [];
    foreach ($needles as $n) {
        if (!str_contains($r['body'], $n)) {
            $bad[] = $n;
        }
    }
    $label = strtok($page, '&') ?: $page;
    if ($r['status'] === 200 && !$bad && !str_contains($r['body'], 'Fatal error') && !str_contains($r['body'], 'Warning:')) {
        pass("page $label");
    } else {
        fail("page $label status={$r['status']} missing=" . implode(',', $bad) . ' fatal=' . (str_contains($r['body'], 'Fatal error') ? 'yes' : 'no'));
    }
}

// Bare admin_extracted should hop into the country-list folder
$r = req('GET', $base . '/index.php?page=admin_extracted');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'folder=extracted_sites')) {
    pass('admin_extracted hub redirects to folder');
} else {
    fail('admin_extracted hub redirect status=' . $r['status'] . ' loc=' . $loc);
}

// Mobile nav markup
$r = req('GET', $base . '/index.php?page=admin_dashboard');
if (str_contains($r['body'], 'data-nav-toggle') && str_contains($r['body'], 'id="app-sidebar"')) {
    pass('mobile nav markup');
} else {
    fail('mobile nav markup missing');
}

// Assets
foreach ([
    '/asset.php?f=css/app.css',
    '/asset.php?f=js/nav-shell.js',
    '/asset.php?f=js/sites-with-emails.js',
    '/asset.php?f=js/csrf.js',
] as $path) {
    $r = req('GET', $base . $path);
    if ($r['status'] === 200 && strlen($r['body']) > 50) {
        pass('asset ' . $path);
    } else {
        fail('asset ' . $path . ' status=' . $r['status']);
    }
}

// install.php locked
$r = req('GET', $base . '/install.php');
if ($r['status'] === 403 || str_contains($r['body'], 'Install locked')) {
    pass('install.php locked');
} else {
    fail('install.php not locked status=' . $r['status']);
}

// upgrade.php requires admin (we are logged in as admin — should show form or complete UI, not 403 login wall)
$r = req('GET', $base . '/upgrade.php');
if ($r['status'] === 200 && (str_contains($r['body'], 'Run upgrade') || str_contains($r['body'], 'Upgrade complete') || str_contains($r['body'], 'Upgrade'))) {
    pass('upgrade.php admin access');
} else {
    fail('upgrade.php unexpected status=' . $r['status']);
}

// Logout + unassigned teammate (waiting dashboard — tools locked until Admin assigns)
req('GET', $base . '/index.php?page=logout');
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'teammate', 'password' => 'TestTeam8z']),
]);
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && (str_contains($loc, 'team_dashboard') || str_contains($loc, 'team_departments'))) {
    pass('team login redirect');
} else {
    fail('team login status=' . $r['status'] . ' loc=' . $loc);
}

$r = req('GET', $base . '/index.php?page=team_dashboard');
if ($r['status'] === 200
    && (str_contains($r['body'], 'Waiting for assignment') || str_contains($r['body'], 'No department assigned'))
    && (str_contains($r['body'], 'csrf.js') || str_contains($r['body'], 'js/csrf.js'))) {
    pass('unassigned teammate waiting dashboard + csrf.js');
} else {
    fail('unassigned teammate dashboard unexpected status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_prospect_check');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400
    && (str_contains($loc, 'team_dashboard') || str_contains($loc, 'team_departments'))) {
    pass('unassigned teammate blocked from Filter & add');
} else {
    fail('unassigned teammate opened Filter status=' . $r['status'] . ' loc=' . $loc);
}

// Dept-scoped finder can open Filter; cannot open Extracting
req('GET', $base . '/index.php?page=logout');
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'finder', 'password' => 'DeptTest9x']),
]);
$r = req('GET', $base . '/index.php?page=team_prospect_check');
if ($r['status'] === 200
    && (str_contains($r['body'], 'Filter') || str_contains($r['body'], 'Paste'))
    && str_contains($r['body'], 'csrf-token')) {
    pass('finder can open Filter & add');
} else {
    fail('finder blocked from Filter & add status=' . $r['status']);
}
if (!preg_match('/href="[^"]*team_extracting[^"]*"/', $r['body'])
    && !preg_match('/href="[^"]*team_extract_batch[^"]*"/', $r['body'])) {
    pass('finder Filter page hides Extracting CTA');
} else {
    fail('finder Filter still links into Extracting');
}
$r = req('GET', $base . '/index.php?page=team_extracting');
$loc = location($r);
if (($r['status'] >= 300 && str_contains($loc, 'team_departments')) || str_contains($r['body'], 'only shows')) {
    pass('finder blocked from Extracting');
} elseif ($r['status'] >= 300 && $r['status'] < 400) {
    pass('finder blocked from Extracting (redirect)');
} else {
    fail('finder unexpectedly opened Extracting status=' . $r['status'] . ' loc=' . $loc);
}

// Extractor can open Extracting
req('GET', $base . '/index.php?page=logout');
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'extractor', 'password' => 'DeptTest9x']),
]);
$r = req('GET', $base . '/index.php?page=team_extracting');
if ($r['status'] === 200 && str_contains($r['body'], 'Extracting')) {
    pass('extractor can open Extracting');
} else {
    fail('extractor blocked from Extracting status=' . $r['status']);
}

// Email Extracting folder shows tool shortcuts
req('GET', $base . '/index.php?page=logout');
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'emailer', 'password' => 'DeptTest9x']),
]);
$r = req('GET', $base . '/index.php?page=team_departments&folder=email_extracting');
if ($r['status'] === 200
    && str_contains($r['body'], 'Email Extracting tools')
    && str_contains($r['body'], 'team_sites_emails')) {
    pass('emailer sees Email Extracting tool shortcuts');
} else {
    fail('emailer Email Extracting shortcuts missing status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_sites_emails');
if ($r['status'] === 200 && str_contains($r['body'], 'Sites with emails')) {
    pass('emailer can open Sites with emails');
} else {
    fail('emailer blocked from Sites with emails status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_admin_emails_delete');
if ($r['status'] === 200
    && str_contains($r['body'], 'Admin emails search')
    && str_contains($r['body'], 'Delete both')) {
    pass('emailer can open Admin emails search');
} else {
    fail('emailer blocked from Admin emails search status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_extracting');
$loc = location($r);
if ($r['status'] >= 300 || str_contains($r['body'], 'only shows') || str_contains($loc, 'team_departments')) {
    pass('emailer blocked from Extracting');
} else {
    fail('emailer unexpectedly opened Extracting status=' . $r['status']);
}

// Communication tools
req('GET', $base . '/index.php?page=logout');
$r = req('POST', $base . '/index.php?page=login', [
    'body' => http_build_query(['username' => 'comms', 'password' => 'DeptTest9x']),
]);
$r = req('GET', $base . '/index.php?page=team_email_campaigns');
if ($r['status'] === 200 && str_contains($r['body'], 'Campaign search')) {
    pass('comms can open Campaign search');
} else {
    fail('comms blocked from Campaign search status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_email_campaigns_drafts');
if ($r['status'] === 200 && str_contains($r['body'], 'Campaign drafts')) {
    pass('comms can open Campaign drafts');
} else {
    fail('comms blocked from Campaign drafts status=' . $r['status']);
}

echo "\n==== HTTP SUMMARY ====\n";
echo 'passed: ' . count($ok) . "\n";
echo 'failed: ' . count($errors) . "\n";
foreach ($errors as $e) {
    echo " - $e\n";
}
exit($errors ? 1 : 0);
