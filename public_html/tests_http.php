<?php
/**
 * HTTP smoke test against php -S 127.0.0.1:8080
 * php tests_http.php
 *
 * Uses PHP streams (allow_url_fopen) — no ext-curl required.
 * If the local server is not running, starts `php -S` for this process and stops it on exit.
 *
 * Expects seeded users from tests_run.php (admin/teammate + finder/extractor/emailer).
 * Unassigned teammate sees the waiting dashboard; dept users unlock their tools.
 *
 * Web hits (Apache / LiteSpeed / php -S) must not run this file.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}
$base = rtrim(getenv('TXF_BASE') ?: 'http://127.0.0.1:8080', '/');
$cookie = sys_get_temp_dir() . '/txf_http_cookie.txt';
@unlink($cookie);

$errors = [];
$ok = [];
function pass(string $m): void { global $ok; $ok[] = $m; echo "OK  $m\n"; }
function fail(string $m): void { global $errors; $errors[] = $m; echo "FAIL $m\n"; }

/**
 * True when $base answers over HTTP (any status counts as “server up”).
 */
function http_base_reachable(string $base): bool
{
    $url = $base . '/index.php?page=login';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);
    $http_response_header = [];
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false || $http_response_header !== [];
}

/**
 * Start php -S for local TXF_BASE only. Returns proc resource or null if not started.
 *
 * @return resource|null
 */
function http_start_builtin_server(string $base)
{
    $parts = parse_url($base);
    $host = (string) ($parts['host'] ?? '');
    $port = (int) ($parts['port'] ?? 0);
    $scheme = (string) ($parts['scheme'] ?? 'http');
    if ($scheme !== 'http' || !in_array($host, ['127.0.0.1', 'localhost'], true)) {
        return null;
    }
    if ($port < 1) {
        $port = 8080;
    }
    $docroot = __DIR__;
    $log = sys_get_temp_dir() . '/txf_http_server_' . $port . '.log';
    $cmd = [
        PHP_BINARY,
        '-S',
        $host . ':' . $port,
        '-t',
        $docroot,
    ];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $log, 'w'],
        2 => ['file', $log, 'a'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, $docroot);
    if (!is_resource($proc)) {
        return null;
    }
    // Wait until the listener accepts connections (max ~5s).
    for ($i = 0; $i < 25; $i++) {
        usleep(200000);
        if (http_base_reachable($base)) {
            return $proc;
        }
        $st = proc_get_status($proc);
        if (empty($st['running'])) {
            break;
        }
    }
    @proc_terminate($proc);
    @proc_close($proc);
    return null;
}

function http_stop_builtin_server($proc): void
{
    if (!is_resource($proc)) {
        return;
    }
    $st = @proc_get_status($proc);
    $pid = (int) ($st['pid'] ?? 0);
    @proc_terminate($proc, 15);
    $deadline = microtime(true) + 2;
    while (microtime(true) < $deadline) {
        $st = @proc_get_status($proc);
        if (empty($st['running'])) {
            break;
        }
        usleep(50000);
    }
    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, 9);
    }
    @proc_close($proc);
}

$httpServerProc = null;
$httpServerOwned = false;
if (!http_base_reachable($base)) {
    echo "HTTP server not reachable at {$base} — starting php -S…\n";
    $httpServerProc = http_start_builtin_server($base);
    if ($httpServerProc) {
        $httpServerOwned = true;
        pass('auto-started php -S for HTTP smoke');
    } else {
        fail('could not start php -S for ' . $base . ' (set TXF_BASE or start the server manually)');
        echo "\n==== HTTP SUMMARY ====\n";
        echo 'passed: ' . count($ok) . "\n";
        echo 'failed: ' . count($errors) . "\n";
        foreach ($errors as $e) {
            echo " - $e\n";
        }
        exit(1);
    }
} else {
    pass('HTTP server already reachable');
}

