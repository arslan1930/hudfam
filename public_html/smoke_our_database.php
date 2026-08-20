<?php
/**
 * Smoke checks for Our database inventory (no DB required for most asserts).
 * Run: php public_html/smoke_our_database.php
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

$prospects = file_get_contents($root . '/includes/prospects.php') ?: '';
foreach ([
    'resolve_canonical_country',
    'delete_prospect_site_by_id',
    'remove_prospect_sites_by_list',
    'search_prospect_sites_global',
    'list_prospect_domains_for_export',
    'update_prospect_site_meta',
] as $fn) {
    if (!str_contains($prospects, "function {$fn}")) {
        fail("prospects missing {$fn}");
    } else {
        ok("prospects {$fn}");
    }
}

if (!str_contains($prospects, 'OR p.url LIKE ?')) {
    fail('inventory search missing url');
} else {
    ok('inventory search includes url');
}

$page = file_get_contents($root . '/pages/admin/prospects.php') ?: '';
foreach ([
    "get('created_by')",
    'resolve_canonical_country',
    'guide_inventory',
    'export=txt',
    'copy_domains_btn',
    'data-export-url',
    'remove_site',
    'remove_list',
    'super_q',
    'save_site_meta',
    'Non-empty only',
    'Add sites',
    'js_string',
] as $needle) {
    if (!str_contains($page, $needle)) {
        fail("admin prospects missing {$needle}");
    } else {
        ok("prospects page has {$needle}");
    }
}

if (str_contains($page, "confirm('Remove")) {
    fail('unsafe confirm() string interpolation still present');
} else {
    ok('confirm uses js_string');
}

if (str_contains($page, '>Add URLs<')) {
    fail('admin prospects still has Add URLs label');
} else {
    ok('no Add URLs CTA');
}

$add = file_get_contents($root . '/pages/admin/prospect_add.php') ?: '';
if (!str_contains($add, 'invalid skipped') && !str_contains($add, 'Invalid skipped')) {
    fail('add sites missing invalid feedback');
} else {
    ok('add sites invalid feedback');
}

$glossary = file_get_contents($root . '/includes/helpers.php') ?: '';
if (str_contains($glossary, '<dt>Add history</dt>')) {
    fail('glossary still says Add history');
} else {
    ok('glossary Site adding history');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
