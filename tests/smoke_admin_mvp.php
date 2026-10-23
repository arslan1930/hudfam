<?php
/**
 * Lightweight smoke checks for Admin dashboard MVP (no DB required).
 * Run: php tests/smoke_admin_mvp.php
 */
declare(strict_types=1);

$root = dirname(__DIR__) . '/public_html';
$failures = 0;

function fail(string $msg): void
{
    global $failures;
    $failures++;
    echo "FAIL: {$msg}\n";
}

function ok(string $msg): void
{
    echo "OK: {$msg}\n";
}

$requiredFiles = [
    'includes/auth.php',
    'includes/security.php',
    'includes/layout.php',
    'includes/prospects.php',
    'includes/account.php',
    'includes/mail.php',
    'pages/account_password.php',
    'pages/forgot_password.php',
    'pages/reset_password.php',
    'pages/verify_email.php',
    'pages/admin/account.php',
    'pages/admin/dashboard.php',
    'pages/admin/prospect_batches.php',
    'pages/admin/prospect_batch.php',
    'pages/admin/invoice_manual.php',
    'pages/admin/users.php',
    'assets/js/password-toggle.js',
    'assets/js/prospect-batch-sheet.js',
    'assets/js/stay-scroll.js',
    'assets/js/csrf.js',
];
foreach ($requiredFiles as $rel) {
    if (!is_file($root . '/' . $rel)) {
        fail("missing {$rel}");
    } else {
        ok("file {$rel}");
    }
}

if (is_file($root . '/reset_admin_once.php')) {
    fail('reset_admin_once.php must not ship in public_html');
} else {
    ok('reset_admin_once.php removed from web root');
}
foreach (['smoke_admin_mvp.php', 'tests_run.php', 'tests_http.php'] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        fail("{$legacy} must not live under public_html");
    } else {
        ok("{$legacy} outside web root");
    }
}

$asset = file_get_contents($root . '/asset.php') ?: '';
foreach (['js/password-toggle.js', 'js/prospect-batch-sheet.js', 'js/stay-scroll.js', 'js/csrf.js'] as $key) {
    if (!str_contains($asset, $key)) {
        fail("asset.php missing allowlist {$key}");
    } else {
        ok("asset allowlist {$key}");
    }
}

$layout = file_get_contents($root . '/includes/layout.php') ?: '';
if (str_contains($layout, 'asset_url(\'assets/css/app.css\')')) {
    fail('layout still loads CSS twice via asset_url');
} else {
    ok('single CSS via stylesheet_url');
}
if (!str_contains($layout, 'Site adding history')) {
    fail('layout missing Site adding history label');
} else {
    ok('nav rename Site adding history');
}
if (preg_match("/'team_prospects'\\s*=>/", $layout)) {
    fail('Team nav still exposes Our database');
} else {
    ok('Team Our database privatized in nav');
}
if (!str_contains($layout, 'password-toggle.js')) {
    fail('layout missing password-toggle.js');
} else {
    ok('layout loads password-toggle.js');
}
if (!str_contains($layout, 'csrf.js') || !str_contains($layout, 'csrf-token')) {
    fail('layout missing CSRF meta/script');
} else {
    ok('layout CSRF meta/script');
}
if (!str_contains($layout, 'noindex')) {
    fail('layout missing noindex robots meta');
} else {
    ok('layout noindex');
}

$index = file_get_contents($root . '/index.php') ?: '';
if (!str_contains($index, "'account_password'")) {
    fail('index.php missing account_password route');
} else {
    ok('account_password route');
}
foreach (['forgot_password', 'reset_password', 'verify_email', 'admin_account'] as $route) {
    if (!str_contains($index, "'" . $route . "'")) {
        fail("index.php missing {$route} route");
    } else {
        ok("{$route} route");
    }
}
if (!str_contains($index, 'user_must_change_password')) {
    fail('index.php missing password force gate');
} else {
    ok('password force gate');
}
if (!str_contains($index, 'require_csrf') || !str_contains($index, 'start_app_session')) {
    fail('index.php missing CSRF/session hardening');
} else {
    ok('index.php CSRF + secure session');
}
if (!str_contains($index, 'includes/account.php') || !str_contains($index, 'includes/mail.php')) {
    fail('index.php missing account/mail includes');
} else {
    ok('account + mail includes');
}

$auth = file_get_contents($root . '/includes/auth.php') ?: '';
foreach (['ensure_users_auth_schema', 'change_user_password', 'user_must_change_password', 'known_weak_passwords'] as $fn) {
    if (!str_contains($auth, "function {$fn}")) {
        fail("auth missing {$fn}");
    } else {
        ok("auth {$fn}");
    }
}

