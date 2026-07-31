<?php
session_start();

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
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
    'admin_pitch_create' => 'pages/admin/pitch_create.php',
    'admin_pitch_item' => 'pages/admin/pitch_item.php',
    'admin_sites' => 'pages/admin/sites.php',
    'admin_site_form' => 'pages/admin/site_form.php',
    'admin_users' => 'pages/admin/users.php',
    'admin_published' => 'pages/admin/published.php',
    'team_dashboard' => 'pages/team/dashboard.php',
    'team_projects' => 'pages/team/projects.php',
    'team_project' => 'pages/team/project_detail.php',
    'team_sites' => 'pages/team/sites.php',
    'team_site_form' => 'pages/team/site_form.php',
    'team_results' => 'pages/team/results.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

require __DIR__ . '/' . $routes[$page];
