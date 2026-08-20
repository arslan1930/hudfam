<?php
session_start();

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/sites_form.php';
require __DIR__ . '/includes/guides.php';
require __DIR__ . '/includes/layout.php';

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

$page = (string) ($_GET['page'] ?? '');
if ($page === '' && current_user()) {
    $u = current_user();
    if (user_must_change_password($u)) {
        redirect('index.php?page=account_password');
    }
    redirect(is_admin() ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard');
}
if ($page === '') {
    redirect('index.php?page=login');
}

// Simple panel: shared URL database + filter/add + history + tasks
$routes = [
    'login' => 'pages/login.php',
    'logout' => 'pages/logout.php',
    'forgot_password' => 'pages/forgot_password.php',
    'reset_password' => 'pages/reset_password.php',
    'verify_email' => 'pages/verify_email.php',

    'admin_dashboard' => 'pages/admin/dashboard.php',
    'admin_prospects' => 'pages/admin/prospects.php',
    'admin_prospect_add' => 'pages/admin/prospect_add.php',
    'admin_prospect_batches' => 'pages/admin/prospect_batches.php',
    'admin_prospect_batch' => 'pages/admin/prospect_batch.php',
    'admin_orders' => 'pages/admin/orders.php',
    'admin_order_sheet' => 'pages/admin/order_sheet.php',
    'admin_invoices' => 'pages/admin/invoices.php',
    'admin_invoice_generate' => 'pages/admin/invoice_generate.php',
    'admin_invoice_manual' => 'pages/admin/invoice_manual.php',
    'admin_invoice_view' => 'pages/admin/invoice_view.php',
    'admin_users' => 'pages/admin/users.php',
    'admin_account' => 'pages/admin/account.php',
    'admin_tasks' => 'pages/admin/tasks.php',
    'admin_extract_sites' => 'pages/admin/extract_sites.php',
    'admin_extract_emails' => 'pages/admin/extract_emails.php',

    'team_dashboard' => 'pages/team/dashboard.php',
    'team_prospect_check' => 'pages/team/prospect_check.php',
    'team_prospects' => 'pages/team/prospects.php',
    'team_prospect_form' => 'pages/team/prospect_form.php',
    'team_prospect_batches' => 'pages/team/prospect_batches.php',
    'team_prospect_batch' => 'pages/team/prospect_batch.php',
    'team_tasks' => 'pages/team/tasks.php',
    'team_extract_submit' => 'pages/team/extract_submit.php',
    'team_extract_queue' => 'pages/team/extract_queue.php',
    'team_extract_work' => 'pages/team/extract_work.php',
    'team_extract_final' => 'pages/team/extract_final.php',
    'team_extract_emails' => 'pages/team/extract_emails.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo 'Page not found.';
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

require __DIR__ . '/' . $routes[$page];