if ($httpServerOwned && is_resource($httpServerProc)) {
    register_shutdown_function(static function () use (&$httpServerProc): void {
        http_stop_builtin_server($httpServerProc);
        $httpServerProc = null;
    });
}

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

function login_csrf_token(string $base): string
{
    $r = req('GET', $base . '/index.php?page=login');
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $r['body'], $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

function login_post(string $base, string $username, string $password): array
{
    return req('POST', $base . '/index.php?page=login', [
        'body' => http_build_query([
            'username' => $username,
            'password' => $password,
            '_csrf' => login_csrf_token($base),
        ]),
    ]);
}

// Login page
$r = req('GET', $base . '/index.php?page=login');
if ($r['status'] === 200 && str_contains($r['body'], 'name="username"') && str_contains($r['body'], 'name="_csrf"')
    && str_contains($r['body'], 'teqnowebs.com') && str_contains($r['body'], 'Sign in')
    && !str_contains($r['body'], 'class="app-bar"')) {
    pass('login page');
} else {
    fail('login page status=' . $r['status'] . ' err=' . $r['error']);
}

// Public auth routes (Account stack — must not 404)
$r = req('GET', $base . '/index.php?page=forgot_password');
if ($r['status'] === 200 && str_contains($r['body'], 'Forgot password')
    && str_contains($r['body'], 'teqnowebs.com') && !str_contains($r['body'], 'class="app-bar"')) {
    pass('forgot_password page');
} else {
    fail('forgot_password status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=reset_password');
if ($r['status'] === 200 && (str_contains($r['body'], 'Reset') || str_contains($r['body'], 'password'))
    && str_contains($r['body'], 'teqnowebs.com')) {
    pass('reset_password page');
} else {
    fail('reset_password status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=verify_email');
if ($r['status'] === 200 && str_contains($r['body'], 'Verify')
    && str_contains($r['body'], 'teqnowebs.com')) {
    pass('verify_email page');
} else {
    fail('verify_email status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=admin_account');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && (str_contains($loc, 'login') || str_contains($loc, 'page=login'))) {
    pass('admin_account redirects when logged out');
} else {
    fail('admin_account unexpected status=' . $r['status'] . ' loc=' . $loc);
}

// Bad login
$r = login_post($base, 'admin', 'wrong');
if ($r['status'] === 200 && (str_contains($r['body'], 'Invalid') || str_contains($r['body'], 'invalid'))) {
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

// Admin pages (extracted hub redirects into folder=extracted_sites)
foreach (
    [
        'admin_dashboard' => ['Our database', 'Extracted Sites', 'Emails data', 'Users', 'Unpaid LIVE', 'Emails Admin', 'Campaign sheets', 'app-footer', 'admin_orders&amp;folder=processing'],
        'admin_prospects' => ['Our database', 'Markets'],
        'admin_extracted&folder=extracted_sites' => ['Extracted Sites'],
        'admin_emails_data' => ['Emails data', 'Working list from Team Push', 'folder-open'],
        'admin_departments' => ['Departments', 'Site Finding', 'folder-open'],
        'admin_orders' => ['Order', 'leftover', 'added here'],
        'admin_invoices' => ['Invoice', 'Mark paid', 'invoice-list-chips'],
        'admin_users' => ['Users', 'Awaiting assignment', 'Must change password', 'users-office'],
        'account_password' => ['Change password', 'breadcrumbs'],
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

$r = req('GET', $base . '/index.php?page=admin_invoices');
$invViewId = 0;
if (preg_match('/admin_invoice_view(?:&amp;|&)id=(\d+)/', $r['body'] ?? '', $invM)) {
    $invViewId = (int) $invM[1];
}
if ($invViewId > 0) {
    $rView = req('GET', $base . '/index.php?page=admin_invoice_view&id=' . $invViewId);
    $rPrint = req('GET', $base . '/index.php?page=admin_invoice_view&id=' . $invViewId . '&print=1');
    if ($rView['status'] === 200
        && str_contains($rView['body'], 'invoice-doc-logohead')
        && (str_contains($rView['body'], 'Mark paid') || str_contains($rView['body'], 'Paid'))
        && !str_contains($rView['body'], 'Fatal error')) {
        pass('admin invoice open bill');
    } else {
        fail('admin invoice open bill status=' . ($rView['status'] ?? '?'));
    }
    if ($rPrint['status'] === 200
        && str_contains($rPrint['body'], 'invoice-print-toolbar')
        && str_contains($rPrint['body'], 'does not print automatically')
        && str_contains($rPrint['body'], 'invoice-doc-logohead')
        && !str_contains($rPrint['body'], 'onload="window.print()"')
        && !str_contains($rPrint['body'], 'Fatal error')) {
        pass('admin invoice print preview');
    } else {
        fail('admin invoice print preview status=' . ($rPrint['status'] ?? '?'));
    }
} else {
    fail('admin invoices list has no Open bill link');
}

$r = req('GET', $base . '/index.php?page=admin_users&awaiting=1');
if ($r['status'] === 200
    && str_contains($r['body'], 'Awaiting assignment')
    && str_contains($r['body'], 'name="awaiting"')
    && (str_contains($r['body'], 'No team users awaiting assignment.') || str_contains($r['body'], '<table'))
    && !str_contains($r['body'], 'Fatal error')) {
    pass('admin_users awaiting=1');
} else {
    fail('admin_users awaiting=1 status=' . ($r['status'] ?? '?'));
}

$r = req('GET', $base . '/index.php?page=admin_emails_data&folder=email_campaigns');
if ($r['status'] === 200
    && str_contains($r['body'], 'Email campaign')
    && !str_contains($r['body'], 'Fatal error')
    && !str_contains($r['body'], 'Warning:')) {
    pass('admin emails campaign folder');
} else {
    fail('admin emails campaign folder status=' . $r['status']);
}
if (preg_match('/project=(\d+)/', $r['body'], $projM)) {
    $rProj = req('GET', $base . '/index.php?page=admin_emails_data&folder=email_campaigns&project=' . (int) $projM[1]);
    if (preg_match('/sheet=(\d+)/', $rProj['body'], $sheetM)) {
        $rSheet = req('GET', $base . '/index.php?page=admin_emails_data&folder=email_campaigns&sheet=' . (int) $sheetM[1]);
        if ($rSheet['status'] === 200
            && str_contains($rSheet['body'], 'Fill gaps')
            && str_contains($rSheet['body'], 'id="camp-fill-gaps"')
            && str_contains($rSheet['body'], 'value="fill_gaps"')
            && str_contains($rSheet['body'], 'Import')
            && !str_contains($rSheet['body'], 'Fatal error')) {
            pass('admin campaign country sheet Fill gaps');
        } else {
            fail('admin campaign sheet Fill gaps status=' . $rSheet['status']);
        }
    } else {
        pass('admin campaign project has no country sheet yet (Fill gaps UI skipped)');
    }
} else {
    pass('admin campaign folder has no project yet (Fill gaps UI skipped)');
}

$r = req('GET', $base . '/index.php?page=admin_emails_data&folder=all_sites_with_emails');
if ($r['status'] === 200
    && str_contains($r['body'], 'swe-open-country')
    && str_contains($r['body'], 'Open a country to paste or import')
    && !str_contains($r['body'], 'Fatal error')) {
    pass('admin Final folder country opener');
} else {
    fail('admin Final folder opener status=' . ($r['status'] ?? '?'));
}
$r = req('GET', $base . '/index.php?page=admin_emails_data&folder=all_sites_with_emails&country=Germany');
if ($r['status'] === 200
    && str_contains($r['body'], 'id="swe-bulk-add"')
    && str_contains($r['body'], 'name="paste_text"')
    && str_contains($r['body'], 'name="import_file"')
    && str_contains($r['body'], 'value="paste"')
    && str_contains($r['body'], 'value="import_file"')
    && !str_contains($r['body'], 'Fatal error')) {
    pass('admin Final Germany sheet paste/import');
} else {
    fail('admin Final Germany paste/import status=' . ($r['status'] ?? '?'));
}
$r = req('GET', $base . '/index.php?page=admin_emails_data&folder=sites_with_emails&country=Germany');
if ($r['status'] === 200
    && !str_contains($r['body'], 'id="swe-bulk-add"')
    && !str_contains($r['body'], 'Fatal error')) {
    pass('admin working list has no Final bulk import');
} else {
    fail('admin working list bulk import leak status=' . ($r['status'] ?? '?'));
}

$r = req('GET', $base . '/index.php?page=admin_site_prices');
$loc = location($r);
$openedCountry = $r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'country=');
$emptyHub = $r['status'] === 200
    && str_contains($r['body'], 'Website prices')
    && str_contains($r['body'], 'Open a country')
    && !str_contains($r['body'], 'Fatal error')
    && !str_contains($r['body'], 'Warning:');
if ($openedCountry || $emptyHub) {
    pass('admin_site_prices opens busiest country or hub');
} else {
    fail('admin_site_prices status=' . $r['status'] . ' loc=' . $loc);
}
$r = req('GET', $base . '/index.php?page=admin_site_prices&hub=1');
if ($r['status'] === 200
    && str_contains($r['body'], 'Website prices')
    && str_contains($r['body'], 'Open a country')
    && str_contains($r['body'], 'Search all countries')
    && str_contains($r['body'], 'data-site-price-jump')
    && str_contains($r['body'], 'data-site-price-jump-results')
    && !str_contains($r['body'], 'Fatal error')
    && !str_contains($r['body'], 'Warning:')) {
    pass('admin_site_prices hub=1');
} else {
    fail('admin_site_prices hub=1 status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=admin_site_prices&country=Germany');
$sheetNeedles = [
    'data-site-price-sheet',
    'data-site-price-jump',
    'Copy selected (this page)',
    '>Email</th>',
    'data-site-price-copy-selected',
    'Search this country',
    'Search all countries',
    'Ctrl/Cmd+Enter',
    'id="status-words"',
    'site-price-email',
    'site-price-color-summary',
    'highlights the whole row',
];
$sheetBad = [];
foreach ($sheetNeedles as $n) {
    if (!str_contains($r['body'], $n)) {
        $sheetBad[] = $n;
    }
}
if ($r['status'] === 200 && !$sheetBad
    && !str_contains($r['body'], 'Fatal error')
    && !str_contains($r['body'], 'Warning:')
    && !str_contains($r['body'], 'Copy all')
    && !str_contains($r['body'], 'Copy selected live URLs')
    && !str_contains($r['body'], 'download=txt')) {
    pass('admin_site_prices Germany sheet');
} else {
    fail('admin_site_prices Germany status=' . $r['status'] . ' missing=' . implode(',', $sheetBad));
}

$r = req('GET', $base . '/index.php?page=admin_orders&folder=processing');
$omCopyNeedles = ['Copy selected sites (this page)', 'Copy selected live URLs (this page)', 'Copy all live URLs', 'Download .txt', 'data-copy-check', 'Mark completed', '+ Add order', 'order-client-list', '<span>Copy</span>', '<span>Complete</span>', 'Left tick', 'With live URL', 'Need a country on every ticked row before completing', 'om-origin-tabs', 'Leftover', 'Added here'];
$omCopyBad = [];
foreach ($omCopyNeedles as $n) {
    if (!str_contains($r['body'], $n)) {
        $omCopyBad[] = $n;
    }
}
if ($r['status'] === 200 && !$omCopyBad && !str_contains($r['body'], 'Fatal error')) {
    pass('admin orders processing copy/download');
} else {
    fail('admin orders processing copy status=' . $r['status'] . ' missing=' . implode(',', $omCopyBad));
}
$r = req('GET', $base . '/index.php?page=admin_orders&folder=completed');
if ($r['status'] === 200
    && str_contains($r['body'], 'Copy selected live URLs (this page)')
    && str_contains($r['body'], 'Copy all live URLs')
    && str_contains($r['body'], 'Copy selected sites (this page)')
    && str_contains($r['body'], 'Push to invoice')
    && str_contains($r['body'], 'Download .txt')
    && str_contains($r['body'], '<span>Bill</span>')
    && str_contains($r['body'], 'Mark paid')
    && str_contains($r['body'], 'data-orig-live')
    && str_contains($r['body'], 'Clearing the live URL also clears Paid')
    && (str_contains($r['body'], 'Push unpaid') || str_contains($r['body'], 'Generate invoice') || str_contains($r['body'], 'none ticked') || str_contains($r['body'], 'Already on invoice'))) {
    pass('admin orders completed copy/download');
} else {
    fail('admin orders completed copy status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=admin_orders&folder=processing&copy=live_urls');
$copyJson = json_decode($r['body'], true);
if ($r['status'] === 200
    && str_contains($r['headers'], 'application/json')
    && is_array($copyJson)
    && !empty($copyJson['ok'])
    && isset($copyJson['urls'])
    && is_array($copyJson['urls'])) {
    pass('admin orders copy=live_urls JSON');
} else {
    fail('admin orders copy=live_urls status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=admin_orders&folder=processing&download=txt');
if ($r['status'] === 200
    && str_contains($r['headers'], 'text/plain')
    && str_contains($r['headers'], 'order-live-urls-')) {
    pass('admin orders download=txt');
} else {
    fail('admin orders download=txt status=' . $r['status']);
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
if (str_contains($r['body'], 'data-nav-toggle') && str_contains($r['body'], 'id="app-sidebar"')
    && str_contains($r['body'], 'mobile-page-title') && str_contains($r['body'], 'class="app-bar"')
    && str_contains($r['body'], 'class="app-footer')) {
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

foreach (['tests_run.php', 'tests_http.php', 'smoke_admin_mvp.php', 'reset_admin_once.php'] as $blocked) {
    $r = req('GET', $base . '/' . $blocked);
    $resetQs = $blocked === 'reset_admin_once.php'
        ? req('GET', $base . '/reset_admin_once.php?confirm=RESET')
        : null;
    if (
        $r['status'] === 404
        && str_contains($r['body'], 'Not found.')
        && ($resetQs === null || ($resetQs['status'] === 404 && str_contains($resetQs['body'], 'Not found.')))
    ) {
        pass($blocked . ' web 404');
    } else {
        fail($blocked . ' web not blocked status=' . $r['status']);
    }
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
$r = login_post($base, 'teammate', 'TestTeam8z');
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
$r = req('GET', $base . '/index.php?page=admin_orders&folder=processing&copy=live_urls');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'team_dashboard')) {
    pass('teammate blocked from OM copy live URLs');
} else {
    fail('teammate OM copy status=' . $r['status'] . ' loc=' . $loc);
}
$r = req('GET', $base . '/index.php?page=admin_orders&folder=processing&download=txt');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'team_dashboard')) {
    pass('teammate blocked from OM live URL txt');
} else {
    fail('teammate OM txt status=' . $r['status'] . ' loc=' . $loc);
}
$r = req('GET', $base . '/index.php?page=admin_emails_data&folder=email_campaigns');
$loc = location($r);
if ($r['status'] >= 300 && $r['status'] < 400
    && (str_contains($loc, 'login') || str_contains($loc, 'team_dashboard') || str_contains($loc, 'team_departments'))) {
    pass('teammate blocked from Admin emails campaign');
} else {
    fail('teammate opened Admin emails campaign status=' . $r['status'] . ' loc=' . $loc);
}

// Dept-scoped finder can open Filter; cannot open Extracting
req('GET', $base . '/index.php?page=logout');
$r = login_post($base, 'finder', 'DeptTest9x');
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
$r = req('GET', $base . '/index.php?page=team_site_prices&country=Germany');
$loc = location($r);
if (($r['status'] >= 300 && $r['status'] < 400 && str_contains($loc, 'team_departments'))
    || str_contains($r['body'], 'only shows work')) {
    pass('finder blocked from Website prices');
} else {
    fail('finder Website prices status=' . $r['status'] . ' loc=' . $loc);
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
$r = login_post($base, 'extractor', 'DeptTest9x');
$r = req('GET', $base . '/index.php?page=team_extracting');
if ($r['status'] === 200 && str_contains($r['body'], 'Extracting')) {
    pass('extractor can open Extracting');
} else {
    fail('extractor blocked from Extracting status=' . $r['status']);
}

// Email Extracting folder shows tool shortcuts
req('GET', $base . '/index.php?page=logout');
$r = login_post($base, 'emailer', 'DeptTest9x');
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
$r = req('GET', $base . '/index.php?page=team_sites_emails&country=Germany');
if ($r['status'] === 200
    && !str_contains($r['body'], 'id="swe-bulk-add"')
    && !str_contains($r['body'], 'name="import_file"')
    && !str_contains($r['body'], 'Fatal error')) {
    pass('Team Sites with emails has no Campaign-style bulk import');
} else {
    fail('Team SWE bulk import leak status=' . ($r['status'] ?? '?'));
}
$r = req('GET', $base . '/index.php?page=team_admin_emails_search');
if ($r['status'] === 200
    && str_contains($r['body'], 'Admin emails search')
    && str_contains($r['body'], 'Delete both')) {
    pass('emailer can open Admin emails search');
} else {
    fail('emailer blocked from Admin emails search status=' . $r['status']);
}
$r = req('GET', $base . '/index.php?page=team_admin_emails_delete');
if ($r['status'] === 200
    && str_contains($r['body'], 'Admin emails search')
    && str_contains($r['body'], 'Delete both')) {
    pass('emailer can open Admin emails search via delete alias');
} else {
    fail('emailer blocked from Admin emails search alias status=' . $r['status']);
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
$r = login_post($base, 'comms', 'DeptTest9x');
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
$r = req('GET', $base . '/index.php?page=team_site_prices&country=Germany');
$teamNeedles = ['data-site-price-sheet', 'data-site-price-copy-one', '>Email</th>'];
$teamBad = [];
foreach ($teamNeedles as $n) {
    if (!str_contains($r['body'], $n)) {
        $teamBad[] = $n;
    }
}
if ($r['status'] === 200 && !$teamBad
    && !str_contains($r['body'], 'Unlock')
    && !str_contains($r['body'], 'Copy all')
    && !str_contains($r['body'], 'Copy selected')
    && !str_contains($r['body'], 'data-site-price-copy-selected')
    && !str_contains($r['body'], 'data-site-price-jump')
    && !str_contains($r['body'], 'data-site-price-remove')
    && !str_contains($r['body'], 'data-site-price-assign')
    && !str_contains($r['body'], 'Copy selected live URLs')
    && !str_contains($r['body'], 'download=txt')
    && !str_contains($r['body'], 'Fatal error')
    && !str_contains($r['body'], 'Warning:')) {
    pass('comms can open Website prices');
} else {
    fail('comms Website prices status=' . $r['status'] . ' missing=' . implode(',', $teamBad));
}
$r = req('GET', $base . '/index.php?page=team_departments&folder=communication');
if ($r['status'] === 200
    && str_contains($r['body'], 'Assign a task')
    && str_contains($r['body'], 'save_task')
    && str_contains($r['body'], 'name="assigned_to"')
    && str_contains($r['body'], 'team_site_prices')) {
    pass('comms can assign department tasks');
} else {
    fail('comms department assign missing status=' . $r['status']);
}

echo "\n==== HTTP SUMMARY ====\n";
echo 'passed: ' . count($ok) . "\n";
echo 'failed: ' . count($errors) . "\n";
foreach ($errors as $e) {
    echo " - $e\n";
}
exit($errors ? 1 : 0);