$security = file_get_contents($root . '/includes/security.php') ?: '';
foreach (['start_app_session', 'require_csrf', 'login_throttle_check', 'send_security_headers'] as $fn) {
    if (!str_contains($security, "function {$fn}")) {
        fail("security missing {$fn}");
    } else {
        ok("security {$fn}");
    }
}

$usersPage = file_get_contents($root . '/pages/admin/users.php') ?: '';
if (!str_contains($usersPage, 'at least 8 characters')) {
    fail('users.php missing min password length');
} else {
    ok('users.php min password length');
}
if (!str_contains($usersPage, 'revealed_temp_password')) {
    fail('users.php still flashes temp password only');
} else {
    ok('users.php one-time temp password reveal');
}
if (!str_contains($usersPage, 'Cannot remove the last active admin')) {
    fail('users.php missing last-admin guard');
} else {
    ok('users.php last-admin guard');
}
if (!str_contains($usersPage, 'cannot deactivate your own account')) {
    fail('users.php missing self-deactivate guard');
} else {
    ok('users.php self-deactivate guard');
}

$invoiceManual = file_get_contents($root . '/pages/admin/invoice_manual.php') ?: '';
if (!str_contains($invoiceManual, "post('action') === 'create_blank'")) {
    fail('invoice_manual must create only on POST create_blank');
} else {
    ok('invoice_manual POST create_blank');
}
if (str_contains($invoiceManual, 'create_blank_invoice') && str_contains($invoiceManual, 'REQUEST_METHOD')
    && str_contains($invoiceManual, 'POST')) {
    ok('invoice_manual does not create on bare GET');
} else {
    fail('invoice_manual still creates on GET or missing POST gate');
}

$invoices = file_get_contents($root . '/includes/invoices.php') ?: '';
if (!str_contains($invoices, "app_config()") || !str_contains($invoices, 'invoice_company_')) {
    fail('invoice_company_defaults must read from config');
} else {
    ok('invoice defaults from config');
}
$sample = file_get_contents($root . '/config.sample.php') ?: '';
if (!str_contains($sample, 'invoice_company_iban') || !str_contains($sample, 'invoice_company_name')) {
    fail('config.sample.php missing invoice company keys');
} else {
    ok('config.sample invoice company keys');
}

$batches = file_get_contents($root . '/pages/admin/prospect_batches.php') ?: '';
if (!str_contains($batches, "get('user')")) {
    fail('admin batches missing ?user= filter');
} else {
    ok('admin batches teammate filter');
}
if (str_contains($batches, "clear_admin_new_data('our_database'")) {
    fail('history list should not clear Our database New badge');
} else {
    ok('history list leaves Our database New badge');
}

$batch = file_get_contents($root . '/pages/admin/prospect_batch.php') ?: '';
if (str_contains($batch, "clear_admin_new_data('our_database'")) {
    fail('history detail should not clear Our database New badge');
} else {
    ok('history detail leaves Our database New badge');
}

$prospects = file_get_contents($root . '/includes/prospects.php') ?: '';
$phaseBReady = str_contains($prospects, 'function set_prospect_batch_domains_from_text')
    && str_contains($prospects, 'function delete_prospect_batch')
    && str_contains($prospects, 'function update_prospect_batch_meta')
    && str_contains($batch, 'prospect-batch-sheet.js')
    && str_contains($batch, 'autosave_sites');
if ($phaseBReady) {
    ok('editable history sheet wired');
} else {
    echo "SKIP: editable history sheet helpers (optional)\n";
}

$teamProspects = file_get_contents($root . '/pages/team/prospects.php') ?: '';
if (!str_contains($teamProspects, 'Admin-only')) {
    fail('team prospects not privatized');
} else {
    ok('team prospects privatized');
}

$teamDelete = file_get_contents($root . '/pages/team/admin_emails_delete.php') ?: '';
if (str_contains($teamDelete, 'delete_sites_with_emails_admin_row')
    || str_contains($teamDelete, "action === 'delete_row'")) {
    fail('team Admin emails search still allows archive delete');
} else {
    ok('team Admin emails search is search-only');
}

$htaccess = file_get_contents($root . '/.htaccess') ?: '';
if (!str_contains($htaccess, 'install|upgrade') || !str_contains($htaccess, 'config.php')) {
    fail('.htaccess missing install/upgrade deny rules');
} else {
    ok('.htaccess denies install/upgrade when configured');
}
if (!str_contains($htaccess, 'tests_run') || !str_contains($htaccess, 'smoke_admin_mvp')) {
    fail('.htaccess missing deny for leftover test scripts');
} else {
    ok('.htaccess denies leftover test scripts');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
