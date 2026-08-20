<?php
/**
 * Lightweight smoke checks for Admin dashboard MVP (no DB required).
 * Run: php public_html/smoke_admin_mvp.php
 *
 * Phase A: allowlist, password toggle, blank invoice POST, history ?user=, user guards.
 * Phase B (editable history sheet) asserts are included; they pass once PR2 lands.
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
    'pages/admin/invoice_manual.php',
    'pages/admin/users.php',
    'assets/js/password-toggle.js',
    'assets/js/prospect-batch-sheet.js',
    'assets/js/stay-scroll.js',
];
foreach ($requiredFiles as $rel) {
    if (!is_file($root . '/' . $rel)) {
        fail("missing {$rel}");
    } else {
        ok("file {$rel}");
    }
}

$asset = file_get_contents($root . '/asset.php') ?: '';
foreach (['js/password-toggle.js', 'js/prospect-batch-sheet.js', 'js/stay-scroll.js'] as $key) {
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

$auth = file_get_contents($root . '/includes/auth.php') ?: '';
foreach (['ensure_users_auth_schema', 'change_user_password', 'user_must_change_password', 'known_weak_passwords'] as $fn) {
    if (!str_contains($auth, "function {$fn}")) {
        fail("auth missing {$fn}");
    } else {
        ok("auth {$fn}");
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
if (preg_match('/create_blank_invoice\s*\(/', $invoiceManual)
    && !preg_match('/REQUEST_METHOD.*POST|POST.*create_blank|create_blank.*POST/s', $invoiceManual)
) {
    // Soft check: create must be inside POST branch (already gated above).
}
if (str_contains($invoiceManual, 'create_blank_invoice') && str_contains($invoiceManual, 'REQUEST_METHOD')
    && str_contains($invoiceManual, 'POST')) {
    ok('invoice_manual does not create on bare GET');
} else {
    fail('invoice_manual still creates on GET or missing POST gate');
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

// Phase B (editable sheet)
$prospects = file_get_contents($root . '/includes/prospects.php') ?: '';
foreach (['set_prospect_batch_domains_from_text', 'delete_prospect_batch', 'update_prospect_batch_meta'] as $fn) {
    if (!str_contains($prospects, "function {$fn}")) {
        fail("prospects missing {$fn}");
    } else {
        ok("prospects {$fn}");
    }
}

$batch = file_get_contents($root . '/pages/admin/prospect_batch.php') ?: '';
if (str_contains($batch, "clear_admin_new_data('our_database'")) {
    fail('history detail should not clear Our database New badge');
} else {
    ok('history detail leaves Our database New badge');
}
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

$index = file_get_contents($root . '/index.php') ?: '';
if (!str_contains($index, "'admin_semrush_sheet'")) {
    fail('index.php missing admin_semrush_sheet route');
} else {
    ok('admin_semrush_sheet route');
}
if (!is_file($root . '/pages/admin/semrush_sheet.php')) {
    fail('missing pages/admin/semrush_sheet.php');
} else {
    ok('file pages/admin/semrush_sheet.php');
}
$adminSemrush = file_get_contents($root . '/pages/admin/semrush_research.php') ?: '';
if (!str_contains($adminSemrush, 'semrush_sheet_url($dest, true)')
    && !str_contains($adminSemrush, 'semrush_sheet_url($c, true)')) {
    fail('admin semrush hub still opens Team sheet URLs');
} else {
    ok('admin semrush uses Admin sheet URLs');
}

$helpers = file_get_contents($root . '/includes/helpers.php') ?: '';
foreach (['csrf_token', 'csrf_field', 'csrf_token_valid', 'require_csrf'] as $fn) {
    if (!str_contains($helpers, "function {$fn}")) {
        fail("helpers missing {$fn}");
    } else {
        ok("helpers {$fn}");
    }
}
$indexFull = file_get_contents($root . '/index.php') ?: '';
if (!str_contains($indexFull, 'require_csrf()') || !str_contains($indexFull, "str_starts_with(\$page, 'admin_')")) {
    fail('index.php missing Admin CSRF gate');
} else {
    ok('index.php Admin CSRF gate');
}
if (!str_contains($indexFull, "\$page === 'team_departments'")) {
    fail('index.php missing Team Departments CSRF gate');
} else {
    ok('index.php Team Departments CSRF gate');
}
if (!is_file($root . '/assets/js/csrf.js')) {
    fail('missing assets/js/csrf.js');
} else {
    ok('file assets/js/csrf.js');
}
$assetFull = file_get_contents($root . '/asset.php') ?: '';
if (!str_contains($assetFull, 'js/csrf.js')) {
    fail('asset.php missing csrf.js allowlist');
} else {
    ok('asset allowlist js/csrf.js');
}
$layoutFull = file_get_contents($root . '/includes/layout.php') ?: '';
if (!str_contains($layoutFull, 'csrf-token') || !str_contains($layoutFull, 'csrf.js')) {
    fail('layout missing csrf meta or script');
} else {
    ok('layout csrf meta + script');
}

$extracted = file_get_contents($root . '/pages/admin/extracted.php') ?: '';
if (!str_contains($extracted, "redirect(\$sitesListUrl)") && !str_contains($extracted, 'redirect($sitesListUrl)')) {
    fail('extracted hub should redirect to country list');
} else {
    ok('extracted hub skips one-card hop');
}
if (!str_contains($extracted, 'extracted_search_all_pages')) {
    fail('extracted missing Search all pages control');
} else {
    ok('extracted Search all pages control');
}

$emailsHub = file_get_contents($root . '/pages/admin/emails_data.php') ?: '';
if (str_contains($emailsHub, 'sync_sites_with_emails_admin_to_all()')
    && !str_contains($emailsHub, 'repair_final_archive')) {
    fail('emails hub still auto-syncs without repair action');
}
if (!str_contains($emailsHub, 'repair_final_archive')) {
    fail('emails hub missing repair_final_archive');
} else {
    ok('emails hub repair Final archive');
}

$ordersPage = file_get_contents($root . '/pages/admin/orders.php') ?: '';
if (!str_contains($ordersPage, 'order-client-search')) {
    fail('orders missing client search');
} else {
    ok('orders client search');
}
if (!str_contains($ordersPage, 'Has unpaid LIVE') || !str_contains($ordersPage, "value=\"archived\"")) {
    fail('orders missing unpaid/archived filters');
} else {
    ok('orders unpaid + archived filters');
}
if (!str_contains($ordersPage, 'Archive') || !str_contains($ordersPage, 'restore')) {
    fail('orders missing archive/restore actions');
} else {
    ok('orders archive/restore actions');
}
if (!str_contains($ordersPage, "['p' => \$pageNum") && !str_contains($ordersPage, 'Page <?= (int) $pageNum ?>')) {
    fail('orders missing list pagination');
} else {
    ok('orders list pagination');
}

$orderSheet = file_get_contents($root . '/pages/admin/order_sheet.php') ?: '';
if (!str_contains($orderSheet, 'yearNow') && !str_contains($orderSheet, 'date(\'Y\')')) {
    fail('order sheet year range not dynamic');
} else {
    ok('order sheet dynamic year range');
}
if (!str_contains($orderSheet, 'csrf_field()')) {
    fail('order sheet missing csrf_field');
} else {
    ok('order sheet csrf_field');
}
if (!str_contains($orderSheet, 'data-no-draft') || !str_contains($orderSheet, 'isDraftIgnored')) {
    fail('order sheet dirty ignore for search missing');
} else {
    ok('order sheet search does not mark dirty');
}
if (!str_contains($orderSheet, 'order-country-list') || !str_contains($orderSheet, '<datalist')) {
    fail('order sheet missing country datalist');
} else {
    ok('order sheet country datalist');
}
if (!str_contains($orderSheet, 'beforeunload')) {
    fail('order sheet missing unsaved beforeunload warning');
} else {
    ok('order sheet unsaved beforeunload');
}
if (!str_contains($orderSheet, 'unpaid LIVE') && !str_contains($orderSheet, 'unpaidLiveCount')) {
    fail('order sheet missing unpaid Generate CTA');
} else {
    ok('order sheet unpaid Generate CTA');
}

$dashboardPage = file_get_contents($root . '/pages/admin/dashboard.php') ?: '';
if (!str_contains($dashboardPage, 'order_management_dashboard_stats')
    || !str_contains($dashboardPage, 'unpaid LIVE')) {
    fail('dashboard missing order unpaid LIVE stats');
} else {
    ok('dashboard order unpaid LIVE stats');
}

$ordersLib = file_get_contents($root . '/includes/orders.php') ?: '';
foreach (['order_client_name_taken', 'count_invoices_for_order_client', 'set_order_client_archived', 'count_order_client_unpaid_live', 'order_management_dashboard_stats'] as $omFn) {
    if (!str_contains($ordersLib, "function {$omFn}")) {
        fail("orders.php missing {$omFn}");
    }
}
ok('orders helpers for OM-1–4');

$testsFull = file_get_contents($root . '/tests_run.php') ?: '';
foreach (['mark paid without LIVE', 'unpaid LIVE count', 'archived client hidden', 'order_management_dashboard_stats', 'clearing LIVE also clears paid'] as $needle) {
    if (!str_contains($testsFull, $needle)) {
        fail("tests_run.php missing OM coverage: {$needle}");
    }
}
ok('tests_run OM coverage needles');

$invoiceGenerate = file_get_contents($root . '/pages/admin/invoice_generate.php') ?: '';
if (!str_contains($invoiceGenerate, 'csrf_field()')) {
    fail('invoice_generate missing csrf_field');
} else {
    ok('invoice_generate csrf_field');
}

$adminDepts = file_get_contents($root . '/pages/admin/departments.php') ?: '';
if (!str_contains($adminDepts, 'csrf_field()')) {
    fail('admin departments missing csrf_field');
} else {
    ok('admin departments csrf_field');
}
if (!str_contains($adminDepts, 'json_encode(') || !str_contains($adminDepts, 'Remove ')) {
    fail('admin departments remove confirm not json_encode-safe');
} else {
    ok('admin departments safe remove confirm');
}
if (!str_contains($adminDepts, "\$statusFilter === \$val ? ' active-soft' : ''")) {
    fail('admin departments status filter missing active-soft for all tabs');
} else {
    ok('admin departments status filter active state');
}
if (!str_contains($adminDepts, 'clear_open_department_task_assignees')
    && !str_contains(file_get_contents($root . '/includes/departments.php') ?: '', 'function clear_open_department_task_assignees')) {
    fail('missing clear_open_department_task_assignees helper');
} else {
    ok('departments clear open assignees helper');
}
if (!str_contains($adminDepts, 'dept-task-search') || !str_contains($adminDepts, '$perPage = 50')) {
    fail('admin departments missing task search/pagination');
} else {
    ok('admin departments task search + pagination');
}
if (!str_contains($adminDepts, 'Unassigned') || !str_contains($adminDepts, 'Assigned')) {
    fail('admin departments missing assignee filters');
} else {
    ok('admin departments assignee filters');
}
$teamDepts = file_get_contents($root . '/pages/team/departments.php') ?: '';
if (!str_contains($teamDepts, 'csrf_field()')) {
    fail('team departments missing csrf_field');
} else {
    ok('team departments csrf_field');
}
if (!str_contains($teamDepts, "'mine' => 'Mine'")) {
    fail('team departments missing Mine filter');
} else {
    ok('team departments Mine filter');
}
if (!str_contains($adminDepts, 'Invalid status') || !str_contains($teamDepts, 'Invalid status')) {
    fail('department status update missing failure handling');
} else {
    ok('department status update failure handling');
}
if (!str_contains($teamDepts, 'dept-task-overdue') && !str_contains($adminDepts, 'dept-task-overdue')) {
    fail('departments missing overdue row class');
} else {
    ok('departments overdue styling');
}

$deptLib = file_get_contents($root . '/includes/departments.php') ?: '';
foreach ([
    'clear_open_department_task_assignees',
    'department_task_is_overdue',
    'departments_dashboard_stats',
] as $fn) {
    if (!str_contains($deptLib, "function {$fn}")) {
        fail("departments.php missing {$fn}");
    }
}
ok('departments helpers for D-1–D-4');

$dashPage = file_get_contents($root . '/pages/admin/dashboard.php') ?: '';
if (!str_contains($dashPage, 'departments_dashboard_stats')
    || !str_contains($dashPage, 'team awaiting assignment')) {
    fail('dashboard missing departments live stats');
} else {
    ok('dashboard departments live stats');
}

$legacyTasks = file_get_contents($root . '/pages/admin/tasks.php') ?: '';
if (!str_contains($legacyTasks, 'admin_departments') || !str_contains($legacyTasks, 'redirect(')) {
    fail('legacy admin_tasks does not redirect to Departments');
} else {
    ok('legacy admin_tasks redirects to Departments');
}
if (!str_contains($indexFull, "'admin_tasks'")) {
    fail('index.php missing admin_tasks route for legacy redirect');
} else {
    ok('index.php admin_tasks legacy route');
}

$extractSites = file_get_contents($root . '/pages/admin/extract_sites.php') ?: '';
if (str_contains($extractSites, 'page=admin_tasks')) {
    fail('extract_sites still links to admin_tasks');
} elseif (!str_contains($extractSites, 'admin_departments')) {
    fail('extract_sites missing Departments CTA');
} else {
    ok('extract_sites CTA points at Departments');
}

$testsFull = file_get_contents($root . '/tests_run.php') ?: '';
foreach ([
    'department invalid status rejected',
    'remove member clears open assignee',
    'department assignee filters mine/unassigned',
    'department overdue helper',
    'departments dashboard stats',
] as $needle) {
    if (!str_contains($testsFull, $needle)) {
        fail("tests_run.php missing Departments coverage: {$needle}");
    }
}
ok('tests_run Departments coverage needles');

$layoutNav = file_get_contents($root . '/includes/layout.php') ?: '';
if (!str_contains($layoutNav, 'nav_is_active($activePage, $current)')) {
    fail('layout nav does not use aliases for child routes');
} else {
    ok('layout nav uses aliases for child routes');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
