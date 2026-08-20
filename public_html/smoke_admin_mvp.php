<?php
/**
 * Lightweight smoke checks for Admin dashboard MVP (no DB required).
 * Run: php public_html/smoke_admin_mvp.php
 */
declare(strict_types=1);

$root = __DIR__;
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
    'includes/layout.php',
    'includes/prospects.php',
    'pages/account_password.php',
    'pages/admin/dashboard.php',
    'pages/admin/prospect_batches.php',
    'pages/admin/prospect_batch.php',
    'assets/js/password-toggle.js',
    'assets/js/prospect-batch-sheet.js',
];
foreach ($requiredFiles as $rel) {
    if (!is_file($root . '/' . $rel)) {
        fail("missing {$rel}");
    } else {
        ok("file {$rel}");
    }
}

$asset = file_get_contents($root . '/asset.php') ?: '';
foreach (['js/password-toggle.js', 'js/prospect-batch-sheet.js'] as $key) {
    if (!str_contains($asset, $key)) {
        fail("asset.php missing allowlist {$key}");
    } else {
        ok("asset allowlist {$key}");
    }
}

$index = file_get_contents($root . '/index.php') ?: '';
if (!str_contains($index, "'account_password'")) {
    fail('index.php missing account_password route');
} else {
    ok('account_password route');
}
if (!str_contains($index, 'user_must_change_password')) {
    fail('index.php missing password force gate');
} else {
    ok('password force gate');
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

$auth = file_get_contents($root . '/includes/auth.php') ?: '';
foreach (['ensure_users_auth_schema', 'change_user_password', 'user_must_change_password', 'known_weak_passwords'] as $fn) {
    if (!str_contains($auth, "function {$fn}")) {
        fail("auth missing {$fn}");
    } else {
        ok("auth {$fn}");
    }
}

$prospects = file_get_contents($root . '/includes/prospects.php') ?: '';
foreach (['set_prospect_batch_domains_from_text', 'delete_prospect_batch', 'update_prospect_batch_meta'] as $fn) {
    if (!str_contains($prospects, "function {$fn}")) {
        fail("prospects missing {$fn}");
    } else {
        ok("prospects {$fn}");
    }
}

$batches = file_get_contents($root . '/pages/admin/prospect_batches.php') ?: '';
if (!str_contains($batches, "get('user')")) {
    fail('admin batches missing ?user= filter');
} else {
    ok('admin batches teammate filter');
}

$batch = file_get_contents($root . '/pages/admin/prospect_batch.php') ?: '';
if (!str_contains($batch, 'prospect-batch-sheet.js')) {
    fail('admin batch missing sheet JS');
} else {
    ok('admin batch sheet JS');
}
if (!str_contains($batch, 'autosave_sites')) {
    fail('admin batch missing autosave action');
} else {
    ok('admin batch autosave');
}

$teamProspects = file_get_contents($root . '/pages/team/prospects.php') ?: '';
if (!str_contains($teamProspects, 'Admin-only')) {
    fail('team prospects not privatized');
} else {
    ok('team prospects privatized');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
