<?php
require __DIR__ . '/includes/helpers.php';
txf_secure_session_start();
txf_send_security_headers();
txf_start_output_compression();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/account.php';
require __DIR__ . '/includes/mail.php';
require __DIR__ . '/includes/departments.php';
require __DIR__ . '/includes/layout.php';

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

try {
    ensure_users_auth_schema();
} catch (Throwable $e) {
    // Schema may still be upgrading.
}

$page = (string) ($_GET['page'] ?? '');
if ($page === '' && current_user()) {
    $u = current_user();
    if (user_must_change_password($u)) {
        redirect('index.php?page=account_password');
    }
    if (is_admin()) {
        redirect('index.php?page=admin_dashboard');
    }
    // Unassigned Team → waiting dashboard; department members → My departments.
    redirect(
        user_is_department_scoped($u)
            ? 'index.php?page=team_departments'
            : 'index.php?page=team_dashboard'
    );
}
if ($page === '') {
    redirect('index.php?page=login');
}

// Simple panel: shared URL database + filter/add + history
$routes = [
    'login' => 'pages/login.php',
    'logout' => 'pages/logout.php',
    'account_password' => 'pages/account_password.php',
    'forgot_password' => 'pages/forgot_password.php',
    'reset_password' => 'pages/reset_password.php',
    'verify_email' => 'pages/verify_email.php',

    'admin_dashboard' => 'pages/admin/dashboard.php',
    'admin_departments' => 'pages/admin/departments.php',
    'admin_prospects' => 'pages/admin/prospects.php',
    'admin_prospect_add' => 'pages/admin/prospect_add.php',
    'admin_prospect_batches' => 'pages/admin/prospect_batches.php',
    'admin_prospect_batch' => 'pages/admin/prospect_batch.php',
    'admin_extracted' => 'pages/admin/extracted.php',
    'admin_emails_data' => 'pages/admin/emails_data.php',
    'admin_orders' => 'pages/admin/orders.php',
    'admin_order_sheet' => 'pages/admin/order_sheet.php',
    'admin_site_prices' => 'pages/admin/site_prices.php',
    'team_site_prices' => 'pages/team/site_prices.php',
    'admin_invoices' => 'pages/admin/invoices.php',
    'admin_invoice_generate' => 'pages/admin/invoice_generate.php',
    'admin_invoice_manual' => 'pages/admin/invoice_manual.php',
    'admin_invoice_view' => 'pages/admin/invoice_view.php',
    'admin_users' => 'pages/admin/users.php',
    'admin_account' => 'pages/admin/account.php',
    'admin_tasks' => 'pages/admin/tasks.php',
    'admin_semrush_research' => 'pages/admin/semrush_research.php',
    'admin_semrush_sheet' => 'pages/admin/semrush_sheet.php',

    'team_dashboard' => 'pages/team/dashboard.php',
    'team_departments' => 'pages/team/departments.php',
    'team_prospect_check' => 'pages/team/prospect_check.php',
    'team_semrush_research' => 'pages/team/semrush_research.php',
    'team_semrush_sheet' => 'pages/team/semrush_sheet.php',
    'team_prospects' => 'pages/team/prospects.php',
    'team_prospect_form' => 'pages/team/prospect_form.php',
    'team_prospect_batches' => 'pages/team/prospect_batches.php',
    'team_prospect_batch' => 'pages/team/prospect_batch.php',
    'team_extracting' => 'pages/team/extracting.php',
    'team_extract_batch' => 'pages/team/extract_batch.php',
    'team_sites_emails' => 'pages/team/sites_emails.php',
    'team_admin_emails_search' => 'pages/team/admin_emails_delete.php',
    'team_admin_emails_delete' => 'pages/team/admin_emails_delete.php',
    'team_email_campaigns' => 'pages/team/email_campaigns.php',
    'team_email_campaigns_drafts' => 'pages/team/email_campaign_drafts.php',
    'presence_ping' => 'pages/presence_ping.php',
];

// Legacy page names → current tools (avoid blank 404 for old bookmarks).
$legacyPageRedirects = [
    'team_extract_queue' => 'index.php?page=team_extracting',
    'team_extract_work' => 'index.php?page=team_extracting',
    'team_extract_emails' => 'index.php?page=team_sites_emails',
    'team_extract_submit' => 'index.php?page=team_extracting',
    'team_extract_final' => 'index.php?page=team_extracting',
    'admin_extract_sites' => 'index.php?page=admin_extracted&folder=extracted_sites',
    'admin_extract_emails' => 'index.php?page=admin_emails_data',
];
if (isset($legacyPageRedirects[$page])) {
    flash('ok', 'That page moved — opened the current tool instead.');
    redirect($legacyPageRedirects[$page]);
}

