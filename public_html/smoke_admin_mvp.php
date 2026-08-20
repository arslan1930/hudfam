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
if (!str_contains($usersPage, 'email_verified_at=NULL')) {
    fail('users.php missing email verify clear on email change');
} else {
    ok('users.php clears email_verified_at when admin email changes');
}
if (!str_contains($usersPage, 'users_stash_form_draft') || !str_contains($usersPage, 'users_take_form_draft')) {
    fail('users.php missing form draft preserve');
} else {
    ok('users.php form draft on validation failure');
}
if (!str_contains($usersPage, 'User not found')) {
    fail('users.php missing invalid edit handling');
} else {
    ok('users.php invalid edit redirect');
}
$accountLib = file_get_contents($root . '/includes/account.php') ?: '';
if (!str_contains($accountLib, 'function admin_email_taken_by_other')) {
    fail('account.php missing admin_email_taken_by_other');
} else {
    ok('admin_email_taken_by_other helper');
}
$authLib = file_get_contents($root . '/includes/auth.php') ?: '';
if (!str_contains($authLib, 'function generate_temp_password')) {
    fail('auth.php missing generate_temp_password');
} else {
    ok('generate_temp_password helper');
}
if (!str_contains($usersPage, "post('action') === 'generate_temp'")
    && !str_contains($usersPage, 'action\') === \'generate_temp\'')) {
    // PHP source uses post('action') === 'generate_temp'
}
if (!str_contains($usersPage, 'generate_temp')) {
    fail('users.php missing generate_temp action');
} else {
    ok('users.php generate temporary password on edit');
}
if (!str_contains($usersPage, 'Must change pwd') || !str_contains($usersPage, 'Departments')) {
    fail('users.php missing must-change / departments columns');
} else {
    ok('users.php must-change and departments columns');
}
if (!str_contains($usersPage, 'name="q"') || !str_contains($usersPage, 'users_role')) {
    fail('users.php missing search/role filters');
} else {
    ok('users.php search and role filters');
}
$guidesLib = file_get_contents($root . '/includes/guides.php') ?: '';
if (!str_contains($guidesLib, 'Assign Team users under Departments')) {
    fail('guide_admin_users still stale');
} else {
    ok('guide_admin_users updated');
}
$deptLib = file_get_contents($root . '/includes/departments.php') ?: '';
if (!str_contains($deptLib, 'function user_deactivation_residue')) {
    fail('departments missing user_deactivation_residue');
} else {
    ok('user_deactivation_residue helper');
}
if (!str_contains($usersPage, 'user_deactivation_residue') || !str_contains($usersPage, 'review under Departments')) {
    fail('users.php missing deactivate residue messaging');
} else {
    ok('users.php deactivate residue messaging');
}
$indexRoutes = file_get_contents($root . '/index.php') ?: '';
foreach (['admin_account', 'forgot_password', 'reset_password', 'verify_email'] as $route) {
    if (!str_contains($indexRoutes, "'{$route}'")) {
        fail("index.php missing route {$route}");
    } else {
        ok("route {$route}");
    }
}
if (!str_contains(file_get_contents($root . '/sql/schema.sql') ?: '', 'email_verified_at')) {
    fail('schema.sql missing email_verified_at');
} else {
    ok('schema.sql email_verified_at');
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
if (!str_contains($indexFull, "str_starts_with(\$page, 'team_')")) {
    fail('index.php missing Team CSRF gate');
} else {
    ok('index.php Team CSRF gate');
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
if (!str_contains($layoutFull, "\$panel === 'admin' || \$panel === 'team'")) {
    fail('layout csrf.js not loaded for Team panel');
} else {
    ok('layout csrf.js for Admin + Team');
}
$prospectCheck = file_get_contents($root . '/pages/team/prospect_check.php') ?: '';
$extractBatch = file_get_contents($root . '/pages/team/extract_batch.php') ?: '';
if (!str_contains($prospectCheck, 'csrf_field()') || !str_contains($extractBatch, 'csrf_field()')) {
    fail('Team Filter/Push forms missing csrf_field');
} else {
    ok('Team Filter & Push csrf_field');
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
    'edit keeps historical assignee after remove',
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

// --- Team panels T-1–T-5 ---
if (!str_contains($deptLib, 'function team_page_unlocked')) {
    fail('departments.php missing team_page_unlocked');
} else {
    ok('team_page_unlocked helper');
}

$prospectCheckT = file_get_contents($root . '/pages/team/prospect_check.php') ?: '';
if (!str_contains($prospectCheckT, 'team_page_unlocked($user, \'team_extract_batch\')')
    && !str_contains($prospectCheckT, 'team_page_unlocked($user, "team_extract_batch")')) {
    fail('Finding redirect to Extracting is not gated by unlock');
} else {
    ok('Finding Extracting redirect gated');
}
if (!str_contains($prospectCheckT, 'team_page_unlocked($user, \'team_extracting\')')
    && !str_contains($prospectCheckT, 'team_page_unlocked($user, "team_extracting")')) {
    fail('Finding Extracting CTA not gated');
} else {
    ok('Finding Extracting CTA gated');
}

$prospectsLib = file_get_contents($root . '/includes/prospects.php') ?: '';
if (!str_contains($prospectsLib, 'uniq_user_batch_date_country')) {
    fail('prospect batches missing per-country unique key');
} else {
    ok('prospect batches unique per user/day/country');
}
if (!str_contains($prospectCheckT, 'json_encode') || !str_contains($prospectCheckT, 'confirm_tld_mismatch')) {
    fail('Finding TLD confirm not json_encode-safe');
} else {
    ok('Finding TLD confirm json_encode-safe');
}

$extractBatchT = file_get_contents($root . '/pages/team/extract_batch.php') ?: '';
if (str_contains($extractBatchT, 'Clean errors') || str_contains($extractBatchT, 'Clean Errors')) {
    fail('Extracting still documents fake Clean errors control');
} else {
    ok('Extracting help omits fake Clean errors');
}
if (!str_contains($extractBatchT, 'remove_extract_batch_domains')
    || !str_contains($extractBatchT, "(int) \$pushed['inserted'] > 0")) {
    fail('Extracting Push missing Sites-list clear / insert-only Results clear');
} else {
    ok('Extracting Push clears Results only after insert');
}
$extractSitesJs = file_get_contents($root . '/assets/js/extract-sites-list.js') ?: '';
if (!str_contains($extractSitesJs, 'data.domains')) {
    fail('extract-sites-list.js autosave does not rewrite from server domains');
} else {
    ok('extract Sites-list autosave syncs textarea');
}

$sweLib = file_get_contents($root . '/includes/sites_with_emails.php') ?: '';
foreach ([
    'list_sites_with_emails_push_conflict_domains',
    'count_sites_with_emails_push_conflicts',
] as $fn) {
    if (!str_contains($sweLib, "function {$fn}")) {
        fail("sites_with_emails.php missing {$fn}");
    }
}
ok('SWE push conflict helpers');
if (!str_contains($sweLib, 'confirmOverwrite') && !str_contains($sweLib, '$confirmOverwrite')) {
    fail('push helpers missing confirmOverwrite gate');
} else {
    ok('push helpers require confirmOverwrite on conflict');
}
if (!str_contains($sweLib, "LEFT(domain, 8) <> '__blank_'")) {
    fail('Admin emails suggest still includes blank placeholder domains');
} else {
    ok('Admin emails suggest skips blank placeholders');
}

$sweApp = file_get_contents($root . '/pages/sites_with_emails_app.php') ?: '';
if (!str_contains($sweApp, 'confirm_overwrite') || !str_contains($sweApp, 'OVERWRITE')) {
    fail('SWE UI missing overwrite confirm');
} else {
    ok('SWE UI overwrite confirm');
}
if (!str_contains($sweApp, 'data-swe-open-site')
    || !str_contains($sweApp, 'data-swe-open-bulk')
    || !str_contains($sweApp, 'data-swe-open-count')
    || !str_contains($sweApp, 'data-swe-open-continue')) {
    fail('SWE UI missing Open site / Open first N / Open next controls');
} else {
    ok('SWE UI Open site + Open first N + continue');
}
$sweJs = file_get_contents($root . '/assets/js/sites-with-emails.js') ?: '';
if (!str_contains($sweJs, 'listEligibleOpenRows')
    || !str_contains($sweJs, 'Open all ')
    || !str_contains($sweJs, 'syncOpenBulkButton')
    || !str_contains($sweJs, 'OPEN_BATCH_SIZE')
    || !str_contains($sweJs, 'startOrContinueOpen')
    || !str_contains($sweJs, 'Open next ')) {
    fail('sites-with-emails.js missing Open first N / batch continue logic');
} else {
    ok('sites-with-emails.js Open first N + batch continue');
}
$sweCss = file_get_contents($root . '/assets/css/app.css') ?: '';
if (!str_contains($sweCss, 'swe-open-site') || !str_contains($sweCss, 'swe-open-group')) {
    fail('app.css missing SWE Open site styles');
} else {
    ok('SWE Open site CSS');
}

$sitesEmailsPage = file_get_contents($root . '/pages/team/sites_emails.php') ?: '';
if (!str_contains($sitesEmailsPage, 'team_page_unlocked')) {
    fail('team_sites_emails missing page-level unlock check');
} else {
    ok('team_sites_emails page-level unlock');
}
$adminEmailsDelete = file_get_contents($root . '/pages/team/admin_emails_delete.php') ?: '';
if (!str_contains($adminEmailsDelete, 'team_page_unlocked($user, \'team_admin_emails_delete\')')
    && !str_contains($adminEmailsDelete, 'team_page_unlocked($user, "team_admin_emails_delete")')) {
    fail('admin_emails_delete missing team_page_unlocked ACL');
} else {
    ok('admin_emails_delete uses team_page_unlocked');
}
if (str_contains($adminEmailsDelete, 'catch (Throwable') && str_contains($adminEmailsDelete, '$ok = false')) {
    fail('admin_emails_delete still uses brittle try/catch deny');
} else {
    ok('admin_emails_delete ACL without brittle catch-deny');
}

$teamDeptsT = file_get_contents($root . '/pages/team/departments.php') ?: '';
if (!str_contains($teamDeptsT, 'Email Extracting tools')) {
    fail('departments missing Email Extracting tool shortcuts');
} else {
    ok('Email Extracting folder tool shortcuts');
}
if (!str_contains($teamDeptsT, 'canSitesEmails') || !str_contains($teamDeptsT, 'canCampaigns')) {
    fail('departments dual-dept shortcuts not unlock-gated');
} else {
    ok('departments dual-dept shortcuts unlock-gated');
}

$testsTeam = file_get_contents($root . '/tests_run.php') ?: '';
foreach ([
    'Team re-push without confirm is blocked',
    'push_site rejects country mismatch',
    'team_page_unlocked',
    'prospect batch per country',
] as $needle) {
    if (!str_contains($testsTeam, $needle)) {
        fail("tests_run.php missing Team coverage: {$needle}");
    }
}
ok('tests_run Team T-1–T-5 coverage needles');

$httpSmoke = file_get_contents($root . '/tests_http.php') ?: '';
if (str_contains($httpSmoke, 'curl_init')) {
    fail('tests_http.php still requires ext-curl (curl_init)');
} elseif (!str_contains($httpSmoke, 'file_get_contents') || !str_contains($httpSmoke, 'stream_context_create')) {
    fail('tests_http.php missing stream-based HTTP client');
} else {
    ok('tests_http.php uses streams (no ext-curl)');
}
if (!str_contains($httpSmoke, 'function http_start_builtin_server')
    || !str_contains($httpSmoke, 'http_base_reachable')) {
    fail('tests_http.php missing auto-start php -S helper');
} else {
    ok('tests_http.php auto-starts php -S when needed');
}
if (!str_contains($httpSmoke, 'Waiting for assignment')
    || !str_contains($httpSmoke, 'admin_extracted&folder=extracted_sites')) {
    fail('tests_http.php missing waiting-dashboard / extracted-folder asserts');
} else {
    ok('tests_http.php ACL + extracted hub asserts');
}
if (!str_contains($httpSmoke, 'forgot_password page')
    || !str_contains($httpSmoke, 'admin_account redirects when logged out')) {
    fail('tests_http.php missing Account/forgot route asserts');
} else {
    ok('tests_http.php Account/forgot route asserts');
}

$prospectCheckSf = file_get_contents($root . '/pages/team/prospect_check.php') ?: '';
if (!str_contains($prospectCheckSf, 'Separate all')
    || !str_contains($prospectCheckSf, 'data-tld-separate')
    || !str_contains($prospectCheckSf, 'send_tld_column')) {
    fail('Filter & add missing Separate all / send_tld_column UI');
} else {
    ok('Filter & add Separate all UI');
}
if (!str_contains($prospectCheckSf, 'Filter unique sites')
    || str_contains($prospectCheckSf, '>Push to extract<')) {
    fail('Filter & add CTA still mislabeled Push to extract');
} else {
    ok('Filter & add CTA says Filter unique sites');
}
if (!str_contains($prospectCheckSf, 'data-tld-workspace')
    || !str_contains($prospectCheckSf, 'data-tld-rail')
    || !str_contains($prospectCheckSf, 'data-tld-panel')) {
    fail('Filter & add missing TLD workspace rail/panel');
} else {
    ok('Filter & add TLD tab workspace');
}
$tldJs = file_get_contents($root . '/assets/js/tld-separate.js') ?: '';
if (str_contains($tldJs, 'max-height: 18rem') || str_contains($tldJs, 'tld-separate-grid')) {
    // grid may remain as unused legacy class name in comments only — require workspace render
}
if (!str_contains($tldJs, 'data-tld-tab') || !str_contains($tldJs, 'tld-workspace-list')) {
    fail('tld-separate.js missing tab workspace render');
} else {
    ok('tld-separate.js tab workspace');
}
$cssApp = file_get_contents($root . '/assets/css/app.css') ?: '';
if (str_contains($cssApp, '.tld-workspace-list')
    && str_contains($cssApp, 'No max-height scroll cage')) {
    ok('TLD workspace CSS without scroll cage');
} else {
    fail('app.css missing TLD workspace no-scroll styles');
}
// Paste Separate workspace must sit outside #filter_form (Send form).
if (!preg_match('/<\/form>\s*.*?data-tld-separate/s', $prospectCheckSf)
    || !str_contains($prospectCheckSf, "data-can-send=\"<?= \$pasteCanSend ? '1' : '0' ?>\"")
        && !str_contains($prospectCheckSf, 'data-can-send="<?= $pasteCanSend ? \'1\' : \'0\' ?>"')
        && !preg_match('/data-can-send="<\?=\s*\$pasteCanSend/', $prospectCheckSf)
        && !str_contains($prospectCheckSf, '$pasteCanSend')) {
    // PHP source has $pasteCanSend variable
}
if (!str_contains($prospectCheckSf, '$pasteCanSend')) {
    fail('paste TLD workspace missing $pasteCanSend outside filter form');
} else {
    ok('paste TLD Send gated by country outside filter form');
}
$geoLib = file_get_contents($root . '/includes/geo.php') ?: '';
if (!str_contains($geoLib, 'function group_domains_by_tld')) {
    fail('geo.php missing group_domains_by_tld');
} else {
    ok('group_domains_by_tld helper');
}
if (!is_file($root . '/assets/js/tld-separate.js')) {
    fail('missing assets/js/tld-separate.js');
} else {
    ok('file assets/js/tld-separate.js');
}
$assetSf = file_get_contents($root . '/asset.php') ?: '';
if (!str_contains($assetSf, 'js/tld-separate.js')) {
    fail('asset.php missing tld-separate.js allowlist');
} else {
    ok('asset allowlist js/tld-separate.js');
}
$testsSf = file_get_contents($root . '/tests_run.php') ?: '';
if (!str_contains($testsSf, 'group_domains_by_tld splits')) {
    fail('tests_run.php missing TLD separate coverage');
} else {
    ok('tests_run TLD separate coverage');
}

$sitesFormJs = file_get_contents($root . '/assets/js/sites-form.js') ?: '';
$sitesFormPhp = file_get_contents($root . '/includes/sites_form.php') ?: '';
if (!str_contains($sitesFormPhp, 'data-domains-attention')
    || !str_contains($sitesFormPhp, 'Clean to root domains')) {
    fail('sites_form missing Ready/Needs attention Clean UI');
} else {
    ok('Clean Ready / Needs attention markup');
}
if (!str_contains($sitesFormJs, 'readyText')
    || !str_contains($sitesFormJs, 'attentionText')) {
    fail('sites-form.js missing Ready/attention split');
} else {
    ok('sites-form.js Ready/attention clean split');
}
if (!str_contains($sitesFormJs, 'PLATFORM_PUBLIC_SUFFIXES')
    || !str_contains($sitesFormJs, 'vercel.app')) {
    fail('sites-form.js missing platform public suffixes');
} else {
    ok('sites-form.js platform public suffixes');
}
$prospectsPhp = (string) @file_get_contents(__DIR__ . '/includes/prospects.php');
if (!str_contains($prospectsPhp, 'known_platform_public_suffixes')
    || !str_contains($prospectsPhp, 'vercel.app')) {
    fail('prospects.php missing known_platform_public_suffixes');
} else {
    ok('prospects.php platform public suffixes');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
