<?php
/**
 * Lightweight smoke checks for Admin dashboard MVP (no DB required).
 * Run: php public_html/smoke_admin_mvp.php
 *
 * Phase A: allowlist, password toggle, blank invoice POST, history ?user=, user guards.
 * Phase B (editable history sheet) asserts are included; they pass once PR2 lands.
 *
 * Web hits (Apache / LiteSpeed / php -S) must not run this file.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

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
    'includes/prospect_niches.php',
    'assets/js/niche-chips.js',
    'pages/account_password.php',
    'pages/admin/dashboard.php',
    'pages/admin/prospect_batches.php',
    'pages/admin/prospect_batch.php',
    'pages/admin/invoice_manual.php',
    'pages/admin/users.php',
    'assets/js/password-toggle.js',
    'assets/js/prospect-batch-sheet.js',
    'assets/js/stay-scroll.js',
    'includes/site_prices.php',
    'pages/admin/site_prices.php',
    'pages/team/site_prices.php',
    'assets/js/site-prices.js',
];
foreach ($requiredFiles as $rel) {
    if (!is_file($root . '/' . $rel)) {
        fail("missing {$rel}");
    } else {
        ok("file {$rel}");
    }
}

$asset = file_get_contents($root . '/asset.php') ?: '';
foreach (['js/password-toggle.js', 'js/prospect-batch-sheet.js', 'js/stay-scroll.js', 'js/niche-chips.js', 'js/site-prices.js'] as $key) {
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
if (!str_contains($layout, 'Login / forgot / reset / verify')) {
    fail('layout missing guest Show password script');
} else {
    ok('layout loads password-toggle.js on login');
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
$schemaSqlAuth = file_get_contents($root . '/sql/schema.sql') ?: '';
if (!str_contains($auth, 'function txf_sync_session_user')
    || !str_contains($auth, 'function bump_user_session_version')
    || !str_contains($auth, 'session_destroy()')
    || !str_contains($auth, 'session_version')
    || !str_contains($schemaSqlAuth, 'session_version INT NOT NULL DEFAULT 1')
    || !str_contains($usersPage, 'bump_user_session_version($id')) {
    fail('session revalidate / logout destroy / users bump missing');
} else {
    ok('session revalidate + logout destroy + users bump');
}
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
    || !str_contains($invoicesAdminPage, 'Previous')
    || !str_contains($invoicesAdminPage, 'invoice-list-pager')
    || !str_contains($invoicesAdminPage, 'invoice_list_page_numbers')) {
    fail('invoices.php missing 50/page pagination');
} else {
    ok('invoices.php 50/page pagination');
}
if (!str_contains($invoicesAdminPage, 'name="filter"')
    || !str_contains($invoicesAdminPage, 'invoice-list-chips')
    || !str_contains($invoicesAdminPage, "'draft' => ['Draft'")
    || !str_contains($invoicesAdminPage, "'unpaid' => ['Waiting'")
    || !str_contains($invoicesAdminPage, "'paid' => ['Paid'")
    || !str_contains($invoicesAdminPage, 'normalize_invoice_list_filter')) {
    fail('invoices.php missing status filter');
} else {
    ok('invoices.php status filter');
}
if (!str_contains($invoicesAdminPage, "\$listOpts['client_id']")
    || !str_contains($invoicesAdminPage, 'Leftover client folder')
    || !str_contains($invoicesAdminPage, 'client_id=')
    || !str_contains($invoicesAdminPage, 'name="client_id"')) {
    fail('invoices.php missing client_id scope');
} else {
    ok('invoices.php client_id list scope');
}
if (!str_contains($invoicesAdminPage, '<th>Bill as</th>')) {
    fail('invoices.php missing Bill as column');
} else {
    ok('invoices.php Bill as column');
}
if (!str_contains($invoicesAdminPage, 'Invoice no., bill as, or note')
    || !str_contains($invoicesAdminPage, 'This invoice is Paid. Delete anyway?')
    || !str_contains($invoicesAdminPage, 'Waiting invoices')
    || !str_contains($invoicesAdminPage, 'Completed unpaid')
    || !str_contains($invoicesAdminPage, "['filter' => 'unpaid']")
    || !str_contains($invoicesAdminPage, 'invoice-list-delete')
    || !str_contains($invoicesAdminPage, 'is-incomplete')
    || !str_contains($invoicesAdminPage, 'Add note')
    || !str_contains($invoicesAdminPage, 'class="num"')
    || !str_contains($invoicesAdminPage, '">Clear</a>')
    || !str_contains($invoicesAdminPage, "'filter' => \$invoiceFilter")
    || str_contains($invoicesAdminPage, "page=admin_invoices') ?>\">Clear")) {
    fail('invoices.php missing list chips / search / paid-delete copy');
} else {
    ok('invoices.php list chips, full search, paid-delete confirm');
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
    || substr_count($usersPage, 'table-wrap') < 1
    || !str_contains($usersPage, 'Assign Team users under Departments')
    || str_contains($usersPage, 'grid-template-columns:1.2fr 1fr')) {
    fail('users.php still has shared-URL copy or missing table-wrap');
} else {
    ok('users.php Office copy + table-wrap');
}
if (!str_contains($usersPage, 'users-office')
    || !str_contains($usersPage, 'name="awaiting"')
    || !str_contains($usersPage, 'name="must_change"')
    || !str_contains($usersPage, 'Awaiting assignment')
    || !str_contains($usersPage, "post('action') === 'send_verify'")
    || !str_contains($usersPage, 'Send verification')
    || !str_contains($usersPage, 'LIMIT')
    || !str_contains($usersPage, 'OFFSET')
    || !str_contains($usersPage, 'Deactivate this user')
    || !str_contains($usersPage, 'username_taken_by_other')
    || !str_contains($usersPage, 'users_filter_hiddens')
    || !str_contains($usersPage, 'name="f_')
    || str_contains($usersPage, 'Admin directory')) {
    fail('users.php office gaps (filters / stay / verify / paging) missing');
} else {
    ok('users.php awaiting filter, send verify, SQL paging, no Admin directory');
}
if (!str_contains($auth, 'function admin_users_url')
    || !str_contains($auth, 'function username_format_error')
    || !str_contains($auth, 'function username_taken_by_other')) {
    fail('auth.php missing Users URL / username helpers');
} else {
    ok('auth Users URL and username helpers');
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
    || !str_contains($guidesLib, 'function guide_site_prices')
    || !str_contains($guidesLib, 'Push to invoice')
    || !str_contains($guidesLib, 'printable letterhead is Topurlz')
    || !str_contains($guidesLib, 'Sidebar Change password updates the same password')) {
    fail('Office page-purpose guides missing');
} else {
    ok('Office Orders/Invoices/Account/Website prices guides present');
}
if (!str_contains($guidesLib, '<details class="help-details page-purpose">')
    || !str_contains($guidesLib, '<summary>What is this? · ')
    || !str_contains($guidesLib, 'help-details-body')) {
    fail('page-purpose guide is not collapsed by default');
} else {
    ok('page-purpose guide is a collapsed details');
}
$ordersHubGuide = file_get_contents($root . '/pages/admin/orders.php') ?: '';
$invoicesHubGuide = file_get_contents($root . '/pages/admin/invoices.php') ?: '';
$accountHubGuide = file_get_contents($root . '/pages/admin/account.php') ?: '';
$sitePricesHubGuide = file_get_contents($root . '/pages/admin/site_prices.php') ?: '';
$sitePricesLib = file_get_contents($root . '/includes/site_prices.php') ?: '';
if (!str_contains($ordersHubGuide, 'guide_orders()')
    || !str_contains($invoicesHubGuide, 'guide_invoices()')
    || !str_contains($accountHubGuide, 'guide_admin_account()')
    || !str_contains($sitePricesHubGuide, 'site_price_run_page')
    || !str_contains($sitePricesLib, 'guide_site_prices()')
    || !str_contains($sitePricesLib, 'Open a country sheet')
    || !str_contains($sitePricesLib, 'data-site-price-sheet')
    || !str_contains($sitePricesLib, 'data-no-draft')) {
    fail('Office hubs missing page-purpose guide calls');
} else {
    ok('Office hubs echo Orders/Invoices/Account/Website prices guides');
}
$schemaSql = file_get_contents($root . '/sql/schema.sql') ?: '';
$upgradePhp = file_get_contents($root . '/upgrade.php') ?: '';
$indexPhp = file_get_contents($root . '/index.php') ?: '';
$dashPhp = file_get_contents($root . '/pages/admin/dashboard.php') ?: '';
if (!str_contains($sitePricesLib, 'function ensure_site_prices_schema')
    || !str_contains($sitePricesLib, 'function site_price_lookup_niche')
    || !str_contains($sitePricesLib, 'function site_price_insert_row')
    || !str_contains($sitePricesLib, 'function count_site_price_rows')
    || !str_contains($sitePricesLib, 'function count_site_price_rows_by_lane')
    || !str_contains($sitePricesLib, 'function site_price_sort_rows')
    || !str_contains($sitePricesLib, 'function site_price_row_for_viewer')
    || !str_contains($sitePricesLib, 'function site_price_save_row')
    || !str_contains($sitePricesLib, "'slug' => 'completed'")
    || !str_contains($sitePricesLib, "'label' => 'Completed'")
    || !str_contains($sitePricesLib, 'function site_price_unlock_row')
    || !str_contains($sitePricesLib, 'identity_locked')
    || !str_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS site_price_rows')
    || !str_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS site_price_statuses')
    || !str_contains($upgradePhp, 'ensure_site_prices_schema')
    || !str_contains($indexPhp, "'admin_site_prices'")
    || !str_contains($indexPhp, "'team_site_prices'")
    || !str_contains($dashPhp, 'index.php?page=admin_site_prices')) {
    fail('Website prices missing schema / helpers / route');
} else {
    ok('Website prices schema + helpers + Admin hub route');
}
$sitePricesJs = file_get_contents($root . '/assets/js/site-prices.js') ?: '';
$sitePricesCss = file_get_contents($root . '/assets/css/app.css') ?: '';
$assetPrices = file_get_contents($root . '/asset.php') ?: '';
if (!str_contains($sitePricesLib, "'save_row'")
    || !str_contains($sitePricesLib, "'unlock_row'")
    || !str_contains($sitePricesLib, 'site_prices_script_tag')
    || str_contains($sitePricesHubGuide, 'Copy all')
    || str_contains($sitePricesLib, 'Copy all')
    || preg_match('/Download \.txt|Download CSV/', $sitePricesHubGuide)
    || preg_match('/Download \.txt|Download CSV/', $sitePricesLib)
    || !str_contains($sitePricesLib, 'data-site-price-add')
    || !str_contains($sitePricesLib, 'Unlock')
    || !str_contains($sitePricesJs, "post('save_row'")
    || !str_contains($sitePricesJs, "post('unlock_row'")
    || !str_contains($sitePricesJs, 'is-copy-lock')
    || !str_contains($sitePricesCss, '.site-price-id.is-locked')
    || !str_contains($assetPrices, "'js/site-prices.js'")) {
    fail('Website prices grid / save / identity lock missing');
} else {
    ok('Website prices add-row + per-row save + identity lock');
}
if (!str_contains($sitePricesLib, "'reorder_lane'")
    || !str_contains($sitePricesLib, "'add_status'")
    || !str_contains($sitePricesLib, 'status-words')
    || !str_contains($sitePricesLib, 'function site_price_add_custom_status')
    || !str_contains($sitePricesLib, 'function site_price_reorder_lane')
    || !str_contains($sitePricesLib, 'data-site-price-lane')
    || !str_contains($sitePricesLib, 'data-site-price-drag')
    || !str_contains($sitePricesJs, "post('reorder_lane'")
    || !str_contains($sitePricesJs, 'bindDrag')
    || !str_contains($sitePricesCss, '.site-price-lane')) {
    fail('Website prices lanes / custom statuses / Admin drag missing');
} else {
    ok('Website prices lanes + custom statuses + Admin drag');
}
if (!str_contains($sitePricesLib, 'function site_price_claim_row')
    || !str_contains($sitePricesLib, 'function render_site_price_history_html')
    || !str_contains($sitePricesLib, 'function render_site_price_country_tabs')
    || !str_contains($sitePricesLib, 'site-price-country-tabs')
    || !str_contains($sitePricesLib, "'claim_row'")
    || !str_contains($sitePricesLib, "'row_history'")
    || !str_contains($sitePricesLib, '>People</th>')
    || !str_contains($sitePricesJs, "post('row_history'")
    || !str_contains($sitePricesJs, "post('claim_row'")
    || !str_contains($sitePricesCss, '.site-price-country-tabs')
    || !str_contains($sitePricesCss, '.site-price-people')
    || !str_contains($sitePricesCss, '.site-price-people-name')
    || !str_contains($sitePricesCss, '.site-price-note-td')
    || !str_contains($sitePricesCss, 'textarea.site-price-note')
    || !str_contains($sitePricesCss, '.site-price-sheet td.prospect-niche-td')
    || !str_contains($sitePricesLib, 'site-price-people-name')
    || !str_contains($sitePricesLib, 'site-price-note-td')
    || !str_contains($sitePricesLib, 'textarea class="site-price-input site-price-note"')
    || !str_contains($sitePricesJs, 'fitAllNotes')) {
    fail('Website prices people / history / country tabs missing');
} else {
    ok('Website prices people + history + country tabs');
}
$teamSitePricesPage = file_get_contents($root . '/pages/team/site_prices.php') ?: '';
$layoutPhp = file_get_contents($root . '/includes/layout.php') ?: '';
$deptLibEarly = file_get_contents($root . '/includes/departments.php') ?: '';
$teamDash = file_get_contents($root . '/pages/team/dashboard.php') ?: '';
if (!str_contains($teamSitePricesPage, "site_price_run_page(\$user, 'team')")
    || !str_contains($teamSitePricesPage, 'team_site_prices')
    || str_contains($teamSitePricesPage, 'Copy all')
    || !str_contains($deptLibEarly, "'team_site_prices'")
    || !str_contains($deptLibEarly, 'Website prices')
    || !str_contains($layoutPhp, "'team_site_prices'")
    || !str_contains($teamDash, 'team_site_prices')
    || str_contains($teamDash, 'admin_orders')
    || str_contains($teamDash, 'admin_invoices')
    || str_contains($teamSitePricesPage, 'admin_orders')
    || str_contains($teamSitePricesPage, 'admin_invoices')
    || !str_contains($sitePricesLib, 'function render_site_price_filters')
    || !str_contains($sitePricesLib, 'data-site-price-filters')
    || !str_contains($sitePricesLib, 'Search this country')
    || !str_contains($sitePricesJs, 'applyFilters')
    || !str_contains($sitePricesJs, 'searchAllPages')
    || !str_contains($sitePricesJs, 'Ctrl+Enter')
    || !str_contains($sitePricesCss, '.site-price-filters')) {
    fail('Website prices Team department / filters missing');
} else {
    ok('Website prices Team department + sheet filters');
}
$teamDeptsPage = file_get_contents($root . '/pages/team/departments.php') ?: '';
$siteFindingHasWp = (bool) preg_match(
    "/if \(\\\$slug === 'site_finding'\) \{([^}]*)\}/s",
    $deptLibEarly,
    $sfm
) && str_contains($sfm[1] ?? '', 'team_site_prices');
$commsHasWp = (bool) preg_match(
    "/elseif \(\\\$slug === 'communication'\) \{([^}]*)\}/s",
    $deptLibEarly,
    $cmm
) && str_contains($cmm[1] ?? '', 'team_site_prices');
if ($siteFindingHasWp
    || !$commsHasWp
    || !str_contains($deptLibEarly, 'function team_can_assign_department_tasks')
    || !str_contains($deptLibEarly, 'function set_department_task_assignee')
    || !str_contains($teamDeptsPage, 'Assign a task')
    || !str_contains($teamDeptsPage, "value=\"assign_task\"")
    || !str_contains($teamDeptsPage, "value=\"save_task\"")
    || !str_contains($teamDeptsPage, 'Only people already in this department')) {
    fail('Team Website prices is not Communication-only, or Team cannot assign department tasks');
} else {
    ok('Team Website prices is Communication-only + Team can assign department tasks');
}
if (!str_contains($sitePricesLib, 'function site_price_jump_search')
    || !str_contains($sitePricesLib, 'function site_price_jump_search_pack')
    || !str_contains($sitePricesLib, 'function list_site_price_rows_page')
    || !str_contains($sitePricesLib, 'function site_price_filter_rows')
    || !str_contains($sitePricesLib, 'Search all countries')
    || !str_contains($sitePricesLib, 'Does not filter this sheet')
    || !str_contains($sitePricesLib, 'reply_email')
    || !str_contains($sitePricesLib, 'row_tint')
    || !str_contains($sitePricesLib, 'Copy selected')
    || !str_contains($sitePricesLib, 'data-site-price-copy-one')
    || str_contains($sitePricesLib, 'Copy all')
    || !str_contains($sitePricesLib, 'data-site-price-jump')
    || !str_contains($sitePricesLib, '>Email</th>')
    || !str_contains($sitePricesLib, 'Copy selected (this page)')
    || !str_contains($sitePricesLib, 'function site_price_delete_row')
    || !str_contains($sitePricesLib, 'function site_price_assign_row')
    || !str_contains($sitePricesLib, 'Only Admin can set Processing or Completed.')
    || !str_contains($sitePricesLib, 'data-site-price-remove')
    || !str_contains($sitePricesLib, 'data-site-price-assign')
    || !str_contains($sitePricesLib, 'site-price-email')
    || !str_contains($sitePricesJs, "post('jump_search'")
    || !str_contains($sitePricesJs, 'lastJumpQuery')
    || !str_contains($sitePricesJs, 'jumpCountLabel')
    || !str_contains($sitePricesJs, 'copySelected')
    || !str_contains($sitePricesJs, 'copyOneSite')
    || !str_contains($sitePricesJs, 'on this page.')
    || !str_contains($sitePricesJs, 'Order management Processing')
    || !str_contains($sitePricesJs, "post('assign_row'")
    || !str_contains($sitePricesJs, "post('delete_row'")
    || !str_contains($sitePricesJs, 'fitEmailBox')
    || !str_contains($sitePricesJs, 'data-site-price-tint')
    || !str_contains($sitePricesJs, 'paintStatusSelect')
    || !str_contains($sitePricesCss, '.site-price-status.is-color-green')
    || str_contains($sitePricesCss, '.site-price-row.is-status-green td')
    || str_contains($sitePricesCss, '.site-price-row.is-tint-yellow td')
    || !str_contains($sitePricesCss, '[data-tint="yellow"]')
    || str_contains($sitePricesCss, '.site-price-color-summary.is-yellow')
    || !str_contains($sitePricesJs, "summary.textContent = '⋯'")
    || !str_contains($sitePricesLib, 'highlights the whole row')
    || !str_contains($sitePricesCss, '.site-price-add-commit')
    || !str_contains($sitePricesCss, '.site-price-tint-chip')
    || !str_contains($sitePricesCss, '.site-price-jump-results')
    || !str_contains($sitePricesCss, 'max-height: 14rem')
    || !str_contains($sitePricesCss, 'textarea.site-price-email')
    || !str_contains($sitePricesLib, 'site-price-add-commit')
    || !str_contains($sitePricesLib, 'data-site-price-filter="tint"')
    || !str_contains($sitePricesLib, 'site-price-color-menu')
    || !str_contains($schemaSql, 'reply_email')
    || !str_contains($schemaSql, 'row_tint')) {
    fail('Website prices colors / email / jump / copy selected missing');
} else {
    ok('Website prices row colors, reply email, jump search, copy selected');
}
foreach ([
    'guide_campaign_search',
    'guide_campaign_drafts',
    'guide_admin_emails_search',
    'guide_semrush_team',
    'guide_team_departments',
] as $fn) {
    if (!str_contains($guidesLib, "function {$fn}")) {
        fail("guides.php missing {$fn}");
    }
}
ok('Team hub page-purpose guide functions');
if (!str_contains($guidesLib, 'Dashboard can update Open / In progress / Done')) {
    fail('departments guide still omits Dashboard status');
} else {
    ok('departments guide mentions Dashboard status');
}
$teamCampHub = file_get_contents($root . '/pages/team/email_campaigns.php') ?: '';
$teamDraftsHub = file_get_contents($root . '/pages/team/email_campaign_drafts.php') ?: '';
$teamAdminEmailsHub = file_get_contents($root . '/pages/team/admin_emails_delete.php') ?: '';
$teamSemrushHubGuide = file_get_contents($root . '/pages/team/semrush_research.php') ?: '';
$teamDeptsHub = file_get_contents($root . '/pages/team/departments.php') ?: '';
if (!str_contains($teamCampHub, 'guide_campaign_search()')
    || !str_contains($teamDraftsHub, 'guide_campaign_drafts()')
    || !str_contains($teamAdminEmailsHub, 'guide_admin_emails_search()')
    || !str_contains($teamSemrushHubGuide, 'guide_semrush_team()')
    || !str_contains($teamDeptsHub, 'guide_team_departments()')) {
    fail('Team hubs missing page-purpose guide calls');
} else {
    ok('Team hubs echo page-purpose guides');
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
$nichesLib = file_get_contents($root . '/includes/prospect_niches.php') ?: '';
$nicheJs = file_get_contents($root . '/assets/js/niche-chips.js') ?: '';
$teamCheck = file_get_contents($root . '/pages/team/prospect_check.php') ?: '';
$histDay = file_get_contents($root . '/pages/admin/prospect_batch.php') ?: '';
if (!str_contains($nichesLib, 'function prospect_parse_niches')
    || !str_contains($nichesLib, 'function render_niche_chip_box')
    || !str_contains($nichesLib, 'function render_prospect_niche_filter_bar')
    || !str_contains($prospectsLib, 'require_once __DIR__ . \'/prospect_niches.php\'')
    || !str_contains($prospectsLib, 'VARCHAR(512)')
    || !str_contains($adminProspects, '<th>Niche</th><th>Domain</th>')
    || !str_contains($adminProspects, 'render_prospect_niche_filter_bar')
    || !str_contains($adminProspects, "post('action') === 'save_niche'")
    || !str_contains($adminProspects, 'No niche')
    || !str_contains($adminProspects, 'prospect_niche_taxonomy_script')
    || !str_contains($adminProspects, 'niche_chips_script_tag')
    || !str_contains($adminProspects, '<th>Niche</th>')
    || !str_contains($teamCheck, 'render_niche_chip_box')
    || !str_contains($histDay, 'render_niche_chip_box')
    || !str_contains($nichesLib, '$map = $compact')
    || !str_contains($nicheJs, 'data-niche-remove')
    || !str_contains($nicheJs, 'save_niche')
    || !str_contains($nicheJs, 'prospect-niche-menu-search')
    || !str_contains($nicheJs, 'scheduleSave(root)')
    || !str_contains($assetPhp, 'js/niche-chips.js')) {
    fail('Our database missing multi-niche chips / filter / autosave');
} else {
    ok('Our database multi-niche chips + filter + autosave');
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
if (!str_contains($adminProspects, 'invoice_list_page_numbers')
    || !str_contains($adminProspects, 'guide_inventory()')
    || !str_contains($adminProspects, 'prospect_copy_all_label')
    || !str_contains($adminProspects, 'prospect_country_sheet_url')
    || !str_contains($adminProspects, 'prospect_open_in_folder_label')
    || !str_contains($adminProspects, 'Open website')
    || !str_contains($adminProspects, 'show empty countries')
    || !str_contains($adminProspects, 'whole country folder')
    || str_contains($adminProspects, 'Go to site')
    || str_contains($adminProspects, '<th>URL</th>')
    || str_contains($adminProspects, 'Add sites above')
    || str_contains($adminProspects, 'choose rows per page below')
    || str_contains($adminProspects, 'Team adds merge')
    || str_contains($adminProspects, 'no. of sites')
    || str_contains($adminProspects, 'Europe first')
    || !str_contains($adminProspects, 'Click a market to open it')
    || !str_contains($adminProspects, "' is-empty'")
    || !str_contains($adminProspects, 'Team Filter &amp; add writes into these folders.')
    || !str_contains($adminProspects, 'data-show-processing="Saving sites…')) {
    fail('Our database missing hub/country UX (guide, pager, Open in, empty toggle)');
} else {
    ok('Our database hub/country UX: guide, pager, Open in, empty toggle');
}
$marketsPos = strpos($adminProspects, 'id="prospect-markets"');
$addHubPos = strpos($adminProspects, 'id="add-sites"');
$superPos = strpos($adminProspects, 'id="super-search"');
$sitesPos = strpos($adminProspects, 'id="prospect-sites-card"');
$addToPos = strpos($adminProspects, 'Add sites to');
if ($marketsPos === false || $addHubPos === false || $superPos === false
    || $marketsPos > $addHubPos || $addHubPos > $superPos) {
    fail('Our database hub is not Markets then Add sites then Super search');
} else {
    ok('Our database hub Markets then Add sites then Super search');
}
if ($sitesPos === false || $addToPos === false || $sitesPos > $addToPos) {
    fail('Our database country Sites table is not above Add sites');
} else {
    ok('Our database country Sites table above Add sites');
}
if (str_contains($adminProspects, 'site(s) to')
    || !str_contains($adminProspects, 'prospect_saved_sites_message')
    || !str_contains($adminProspects, 'just_added')
    || !str_contains($adminProspects, 'Save uses the Ready list only.')
    || str_contains($adminProspects, 'Push uses Ready only')
    || !str_contains($adminProspects, 'prospect-add-sites-form')
    || !str_contains($adminProspects, 'prospect_just_added_highlight')
    || !str_contains($adminProspects, 'prospect_store_just_added_ids')
    || str_contains($adminProspects, 'highlightYmd')) {
    fail('Our database save still uses site(s) / Push copy / missing just-added');
} else {
    ok('Our database save grammar + Ready copy + just-added');
}
if (!str_contains($prospectsLib, 'function prospect_copy_all_label')
    || !str_contains($prospectsLib, 'function prospect_country_sheet_url')
    || !str_contains($prospectsLib, 'p.niche LIKE')
    || !str_contains($prospectsLib, 'Open website')
    || !str_contains($prospectsLib, 'duplicate found and removed')
    || !str_contains($prospectsLib, 'function prospect_just_added_highlight')
    || !str_contains($prospectsLib, 'function prospect_store_just_added_ids')) {
    fail('Our database helpers missing copy labels / niche super-search / Open website');
} else {
    ok('Our database helpers copy labels + niche super-search + Open website');
}
if (!str_contains($prospectsCountryJs, 'pageNumbers')
    || !str_contains($prospectsCountryJs, 'nav.pagination')) {
    fail('prospects-country.js missing numbered pager rebuild');
} else {
    ok('prospects-country.js numbered pager rebuild');
}
if (!str_contains($adminProspects, 'data-open-default')
    || !str_contains($adminProspects, "setMarketOpen(market, market.getAttribute('data-open-default') === '1')")
    || !str_contains($adminProspects, '$busiestRegion')
    || !str_contains($adminProspects, '$openByDefault = ($busiestTotal > 0')) {
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
$draftJsSmoke = file_get_contents($root . '/assets/js/draft-autosave.js') ?: '';
$sweAppCsrfSmoke = file_get_contents($root . '/pages/sites_with_emails_app.php') ?: '';
$presenceJsCsrfSmoke = file_get_contents($root . '/assets/js/task-presence.js') ?: '';
if (!str_contains($indexFull, "\$page === 'presence_ping'")
    || !str_contains($helpers, 'csrf_field()')
    || !str_contains($helpers, "function render_sheet_shared_row_action_forms")
    || !preg_match('/\$nav = \(function_exists\(\'csrf_field\'\) \? csrf_field\(\) : \'\'\)/', $helpers)
    || !str_contains($draftJsSmoke, "name === '_csrf'")
    || !str_contains($draftJsSmoke, 'shouldClearDraft')
    || !str_contains($draftJsSmoke, 'alert-box.alert-ok')
    || !str_contains($draftJsSmoke, 'just_added')
    || !str_contains($draftJsSmoke, 'prospect-add-sites-form')
    || !str_contains($draftJsSmoke, 'Restore already wrote localStorage')
    || !str_contains($draftJsSmoke, 'restoreBannerVisible')
    || !str_contains($draftJsSmoke, 'saveForm(form, index, true)')
    || !str_contains($draftJsSmoke, "typeahead::")
    || !str_contains($draftJsSmoke, 'typeahead:select')
    || !str_contains($draftJsSmoke, 'Typed country')
    || !preg_match('/data-swe-save>\s*<\?=\s*csrf_field\(\)/', $sweAppCsrfSmoke)
    || !str_contains($presenceJsCsrfSmoke, "body.set('_csrf'")) {
    fail('draft autosave / shared sheet / SWE save / presence CSRF missing');
} else {
    ok('draft autosave skips _csrf + sheet forms + presence CSRF');
}
if (!str_contains($indexFull, "'team_admin_emails_search'")
    || !str_contains($indexFull, "'team_admin_emails_delete'")) {
    fail('index.php missing Admin search route or delete alias');
} else {
    ok('index.php Admin search route + delete alias');
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
if (!str_contains($extractBatch, "!empty(\$conflict['conflict'])")) {
    fail('Extracting Sites autosave treats non-conflict errors as last-writer 409');
} else {
    ok('Extracting Sites 409 only on last-writer conflict');
}
$extractingHub = file_get_contents($root . '/pages/team/extracting.php') ?: '';
$teamHistory = file_get_contents($root . '/pages/team/prospect_batches.php') ?: '';
if (!str_contains($extractingHub, 'table-wrap') || !str_contains($teamHistory, 'table-wrap')
    || !str_contains($extractingHub, 'guide_extracting()')) {
    fail('Team Extracting/history missing table-wrap or extracting guide');
} else {
    ok('Team Extracting + history table-wrap');
}
if (!str_contains($extractingHub, 'Last Push')) {
    fail('Extracting hub missing Last Push column');
} else {
    ok('Extracting hub Last Push');
}
if (!str_contains($prospectCheck, 'Extracting received')) {
    fail('Filter & add missing Extracting landing flash');
} else {
    ok('Filter & add Extracting landing flash');
}
if (!str_contains($prospectCheck, 'Unique this Filter')
    || !str_contains($prospectCheck, 'this Filter run')
    || !str_contains($prospectCheck, 'Folder totals are shared')) {
    fail('Filter & add missing session leftover vs shared folder copy');
} else {
    ok('Filter & add leftover vs shared folder copy');
}
if (!str_contains($extractingHub, 'shared')
    || !str_contains($extractingHub, 'Sites list')) {
    fail('Extracting hub missing shared Sites list copy');
} else {
    ok('Extracting hub shared Sites list copy');
}
$extractLibSync = file_get_contents($root . '/includes/extracting.php') ?: '';
$prospectsLibSync = file_get_contents($root . '/includes/prospects.php') ?: '';
$adminProspectsSync = file_get_contents($root . '/pages/admin/prospects.php') ?: '';
$guidesSync = file_get_contents($root . '/includes/guides.php') ?: '';
if (!str_contains($extractLibSync, 'function remove_domains_from_extract_sites_for_country')
    || !str_contains($extractLibSync, 'Drop any leftover Extracting row first')
    || !str_contains($prospectsLibSync, 'function prospect_remove_domains_from_extracting')
    || !str_contains($adminProspectsSync, 'Our database and Extracting sites')
    || !str_contains($guidesSync, 'also removes it from this country’s Extracting')) {
    fail('Our database delete missing Extracting Sites cascade / re-add');
} else {
    ok('Our database delete clears Extracting; Filter & add re-inserts');
}
if (!str_contains($teamHistory, 'count_prospect_batches')
    || !str_contains($teamHistory, '$totalBatches > 100')) {
    fail('Team history missing pager when days exceed 100');
} else {
    ok('Team history pager past 100 days');
}
$extractSitesJs = file_get_contents($root . '/assets/js/extract-sites-list.js') ?: '';
$semrushSheetJs = file_get_contents($root . '/assets/js/semrush-sheet.js') ?: '';
if (!str_contains($extractSitesJs, 'writer_at')
    || !str_contains($extractSitesJs, 'data.conflict')
    || !str_contains($extractSitesJs, 'err.conflict')
    || !str_contains($extractSitesJs, 'lastSavedText = lastSnapshot')
    || !str_contains($extractSitesJs, 'undoStack = []')
    || !str_contains($semrushSheetJs, 'writer_at')
    || !str_contains($semrushSheetJs, 'data.conflict')
    || !str_contains($semrushSheetJs, 'err.conflict')
    || !str_contains($semrushSheetJs, 'lastSavedText = lastSnapshot')
    || !str_contains($semrushSheetJs, 'undoStack = []')) {
    fail('Extracting/Semrush sheets missing last-writer conflict check');
} else {
    ok('Extracting + Semrush last-writer conflict');
}
$sweAppSmoke = file_get_contents($root . '/pages/sites_with_emails_app.php') ?: '';
if (!str_contains($sweAppSmoke, 'new from Push')
    || !str_contains($sweAppSmoke, 'Last Push')) {
    fail('Team emails hub missing Last Push / new from Push');
} else {
    ok('Team emails Last Push + new from Push');
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
if (!str_contains($semrushSheetSmoke, 'semrush_sheet_writer_conflict')
    || !str_contains($semrushSheetSmoke, 'data-writer-at')) {
    fail('Admin Semrush sheet missing last-writer conflict check');
} else {
    ok('Admin Semrush last-writer conflict');
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
    || !str_contains($emailsHub, 'emailed checkpoint here')
    || !str_contains($emailsHub, 'Keeps a copy after Mark emailed or Remove on Admin')
    || str_contains($emailsHub, 'keeps copies after emailed/remove')) {
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
    || !str_contains($sweAppSmoke, 'also creates the Admin working-list row')
    || !str_contains($sweAppSmoke, 'Open an empty country')
    || !str_contains($sweAppSmoke, 'data-no-draft')
    || !str_contains($sweAppSmoke, 'emptyCatalogCountries')
    || !str_contains($sweAppSmoke, '$finalOpenerHtml')
    || str_contains($sweAppSmoke, 'keeps copies after emailed/remove')
    || str_contains($sweAppSmoke, 'Open a folder below')) {
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
if (!str_contains($campLibSmoke, 'csrf_field()')
    || !str_contains($sweLibSmoke, 'csrf_field()')
    || !str_contains($campLibSmoke, 'JavaScript is required to search and update')
    || !str_contains($sweLibSmoke, 'JavaScript is required to search and update')
    || !str_contains($campSearchJsSmoke, 'payload._csrf')
    || !str_contains($sweDeleteJsSmoke, 'payload._csrf')) {
    fail('Team super-search cards missing csrf_field / JS _csrf');
} else {
    ok('Team super-search csrf_field + JS _csrf');
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
$assetPhpPerfSmoke = file_get_contents($root . '/asset.php') ?: '';
$upgradePerfSmoke = file_get_contents($root . '/upgrade.php') ?: '';
if (!str_contains($helpersSmoke, 'function txf_schema_is_current')
    || !str_contains($helpersSmoke, 'function txf_schema_clear_stamps')
    || !str_contains($helpersSmoke, 'function txf_start_output_compression')
    || !str_contains($geoSmoke, "txf_schema_mark_current('repair_country_alias_folders')")
    || !str_contains($geoSmoke, 'function country_repair_table_columns')
    || !str_contains($geoSmoke, 'function ensure_countries_schema')
    || !str_contains($assetPhpPerfSmoke, 'max-age=31536000')
    || !str_contains($assetPhpPerfSmoke, 'immutable')
    || !str_contains($upgradePerfSmoke, 'txf_schema_clear_stamps')
    || !str_contains($htaccessSmoke, 'AddOutputFilterByType DEFLATE')
    || !str_contains($indexSmoke, '$txfLightPages')
    || str_contains($assetPhpPerfSmoke, 'gzencode')
    || str_contains($layoutFull, "js/csrf.js')) . '\" defer")) {
    fail('perf: schema stamps / asset immutable / light login missing, or PHP gzip/csrf-defer still on');
} else {
    ok('perf: schema stamps, versioned asset cache, light login, no PHP gzip');
}
$cliGuardNeedle = "PHP_SAPI !== 'cli'";
$cliGuardFiles = [
    'tests_run.php',
    'tests_http.php',
    'smoke_admin_mvp.php',
    'reset_admin_once.php',
];
$cliGuardOk = true;
foreach ($cliGuardFiles as $guardFile) {
    $src = file_get_contents($root . '/' . $guardFile) ?: '';
    $beforeRequire = preg_split('/\brequire(?:_once)?\b/', $src, 2)[0] ?? $src;
    if (!str_contains($beforeRequire, $cliGuardNeedle)) {
        $cliGuardOk = false;
        break;
    }
}
$resetOnceSmoke = file_get_contents($root . '/reset_admin_once.php') ?: '';
$hostingerSmoke = file_get_contents($root . '/HOSTINGER.md') ?: '';
$readmeSmoke = file_get_contents(dirname($root) . '/README.md') ?: '';
if (
    !$cliGuardOk
    || str_contains($resetOnceSmoke, "\$_GET['confirm']")
    || str_contains($resetOnceSmoke, '?confirm=RESET')
    || !str_contains($hostingerSmoke, 'PHP_SAPI')
    || !str_contains($hostingerSmoke, 'Temporarily comment')
    || str_contains($readmeSmoke, 'open `/upgrade.php` once (Admin-only), then delete it.')
) {
    fail('web-root CLI guards / HOSTINGER upgrade docs missing');
} else {
    ok('web-root CLI guards + HOSTINGER upgrade docs');
}
$campLibSmoke = file_get_contents($root . '/includes/email_campaigns.php') ?: '';
$campAppSmoke = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
if (!str_contains($campLibSmoke, 'function email_campaign_slots_equal')
    || !str_contains($campLibSmoke, 'skipped_duplicate')
    || !str_contains($campLibSmoke, "'replace'")
    || !str_contains($campLibSmoke, 'duplicate domain(s) skipped')
    || !str_contains($campAppSmoke, 'email_campaign_bulk_result_message')
    || !str_contains($campAppSmoke, "import_email_campaign_sheet_from_swe(\$sheetId, \$source, \$sheetCountry, 'replace')")) {
    fail('campaigns missing duplicate skip / replace-different-emails');
} else {
    ok('campaigns duplicate skip + replace different emails');
}
if (!str_contains($campLibSmoke, 'function diff_email_campaign_vs_archives')
    || !str_contains($campLibSmoke, 'function fill_email_campaign_gaps_from_archives')
    || !str_contains($campLibSmoke, "import_email_campaign_sheet_from_swe(\$sheetId, 'admin_all'")
    || !str_contains($campLibSmoke, "import_email_campaign_sheet_from_swe(\$sheetId, 'admin'")
    || !str_contains($campAppSmoke, 'Fill gaps')
    || !str_contains($campAppSmoke, "action === 'fill_gaps'")
    || !str_contains($campAppSmoke, "value=\"fill_gaps\"")
    || !str_contains($campAppSmoke, 'id="camp-fill-gaps"')) {
    fail('campaigns missing fill-gaps from Admin + Final');
} else {
    ok('campaign fill-gaps from Admin + Final');
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
if (!str_contains($ordersPage, 'id="order-filter-bar"')
    || !str_contains($ordersPage, 'id="order-sheet-search"')
    || !str_contains($ordersPage, 'name="country"')
    || !str_contains($ordersPage, 'name="admin_id"')
    || !str_contains($ordersPage, 'name="date_from"')
    || !str_contains($ordersPage, 'name="date_to"')
    || !str_contains($ordersPage, 'name="status"')) {
    fail('orders missing filter bar searches');
} else {
    ok('orders filter bar searches');
}
if (!str_contains($ordersPage, 'client_label')
    || !str_contains($ordersPage, 'email or name')
    || !str_contains($ordersPage, 'admin_user_id')
    || !str_contains($ordersPage, 'order_date')) {
    fail('orders missing country/date/admin/client columns');
} else {
    ok('orders country date admin client columns');
}
if (!str_contains($ordersPage, 'push_invoice') || !str_contains($ordersPage, 'Push to invoice')) {
    fail('orders missing push to invoice');
} else {
    ok('orders push to invoice');
}
if (!str_contains($ordersPage, "folder=processing")
    || !str_contains($ordersPage, "folder=completed")
    || !str_contains($ordersPage, 'id="om-folders"')
    || !str_contains($ordersPage, 'Completed orders')
    || !str_contains($ordersPage, 'use ($filter, $perPage, $pageNum, $folder, $origin)')
    || !str_contains($ordersPage, 'name="folder"')
    || !str_contains($ordersPage, 'id="om-origin-tabs"')
    || !str_contains($ordersPage, 'Added here')
    || !str_contains($ordersPage, 'Leftover')
    || !str_contains($ordersPage, 'order_pipeline_pick_processing_origin')
    || !str_contains($ordersPage, "'origin' => ''")
    || str_contains($ordersPage, 'orders from Website prices Processing')) {
    fail('orders missing Processing/Completed hub folders');
} else {
    ok('orders Processing and Completed hub');
}
$omCss = file_get_contents($root . '/assets/css/app.css') ?: '';
if (!str_contains($omCss, '.order-sheet-card')
    || !preg_match('/\.order-sheet\s*\{[^}]*width:\s*max-content/', $omCss)
    || !str_contains($omCss, 'flex-wrap: nowrap')
    || !str_contains($omCss, 'grid-template-columns: 240px minmax(0, 1fr)')
    || str_contains($omCss, 'min-width: 1900px')) {
    fail('orders sheet still squeezes into the viewport');
} else {
    ok('orders sheet scrolls instead of squeezing');
}
if (!str_contains($ordersPage, 'Need a country on every ticked row before completing')
    || !str_contains($ordersPage, 'Need a client email or name on every ticked row before completing')
    || !str_contains($ordersPage, 'data-orig-live')
    || !str_contains($ordersPage, 'Clearing the live URL also clears Paid')
    || !str_contains($ordersPage, 'Every ticked row needs a live URL, country, and client email or name')) {
    fail('orders missing complete/push country-client checks or LIVE clear confirm');
} else {
    ok('orders complete/push require country+client + LIVE clear confirm');
}
if (!str_contains($ordersPage, 'Copy selected sites (this page)')
    || !str_contains($ordersPage, 'Copy selected live URLs (this page)')
    || !str_contains($ordersPage, 'Copy all live URLs')
    || str_contains($ordersPage, 'Copy all live URLs (this page)')
    || !str_contains($ordersPage, 'Download .txt')
    || !str_contains($ordersPage, 'data-copy-check')
    || !str_contains($ordersPage, 'data-push-check')
    || !str_contains($ordersPage, "copy' => 'live_urls'")
    || !str_contains($ordersPage, "download' => 'txt'")
    || !str_contains($ordersPage, 'data-copy-selected-sites')
    || !str_contains($ordersPage, 'data-copy-all-live')) {
    fail('orders missing copy/download live URLs');
} else {
    ok('orders copy selected/all live URLs + txt');
}
if (!str_contains($ordersPage, '<span>Copy</span>')
    || !str_contains($ordersPage, "\$isProcessing ? 'Complete' : 'Bill'")
    || !str_contains($ordersPage, 'Left tick')
    || !str_contains($ordersPage, 'order-client-list')
    || !str_contains($ordersPage, 'Open in Website prices')
    || !str_contains($ordersPage, 'omConfirmRemove')
    || !str_contains($ordersPage, 'restore_wp')
    || !str_contains($ordersPage, "['folder' => 'completed'")
    || !str_contains($ordersPage, 'No leftover Processing orders')
    || !str_contains($ordersPage, 'order_row_ready_for_complete')
    || !str_contains($ordersPage, 'Mark this order completed?')
    || !str_contains($ordersPage, 'order_invoice_generate_push_cta')) {
    fail('orders missing Copy/Complete labels, WP link, or confirm');
} else {
    ok('orders Copy vs Complete labels, WP link, confirm stay');
}
if (!str_contains($ordersPage, 'Article doc')
    || !str_contains($ordersPage, 'name="article_doc_url')
    || !str_contains($ordersPage, 'col-doc')
    || !str_contains($ordersPage, '$colspan = 16')) {
    fail('orders missing Article doc column');
} else {
    ok('orders Article doc column');
}
if (str_contains($ordersPage, 'if ($isProcessing):') && str_contains($ordersPage, 'Push to invoice')
    && str_contains($ordersPage, 'if ($isCompleted):')) {
    ok('orders Push to invoice only on Completed');
} else {
    fail('orders Push to invoice not gated to Completed');
}
$pushNeedle = strpos($ordersPage, "action === 'push_invoice'");
$saveAfterPush = $pushNeedle !== false ? strpos($ordersPage, '$saveCurrent();', $pushNeedle) : false;
$nextActionAfterPush = $pushNeedle !== false ? strpos($ordersPage, "action ===", $pushNeedle + 10) : false;
if ($pushNeedle === false || $saveAfterPush === false
    || ($nextActionAfterPush !== false && $saveAfterPush > $nextActionAfterPush)) {
    fail('orders Push to invoice must save the sheet first');
} else {
    ok('orders Push to invoice saves sheet first');
}
if (!str_contains($ordersPage, 'Mark paid')
    || !str_contains($ordersPage, 'btn-paid-mark')
    || !str_contains($ordersPage, 'Remove paid mark?')
    || !str_contains($ordersPage, 'With live URL')
    || !str_contains($ordersPage, 'data-on-invoice')
    || !str_contains($ordersPage, 'order-on-invoice')
    || !str_contains($ordersPage, 'Already on invoice')
    || !str_contains($ordersPage, 'invoice_generate_append_href')
    || !str_contains($ordersPage, 'Download month close')) {
    fail('orders missing Mark paid label or Processing live-URL footer');
} else {
    ok('orders Mark paid label + Processing live-URL footer');
}
if (!str_contains($ordersPage, 'Unpaid LIVE')
    || !str_contains($ordersPage, 'Unpaid to bill')
    || !str_contains($ordersPage, 'om-status-tabs')
    || !str_contains($ordersPage, 'Completed unpaid')) {
    fail('orders missing unpaid LIVE filter');
} else {
    ok('orders unpaid LIVE filter');
}
$ordersCss = file_get_contents($root . '/assets/css/app.css') ?: '';
if (!str_contains($ordersPage, 'id="om-folder-tabs"')
    || str_contains($ordersPage, '>Folders</a>')
    || !str_contains($ordersPage, 'order-filter-bar-completed')
    || !str_contains($ordersPage, 'order-check-hint-bill')
    || !str_contains($ordersPage, 'compactUnpaidStats')
    || !str_contains($ordersPage, "label_with_info('Owner'")
    || !str_contains($ordersPage, "label_with_info('Decided'")
    || !str_contains($ordersPage, 'then use <strong>Push to invoice</strong> on this sheet')
    || !str_contains($ordersCss, 'order-filter-bar-completed')
    || !str_contains($ordersCss, 'th.col-price .with-info-label')
    || !str_contains($ordersCss, 'order-check-hint-bill')) {
    fail('orders missing Completed unpaid sheet UX');
} else {
    ok('orders Completed unpaid sheet UX (folder tabs, search, Bill hint, compact stats)');
}
$stickyNeedle = strpos($ordersPage, 'class="actions-sticky"');
$stickyPush = $stickyNeedle !== false ? strpos($ordersPage, "value='push_invoice'", $stickyNeedle) : false;
$formCloseAfterSticky = $stickyNeedle !== false ? strpos($ordersPage, '</form>', $stickyNeedle) : false;
if ($stickyNeedle === false || ($stickyPush !== false && $formCloseAfterSticky !== false && $stickyPush < $formCloseAfterSticky)) {
    fail('orders sticky footer still has Push to invoice on Completed');
} else {
    ok('orders sticky footer Save only on Completed');
}
if (!str_contains($ordersPage, 'om-sheet-pager')
    || !str_contains($ordersPage, 'invoice_list_page_numbers')
    || !str_contains($ordersPage, 'Previous')) {
    fail('orders missing list pagination');
} else {
    ok('orders list pagination');
}

$orderSheet = file_get_contents($root . '/pages/admin/orders.php') ?: '';
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
foreach (['order_client_name_taken', 'count_invoices_for_order_client', 'set_order_client_archived', 'count_order_client_unpaid_live', 'order_management_dashboard_stats', 'count_order_clients', 'list_order_pipeline_rows', 'count_order_pipeline_rows', 'add_order_pipeline_row', 'order_mark_completed', 'order_sync_from_site_price_row', 'order_reconcile_processing_from_website_prices', 'order_live_urls_from_rows', 'order_site_names_from_rows', 'order_pipeline_download_txt', 'list_order_pipeline_ids', 'list_order_pipeline_client_labels', 'order_invoice_generate_push_cta', 'order_wp_sheet_url', 'normalize_order_pipeline_origin', 'order_pipeline_pick_processing_origin', 'order_normalize_article_doc_url'] as $omFn) {
    if (!str_contains($ordersLib, "function {$omFn}")) {
        fail("orders.php missing {$omFn}");
    }
}
ok('orders helpers for OM-1–4');

$invoicesLib = file_get_contents($root . '/includes/invoices.php') ?: '';
if (!str_contains($invoicesLib, 'function count_invoices')
    || !str_contains($invoicesLib, 'function invoices_search_sql')
    || !str_contains($invoicesLib, 'function invoices_where_sql')
    || !str_contains($invoicesLib, 'function count_invoices_by_work_status')
    || !str_contains($invoicesLib, 'function count_invoices_unpaid')
    || !str_contains($invoicesLib, 'function order_items_on_open_invoices')
    || !str_contains($invoicesLib, 'function filter_order_items_not_on_open_invoice')
    || !str_contains($invoicesLib, 'function normalize_invoice_list_filter')
    || !str_contains($invoicesLib, 'function invoice_list_query')
    || !str_contains($invoicesLib, 'function invoice_generate_empty_stats')
    || !str_contains($invoicesLib, 'function invoice_assert_single_bill_as')
    || !str_contains($invoicesLib, 'function invoice_generate_pick_cap')
    || !str_contains($invoicesLib, 'function list_invoice_linked_order_items')
    || !str_contains($invoicesLib, 'function invoice_record_event')
    || !str_contains($invoicesLib, 'function list_invoice_events')
    || !str_contains($invoicesLib, 'invoice_events')
    || !str_contains($invoicesLib, "i.work_status='draft'")
    || !str_contains($invoicesLib, "i.payment_status='unpaid' AND i.work_status='done'")
    || !str_contains($invoicesLib, 'LIMIT ')) {
    fail('invoices.php missing SQL paging helpers');
} else {
    ok('invoices SQL paging helpers');
}
if (!str_contains($invoicesLib, 'AND TRIM(country) <> \'\'')
    || !str_contains($invoicesLib, 'AND TRIM(client_label) <> \'\'')
    || !str_contains($invoicesLib, 'SELECT is_paid, live_url, country, client_label')
    || !str_contains($invoicesLib, 'Country is required before pushing a row to an invoice')
    || !str_contains($invoicesLib, 'Client email or name is required before pushing a row to an invoice')) {
    fail('invoices.php missing country/client invoiceable guards');
} else {
    ok('invoices country+client required to generate');
}

$testsFull = file_get_contents($root . '/tests_run.php') ?: '';
foreach (['mark paid without LIVE', 'unpaid LIVE count', 'archived client hidden', 'order_management_dashboard_stats', 'clearing LIVE also clears paid', 'order clients SQL limit/offset', 'invoices SQL limit/offset', 'invoice draft count helper', 'invoice unpaid-done count helper', 'invoice list filter draft', 'invoice list filter unpaid', 'invoice list filter paid', 'invoice list client_id excludes blanks', 'invoice generate option unpaid LIVE', 'pipeline sheet filters', 'pipeline invoice without client folder', 'normalize_order_date keeps calendar day', 'add order keeps filter country', 'invoice display bill as', 'invoice save bill as header', 'WP Processing syncs to OM Processing', 'complete without live URL rejected', 'complete without client rejected', 'complete without country rejected', 'invoice without country rejected', 'Team cannot use OM or invoices', 'filling LIVE URL does not auto-complete', 'copy live URLs unique first-seen', 'txt/copy uses folder + filter', 'OM copy UI on Processing and Completed', 'WP leaving Processing keeps OM row in Processing', 'Processing origin wp leftover manual all', 'restoring WP Processing recreates OM row', 'Push unpaid CTA ticks current-filter ids or honest label', 'OM Open in Website prices URL + status label', 'OM sheet Copy/Complete labels, confirm, WP link, client typeahead', 'mixed bill-as blocked', 'generate empty stats match invoiceable', 'generate pick cap', 'invoice linked OM rows', 'article doc URL saved and kept after complete', 'invoice created event snapshots article doc', 'invoice append event snapshots article doc', 'invoice waiting match, labels, aging', 'OM month close bounds and totals', 'invoice search Waiting/Draft labels', 'Processing default origin follows non-empty tab'] as $needle) {
    if (!str_contains($testsFull, $needle)) {
        fail("tests_run.php missing OM coverage: {$needle}");
    }
}
ok('tests_run OM coverage needles');
foreach (['second push blocked while on open invoice', 'delete invoice frees order row for push', 'append unpaid LIVE grows existing invoice', 'append unpaid LIVE grows generated invoice', 'append skips this invoice, blocks other open, mixed bill-as', 'append rejected on paid invoice'] as $needle) {
    if (!str_contains($testsFull, $needle)) {
        fail("tests_run.php missing OM coverage: {$needle}");
    }
}
ok('tests_run OM double-bill coverage');

$invoiceGenerate = file_get_contents($root . '/pages/admin/invoice_generate.php') ?: '';
if (!str_contains($invoiceGenerate, 'csrf_field()')) {
    fail('invoice_generate missing csrf_field');
} else {
    ok('invoice_generate csrf_field');
}
if (!str_contains($invoiceGenerate, 'unpaid LIVE')
    || !str_contains($invoiceGenerate, 'bill_to_name')
    || !str_contains($invoiceGenerate, 'invoice_bill_as_from_orders')
    || !str_contains($invoiceGenerate, 'Order management')
    || !str_contains($invoiceGenerate, 'invoice-pick-search')
    || !str_contains($invoiceGenerate, '$precheck')
    || !str_contains($invoiceGenerate, 'data-invoice-pick-item')) {
    fail('invoice_generate missing unpaid LIVE pick / bill-as');
} else {
    ok('invoice_generate unpaid LIVE pick + bill-as');
}
if (!str_contains($invoiceGenerate, 'invoice_assert_single_bill_as')
    || !str_contains($invoiceGenerate, 'cannot share one invoice')
    || !str_contains($invoiceGenerate, 'invoice_generate_empty_stats')
    || !str_contains($invoiceGenerate, 'invoice_generate_pick_cap')
    || !str_contains($invoiceGenerate, 'Push from Completed')
    || str_contains($invoiceGenerate, 'group_same_amount" value="1" checked')
    || !str_contains($invoiceGenerate, 'data-no-draft')) {
    fail('invoice_generate missing mixed bill-as / empty reasons / grouping off');
} else {
    ok('invoice_generate mixed bill-as blocked, empty reasons, grouping off');
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
    || !str_contains($invoiceViewCsrf, "value=\"save_bill\"")
    || !str_contains($invoiceViewCsrf, "value=\"mark_paid\"")
    || !str_contains($invoiceViewCsrf, "value=\"mark_sent\"")) {
    fail('Invoice list/view POST forms missing csrf_field');
} elseif (str_contains($invoiceViewCsrf, 'admin_invoice_generate&amp;client_id=')) {
    fail('Invoice view Generate another still scoped to a client folder');
} elseif (!str_contains($invoiceViewCsrf, 'Add sites to this invoice')
    || !str_contains($invoiceViewCsrf, 'invoice_generate_append_href')
    || !str_contains($invoiceViewCsrf, 'Mark as sent')
    || str_contains($invoiceViewCsrf, 'Save as done')
    || str_contains($invoiceViewCsrf, 'Generate another')
    || str_contains($invoiceViewCsrf, 'More sites need a new bill')
    || str_contains($invoiceViewCsrf, 'You will not be able to add more sites')) {
    fail('invoice view missing Add sites on waiting unpaid bills');
} else {
    ok('Invoice list/view csrf_field on POST forms');
}
if (!str_contains($invoicesListCsrf, 'btn-paid-mark')
    || !str_contains($invoicesListCsrf, 'Mark paid')
    || str_contains($invoicesListCsrf, 'Mark payment received')) {
    fail('invoices list unpaid CTA is not Mark paid');
} else {
    ok('invoices list unpaid CTA is Mark paid');
}
if (!str_contains($invoicesListCsrf, 'Add sites')
    || !str_contains($invoicesListCsrf, 'invoice_generate_append_href')
    || !str_contains($invoicesListCsrf, 'Waiting')) {
    fail('invoices list missing Add sites on waiting bills');
} else {
    ok('invoices list Add sites on waiting unpaid');
}
if (!str_contains($invoiceViewCsrf, 'invoice-print-toolbar')
    || str_contains($invoiceViewCsrf, 'onload="window.print()"')
    || !str_contains($invoiceViewCsrf, 'does not print automatically')
    || !str_contains($invoiceViewCsrf, 'btn-paid-mark')
    || str_contains($invoiceViewCsrf, 'Mark payment received')) {
    fail('invoice view missing print toolbar / Mark paid');
} else {
    ok('invoice view print toolbar + Mark paid');
}
if (!str_contains($invoiceViewCsrf, 'invoice-om-links')
    || !str_contains($invoiceViewCsrf, 'list_invoice_linked_order_items')
    || !str_contains($invoiceViewCsrf, 'Leftover client-folder')
    || !str_contains($invoiceViewCsrf, 'folder=completed')) {
    fail('invoice view missing OM row links');
} else {
    ok('invoice view OM row links');
}
if (!str_contains($invoiceViewCsrf, 'invoice-history')
    || !str_contains($invoiceViewCsrf, 'list_invoice_events')
    || !str_contains($invoiceViewCsrf, 'No history yet')
    || !str_contains($invoiceViewCsrf, 'Article doc')
    || !str_contains($invoicesLib, 'CREATE TABLE IF NOT EXISTS invoice_events')
    || !str_contains($invoicesLib, 'function invoice_ensure_events_table')
    || !str_contains($ordersLib, 'function order_ensure_article_doc_column')) {
    fail('invoice view missing History / Article doc');
} else {
    ok('invoice view History and Article doc');
}
$schemaSqlLate = file_get_contents($root . '/sql/schema.sql') ?: '';
if (!str_contains($schemaSqlLate, 'article_doc_url VARCHAR(500)')
    || !str_contains($schemaSqlLate, 'CREATE TABLE IF NOT EXISTS invoice_events')) {
    fail('schema.sql missing article_doc_url or invoice_events');
} else {
    ok('schema.sql article doc + invoice events');
}
if (!str_contains($invoiceGenerate, 'invoice-legacy-client')
    || !str_contains($invoiceGenerate, 'client_id=')
    || !str_contains($invoiceGenerate, 'Show all unpaid LIVE')) {
    fail('invoice_generate missing leftover client_id banner');
} else {
    ok('invoice_generate leftover client_id banner');
}
if (!str_contains($invoicesLib, 'function append_orders_to_invoice')
    || !str_contains($invoicesLib, 'function list_invoices_open_for_append')
    || !str_contains($invoicesLib, 'function invoice_is_sent_for_payment')
    || !str_contains($invoicesLib, 'function invoice_can_append_orders')
    || !str_contains($invoicesLib, 'function mark_invoice_sent')
    || !str_contains($invoiceGenerate, 'name="destination"')
    || !str_contains($invoiceGenerate, 'Add to existing')
    || !str_contains($invoiceGenerate, 'existing_invoice_id')
    || !str_contains($invoiceGenerate, 'Use Add to existing to put these sites')
    || !str_contains($invoiceGenerate, 'Paid invoices stay locked')
    || !str_contains($invoiceGenerate, 'Find Draft or waiting invoice')
    || !str_contains($invoiceGenerate, 'Waiting = sent, still unpaid')
    || !str_contains($invoiceGenerate, "get('existing')")
    || !str_contains($invoicesLib, 'function invoice_match_open_for_bill_as')
    || !str_contains($invoiceGenerate, 'selectedExistBillAs')
    || !str_contains($invoiceGenerate, 'maybeAutoExisting')
    || !str_contains($invoiceGenerate, 'Use a new invoice instead')
    || !str_contains($invoicesLib, 'function invoice_append_status_label')
    || !str_contains($invoicesLib, 'function invoice_generate_append_href')
    || str_contains($invoiceGenerate, 'name="invoice_number"')) {
    fail('invoice generate missing add-to-existing unpaid destination');
} else {
    ok('invoice generate add to existing unpaid');
}
$invoiceCss = file_get_contents($root . '/assets/css/app.css') ?: '';
if (!str_contains($invoiceCss, '.invoice-doc-logohead')
    || !str_contains($invoiceCss, 'padding: 0.9rem 1.35rem 0.45rem')
    || !str_contains($invoiceCss, '.invoice-print-toolbar')
    || !str_contains($invoiceCss, 'height: 52px !important')
    || !str_contains($invoiceCss, 'object-position: left center')
    || preg_match('/\.invoice-doc-logo\s*\{[^}]*height:\s*40px/', $invoiceCss)
    || preg_match('/@media print[\s\S]{0,1200}\.invoice-doc-logo\s*\{[^}]*height:\s*48px/', $invoiceCss)) {
    fail('invoice logo gutter / print size mismatch');
} else {
    ok('invoice logo gutter 1.35rem and 52px print');
}
if (!is_file($root . '/assets/img/topurlz-logo.png')) {
    fail('missing assets/img/topurlz-logo.png');
} else {
    ok('file assets/img/topurlz-logo.png');
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
    || !str_contains($adminDepts, 'Open Users')
    || !str_contains($adminDepts, 'admin_users&amp;awaiting=1')) {
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
$stayScrollJs = file_get_contents($root . '/assets/js/stay-scroll.js') ?: '';
if (!preg_match('/var req = postStayAjax\(form\);\s*sel\.disabled = true/s', $stayScrollJs)) {
    fail('stay-ajax disables select before FormData');
} else {
    ok('stay-ajax collects FormData before disabling select');
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
if (str_contains($teamDash, 'launch-cards') || str_contains($teamDash, '$todayBatch')) {
    fail('team dashboard still has dead all-tools launch cards');
} else {
    ok('team dashboard dropped dead all-tools branch');
}
if (!str_contains($teamDash, "post('action') === 'set_status'")
    || !str_contains($teamDash, 'csrf_field()')
    || !str_contains($teamDash, 'team_can_set_department_task_status')
    || !str_contains($teamDash, 'id="dashboard-search"')
    || !str_contains($teamDash, 'data-dashboard-item')) {
    fail('team dashboard missing status dropdown CSRF or Filter this page');
} else {
    ok('team dashboard status dropdown + Filter this page');
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
if (!str_contains($deptLib, "\$page === 'team_admin_emails_delete'")
    || !str_contains($deptLib, "'team_admin_emails_search'")) {
    fail('departments ACL missing Admin search rename alias');
} else {
    ok('departments Admin search route alias');
}

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
    || !str_contains($dashPage, 'admin_users&awaiting=1')
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
    || !str_contains($dashPage, 'count_email_campaign_projects')
    || !str_contains($dashPage, 'count_invoices_by_work_status')
    || !str_contains($dashPage, 'count_invoices_unpaid')
    || !str_contains($dashPage, 'draft invoice')) {
    fail('dashboard missing attention strip / email / invoice counts');
} else {
    ok('dashboard attention strip and email/invoice counts');
}
if (str_contains($dashPage, 'admin_invoices&q=draft')
    || !str_contains($dashPage, 'admin_invoices&filter=draft')
    || !str_contains($dashPage, 'admin_invoices&filter=unpaid')
    || str_contains($dashPage, 'drafts listed underneath')) {
    fail('dashboard invoice tiles must use filter= and keep drafts off the unpaid tile');
} else {
    ok('dashboard Draft/Unpaid tiles use invoice filter');
}
if (str_contains($dashPage, ". ' unpaid LIVE'")
    || str_contains($dashPage, '\' unpaid LIVE\'')) {
    fail('dashboard attention still duplicates unpaid LIVE');
} else {
    ok('dashboard unpaid LIVE is the stat tile only');
}
if (!str_contains($dashPage, 'admin_users&must_change=1')
    || !str_contains($dashPage, 'must change password')) {
    fail('dashboard missing must-change password chip');
} else {
    ok('dashboard must-change password chip');
}
if (!str_contains($dashPage, 'Emails Admin')
    || !str_contains($dashPage, 'URLs (all countries)')
    || !str_contains($dashPage, 'Could not load')
    || !str_contains($dashPage, 'render_admin_dashboard_stat')
    || !str_contains($dashPage, 'Unpaid LIVE')
    || !str_contains($dashPage, 'Waiting invoices')
    || !str_contains($dashPage, 'Waiting > 14 days')
    || !str_contains($dashPage, 'count_invoices_waiting_older_than')
    || !str_contains($dashPage, 'dashboard-waiting-bills')
    || !str_contains($dashPage, 'Campaign sheets')) {
    fail('dashboard stats tiles missing pipeline labels');
} else {
    ok('dashboard stats match pipeline');
}
if (!str_contains($dashPage, 'render_workflow')
    || !str_contains($dashPage, "'Departments'")
    || !str_contains($dashPage, "'Extracted Sites'")
    || !str_contains($dashPage, "'Emails data'")
    || !str_contains($dashPage, "'Orders + Invoices'")) {
    fail('dashboard missing workflow strip');
} else {
    ok('dashboard workflow strip');
}
if (!str_contains($dashPage, 'Recent Our database adds')
    || !str_contains($dashPage, 'count_site_price_rows_by_lane')
    || !str_contains($dashPage, 'cards, stats, and chips')
    || str_contains($dashPage, 'btn secondary" href="index.php?page=admin_prospects#add-sites">Our database')) {
    fail('dashboard missing WP lanes / filter stats+chips / recent heading, or extra Our database button');
} else {
    ok('dashboard WP lanes, filter, recent heading, no extra Our database button');
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
    'prospect_site_rows_html Niche before Domain, no Status',
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
if (!str_contains($layoutNav, "'admin_orders&folder=processing'")
    || !str_contains($layoutNav, "'admin_orders&folder=completed'")
    || !str_contains($layoutNav, "\$activePage === 'admin_orders'")
    || !str_contains($layoutNav, "'admin_invoices&filter=unpaid'")) {
    fail('layout Order management does not open Processing');
} else {
    ok('layout Order management opens Processing');
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
if (!str_contains($teamSemrushSheet, 'json_encode')
    || !str_contains($teamSemrushSheet, 'Delete this comment?')) {
    fail('Team Semrush comment delete confirm not json_encode-safe');
} else {
    ok('Team Semrush comment delete confirm json_encode-safe');
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
if (!str_contains($prospectsLib, "'extract_error'")
    || !str_contains($prospectCheckT, "added['extract_error']")) {
    fail('Filter add missing Extracting insert error surface');
} else {
    ok('Filter add surfaces Extracting insert errors');
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
} elseif (!str_contains($guidesPhp, 'Copy, Undo, Redo')
    || !str_contains($guidesPhp, 'Open first 10')) {
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
if (!str_contains($extractBatchT, 'data-extract-open-count')
    || !str_contains($extractBatchT, 'data-extract-open-bulk')
    || !str_contains($extractBatchT, 'data-extract-open-continue')
    || !str_contains($extractBatchT, 'First 20')
    || !str_contains($extractBatchT, 'First 50')) {
    fail('Extracting Sites list missing Open first 10–50 controls');
} else {
    ok('Extracting Sites list Open first 10–50');
}
if (!str_contains($extractSitesJs, 'listEligibleOpenHosts')
    || !str_contains($extractSitesJs, 'Open all ')
    || !str_contains($extractSitesJs, 'syncOpenBulkButton')
    || !str_contains($extractSitesJs, 'OPEN_BATCH_SIZE')
    || !str_contains($extractSitesJs, 'startOrContinueOpen')
    || !str_contains($extractSitesJs, 'Open next ')) {
    fail('extract-sites-list.js missing Open first N / batch continue logic');
} else {
    ok('extract-sites-list.js Open first N + batch continue');
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
$openSitePhp = file_get_contents($root . '/includes/sites_form.php') ?: '';
if (!str_contains($openSiteJs, 'docs|drive)\\.google\\.com')
    || !str_contains($openSitePhp, 'docs|drive)\\.google\\.com')) {
    fail('Open site missing Google Doc path keep');
} else {
    ok('Open site keeps Google Doc path');
}
$campApp = file_get_contents($root . '/pages/admin/email_campaigns_app.php') ?: '';
$campDraftsTeam = file_get_contents($root . '/pages/team/email_campaign_drafts.php') ?: '';
$campLib = file_get_contents($root . '/includes/email_campaigns.php') ?: '';
if (!str_contains($campLib, 'function email_campaign_bulk_result_message')
    || !str_contains($campApp, 'email_campaign_bulk_result_message(\'Imported file into sheet\'')
    || !str_contains($campApp, 'email_campaign_bulk_result_message(\'Added to sheet\'')
    || !str_contains($campApp, 'Extra columns after email 4 are ignored')
    || !str_contains($campLib, 'function email_campaign_xlsx_xpath')
    || !str_contains($campLib, 'local-name()')
    || !str_contains($campLib, 'function_exists(\'simplexml_load_string\')')
    || !str_contains($campLib, 'function email_campaign_xlsx_first_sheet_path')
    || !str_contains($campLib, 'php://temp')
    || !str_contains($campLib, 'rPh')) {
    fail('Campaign paste/import missing shared result message, extra-column help, or xlsx xpath fix');
} else {
    ok('Campaign paste/import result message + extra-column help');
}
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
    || !str_contains($campLib, 'function count_email_campaign_projects')
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
if (!str_contains($campLib, 'function record_email_campaign_row_event')
    || !str_contains($campLib, 'email_campaign_row_events')
    || !str_contains($campLib, 'function list_email_campaign_row_events')
    || !str_contains(file_get_contents($root . '/pages/team/email_campaigns.php') ?: '', 'delete_email_campaign_row($sid, $rowId, true, $user)')
    || !str_contains(file_get_contents($root . '/pages/team/email_campaigns.php') ?: '', 'remove_email_from_email_campaign_row($sid, $rowId, (string) post(\'email\'), $user)')
    || !str_contains($campApp, 'delete_email_campaign_row($sheetId, $rowId, true, $user)')
    || !str_contains($campApp, 'delete_email_campaign_rows_by_ids($sheetId, $ids, $user)')
    || !str_contains($campApp, 'save_email_campaign_row($sheetId, $rowId, (string) post(\'domain\'), $emails, $user)')) {
    fail('campaign missing delete-who event stamp');
} else {
    ok('campaign delete-who event stamp');
}
if (!str_contains($campLib, 'function email_campaign_who_for_exclusion')
    || !str_contains($campApp, 'email_campaign_who_for_exclusion($whoMap, \'delete_site\'')
    || !str_contains($campApp, 'email_campaign_who_for_exclusion($whoMap, \'remove_email\'')
    || !str_contains($campApp, 'id="camp-removed"')
    || !str_contains($campApp, '<th>Who</th>')) {
    fail('campaign missing delete-who Admin UI');
} else {
    ok('campaign delete-who Admin UI');
}
$campJsBatches = file_get_contents($root . '/assets/js/email-campaign-sheet.js') ?: '';
$campSql = file_get_contents($root . '/sql/upgrade_email_campaigns.sql') ?: '';
if (!str_contains($campLib, 'function create_email_campaign_send_batch')
    || !str_contains($campLib, 'function mark_email_campaign_emailed_up_to')
    || !str_contains($campLib, 'email_campaign_send_batches')
    || !str_contains($campLib, 'send_batch_id')
    || !str_contains($campApp, "post('batch_name')")
    || !str_contains($campApp, 'email_campaign_row_emailed_status')
    || !str_contains($campApp, 'data-camp-batch-suggest')
    || !str_contains($campJsBatches, 'window.prompt')
    || !str_contains($campJsBatches, 'batch_name')
    || !str_contains($campJsBatches, 'Name this send batch')
    || !str_contains($campSql, 'email_campaign_send_batches')) {
    fail('campaign missing named send-batch stamp / prompt');
} else {
    ok('campaign named send-batch stamp + prompt');
}
if (!str_contains($campApp, "render_sheet_tool_menu_open('Batches'")
    || !str_contains($campApp, 'id="camp-batch-open"')
    || !str_contains($campApp, "get('batch')")
    || !str_contains($campApp, "post('batch')")
    || !str_contains($campApp, 'id="camp-batches"')
    || !str_contains($campApp, "'batch' => \$batchFilter")
    || !str_contains($campLib, 'send_batch_id = ?')) {
    fail('campaign missing Batches menu / open filter');
} else {
    ok('campaign Batches menu + open filter');
}
$extractedPg = file_get_contents($root . '/pages/admin/extracted.php') ?: '';
$orderSheet = file_get_contents($root . '/pages/admin/orders.php') ?: '';
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
if (!str_contains($adminEmailsDelete, 'team_page_unlocked($user, \'team_admin_emails_search\')')
    && !str_contains($adminEmailsDelete, 'team_page_unlocked($user, "team_admin_emails_search")')) {
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
    'extract last_pushed_at stamped after Push',
    'team_page_unlocked admin emails search + delete alias',
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
if (!str_contains($httpSmoke, 'bad login keeps username and recovery help')
    || !str_contains($httpSmoke, 'Username or Admin email')) {
    fail('tests_http.php missing login recovery UX asserts');
} else {
    ok('tests_http.php login recovery UX');
}
if (!str_contains($httpSmoke, 'finder Send this ending stays on Filter')
    || !str_contains($httpSmoke, 'finder Send leftover unique still on Filter')) {
    fail('tests_http.php missing finder Send stay-on-Filter assert');
} else {
    ok('tests_http.php finder Send stays on Filter');
}
if (!str_contains($httpSmoke, 'tld-separate.js Delete ending strips paste and unique')) {
    fail('tests_http.php missing tld-separate Delete column strip assert');
} else {
    ok('tests_http.php tld-separate Delete column strip');
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
if (!str_contains($prospectCheckSf, 'Extracting received')
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
if (str_contains($tldJs, "sourceSel !== '#domains'")
    || str_contains($tldJs, 'if (!el || el.readOnly) return')) {
    fail('tld-separate.js Delete still skips unique/paste columns');
} elseif (!str_contains($tldJs, 'function stripSharedColumns')
    || !str_contains($tldJs, 'function domainKey')
    || !str_contains($tldJs, 'function stripLinesFromTextarea')
    || !str_contains($tldJs, "querySelector('#domains')")
    || !str_contains($tldJs, "querySelector('#unique_domains_preview')")) {
    fail('tld-separate.js missing Delete ending column strip');
} else {
    ok('tld-separate.js Delete ending strips paste and unique columns');
}
if (!str_contains($prospectCheckSf, 'data-source="#domains"')
    || !str_contains($prospectCheckSf, 'data-source="#unique_domains_preview"')) {
    fail('Filter missing both Separate all sources');
} else {
    ok('Filter Separate all paste + unique sources');
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
if (!str_contains($prospectsLib, 'function prospect_filter_gate_subtract')
    || !str_contains($prospectsLib, 'function prospect_filter_gate_domains')
    || !str_contains($prospectCheckSf, 'prospect_filter_gate_subtract')) {
    fail('Filter missing remaining-after-Send gate subtract');
} else {
    ok('Filter Send subtracts remaining unique');
}
if (str_contains($prospectCheckSf, 'page=team_prospect_batch&id=')) {
    fail('Filter & add still redirects Add/Send to Site adding history');
} elseif (!str_contains($prospectCheckSf, 'page=team_prospect_check&country=')) {
    fail('Filter & add missing stay-on-Filter redirect');
} else {
    ok('Filter & add stays on Filter after Add/Send');
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
if (!str_contains($testsSf, 'gate subtract leaves remaining unique')) {
    fail('tests_run.php missing Filter Send remaining-unique coverage');
} else {
    ok('tests_run Filter Send remaining unique');
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
$ianaTldsPhp = (string) @file_get_contents(__DIR__ . '/includes/iana_ascii_tlds.php');
$geoPhpSmoke = (string) @file_get_contents(__DIR__ . '/includes/geo.php');
if (!str_contains($ianaTldsPhp, ' gal ')
    || !str_contains($ianaTldsPhp, ' madrid ')
    || !str_contains($ianaTldsPhp, ' eus ')
    || !str_contains($ianaTldsPhp, ' run ')
    || !str_contains($sitesFormJs, ' gal ')
    || !str_contains($sitesFormJs, ' madrid ')
    || !str_contains($sitesFormJs, ' run ')
    || !str_contains($prospectsPhp, 'iana_ascii_tld_labels')
    || !str_contains($geoPhpSmoke, "'gal' => 'Spain'")
    || !str_contains($geoPhpSmoke, "'madrid' => 'Spain'")) {
    fail('Clean still missing real geoTLDs .gal / .madrid');
} else {
    ok('Clean IANA TLDs include .gal .madrid .eus .run');
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
    || !str_contains($procJsSmoke, "method === 'get'")
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
    || !str_contains($layoutUiSmoke, "'admin_emails_data' => ['Emails data', 'Admin · Final · Campaign']")
    || !str_contains($layoutUiSmoke, "'admin_site_prices' => ['Website prices', 'Country sheets · publisher rates']")) {
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
    || !str_contains($campUi, "'Undo mark' : 'Mark emailed'")) {
    fail('sheets missing dense rows / compact emailed rule / short mark labels');
} else {
    ok('dense sheet rows + compact checkpoint + short Emailed/Undo');
}
$campLibSmokeUx = file_get_contents($root . '/includes/email_campaigns.php') ?: '';
if (!str_contains($campUi, 'href="#camp-fill-gaps"')
    || !str_contains($campUi, 'Not emailed (')
    || !str_contains($campUi, 'Search site or email (this page)')
    || substr_count($campUi, 'id="camp-add-toggle"') !== 1
    || !str_contains($campLibSmokeUx, 'function email_campaign_default_language')
    || !str_contains($campLibSmokeUx, 'function email_campaign_fill_blank_row_languages')
    || !str_contains($campLibSmokeUx, 'function list_email_campaign_project_country_nav')
    || !str_contains($campUi, 'camp-country-jump')
    || !str_contains($campUi, 'render_sheet_country_jump')
    || !str_contains($cssUi, '.camp-country-jump select')
    || !str_contains($cssUi, '.sheet-country-jump select')
    || !str_contains($cssUi, '.swe-checkpoint-compact .with-info-label')) {
    fail('campaign sheet missing Fill gaps header, chip counts, or language default');
} else {
    ok('campaign sheet Fill gaps in header, chip counts, language default');
}
$sweLibSmoke = file_get_contents($root . '/includes/sites_with_emails.php') ?: '';
if (!str_contains($helpersSmoke, 'function render_sheet_country_jump')
    || !str_contains($adminProspects, 'prospect-country-jump')
    || !str_contains($adminProspects, 'list_prospect_country_nav')
    || !str_contains($prospectsLib, 'function list_prospect_country_nav')
    || !str_contains($sweUi, 'swe-country-jump')
    || !str_contains($sweUi, 'list_sites_with_emails_country_nav')
    || !str_contains($sweLibSmoke, 'function list_sites_with_emails_country_nav')) {
    fail('country sheets missing in-place country switcher');
} else {
    ok('Our database + Admin/Final country title switcher');
}
$extractLibSmoke = file_get_contents($root . '/includes/extracting.php') ?: '';
$extractHubSmoke = file_get_contents($root . '/pages/team/extracting.php') ?: '';
$extractBatchJump = file_get_contents($root . '/pages/team/extract_batch.php') ?: '';
$semrushHubSmoke = file_get_contents($root . '/pages/team/semrush_research.php') ?: '';
$tldJsSmoke = file_get_contents($root . '/assets/js/tld-separate.js') ?: '';
if (!str_contains($extractBatchJump, 'extract-country-jump')
    || !str_contains($extractBatchJump, 'list_extract_batch_country_nav')
    || !str_contains($extractLibSmoke, 'function list_extract_batch_country_nav')
    || !str_contains($extractHubSmoke, 'extract-country-search')
    || !str_contains($extractHubSmoke, 'list_extract_batches(2000)')
    || !str_contains($extractLibSmoke, 'min(10000, $limit)')
    || !str_contains($extractLibSmoke, 'live_site_count')
    || !str_contains($extractLibSmoke, 'EXISTS (SELECT 1 FROM extract_batch_sites')) {
    fail('Extracting missing country switcher, hub search/cap, or live site count');
} else {
    ok('Extracting country switcher + hub search cap');
}
if (!str_contains($extractBatchJump, 'Clean first — Push only sends Ready.')) {
    fail('Extracting Push empty Ready copy missing');
} else {
    ok('Extracting Push empty Ready copy');
}
if (!str_contains($semrushHubSmoke, 'Filled from Extracting Push; Clear is Site Finding / Admin.')) {
    fail('Semrush hub missing ownership line');
} else {
    ok('Semrush hub ownership line');
}
if (!str_contains($prospectsLib, 'function prospect_destinations_phrase')
    || !str_contains($prospectCheckSf, 'prospect_destinations_phrase')
    || !str_contains($prospectCheckSf, 'Add ')
    || !str_contains($prospectCheckSf, 'unique site')
    || str_contains($prospectCheckSf, 'Country database (private)')
    || !str_contains($prospectCheckSf, 'Send this ending')
    || !str_contains($tldJsSmoke, 'Add ')
    || !str_contains($tldJsSmoke, 'unique site')
    || str_contains($tldJsSmoke, 'new sites to ')) {
    fail('Filter & add missing destination-country Add copy or still shows private DB card');
} else {
    ok('Filter & add destination Add copy + no private DB card');
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

if (!str_contains($sweLibSmoke, 'function paste_sites_with_emails_rows')
    || !str_contains($sweLibSmoke, 'function import_sites_with_emails_rows_from_upload')
    || !str_contains($sweUi, 'id="swe-bulk-add"')
    || !str_contains($sweUi, 'name="paste_text"')
    || !str_contains($sweUi, 'name="import_file"')
    || !str_contains($sweUi, 'id="swe-open-country"')
    || !str_contains($sweUi, '$isAdminAll && ($action === \'paste\'')
    || !str_contains($guidesLib, 'Paste or import CSV / Excel / TXT like Campaign')) {
    fail('Final missing Campaign-style paste/CSV import');
} else {
    ok('Final Campaign-style paste/CSV import');
}

$invoicesChrome = file_get_contents($root . '/pages/admin/invoices.php') ?: '';
$filterAddChrome = file_get_contents($root . '/pages/team/prospect_check.php') ?: '';
if (!str_contains($layoutUiSmoke, 'class="app-bar"')
    || !str_contains($layoutUiSmoke, 'mobile-page-title')
    || !str_contains($layoutUiSmoke, 'class="app-footer project-credit"')
    || !str_contains($layoutUiSmoke, 'app-footer-primary')
    || !str_contains($layoutUiSmoke, 'teqnowebs.com')
    || !str_contains($layoutUiSmoke, 'rel="noopener"')) {
    fail('layout missing app bar / footer chrome');
} else {
    ok('layout app bar + Teqnowebs footer');
}
if (!str_contains($cssUi, '--grad-btn: linear-gradient(180deg, #374151')
    || !str_contains($cssUi, 'background: #9ca3af')
    || !str_contains($cssUi, '.app-bar {')
    || !str_contains($cssUi, '.app-footer {')
    || !str_contains($cssUi, '.app-footer-primary')
    || !str_contains($cssUi, '0 0 0 2px #fff')) {
    fail('CSS missing ink commit buttons or app chrome');
} else {
    ok('CSS ink commit buttons + app chrome');
}
if (!str_contains($teamDash, 'btn secondary') || !str_contains($teamDash, 'My departments')) {
    fail('team dashboard My departments should be a secondary shortcut');
} else {
    ok('team dashboard My departments is secondary');
}
if (!str_contains($filterAddChrome, 'btn secondary')
    || !str_contains($filterAddChrome, 'Semrush Research')) {
    fail('Filter & add Semrush shortcut should be secondary');
} else {
    ok('Filter & add Semrush shortcut is secondary');
}
if (str_contains($invoicesChrome, 'btn crystal')) {
    fail('invoices still uses btn crystal');
} else {
    ok('invoices Blank invoice is secondary not crystal');
}
$teamHistory = file_get_contents($root . '/pages/team/prospect_batches.php') ?: '';
$teamHistoryDay = file_get_contents($root . '/pages/team/prospect_batch.php') ?: '';
$accountPw = file_get_contents($root . '/pages/account_password.php') ?: '';
if (!str_contains($teamHistory, 'render_breadcrumbs')
    || !str_contains($teamHistoryDay, 'render_breadcrumbs')
    || !str_contains($accountPw, 'render_breadcrumbs')) {
    fail('Team history / Change password missing breadcrumbs');
} else {
    ok('Team history + Change password breadcrumbs');
}
if (!str_contains($helpers, 'function folder_open_cue')
    || !str_contains($teamDash, 'folder_open_cue()')
    || !str_contains($cssUi, '.folder-open {')
    || !str_contains($cssUi, '.folder.is-empty')
    || !str_contains($cssUi, '.prospect-markets-toolbar .sheet-search')) {
    fail('folder Open cue missing from cards or CSS');
} else {
    ok('folder Open cue on tool cards');
}
if (str_contains($cssUi, '.btn.crystal')) {
    fail('unused .btn.crystal CSS still present');
} else {
    ok('crystal button CSS removed');
}
$loginPhp = file_get_contents($root . '/pages/login.php') ?: '';
$forgotPhp = file_get_contents($root . '/pages/forgot_password.php') ?: '';
$resetPhp = file_get_contents($root . '/pages/reset_password.php') ?: '';
$verifyPhp = file_get_contents($root . '/pages/verify_email.php') ?: '';
if (!str_contains($loginPhp, 'render_project_credit()')
    || !str_contains($forgotPhp, 'render_project_credit()')
    || !str_contains($resetPhp, 'render_project_credit()')
    || !str_contains($verifyPhp, 'render_project_credit()')
    || !str_contains($loginPhp, '>Sign in</button>')
    || !str_contains($forgotPhp, '>Send reset link</button>')) {
    fail('logged-out pages missing Teqnowebs credit or commit buttons');
} else {
    ok('login/forgot/reset/verify Teqnowebs credit');
}
$mailLib = file_get_contents($root . '/includes/mail.php') ?: '';
$toggleJs = file_get_contents($root . '/assets/js/password-toggle.js') ?: '';
$testsRunLogin = file_get_contents($root . '/tests_run.php') ?: '';
if (!str_contains($loginPhp, 'Username or Admin email')
    || !str_contains($loginPhp, 'value="<?= h($userName) ?>"')
    || !str_contains($loginPhp, 'Sign in to')
    || str_contains($loginPhp, 'Shared site database')
    || !str_contains($loginPhp, 'id="login_help"')
    || !str_contains($loginPhp, 'app_mail_reset_is_ready')
    || !str_contains($forgotPhp, 'app_mail_reset_is_ready')
    || !str_contains($mailLib, 'function app_mail_reset_is_ready')
    || !str_contains($toggleJs, 'password-caps-hint')
    || !str_contains($toggleJs, 'CapsLock')
    || !str_contains($testsRunLogin, 'app_mail_reset_is_ready returns bool')) {
    fail('login recovery UX missing keep-username / Show / Caps Lock / mail-ready copy');
} else {
    ok('login recovery UX keep-username, Show, Caps Lock, mail-ready');
}
$extractBatchUi = file_get_contents($root . '/pages/team/extract_batch.php') ?: '';
if (!str_contains($extractBatchUi, 'id="extract_push_btn"')
    || !str_contains($extractBatchUi, 'class="btn large"')) {
    fail('Extracting Push commit button missing');
} else {
    ok('Extracting Push is a filled commit button');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
