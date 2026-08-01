<?php

function render_header(string $title, string $panel = ''): void
{
    $app = app_config()['app_name'] ?? 'Hudfam';
    $user = current_user();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h($app) . '</title>';
    echo '<link rel="stylesheet" href="assets/css/app.css">';
    echo '</head><body>';

    if (!$user || $panel === '') {
        return;
    }

    $home = $panel === 'admin' ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard';
    echo '<div class="shell"><aside class="sidebar">';
    echo '<a class="brand" href="' . h($home) . '">' . h($app) . '</a><nav>';
    if ($panel === 'admin') {
        $links = [
            'admin_dashboard' => 'Dashboard',
            'admin_projects' => 'Projects',
            'admin_sites' => 'Inventory',
            'admin_bulk_import' => 'Bulk import',
            'admin_clients' => 'Clients',
            'admin_orders_export' => 'Orders export',
            'admin_countries' => 'Countries',
            'admin_published' => 'Published',
            'admin_users' => 'Users',
        ];
    } else {
        $links = [
            'team_dashboard' => 'Dashboard',
            'team_search' => 'Super search',
            'team_projects' => 'Projects',
            'team_results' => 'Results feed',
            'team_countries' => 'Countries',
        ];
    }
    foreach ($links as $page => $label) {
        echo '<a href="index.php?page=' . h($page) . '">' . h($label) . '</a>';
    }
    echo '<a href="index.php?page=logout">Logout</a>';
    echo '</nav></aside><main class="main">';
    foreach (get_flashes() as $flash) {
        $cls = $flash['type'] === 'error' ? 'error' : '';
        echo '<ul class="messages"><li class="' . h($cls) . '">' . h($flash['message']) . '</li></ul>';
    }
}

function render_footer(string $panel = ''): void
{
    if (current_user() && $panel !== '') {
        echo '</main></div>';
    }
    echo '</body></html>';
}
