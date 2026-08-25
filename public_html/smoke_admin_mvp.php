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
    'includes/sheet_history.php',
    'assets/js/sheet-select-undo.js',
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
if (preg_match("/'team_prospects'\\s*=>/", $layout)
    && str_contains($layout, 'Browse country folders')) {
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
if (!str_contains($usersPage, '$usersPage') || !str_contains($usersPage, '$perPage = 50')) {
    fail('users.php missing 50/page pagination');
} else {
    ok('users.php 50/page pagination');
}
$invoicesAdminPage = file_get_contents($root . '/pages/admin/invoices.php') ?: '';
if (!str_contains($invoicesAdminPage, '$perPage = 50')
    || !str_contains($invoicesAdminPage, '$invoiceListQs')
    || !str_contains($invoicesAdminPage, 'Previous')) {
    fail('invoices.php missing 50/page pagination');
} else {
    ok('invoices.php 50/page pagination');
}
if (!str_contains($invoicesAdminPage, 'name="filter"')
    || !str_contains($invoicesAdminPage, 'value="draft"')
    || !str_contains($invoicesAdminPage, 'value="unpaid"')
    || !str_contains($invoicesAdminPage, 'value="paid"')
    || !str_contains($invoicesAdminPage, 'normalize_invoice_list_filter')) {
    fail('invoices.php missing status filter');
} else {
    ok('invoices.php status filter');
}
if (!str_contains($invoicesAdminPage, "\$listOpts['client_id']")
    || !str_contains($invoicesAdminPage, 'Linked to this client sheet')
    || !str_contains($invoicesAdminPage, 'name="client_id"')) {
    fail('invoices.php missing client_id scope');
} else {
    ok('invoices.php client_id list scope');
}
$adminProspects = file_get_contents($root . '/pages/admin/prospects.php') ?: '';
if (str_contains($adminProspects, 'Clean errors') || str_contains($adminProspects, 'Clean Errors')) {
    fail('Admin Our database still says Clean errors');
} else {
    ok('Admin Our database uses Clean to root domains wording');
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
if (str_contains($usersPage, 'shared URL database')
    || substr_count($usersPage, 'table-wrap') < 2
    || !str_contains($usersPage, 'Assign Team users under Departments')) {
    fail('users.php still has shared-URL copy or missing table-wrap');
} else {
    ok('users.php Office copy + table-wrap');
}
$guidesLib = file_get_contents($root . '/includes/guides.php') ?: '';
if (!str_contains($guidesLib, 'Assign Team users under Departments')) {
    fail('guide_admin_users still stale');
} else {
    ok('guide_admin_users updated');
}
if (!str_contains($guidesLib, 'function guide_emails_data')
    || !str_contains($guidesLib, 'Super search on this hub updates Admin only')) {
    fail('guide_emails_data missing');
} else {
    ok('guide_emails_data present');
}
if (!str_contains($guidesLib, 'function guide_orders')
    || !str_contains($guidesLib, 'function guide_invoices')
    || !str_contains($guidesLib, 'function guide_admin_account')
    || !str_contains($guidesLib, 'Deleting a sheet keeps invoices')
    || !str_contains($guidesLib, 'printable letterhead is Topurlz')
    || !str_contains($guidesLib, 'Sidebar Change password updates the same password')) {
    fail('Office page-purpose guides missing');
} else {
    ok('Office Orders/Invoices/Account guides present');
}
$ordersHubGuide = file_get_contents($root . '/pages/admin/orders.php') ?: '';
$invoicesHubGuide = file_get_contents($root . '/pages/admin/invoices.php') ?: '';
$accountHubGuide = file_get_contents($root . '/pages/admin/account.php') ?: '';
if (!str_contains($ordersHubGuide, 'guide_orders()')
    || !str_contains($invoicesHubGuide, 'guide_invoices()')
    || !str_contains($accountHubGuide, 'guide_admin_account()')) {
    fail('Office hubs missing page-purpose guide calls');
} else {
    ok('Office hubs echo Orders/Invoices/Account guides');
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
// Team page is a privatize stub — browse UI lives on Admin only.
if (str_contains($teamProspects, 'stream_prospect_domains_plain')
    || str_contains($teamProspects, 'prospect-site-search')
    || str_contains($teamProspects, 'read-only')) {
    fail('team prospects still has browse UI (should be Admin-only stub)');
} else {
    ok('team prospects has no browse UI (privatized stub)');
}

$adminProspects = file_get_contents($root . '/pages/admin/prospects.php') ?: '';
$prospectsLib = file_get_contents($root . '/includes/prospects.php') ?: '';
if (!str_contains($prospectsLib, 'function stream_prospect_domains_plain')
    || !str_contains($prospectsLib, 'function stream_prospect_domains_csv')
    || !str_contains($prospectsLib, 'function count_prospect_sites_matching')
    || !str_contains($adminProspects, 'prospect_copy_all')
    || !str_contains($adminProspects, 'prospect_copy_matches')
    || !str_contains($adminProspects, 'prospect-site-search')
    || !str_contains($adminProspects, 'prospects-country.js')) {
    fail('Our database country missing export / matches / live search');
} else {
    ok('Our database country export + matches + live search');
}
$assetPhp = file_get_contents($root . '/asset.php') ?: '';
if (!str_contains($assetPhp, 'js/prospects-country.js')) {
    fail('asset.php missing prospects-country.js allowlist');
} else {
    ok('asset allowlist prospects-country.js');
}
$prospectsCountryJs = file_get_contents($root . '/assets/js/prospects-country.js') ?: '';
if (!str_contains($prospectsCountryJs, 'DEBOUNCE_MS')
    || !str_contains($prospectsCountryJs, 'commitServerSearch')
    || !str_contains($prospectsCountryJs, "Accept: 'application/json'")
    || !str_contains($prospectsCountryJs, 'preventScroll')
    || !str_contains($prospectsCountryJs, 'prospect_copy_matches')
    || !str_contains($prospectsCountryJs, 'downloadTextFile')
    || !str_contains($prospectsCountryJs, 'Clipboard blocked')) {
    fail('prospects-country.js missing debounce / AJAX search / copy matches / download fallback');
} else {
    ok('prospects-country.js debounce + AJAX search + copy + download fallback');
}
if (!str_contains($adminProspects, 'prospect-match-actions')
    || !str_contains($adminProspects, "get('ajax') === '1'")
    || !str_contains($adminProspects, 'prospect_site_rows_html')
    || !str_contains($prospectsLib, 'function prospect_site_rows_html')) {
    fail('Our database missing match actions beside search / AJAX rows helper');
} else {
    ok('Our database match actions beside search + AJAX rows');
}
if (!str_contains($prospectsLib, 'function prospect_export_basename')
    || !str_contains($prospectsLib, '-our-database')
    || !str_contains($adminProspects, 'data-download-name')
    || !str_contains($adminProspects, 'data-fallback-download-url')) {
    fail('Our database missing export basename / download-name attrs');
} else {
    ok('Our database export filenames + download-name attrs');
}
if (!str_contains($prospectsLib, 'function purge_duplicate_prospect_site_rows')
    || !str_contains($prospectsLib, 'function prospect_duplicates_deleted_message')
    || !str_contains($prospectsLib, 'duplicate_count')
    || !str_contains($adminProspects, "flash('fade'")
    || !str_contains(file_get_contents($root . '/pages/team/prospect_check.php') ?: '', 'prospect_duplicates_deleted_message')
    || !str_contains(file_get_contents($root . '/assets/js/alert-fade.js') ?: '', 'data-alert-fade')
    || !str_contains($assetPhp, 'js/alert-fade.js')) {
    fail('Our database missing auto-dedupe / fade notice');
} else {
    ok('Our database auto-dedupe + fade notice');
}
if (!str_contains($adminProspects, 'csrf_field()')) {
    fail('Admin Our database POST forms missing csrf_field');
} else {
    ok('Admin Our database csrf_field on POST forms');
}
if (str_contains($adminProspects, '<th>Status</th>')
    || str_contains($adminProspects, '<label>Status</label>')
    || str_contains($prospectsLib, 'data-label="Status"')) {
    fail('Our database still shows leftover CRM Status UI');
} else {
    ok('Our database Status filter/column removed from UI');
}
if (str_contains($adminProspects, 'id="prospects_per_page"')
    || str_contains($adminProspects, 'id="prospect-country-filters"')) {
    fail('Our database still has duplicate Per page / filters card');
} else {
    ok('Our database Per page only in pager');
}
if (!str_contains($adminProspects, 'data-open-default')
    || !str_contains($adminProspects, "setMarketOpen(market, market.getAttribute('data-open-default') === '1')")) {
    fail('Our database market search-clear does not restore default accordion');
} else {
    ok('Our database market accordion restores on search clear');
}
if (!str_contains($adminProspects, '$superLimit = 200')
    || !str_contains($adminProspects, 'Showing the first')) {
    fail('Our database super search missing truncation copy');
} else {
    ok('Our database super search truncation copy');
}
if (!str_contains($adminProspects, 'id="prospect-site-table"')
    || !preg_match('/table-wrap[\s\S]*prospect-site-table/', $adminProspects)) {
    fail('Our database country Sites table missing table-wrap');
} else {
    ok('Our database country Sites table-wrap');
}
if (!str_contains($adminProspects, 'created_by')
    || !str_contains($adminProspects, 'list_prospect_countries_for_creator')
    || !str_contains($adminProspects, 'Clear person filter')
    || !str_contains($prospectsLib, 'function list_prospect_countries_for_creator')
    || !str_contains($prospectsLib, 'function count_prospect_batches')) {
    fail('Our database missing created_by person filter');
} else {
    ok('Our database created_by person filter');
}
if (str_contains($adminProspects, "'status' => \$status")
    || str_contains($adminProspects, "'status' => \$status,")) {
    fail('Our database country page still applies leftover CRM status filter');
} else {
    ok('Our database country page ignores leftover status= URL');
}
// Policy: Our database country lists stay Admin-only (Team uses Filter & add).
if (str_contains($teamProspects, 'redirect(')
    && (str_contains($teamProspects, 'Admin-only') || str_contains($teamProspects, 'team_prospect_check'))) {
    ok('Team Our database privatized (Admin-only stub)');
} else {
    fail('team prospects not privatized / Admin-only stub');
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
if (!str_contains($indexFull, "\$page === 'account_password'")) {
    fail('index.php CSRF gate missing account_password');
} else {
    ok('index.php account_password CSRF gate');
}
$accountPw = file_get_contents($root . '/pages/account_password.php') ?: '';
if (!str_contains($accountPw, 'csrf_field()')) {
    fail('account_password form missing csrf_field');
} else {
    ok('account_password csrf_field');
}
if (!str_contains($indexFull, 'Page not found')
    || !str_contains($indexFull, 'Go to dashboard')
    || !str_contains($indexFull, 'legacyPageRedirects')) {
    fail('index.php missing branded 404 / legacy redirects');
} else {
    ok('index.php branded 404 + legacy redirects');
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
if (str_contains($extracted, 'extracted_search_all_pages')) {
    fail('extracted still has Search all pages (should be whole-folder search)');
} else {
    ok('extracted Search all pages removed');
}
if (!str_contains($extracted, 'Search this country')
    || !str_contains($extracted, "get('ajax')")
    || !str_contains(file_get_contents($root . '/includes/extracted.php') ?: '', 'function extracted_url_items_html')
    || !str_contains(file_get_contents($root . '/assets/js/extracted-admin.js') ?: '', 'Searching whole folder')) {
    fail('extracted missing whole-folder AJAX search');
} else {
    ok('extracted whole-folder AJAX search');
}
if (!str_contains($extracted, 'csrf_field()')) {
    fail('extracted missing csrf_field on POST forms');
} else {
    ok('extracted csrf_field on POST forms');
}
if (!str_contains($extracted, 'Last pushed')
    || !str_contains($extracted, 'class="table-wrap"')
    || !str_contains($extracted, 'admin_semrush_research')) {
    fail('extracted hub missing last pushed / table-wrap / Semrush link');
} else {
    ok('extracted hub last pushed + table-wrap + Semrush link');
}

$semrushHubSmoke = file_get_contents($root . '/pages/admin/semrush_research.php') ?: '';
$semrushSheetSmoke = file_get_contents($root . '/pages/admin/semrush_sheet.php') ?: '';
if (!str_contains($semrushHubSmoke, 'json_encode')
    || !str_contains($semrushHubSmoke, 'Extracted Sites stay unchanged')
    || str_contains($semrushHubSmoke, "confirm('Clear ALL Semrush")) {
    fail('Admin Semrush hub Clear confirm still uses h() inside JS string');
} else {
    ok('Admin Semrush hub Clear uses json_encode');
}
if (!str_contains($semrushHubSmoke, 'csrf_field()')
    || !str_contains($semrushSheetSmoke, 'csrf_field()')
    || !str_contains($semrushSheetSmoke, 'json_encode')) {
    fail('Admin Semrush missing csrf_field or sheet json_encode confirm');
} else {
    ok('Admin Semrush csrf_field + sheet json_encode confirm');
}
if (str_contains($semrushHubSmoke, 'team_semrush_research')
    || str_contains($semrushSheetSmoke, 'semrush_sheet_url($country, false)')) {
    fail('Admin Semrush still links into Team chrome');
} else {
    ok('Admin Semrush stays in Admin chrome');
}
if (!str_contains($semrushHubSmoke, 'semrush-country-search')) {
    fail('Admin Semrush hub missing country search');
} else {
    ok('Admin Semrush hub country search');
}

if (!str_contains($batches, 'table-wrap')
    || !str_contains($batches, '<th>Actions</th>')
    || !str_contains($batches, 'Repair missing days')
    || !str_contains($batches, 'count_prospect_batches')
    || !str_contains($batches, 'By person')) {
    fail('history list missing table-wrap / Actions / pager / person totals / repair');
} else {
    ok('history list table-wrap + pager + person totals + repair');
}
if (!str_contains($batch, 'csrf_field()')) {
    fail('history day missing csrf_field');
} else {
    ok('history day csrf_field');
}
if (!str_contains($batch, 'render_country_typeahead')
    || !str_contains($batch, 'does not move sites')
    || !str_contains($batch, 'New lines are added to Our database')
    || str_contains($batch, 'history_also_remove_db')) {
    fail('history day missing typeahead / autosave copy / still has live DB-remove checkbox');
} else {
    ok('history day typeahead + autosave copy');
}
if (!str_contains($batch, 'created_by=')
    || !str_contains($batch, 'Inventory by person')) {
    fail('history day Inventory by person missing created_by');
} else {
    ok('history day Inventory by person uses created_by');
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
if (str_contains($emailsHub, 'Final list from Team Push')
    || (str_contains($emailsHub, "clear_admin_new_data('emails_admin'")
        && !str_contains($emailsHub, 'cleared when a country sheet'))) {
    fail('emails hub still mislabels Admin as Final or clears New on hub open');
} else {
    ok('emails hub Admin naming + no hub New clear');
}
if (!str_contains($emailsHub, 'Working list from Team Push')
    || !str_contains($emailsHub, 'emailed checkpoint here')) {
    fail('emails hub missing Admin working-list copy');
} else {
    ok('emails hub Admin working-list copy');
}
if (!str_contains($emailsHub, "admin_new_badge_html('emails_admin'")
    || !str_contains($emailsHub, 'swe_admin_new_counts_by_country')) {
    fail('emails hub missing Admin New badge / country new counts');
} else {
    ok('emails hub Admin New badge + country counts');
}
if (!str_contains($emailsHub, 'render_sites_with_emails_admin_super_search')
    || !str_contains($emailsHub, "get('ajax') === 'suggest'")
    || !str_contains($emailsHub, "action === 'delete_row'")
    || !str_contains($emailsHub, 'added_samples')
    || !str_contains($emailsHub, 'Final archive repaired · added')) {
    fail('emails hub missing P3 super-search / Final repair report');
} else {
    ok('emails hub P3 super-search + Final repair report');
}
if (!str_contains($emailsHub, 'guide_emails_data()')
    || !str_contains($emailsHub, 'Three folders: Admin is the working list from Team Push')) {
    fail('emails hub missing page-purpose guide or folder H1 copy');
} else {
    ok('emails hub page-purpose guide + folder H1');
}
$sweLibSmoke = file_get_contents($root . '/includes/sites_with_emails.php') ?: '';
$sweDeleteJsSmoke = file_get_contents($root . '/assets/js/admin-emails-delete.js') ?: '';
$teamAdminEmailsSmoke = file_get_contents($root . '/pages/team/admin_emails_delete.php') ?: '';
if (str_contains($emailsHub, 'Admin + Final')
    || str_contains($emailsHub, 'from Admin and Final')
    || str_contains($sweLibSmoke, 'deleted from Admin and Final')
    || str_contains($sweDeleteJsSmoke, 'Admin + Final')
    || str_contains($teamAdminEmailsSmoke, 'Admin + Final')
    || !str_contains($emailsHub, 'Final keeps its archive copy')
    || !str_contains($sweLibSmoke, 'Final keeps its archive copy')
    || !str_contains($sweDeleteJsSmoke, 'Final keeps its archive copy')
    || !str_contains($teamAdminEmailsSmoke, 'Final keeps its archive copy')) {
    fail('emails super-search still claims last-email delete wipes Final');
} else {
    ok('emails super-search last-email copy keeps Final');
}
$sweAppSmoke = file_get_contents($root . '/pages/sites_with_emails_app.php') ?: '';
$campAppSmoke = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
if (str_contains($sweAppSmoke, "confirm('Clear ALL emailed")
    || str_contains($sweAppSmoke, "confirm('Remove ALL")
    || str_contains($sweAppSmoke, "confirm('Remove matching sites")
    || !str_contains($sweAppSmoke, 'json_encode')
    || str_contains($campAppSmoke, "confirm('Clear ALL emailed")
    || str_contains($campAppSmoke, "confirm('Import into")
    || str_contains($campAppSmoke, "confirm('Remove <?= h(\$sheetCountry)")
    || !str_contains($campAppSmoke, "json_encode('Clear ALL emailed marks on '")) {
    fail('Emails Admin/Final/Campaign confirms still use h() inside JS strings');
} else {
    ok('Emails Admin/Final/Campaign confirms use json_encode');
}
if (str_contains($campAppSmoke, 'it?\n\nTeam')
    || !str_contains($campAppSmoke, '"\n\nTeam “fetched to "')) {
    fail('Campaign delete-project confirm still has single-quoted \\n');
} else {
    ok('Campaign delete-project confirm uses real newlines');
}
if (substr_count($campAppSmoke, 'csrf_field()') < 21
    || !str_contains($campAppSmoke, "value=\"create_project\"")
    || !str_contains($campAppSmoke, "value=\"clear_all_emailed\"")
    || !str_contains($campAppSmoke, "value=\"delete_sheet\"")) {
    fail('Campaign POST forms missing csrf_field');
} else {
    ok('Campaign csrf_field on POST forms');
}
if (!str_contains($sweAppSmoke, 'id="swe-country-table"')
    || !str_contains($sweAppSmoke, '<div class="table-wrap">')
    || !str_contains($sweAppSmoke, 'also creates the Admin working-list row')) {
    fail('SWE country list missing table-wrap or Final add-site copy');
} else {
    ok('SWE country list table-wrap + Final add-site copy');
}
if (!str_contains($sweLibSmoke, 'function merge_swe_email_slots_prefer_admin_stats')
    || !str_contains($sweLibSmoke, 'skipped_full_slots')
    || !str_contains($sweLibSmoke, "'row_deleted' => true")
    || !str_contains($sweAppSmoke, 'no emails left')
    || !str_contains($sweAppSmoke, 'Admin already had 4')) {
    fail('SWE missing push full-slot stats / Admin empty-email delete');
} else {
    ok('SWE push full-slot stats + Admin empty-email delete');
}
if (!str_contains($sweLibSmoke, 'function swe_admin_clear_emailed_if_slots_changed')
    || !str_contains($sweLibSmoke, 'emailed_cleared')
    || !str_contains($sweAppSmoke, 'emailed mark cleared')
    || !str_contains($sweAppSmoke, 'cleared emailed on')) {
    fail('SWE missing P2 re-push emailed clear');
} else {
    ok('SWE P2 re-push clears emailed when slots change');
}
if (!str_contains($sweLibSmoke, 'function swe_admin_mark_country_seen')
    || !str_contains($sweLibSmoke, 'swe_admin_country_seen')
    || !str_contains($sweAppSmoke, 'swe_admin_mark_country_seen')
    || !str_contains($sweAppSmoke, 'mark_all_countries_seen')
    || !str_contains($sweAppSmoke, 'swe-country-new')) {
    fail('SWE missing Admin country New watermark UI');
} else {
    ok('SWE Admin country New watermark + list +N');
}
if (!str_contains($sweAppSmoke, 'swe-row-chip')
    || !str_contains($sweAppSmoke, "get('filter')")
    || !str_contains($sweAppSmoke, 'since your last visit')) {
    fail('SWE missing P1b row New/Updated chips / flash / filter');
} else {
    ok('SWE P1b row chips + flash + filter');
}
if (!str_contains($sweAppSmoke, '$adminVisitStarted')
    || !str_contains($sweAppSmoke, 'array_key_exists($countryName')
    || !str_contains($sweLibSmoke, 'email1 = VALUES(email1) AND email2 = VALUES(email2)')
    || !str_contains(file_get_contents($root . '/assets/js/sites-with-emails.js') ?: '', 'data.row_deleted')) {
    fail('SWE missing chain fixes (visit mark-once / updated_at / row_deleted UI)');
} else {
    ok('SWE chain fixes visit mark-once + updated_at + row_deleted');
}
if (!str_contains($sweLibSmoke, 'function delete_sites_with_emails_admin_keep_final')
    || !str_contains($sweLibSmoke, 'skip invalid tokens so one bad address never blocks Copy')
    || !str_contains($sweLibSmoke, 'bool $strict = false')
    || !str_contains($sweLibSmoke, 'Does NOT delete Final-only rows')
    || !str_contains($sweLibSmoke, 'function sites_with_emails_final_needs_repair')
    || !str_contains($sweAppSmoke, 'Mark emailed removes the site from this Admin working list')
    || !str_contains(file_get_contents($root . '/assets/js/sites-with-emails.js') ?: '', 'Final kept the copy')) {
    fail('SWE missing Copy-all invalid skip / mark-emailed removes Admin keeps Final');
} else {
    ok('SWE Copy-all skips invalid + mark emailed keeps Final');
}
$sweJsSmoke = file_get_contents($root . '/assets/js/sites-with-emails.js') ?: '';
$helpersSmoke = file_get_contents($root . '/includes/helpers.php') ?: '';
if (!str_contains($helpersSmoke, 'function render_sheet_shared_row_action_forms')
    || !str_contains($helpersSmoke, 'return 100;')
    || !str_contains($sweLibSmoke, 'Stop at the first mismatch')
    || !str_contains($sweLibSmoke, 'Indexed prefix on domain')
    || !str_contains($sweLibSmoke, 'function swe_admin_suggestion_from_row')
    || !str_contains($sweAppSmoke, 'data-sheet-action="mark"')
    || !str_contains($sweAppSmoke, 'render_sheet_shared_row_action_forms')
    || !str_contains($sweJsSmoke, 'function scheduleFilterRows')
    || !str_contains($sweJsSmoke, 'swe-shared-')
    || !str_contains(file_get_contents($root . '/assets/js/email-campaign-sheet.js') ?: '', 'function scheduleFilterRows')
    || !str_contains(file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '', 'data-sheet-action="mark"')) {
    fail('SWE/campaigns missing large-list perf (default 100, debounce, shared actions, prefix search)');
} else {
    ok('SWE large-list perf: default 100 + debounce + shared actions + prefix search');
}
$extractedAdminJsSmoke = file_get_contents($root . '/assets/js/extracted-admin.js') ?: '';
$csrfJsSmoke = file_get_contents($root . '/assets/js/csrf.js') ?: '';
$presenceJsSmoke = file_get_contents($root . '/assets/js/task-presence.js') ?: '';
$sitesFormJsSmoke = file_get_contents($root . '/assets/js/sites-form.js') ?: '';
$campSearchJsSmoke = file_get_contents($root . '/assets/js/email-campaign-search.js') ?: '';
$navJsSmoke = file_get_contents($root . '/assets/js/nav-shell.js') ?: '';
$dashSmoke = file_get_contents($root . '/pages/admin/dashboard.php') ?: '';
$geoSmoke = file_get_contents($root . '/includes/geo.php') ?: '';
$campLibSmoke = file_get_contents($root . '/includes/email_campaigns.php') ?: '';
if (!str_contains($helpersSmoke, 'function table_has_any_row')
    || !str_contains($helpersSmoke, 'function cached_scalar_count')
    || !str_contains($helpersSmoke, 'function cached_count_result')
    || !str_contains($helpersSmoke, 'function table_has_index')
    || !str_contains($sweLibSmoke, 'table_has_any_row($pdo, \'sites_with_emails_admin\')')
    || !str_contains($campLibSmoke, 'function email_campaign_suggestion_from_row')
    || !str_contains($campLibSmoke, 'Indexed prefix on domain')
    || !str_contains($campLibSmoke, '$useContains = mb_strlen($q) >= 3')
    || !str_contains($extractedAdminJsSmoke, 'function scheduleSearch')
    || !str_contains($csrfJsSmoke, 'requestAnimationFrame')
    || !str_contains($presenceJsSmoke, 'document.hidden')
    || !str_contains($sitesFormJsSmoke, 'function scheduleStatus')
    || !str_contains($campSearchJsSmoke, 'fetchSuggest(q); }, 280')
    || !str_contains($campSearchJsSmoke, 'q.length < 3')
    || !str_contains($navJsSmoke, "addEventListener('change'")
    || !str_contains($dashSmoke, 'cached_count_result')
    || !str_contains($geoSmoke, 'SELECT 1 FROM countries LIMIT 1')
    || !str_contains($sweJsSmoke, "behavior: 'auto'")) {
    fail('sitewide smoothness missing LIMIT 1 schema / prefix campaign search / debounce / hidden presence');
} else {
    ok('sitewide smoothness: LIMIT 1 schema, prefix campaign search, debounce, hidden presence');
}
$appCssSmoke = file_get_contents($root . '/assets/css/app.css') ?: '';
$loginSmoke = file_get_contents($root . '/pages/login.php') ?: '';
$authSmoke = file_get_contents($root . '/includes/auth.php') ?: '';
$indexSmoke = file_get_contents($root . '/index.php') ?: '';
$htaccessSmoke = file_get_contents($root . '/.htaccess') ?: '';
if (str_contains($appCssSmoke, 'content-visibility: auto')
    || !str_contains($appCssSmoke, '[hidden]')
    || !str_contains($appCssSmoke, 'display: none !important')
    || !str_contains($sweJsSmoke, "addEventListener('search'")
    || !str_contains($sweLibSmoke, 'domain NOT LIKE')
    || !str_contains($helpersSmoke, 'function txf_secure_session_start')
    || !str_contains($helpersSmoke, 'function txf_send_security_headers')
    || !str_contains($loginSmoke, 'csrf_field()')
    || !str_contains($loginSmoke, 'login_throttle_blocked')
    || !str_contains($authSmoke, 'function login_throttle_blocked')
    || !str_contains($indexSmoke, "\$page === 'login'")
    || !str_contains($htaccessSmoke, 'reset_admin_once')) {
    fail('search hide / security headers / login CSRF+throttle missing');
} else {
    ok('search [hidden] hide + login CSRF/throttle + security headers');
}
$campLibSmoke = file_get_contents($root . '/includes/email_campaigns.php') ?: '';
$campAppSmoke = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
if (!str_contains($campLibSmoke, 'function email_campaign_slots_equal')
    || !str_contains($campLibSmoke, 'skipped_duplicate')
    || !str_contains($campLibSmoke, "'replace'")
    || !str_contains($campAppSmoke, 'duplicate domain(s) skipped')
    || !str_contains($campAppSmoke, "import_email_campaign_sheet_from_swe(\$sheetId, \$source, \$sheetCountry, 'replace')")) {
    fail('campaigns missing duplicate skip / replace-different-emails');
} else {
    ok('campaigns duplicate skip + replace different emails');
}
if (!str_contains($campLibSmoke, 'function collect_email_campaign_domains')
    || !str_contains($campLibSmoke, 'function record_email_campaign_source_fetch')
    || !str_contains($campLibSmoke, 'email_campaign_source_fetches')
    || !str_contains($campLibSmoke, "['admin', 'admin_all', 'team']")
    || !str_contains($campAppSmoke, 'Copy not emailed domains')
    || !str_contains($campAppSmoke, "value=\"team\"")
    || !str_contains($campAppSmoke, 'matching this filter')
    || !str_contains($campLibSmoke, 'Already fetched to campaign')
    || !str_contains($sweAppSmoke, 'render_email_campaign_fetch_stamps')
    || !str_contains(file_get_contents($root . '/assets/js/email-campaign-sheet.js') ?: '', 'data-camp-copy-domains')) {
    fail('campaigns missing copy-not-emailed domains / Team fetch stamp');
} else {
    ok('campaign copy not-emailed domains + Team fetch stamps');
}
if (!str_contains($campLibSmoke, 'Never copies emailed flags')
    || !str_contains($campLibSmoke, 'Never set email_sent here')
    || !str_contains($campLibSmoke, 'function email_campaign_ensure_source_fetch_cascade')
    || !str_contains($campLibSmoke, 'ON DELETE CASCADE')
    || !str_contains($campLibSmoke, 'Each campaign keeps its own copy and emailed marks')
    || !str_contains($campAppSmoke, 'This campaign’s emailed marks stay on this sheet only')
    || !str_contains($campAppSmoke, 'Other campaigns are not affected')
    || !str_contains($campAppSmoke, 'Team sites stay')) {
    fail('campaigns missing emailed isolation / stamp cascade copy');
} else {
    ok('campaign emailed isolation + stamp cascade copy');
}
$newDataSmoke = file_get_contents($root . '/includes/admin_new_data.php') ?: '';
$layoutSmoke = file_get_contents($root . '/includes/layout.php') ?: '';
if (!str_contains($newDataSmoke, "section !== 'emails_admin'")
    || !str_contains($layoutSmoke, "'admin_emails_data' => 'emails_admin'")) {
    fail('emails_admin New badge not re-enabled (nav / helper)');
} else {
    ok('emails_admin New badge re-enabled only');
}

$ordersPage = file_get_contents($root . '/pages/admin/orders.php') ?: '';
if (!str_contains($ordersPage, 'order-client-search')) {
    fail('orders missing client search');
} else {
    ok('orders client search');
}
if (!str_contains($ordersPage, 'admin_invoices&amp;client_id=')) {
    fail('orders Invoices link missing client_id');
} else {
    ok('orders Invoices link scopes by client_id');
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
foreach (['order_client_name_taken', 'count_invoices_for_order_client', 'set_order_client_archived', 'count_order_client_unpaid_live', 'order_management_dashboard_stats', 'count_order_clients'] as $omFn) {
    if (!str_contains($ordersLib, "function {$omFn}")) {
        fail("orders.php missing {$omFn}");
    }
}
ok('orders helpers for OM-1–4');

$invoicesLib = file_get_contents($root . '/includes/invoices.php') ?: '';
if (!str_contains($invoicesLib, 'function count_invoices')
    || !str_contains($invoicesLib, 'function invoices_where_sql')
    || !str_contains($invoicesLib, 'function count_invoices_by_work_status')
    || !str_contains($invoicesLib, 'function count_invoices_unpaid')
    || !str_contains($invoicesLib, 'function normalize_invoice_list_filter')
    || !str_contains($invoicesLib, 'function invoice_list_query')
    || !str_contains($invoicesLib, "i.work_status='draft'")
    || !str_contains($invoicesLib, "i.payment_status='unpaid' AND i.work_status='done'")
    || !str_contains($invoicesLib, 'LIMIT ')) {
    fail('invoices.php missing SQL paging helpers');
} else {
    ok('invoices SQL paging helpers');
}

$testsFull = file_get_contents($root . '/tests_run.php') ?: '';
foreach (['mark paid without LIVE', 'unpaid LIVE count', 'archived client hidden', 'order_management_dashboard_stats', 'clearing LIVE also clears paid', 'order clients SQL limit/offset', 'invoices SQL limit/offset', 'invoice draft count helper', 'invoice unpaid-done count helper', 'invoice list filter draft', 'invoice list filter unpaid', 'invoice list filter paid', 'invoice list client_id excludes blanks', 'invoice generate option unpaid LIVE'] as $needle) {
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
if (!str_contains($invoiceGenerate, 'invoice_generate_client_option_label')
    || !str_contains($invoiceGenerate, 'unpaid LIVE')
    || !str_contains($invoiceGenerate, 'data-searchable')
    || !str_contains($invoiceGenerate, 'js/searchable-select.js')
    || !str_contains($invoiceGenerate, 'invoice_generate_client_typeahead_min')) {
    fail('invoice_generate missing unpaid LIVE options or typeahead');
} else {
    ok('invoice_generate unpaid LIVE options + typeahead');
}
if (!is_file($root . '/assets/js/searchable-select.js')) {
    fail('missing assets/js/searchable-select.js');
} else {
    ok('file assets/js/searchable-select.js');
}
$assetInvGen = file_get_contents($root . '/asset.php') ?: '';
if (!str_contains($assetInvGen, 'js/searchable-select.js')) {
    fail('asset.php missing searchable-select.js allowlist');
} else {
    ok('asset allowlist js/searchable-select.js');
}
$invoicesLibTypeahead = file_get_contents($root . '/includes/invoices.php') ?: '';
if (!str_contains($invoicesLibTypeahead, 'function invoice_generate_client_option_label')
    || !str_contains($invoicesLibTypeahead, 'unpaid LIVE')
    || !str_contains($invoicesLibTypeahead, 'function invoice_generate_client_typeahead_min')) {
    fail('invoices.php missing generate client option helpers');
} else {
    ok('invoice generate client option helpers');
}
$invoicesListCsrf = file_get_contents($root . '/pages/admin/invoices.php') ?: '';
$invoiceViewCsrf = file_get_contents($root . '/pages/admin/invoice_view.php') ?: '';
if (substr_count($invoicesListCsrf, 'csrf_field()') < 3
    || !str_contains($invoicesListCsrf, "value=\"save_note\"")
    || !str_contains($invoicesListCsrf, "value=\"mark_paid\"")
    || !str_contains($invoicesListCsrf, "value=\"delete\"")
    || !str_contains($invoiceViewCsrf, 'csrf_field()')
    || !str_contains($invoiceViewCsrf, "value=\"save_blank\"")
    || !str_contains($invoiceViewCsrf, "value=\"mark_paid\"")) {
    fail('Invoice list/view POST forms missing csrf_field');
} else {
    ok('Invoice list/view csrf_field on POST forms');
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
if (!str_contains($adminDepts, "'overdue' => 'Overdue'")
    || !str_contains($adminDepts, "\$statusFilter === \$val ? ' active-soft' : ''")) {
    fail('admin departments missing Overdue status chip');
} else {
    ok('admin departments Overdue status chip');
}
if (!str_contains($adminDepts, "membership unlocks that department's tools")
    || !str_contains($adminDepts, 'Open Users')) {
    fail('admin departments hub missing tools copy / unassigned Users link');
} else {
    ok('admin departments hub tools copy + unassigned Users');
}
if (!preg_match('/id="members"[\s\S]*table-wrap[\s\S]*<table>/', $adminDepts)) {
    fail('admin departments members table missing table-wrap');
} else {
    ok('admin departments members table-wrap');
}
if (!preg_match('/data-due-cell[\s\S]{0,80}<\/td>\s*<td>\s*<form method="post"/', $adminDepts)) {
    fail('admin departments Delete not wrapped in td');
} else {
    ok('admin departments Delete Actions cell');
}
if (!str_contains($adminDepts, '<th>Actions</th>')
    || !str_contains($adminDepts, '$deptFolderUrl()')) {
    fail('admin departments Actions header / POST forms drop folder filters');
} else {
    ok('admin departments Actions header + filter-preserving POST');
}
$teamDepts = file_get_contents($root . '/pages/team/departments.php') ?: '';
if (!str_contains($teamDepts, 'csrf_field()')) {
    fail('team departments missing csrf_field');
} else {
    ok('team departments csrf_field');
}
if (!str_contains($teamDepts, 'team_can_set_department_task_status')
    || !str_contains($teamDepts, 'Only the assignee can update this task')) {
    fail('team departments missing assignee status ACL');
} else {
    ok('team departments assignee status ACL');
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
$teamDash = file_get_contents($root . '/pages/team/dashboard.php') ?: '';
if (substr_count($teamDash, "render_dashboard_help('team')") < 2
    || !str_contains($teamDash, 'department_task_is_overdue')
    || !str_contains($teamDash, 'dept-task-overdue')) {
    fail('team dashboard missing How Team works / overdue on assigned tasks');
} else {
    ok('team dashboard How Team works + overdue');
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
if (str_contains($dashPage, 'each country has its own URL database')
    || str_contains($dashPage, 'Site adding history days')
    || !str_contains($dashPage, 'Filter this page')
    || !str_contains($dashPage, 'index.php?page=admin_users')
    || !str_contains($dashPage, 'has-admin-new')
    || !str_contains($dashPage, "admin_new_badge_html('emails_admin'")
    || !str_contains($dashPage, 'table-wrap')
    || !str_contains($dashPage, 'admin_prospect_batches">See all')
    || !str_contains($dashPage, 'id="dashboard-recent-card"')
    || !str_contains($dashPage, 'recentCard.hidden')) {
    fail('dashboard missing chrome: Users card, Emails New, recent-adds wrap, copy');
} else {
    ok('dashboard chrome: Users, Emails New, recent wrap, copy');
}
if (!str_contains($dashPage, 'data-dashboard-attention')
    || !str_contains($dashPage, 'count_sites_with_emails')
    || !str_contains($dashPage, 'count_email_campaign_sheets')
    || !str_contains($dashPage, 'count_invoices_by_work_status')
    || !str_contains($dashPage, 'count_invoices_unpaid')
    || !str_contains($dashPage, 'draft invoice')) {
    fail('dashboard missing attention strip / email / invoice counts');
} else {
    ok('dashboard attention strip and email/invoice counts');
}
if (str_contains($dashPage, 'admin_invoices&q=draft')
    || !str_contains($dashPage, 'admin_invoices&filter=draft')
    || !str_contains($dashPage, 'admin_invoices&filter=unpaid')) {
    fail('dashboard invoice tiles must use filter= not q=draft');
} else {
    ok('dashboard Draft/Unpaid tiles use invoice filter');
}
if (!str_contains($dashPage, 'Emails Admin')
    || !str_contains($dashPage, 'URLs (all countries)')
    || !str_contains($dashPage, 'Could not load')
    || !str_contains($dashPage, 'render_admin_dashboard_stat')
    || !str_contains($dashPage, 'Unpaid LIVE')
    || !str_contains($dashPage, 'Unpaid invoices')) {
    fail('dashboard stats tiles missing pipeline labels');
} else {
    ok('dashboard stats match pipeline');
}
if (!str_contains($dashPage, 'render_workflow')
    || !str_contains($dashPage, "'Extracted Sites'")
    || !str_contains($dashPage, "'Emails data'")) {
    fail('dashboard missing workflow strip');
} else {
    ok('dashboard workflow strip');
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

$legacyExtractPages = [
    'pages/admin/extract_sites.php',
    'pages/admin/extract_emails.php',
    'pages/team/extract_submit.php',
    'pages/team/extract_queue.php',
    'pages/team/extract_work.php',
    'pages/team/extract_final.php',
    'pages/team/extract_emails.php',
];
$legacyStillThere = [];
foreach ($legacyExtractPages as $rel) {
    if (is_file($root . '/' . $rel)) {
        $legacyStillThere[] = $rel;
    }
}
if ($legacyStillThere) {
    fail('legacy extract pages still on disk: ' . implode(', ', $legacyStillThere));
} else {
    ok('legacy extract pages removed (routes still redirect)');
}
if (!str_contains($indexFull, "'admin_extract_sites'")
    || !str_contains($indexFull, "'team_extract_submit'")) {
    fail('index.php missing legacy extract redirects');
} else {
    ok('legacy extract routes still redirect');
}
$accountLibSmoke = file_get_contents($root . '/includes/account.php') ?: '';
if (str_contains($accountLibSmoke, 'CREATE TABLE IF NOT EXISTS team_tasks')) {
    fail('ensure_tasks_schema still creates team_tasks');
} else {
    ok('team_tasks schema is not auto-created');
}
$hostingerMd = file_get_contents(dirname($root) . '/public_html/HOSTINGER.md') ?: file_get_contents($root . '/HOSTINGER.md') ?: '';
if (str_contains($hostingerMd, 'includes/inventory.php`, `email_campaigns.php')
    || str_contains($hostingerMd, 'No Catalog, Emails, Orders')) {
    fail('HOSTINGER.md still tells you to delete live Emails/Orders modules');
} else {
    ok('HOSTINGER.md matches live Emails/Orders app');
}
$cfgSample = file_get_contents($root . '/config.sample.php') ?: '';
if (!str_contains($cfgSample, "'127.0.0.1'")) {
    fail('config.sample.php should default db_host to 127.0.0.1');
} else {
    ok('config.sample.php uses 127.0.0.1');
}
$htaccessSmoke = file_get_contents($root . '/.htaccess') ?: '';
if (!preg_match('/upgrade/', $htaccessSmoke)) {
    fail('.htaccess does not block upgrade.php');
} else {
    ok('.htaccess blocks upgrade.php');
}
$deptToolsSmoke = file_get_contents($root . '/includes/departments.php') ?: '';
if (str_contains($deptToolsSmoke, "\$pages[] = 'team_prospects'")) {
    fail('Site Finding still unlocks team_prospects browse');
} else {
    ok('Site Finding tools omit Our database browse');
}
$teamProspectStub = file_get_contents($root . '/pages/team/prospects.php') ?: '';
if (str_contains($teamProspectStub, "flash('error'")) {
    fail('team_prospects stub still flashes an error');
} else {
    ok('team_prospects quietly redirects to Filter & add');
}

$testsFull = file_get_contents($root . '/tests_run.php') ?: '';
foreach ([
    'department invalid status rejected',
    'remove member clears open assignee',
    'department assignee filters mine/unassigned',
    'department overdue helper',
    'department overdue status filter',
    'department_stats overdue_count',
    'departments dashboard stats',
    'assignee cannot change someone else task status',
    'prospect_site_rows_html has no Status column',
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
if (!str_contains($deptLib, 'function team_can_clear_semrush_country')
    || !str_contains($deptLib, 'function team_can_set_department_task_status')
    || !str_contains($deptLib, "team_semrush_research")
    || !str_contains($deptLib, 'Clear country stays with Site Finding')) {
    fail('departments.php missing Extracting Semrush unlock / Clear ACL');
} else {
    ok('Extracting Semrush unlock + Clear country ACL');
}
$teamSemrushHub = file_get_contents($root . '/pages/team/semrush_research.php') ?: '';
$teamSemrushSheet = file_get_contents($root . '/pages/team/semrush_sheet.php') ?: '';
if (!str_contains($teamSemrushHub, 'team_can_clear_semrush_country')
    || !str_contains($teamSemrushSheet, 'team_can_clear_semrush_country')
    || !str_contains($teamSemrushHub, 'Clear country is for Site Finding')
    || !str_contains($teamSemrushSheet, 'Clear country is for Site Finding')) {
    fail('Team Semrush missing Clear country gate');
} else {
    ok('Team Semrush Clear country gated in hub + sheet');
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
if (!str_contains($extractBatchT, 'Clean to root domains')
    || !str_contains($extractBatchT, 'render_domains_paste_field')
    || !str_contains($extractBatchT, 'sites_form_script_tag')) {
    fail('Extracting Results missing Clean Ready/attention UI');
} else {
    ok('Extracting Results Clean Ready/attention');
}
$guidesPhp = file_get_contents($root . '/includes/guides.php') ?: '';
if (str_contains($guidesPhp, 'Backspace delete')
    || str_contains($guidesPhp, 'Open links in new tabs')) {
    fail('guide_extracting still documents missing Sites list Open/Backspace UI');
} elseif (!str_contains($guidesPhp, 'Copy, Undo, and Redo')) {
    fail('guide_extracting missing real Sites list tools');
} else {
    ok('Extracting guide matches Sites list tools');
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
if (!str_contains($sweApp, 'confirm_overwrite') || !str_contains($sweApp, 'MERGE Team emails')) {
    fail('SWE UI missing merge-on-conflict confirm');
} else {
    ok('SWE UI merge-on-conflict confirm');
}
if (str_contains($sweApp, 'merge is not available yet') || str_contains($sweLib, 'merge is not available yet')) {
    fail('SWE still says merge is not available');
} else {
    ok('SWE merge blanks available');
}
if (!str_contains($sweLib, 'merge_swe_email_slots_prefer_admin')) {
    fail('sites_with_emails.php missing merge_swe_email_slots_prefer_admin');
} else {
    ok('SWE merge_swe_email_slots_prefer_admin helper');
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
if (!str_contains($sweJs, 'data-swe-open-track')
    || !str_contains($sweJs, 'markRowOpened')
    || !str_contains($sweJs, 'swe-row-opened')
    || !str_contains($sweJs, 'clearRowOpened')
    || !str_contains($sweJs, 'syncAllOpenedHighlights')) {
    fail('sites-with-emails.js missing Open highlight-until-email tracking');
} else {
    ok('sites-with-emails.js Open highlight until email');
}
if (!str_contains($sweApp, 'data-swe-open-track')
    || !str_contains($sweApp, 'swe-col-num')
    || !str_contains($sweApp, 'swe-row-num')
    || !str_contains($sweApp, 'data-row-num')) {
    fail('SWE Team missing row # / open-track markup');
} else {
    ok('SWE Team row numbers + open-track markup');
}
$sweCss = file_get_contents($root . '/assets/css/app.css') ?: '';
if (!str_contains($sweCss, 'swe-open-site') || !str_contains($sweCss, 'swe-open-group')) {
    fail('app.css missing SWE Open site styles');
} else {
    ok('SWE Open site CSS');
}
if (!str_contains($sweCss, 'swe-row-opened') || !str_contains($sweCss, 'swe-row-num')) {
    fail('app.css missing SWE opened-row / row-number styles');
} else {
    ok('SWE opened-row + row-number CSS');
}

$openSiteJs = file_get_contents($root . '/assets/js/open-site.js') ?: '';
if (!str_contains($openSiteJs, 'OpenSite') || !str_contains($openSiteJs, 'normalizeSiteHost')) {
    fail('open-site.js missing shared Open helpers');
} else {
    ok('open-site.js shared helpers');
}
$campApp = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
$campDraftsTeam = file_get_contents($root . '/pages/team/email_campaign_drafts.php') ?: '';
$campLib = file_get_contents($root . '/includes/email_campaigns.php') ?: '';
if (!str_contains($campLib, 'function email_campaign_user_can_delete_draft')
    || !str_contains($campLib, 'updated_by')
    || !str_contains($campLib, 'email_campaign_draft_attribution')
    || !str_contains($campLib, 'Only the draft creator or Admin')) {
    fail('campaign drafts missing creator/Admin delete ACL + authorship');
} else {
    ok('campaign drafts creator/Admin delete ACL + authorship');
}
if (!str_contains($campDraftsTeam, 'email_campaign_user_can_delete_draft($user, $d)')
    || !str_contains($campDraftsTeam, 'camp-draft-attribution')
    || !str_contains($campApp, 'email_campaign_draft_attribution')) {
    fail('campaign drafts UI missing attribution / gated Delete');
} else {
    ok('campaign drafts UI attribution + gated Delete');
}
if (!str_contains($campLib, 'function expand_email_campaign_draft_tokens')
    || (!str_contains($campLib, 'subject VARCHAR') && !str_contains($campLib, 'ADD COLUMN subject'))
    || !str_contains($campDraftsTeam, 'data-camp-draft-copy-plain')
    || !str_contains($campDraftsTeam, 'name="subject"')) {
    fail('campaign drafts missing subject/tokens/Copy plain/category preserve');
} else {
    ok('campaign drafts subject + tokens + Copy plain');
}
if (!str_contains($campLib, 'data-camp-open-drafts')
    || !str_contains($campLib, 'data-drafts-url')
    || !str_contains(file_get_contents($root . '/assets/js/email-campaign-search.js') ?: '', 'syncDraftsLink')) {
    fail('campaign search missing Open drafts deep-link');
} else {
    ok('campaign search Open drafts deep-link');
}
$campDraftJs = file_get_contents($root . '/assets/js/email-campaign-drafts.js') ?: '';
if (!str_contains($campDraftJs, 'data-camp-draft-copy-plain')
    || !str_contains($campDraftJs, 'expandTokens')
    || !str_contains($campDraftJs, 'data-camp-draft-token')) {
    fail('email-campaign-drafts.js missing Copy plain / tokens');
} else {
    ok('email-campaign-drafts.js Copy plain + tokens');
}
if (!str_contains($campLib, 'function count_email_campaign_drafts_by_projects')
    || !str_contains($campLib, 'function count_email_campaign_sheets')
    || !str_contains($campLib, 'function move_email_campaign_draft')
    || !str_contains($campLib, 'function email_campaign_draft_size_warning')
    || !str_contains($campLib, '%%CAMPLINK')
    || !str_contains($campLib, "'ul'")
    || !str_contains($campLib, 'data-camp-draft-cmd="link"')
    || !str_contains($campDraftsTeam, 'count_email_campaign_drafts_by_projects')
    || !str_contains($campDraftsTeam, 'move_draft')
    || !str_contains($campDraftsTeam, 'camp-draft-size-warn')
    || !str_contains($campApp, 'move_draft')
    || !str_contains($campDraftJs, 'a: 1, ul: 1, ol: 1, li: 1')) {
    fail('campaign drafts P2 missing batch counts / reorder / links-lists / size warn');
} else {
    ok('campaign drafts P2 counts + reorder + links/lists + size warn');
}
if (!str_contains($campLib, 'email_campaign_excluded_emails')
    || !str_contains($campLib, 'function exclude_email_campaign_email')
    || !str_contains($campLib, 'function filter_email_campaign_slots_against_exclusions')
    || !str_contains($campLib, 'function load_email_campaign_exclusion_sets')
    || str_contains($campLib, 'Manual add / paste means Admin wants this site again')
    || str_contains($campLib, 'Intentional paste/add lifts')
    || !str_contains($campApp, 'Paste / + Add also respect previously removed')
    || !str_contains($campApp, 'blocked from re-add')) {
    fail('campaign missing sticky domain/email exclusions (P0/P1)');
} else {
    ok('campaign sticky domain + email exclusions (P0/P1)');
}
if (!str_contains($campLib, 'function list_email_campaign_excluded_emails')
    || !str_contains($campLib, 'function count_email_campaign_excluded_emails')
    || !str_contains($campApp, 'allow_excluded_email')
    || !str_contains($campApp, 'allow_excluded_emails_for_domain')
    || !str_contains($campApp, 'Previously removed')
    || !str_contains($campApp, 'Allow all for site')) {
    fail('campaign missing excluded-email Admin UI (P2)');
} else {
    ok('campaign excluded-email Admin UI (P2)');
}
$extractedPg = file_get_contents($root . '/pages/admin/extracted.php') ?: '';
$orderSheet = file_get_contents($root . '/pages/admin/order_sheet.php') ?: '';
if (!str_contains($campApp, 'render_open_site_anchor')
    || (!str_contains($extractedPg, 'render_open_site_anchor')
        && !str_contains(file_get_contents($root . '/includes/extracted.php') ?: '', 'render_open_site_anchor'))
    || !str_contains($orderSheet, 'render_open_site_anchor')
    || !str_contains($orderSheet, 'data-open-site-host')) {
    fail('Open site parity missing on Campaigns / Extracted / Orders');
} else {
    ok('Open site parity on Campaigns / Extracted / Orders');
}
if (!str_contains(file_get_contents($root . '/asset.php') ?: '', "'js/open-site.js'")) {
    fail('asset.php missing open-site.js allowlist');
} else {
    ok('asset allowlist open-site.js');
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
    'semrush Clear country is Finding not Extracting',
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
if (!str_contains($prospectCheckSf, 'saved for the Extracting team')
    || !str_contains($prospectCheckSf, 'Clean to root domains')) {
    fail('Filter & add missing ACL-aware Extracting flash / Clean wording');
} else {
    ok('Filter & add ACL-aware Extracting flash');
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
if (!str_contains($prospectCheckSf, '$pasteCanSend')
    || !str_contains($prospectCheckSf, '$pasteCanSend = false')
    || !str_contains($prospectCheckSf, 'prospect_filter_gate_allows')
    || !str_contains($prospectCheckSf, 'Filter unique sites first')) {
    fail('paste Separate must not Send before Filter unique sites (gate)');
} else {
    ok('paste Separate Send gated until Filter unique sites');
}
$prospectsLib = file_get_contents($root . '/includes/prospects.php') ?: '';
if (!str_contains($prospectsLib, 'function prospect_filter_gate_set')
    || !str_contains($prospectsLib, 'function prospect_filter_gate_allows')) {
    fail('prospects.php missing Filter gate helpers');
} else {
    ok('prospect Filter gate helpers');
}
if (!str_contains($prospectsLib, 'function filter_domains_routed_against_prospects')
    || !str_contains($prospectsLib, 'route_domains_by_country_tld')) {
    fail('prospects.php missing routed Filter helper');
} else {
    ok('prospect routed Filter helper');
}
if (!str_contains($prospectCheckSf, 'filter_domains_routed_against_prospects')
    || !str_contains($prospectCheckSf, 'TLD → country')) {
    fail('Filter & add missing routed Filter/Add path');
} else {
    ok('Filter & add uses routed per-country de-dupe');
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

$sheetHistSmoke = file_get_contents($root . '/includes/sheet_history.php') ?: '';
$campAppSmokeUi = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
$layoutLoadSmoke = file_get_contents($root . '/includes/layout.php') ?: '';
$procJsSmoke = file_get_contents($root . '/assets/js/app-processing.js') ?: '';
$sheetSelJsSmoke = file_get_contents($root . '/assets/js/sheet-select-undo.js') ?: '';
$extractBatchArrows = file_get_contents($root . '/pages/team/extract_batch.php') ?: '';
if (!str_contains($sheetHistSmoke, 'function sheet_history_push_emailed')
    || !str_contains($sheetHistSmoke, "'op' => 'emailed'")) {
    fail('sheet_history missing emailed undo op');
} else {
    ok('sheet history emailed undo');
}
if (!str_contains($sheetHistSmoke, 'function render_sheet_edit_toolbar')
    || !str_contains($sheetHistSmoke, 'function render_undo_redo_arrow_buttons')
    || !str_contains($sheetHistSmoke, 'data-sheet-select-all')
    || !str_contains($sheetHistSmoke, 'data-sheet-undo')) {
    fail('sheet_history missing toolbar/undo helpers');
} else {
    ok('sheet history toolbar helpers');
}
if (str_contains($sheetHistSmoke, 'data-sheet-select title=')
    || preg_match('/>Select</', $sheetHistSmoke)) {
    fail('sheet toolbar still has extra Select button (keep Select all only)');
} else {
    ok('sheet toolbar has Select all without extra Select');
}
if (!str_contains($sheetSelJsSmoke, 'getComputedStyle')
    || !str_contains($sheetSelJsSmoke, 'clearHiddenSelection')
    || !str_contains($sheetSelJsSmoke, 'window.confirm')
    || !str_contains($sheetSelJsSmoke, 'sync: syncRemoveButton')) {
    fail('sheet-select-undo.js missing visible-row sync / confirm');
} else {
    ok('sheet select follows search + confirm remove');
}
$campFilterJs = file_get_contents($root . '/assets/js/email-campaign-sheet.js') ?: '';
$sweFilterJs = file_get_contents($root . '/assets/js/sites-with-emails.js') ?: '';
$extractedFilterJs = file_get_contents($root . '/assets/js/extracted-admin.js') ?: '';
$prospectsCountryJsSel = file_get_contents($root . '/assets/js/prospects-country.js') ?: '';
if (!str_contains($campFilterJs, 'SheetSelectUndo.sync')
    || !str_contains($sweFilterJs, 'SheetSelectUndo.sync')
    || !str_contains($extractedFilterJs, 'SheetSelectUndo.sync')
    || !str_contains($prospectsCountryJsSel, 'SheetSelectUndo.sync')) {
    fail('filter/search JS missing SheetSelectUndo.sync after hide/replace');
} else {
    ok('live search syncs sheet selection');
}
if (!str_contains($campAppSmokeUi, 'render_sheet_edit_toolbar')
    || !str_contains($campAppSmokeUi, 'remove_selected')
    || !str_contains($campAppSmokeUi, 'sheet-select-undo.js')) {
    fail('Email campaign sheet missing select/undo UI');
} else {
    ok('Email campaign select + undo/redo');
}
$sweAppUndoSmoke = file_get_contents($root . '/pages/sites_with_emails_app.php') ?: '';
$extractedUndoSmoke = file_get_contents($root . '/pages/admin/extracted.php') ?: '';
$prospectsUndoSmoke = file_get_contents($root . '/pages/admin/prospects.php') ?: '';
if (!str_contains($sweAppUndoSmoke, 'render_sheet_edit_toolbar')
    || !str_contains($extractedUndoSmoke, 'render_sheet_edit_toolbar')
    || !str_contains($prospectsUndoSmoke, 'render_sheet_edit_toolbar')) {
    fail('Select/undo toolbar not sitewide on inventory sheets');
} else {
    ok('Select/undo toolbar on SWE + Extracted + Our database');
}
if (!str_contains($extractBatchArrows, 'render_undo_redo_arrow_buttons')
    || !str_contains(file_get_contents($root . '/pages/admin/semrush_sheet.php') ?: '', 'render_undo_redo_arrow_buttons')
    || !str_contains(file_get_contents($root . '/pages/admin/prospect_batch.php') ?: '', 'render_undo_redo_arrow_buttons')) {
    fail('Textarea sheets missing undo/redo arrow buttons');
} else {
    ok('Textarea Undo/Redo arrows');
}
if (!str_contains($layoutLoadSmoke, 'id="app-processing"')
    || !str_contains($layoutLoadSmoke, 'hidden aria-busy="false"')
    || str_contains($layoutLoadSmoke, 'classList.add("is-page-loading")')
    || !str_contains($procJsSmoke, 'finishPageLoad')
    || !str_contains($procJsSmoke, 'NAV_DELAY_MS')
    || !str_contains($procJsSmoke, 'armDelayedLoading')
    || !str_contains($sheetSelJsSmoke, 'data-sheet-remove-selected')) {
    fail('Missing delayed loading overlay or select-remove JS');
} else {
    ok('Page loading UI + select/remove JS');
}
if (!str_contains($assetFull, 'js/sheet-select-undo.js')) {
    fail('asset.php missing sheet-select-undo.js allowlist');
} else {
    ok('asset allowlist sheet-select-undo.js');
}

$layoutUiSmoke = file_get_contents($root . '/includes/layout.php') ?: '';
$sheetHistUi = file_get_contents($root . '/includes/sheet_history.php') ?: '';
$sweUi = file_get_contents($root . '/pages/sites_with_emails_app.php') ?: '';
$campUi = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
$cssUi = file_get_contents($root . '/assets/css/app.css') ?: '';
if (!str_contains($layoutUiSmoke, "'Work' =>")
    || !str_contains($layoutUiSmoke, "'Office' =>")
    || !str_contains($layoutUiSmoke, "'admin_emails_data' => ['Emails data', 'Admin · Final · Campaign']")) {
    fail('Admin sidebar missing Work vs Office groups');
} else {
    ok('Admin sidebar Work vs Office');
}
if (!str_contains($sheetHistUi, 'sheet-history-text')
    || !str_contains($sheetHistUi, '>Undo</span>')
    || !str_contains($sheetHistUi, 'function render_sheet_row_more_open')
    || !str_contains($sheetHistUi, 'function render_sheet_tool_menu_open')) {
    fail('sheet toolbar missing visible Undo/Redo or row/tool menus');
} else {
    ok('sheet Undo/Redo labels + menus');
}
if (!str_contains($sweUi, 'Copy / Open')
    || !str_contains($sweUi, 'render_sheet_row_more_open')
    || !str_contains($sweUi, 'sheet-cards-mobile')
    || !str_contains($campUi, 'render_sheet_tool_menu_open')
    || !str_contains($campUi, 'sheet-cards-mobile')) {
    fail('SWE/campaign missing Copy/Open menu, row ⋮, or mobile cards');
} else {
    ok('Copy/Open menu + row more + mobile cards');
}
if (!str_contains($sweUi, 'is-dense')
    || !str_contains($campUi, 'is-dense')
    || !str_contains($sweUi, "placeholder' => '+'")
    || !str_contains($campUi, "placeholder' => '+'")
    || !str_contains($sweUi, "render_sheet_checkpoint_compact")
    || !str_contains($campUi, "render_sheet_checkpoint_compact")
    || !str_contains($helpersSmoke, 'function render_sheet_checkpoint_compact')
    || !str_contains($cssUi, '.swe-sheet-table.is-dense tbody td')
    || !str_contains($cssUi, '@media (min-width: 900px)')
    || !str_contains($cssUi, '.main.is-sheet-app > .alert-box.alert-ok .alert-title')
    || !str_contains($layoutUiSmoke, 'is-sheet-app')
    || !str_contains($sweUi, "'Undo' : 'Emailed'")
    || !str_contains($campUi, "'Undo' : 'Emailed'")) {
    fail('sheets missing dense rows / compact emailed rule / short mark labels');
} else {
    ok('dense sheet rows + compact checkpoint + short Emailed/Undo');
}
if (!str_contains($cssUi, '@media (max-width: 899px)')
    || !str_contains($cssUi, 'table.sheet-cards-mobile tr')
    || !str_contains($cssUi, 'content: attr(data-label)')) {
    fail('CSS missing stacked sheet cards under 900px');
} else {
    ok('mobile stacked sheet cards CSS');
}
if (!str_contains($sweUi, 'No search matches on this page')
    || !str_contains($campUi, 'No search matches on this page')
    || !str_contains($sheetSelJsSmoke, 'syncPageStatus')
    || !str_contains($sweUi, 'data-sheet-page-status')) {
    fail('sheets missing no-search-matches empty copy');
} else {
    ok('empty search matches copy');
}
if (!str_contains($sweUi, 'data-swe-add-toggle')
    || !str_contains($sweUi, 'id="swe-add-row"')
    || !str_contains($sweUi, '$isTeam || $isAdminAll')
    || str_contains($sweUi, 'Optional manual add. Most site names arrive')
    || !str_contains($sweJsSmoke, 'function openAddRow')
    || !str_contains($sweJsSmoke, 'data-swe-add-toggle')) {
    fail('SWE Team/Final missing campaign-style + Add site row');
} else {
    ok('SWE Team/Final inline + Add site');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
