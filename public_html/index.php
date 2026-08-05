<?php
session_start();

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/inventory.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/email_campaigns.php';
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

$routes = [
    'login' => 'pages/login.php',
    'logout' => 'pages/logout.php',
    'admin_dashboard' => 'pages/admin/dashboard.php',
    'admin_projects' => 'pages/admin/projects.php',
    'admin_project' => 'pages/admin/project_detail.php',
    'admin_project_form' => 'pages/admin/project_form.php',
    'admin_project_filter' => 'pages/admin/project_filter.php',
    'admin_pitch_create' => 'pages/admin/pitch_create.php',
    'admin_pitch_item' => 'pages/admin/pitch_item.php',
    'admin_sites' => 'pages/admin/sites.php',
    'admin_site_form' => 'pages/admin/site_form.php',
    'admin_bulk_import' => 'pages/admin/bulk_import.php',
    'admin_users' => 'pages/admin/users.php',
    'admin_published' => 'pages/admin/published.php',
    'admin_clients' => 'pages/admin/clients.php',
    'admin_client_form' => 'pages/admin/client_form.php',
    'admin_client' => 'pages/admin/client_detail.php',
    'admin_order_form' => 'pages/admin/order_form.php',
    'admin_orders_export' => 'pages/admin/orders_export.php',
    'admin_countries' => 'pages/admin/countries.php',
    'admin_prospects' => 'pages/admin/prospects.php',
    'admin_prospect_add' => 'pages/admin/prospect_add.php',
    'admin_prospect_batches' => 'pages/admin/prospect_batches.php',
    'admin_prospect_batch' => 'pages/admin/prospect_batch.php',
    'admin_email_campaigns' => 'pages/admin/email_campaigns.php',
    'admin_email_campaign_import' => 'pages/admin/email_campaign_import.php',
    'team_dashboard' => 'pages/team/dashboard.php',
    'team_projects' => 'pages/team/projects.php',
    'team_project' => 'pages/team/project_detail.php',
    'team_project_filter' => 'pages/team/project_filter.php',
    'team_sites' => 'pages/team/sites.php',
    'team_site_form' => 'pages/team/site_form.php',
    'team_search' => 'pages/team/search.php',
    'team_prospects' => 'pages/team/prospects.php',
    'team_prospect_check' => 'pages/team/prospect_check.php',
    'team_prospect_form' => 'pages/team/prospect_form.php',
    'team_prospect_batches' => 'pages/team/prospect_batches.php',
    'team_prospect_batch' => 'pages/team/prospect_batch.php',
    'team_results' => 'pages/team/results.php',
    'team_countries' => 'pages/team/countries.php',
    'team_country' => 'pages/team/country_detail.php',
    'team_email_campaigns' => 'pages/team/email_campaigns.php',
    'team_email_search' => 'pages/team/email_search.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

require __DIR__ . '/' . $routes[$page];
