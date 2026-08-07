<?php
session_start();

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/extracting.php';
require __DIR__ . '/includes/extracted.php';
require __DIR__ . '/includes/sites_with_emails.php';
require __DIR__ . '/includes/sites_form.php';
require __DIR__ . '/includes/orders.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/guides.php';
require __DIR__ . '/includes/layout.php';

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

$page = (string) ($_GET['page'] ?? '');
if ($page === '' && current_user()) {
    redirect(is_admin() ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard');
}
if ($page === '') {
    redirect('index.php?page=login');
}

// Simple panel: shared URL database + filter/add + history
$routes = [
    'login' => 'pages/login.php',
    'logout' => 'pages/logout.php',

    'admin_dashboard' => 'pages/admin/dashboard.php',
    'admin_prospects' => 'pages/admin/prospects.php',
    'admin_prospect_add' => 'pages/admin/prospect_add.php',
    'admin_prospect_batches' => 'pages/admin/prospect_batches.php',
    'admin_prospect_batch' => 'pages/admin/prospect_batch.php',
    'admin_extracted' => 'pages/admin/extracted.php',
    'admin_orders' => 'pages/admin/orders.php',
    'admin_order_sheet' => 'pages/admin/order_sheet.php',
    'admin_invoices' => 'pages/admin/invoices.php',
    'admin_invoice_generate' => 'pages/admin/invoice_generate.php',
    'admin_invoice_manual' => 'pages/admin/invoice_manual.php',
    'admin_invoice_view' => 'pages/admin/invoice_view.php',
    'admin_users' => 'pages/admin/users.php',

    'team_dashboard' => 'pages/team/dashboard.php',
    'team_prospect_check' => 'pages/team/prospect_check.php',
    'team_prospects' => 'pages/team/prospects.php',
    'team_prospect_form' => 'pages/team/prospect_form.php',
    'team_prospect_batches' => 'pages/team/prospect_batches.php',
    'team_prospect_batch' => 'pages/team/prospect_batch.php',
    'team_extracting' => 'pages/team/extracting.php',
    'team_extract_batch' => 'pages/team/extract_batch.php',
    'team_sites_emails' => 'pages/team/sites_emails.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

require __DIR__ . '/' . $routes[$page];
