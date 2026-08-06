<?php
session_start();

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/geo.php';
require __DIR__ . '/includes/prospects.php';
require __DIR__ . '/includes/mail.php';
require __DIR__ . '/includes/account.php';
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
    'admin_users' => 'pages/admin/users.php',
    'admin_account' => 'pages/admin/account.php',
    'admin_tasks' => 'pages/admin/tasks.php',

    'team_dashboard' => 'pages/team/dashboard.php',
    'team_prospect_check' => 'pages/team/prospect_check.php',
    'team_prospects' => 'pages/team/prospects.php',
    'team_prospect_form' => 'pages/team/prospect_form.php',
    'team_prospect_batches' => 'pages/team/prospect_batches.php',
    'team_prospect_batch' => 'pages/team/prospect_batch.php',
    'team_tasks' => 'pages/team/tasks.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

require __DIR__ . '/' . $routes[$page];