if (!isset($routes[$page])) {
    http_response_code(404);
    $home = 'index.php?page=login';
    if ($cu = current_user()) {
        if (is_admin()) {
            $home = 'index.php?page=admin_dashboard';
        } elseif (user_is_department_scoped($cu)) {
            $home = 'index.php?page=team_departments';
        } else {
            $home = 'index.php?page=team_dashboard';
        }
    }
    $homeLabel = current_user() ? 'Go to dashboard' : 'Sign in';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Page not found · TechxForm</title>'
        . '<link rel="stylesheet" href="' . h(stylesheet_url()) . '">'
        . '</head><body class="auth-body"><div class="auth-card" style="max-width:28rem;margin:3rem auto;padding:1.5rem">'
        . '<h1>Page not found</h1>'
        . '<p class="muted">That link is missing or was renamed.</p>'
        . '<p><a class="btn" href="' . h($home) . '">' . h($homeLabel) . '</a></p>'
        . '</div></body></html>';
    exit;
}

// Must change weak/default password before using the app.
$cu = current_user();
if ($cu && user_must_change_password($cu)) {
    $passwordAllowed = ['login', 'logout', 'account_password'];
    if (!in_array($page, $passwordAllowed, true)) {
        flash('error', 'Change your password before continuing.');
        redirect('index.php?page=account_password');
    }
}

// Team with no department: waiting screen only (no tools until Admin assigns).
$teamWaitingAllowed = [
    'login',
    'logout',
    'account_password',
    'team_dashboard',
    'team_departments',
    'presence_ping',
];
if (
    $cu
    && function_exists('team_user_awaits_department')
    && team_user_awaits_department($cu)
    && !in_array($page, $teamWaitingAllowed, true)
) {
    flash('error', 'Ask Admin to assign you to a department before using tools.');
    redirect('index.php?page=team_dashboard');
}

// Department members only see assigned work + tools for their departments.
if (
    $cu
    && ($cu['role'] ?? '') === 'team'
    && user_is_department_scoped($cu)
    && function_exists('team_page_unlocked')
    && !team_page_unlocked($cu, $page)
) {
    flash('error', 'Your login only shows work and tools for your department.');
    redirect('index.php?page=team_departments');
}

// CSRF: every Admin and Team POST (forms + AJAX) must carry a valid token.
// Also protect Change password (forced-change path is not admin_/team_ prefixed).
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (
        str_starts_with($page, 'admin_')
        || str_starts_with($page, 'team_')
        || $page === 'account_password'
        || $page === 'login'
        || $page === 'forgot_password'
        || $page === 'reset_password'
        || $page === 'presence_ping'
    )
) {
    require_csrf();
}

// Login / logout / password mail / presence heartbeat skip the 20k-line inventory
// libraries. Every other page still loads the same includes as before.
$txfLightPages = [
    'login',
    'logout',
    'forgot_password',
    'reset_password',
    'verify_email',
    'presence_ping',
];
if (!in_array($page, $txfLightPages, true)) {
    require __DIR__ . '/includes/geo.php';
    require __DIR__ . '/includes/prospects.php';
    require __DIR__ . '/includes/extracting.php';
    require __DIR__ . '/includes/extracted.php';
    require __DIR__ . '/includes/sites_with_emails.php';
    require __DIR__ . '/includes/email_campaigns.php';
    require __DIR__ . '/includes/admin_new_data.php';
    require __DIR__ . '/includes/sites_form.php';
    require __DIR__ . '/includes/orders.php';
    require __DIR__ . '/includes/site_prices.php';
    require __DIR__ . '/includes/invoices.php';
    require __DIR__ . '/includes/guides.php';
    require __DIR__ . '/includes/presence.php';
    require __DIR__ . '/includes/semrush_research.php';
    require __DIR__ . '/includes/sheet_history.php';
} elseif ($page === 'presence_ping') {
    require __DIR__ . '/includes/presence.php';
}

require __DIR__ . '/' . $routes[$page];
