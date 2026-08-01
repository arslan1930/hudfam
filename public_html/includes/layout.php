<?php

function current_route_page(): string
{
    return (string) ($_GET['page'] ?? '');
}

/**
 * Whether a nav target should show as active for the current route.
 */
function nav_is_active(string $navPage, string $current): bool
{
    if ($current === $navPage) {
        return true;
    }
    // Highlight parent section for detail/form routes
    $aliases = [
        'admin_projects' => ['admin_project', 'admin_project_form', 'admin_pitch_create', 'admin_pitch_item'],
        'admin_sites' => ['admin_site_form'],
        'admin_clients' => ['admin_client', 'admin_client_form', 'admin_order_form'],
        'admin_prospects' => [],
        'team_projects' => ['team_project', 'team_site_form', 'team_sites'],
        'team_prospects' => ['team_prospect_form'],
        'team_prospect_check' => [],
        'team_countries' => ['team_country'],
    ];
    return in_array($current, $aliases[$navPage] ?? [], true);
}

function render_header(string $title, string $panel = ''): void
{
    $app = app_config()['app_name'] ?? 'Hudfam';
    $user = current_user();
    $base = app_base_path();
    // PHP-served CSS first (Hostinger-safe), then static file as second source
    $cssPhp = stylesheet_url();
    $cssFile = asset_url('assets/css/app.css');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h($app) . '</title>';
    // Helps relative links when the app lives in a subfolder (e.g. /hudfam/)
    if ($base !== '') {
        echo '<base href="' . h($base . '/') . '">';
    }
    echo '<link rel="stylesheet" href="' . h($cssPhp) . '">';
    echo '<link rel="stylesheet" href="' . h($cssFile) . '">';
    echo '</head><body>';

    if (!$user || $panel === '') {
        return;
    }

    $home = $panel === 'admin' ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard';
    $current = current_route_page();
    $roleLabel = $panel === 'admin' ? 'Admin' : 'Team';

    echo '<div class="shell"><aside class="sidebar">';
    echo '<a class="brand" href="' . h($home) . '">' . h($app) . '</a>';
    echo '<div class="sidebar-role">' . h($roleLabel) . ' · ' . h((string) ($user['username'] ?? '')) . '</div>';
    echo '<nav aria-label="' . h($roleLabel) . ' navigation">';

    if ($panel === 'admin') {
        $links = [
            'admin_dashboard' => 'Dashboard',
            'admin_projects' => 'Projects',
            'admin_sites' => 'Catalog inventory',
            'admin_bulk_import' => 'Bulk import',
            'admin_prospects' => 'Prospects',
            'admin_prospect_batches' => 'Prospect batches',
            'admin_clients' => 'Clients',
            'admin_orders_export' => 'Orders export',
            'admin_countries' => 'Countries',
            'admin_published' => 'Published',
            'admin_users' => 'Admins & users',
        ];
    } else {
        $links = [
            'team_dashboard' => 'Dashboard',
            'team_prospect_check' => 'Filter & add sites',
            'team_prospect_batches' => 'Dated batches',
            'team_prospects' => 'Old inventory',
            'team_search' => 'Catalog search',
            'team_projects' => 'Projects',
            'team_results' => 'Results feed',
            'team_countries' => 'Countries',
        ];
    }

    foreach ($groups as $groupLabel => $links) {
        echo '<div class="nav-group">';
        echo '<div class="nav-group-label">' . h($groupLabel) . '</div>';
        foreach ($links as $page => $label) {
            $active = nav_is_active($page, $current) ? ' active' : '';
            echo '<a class="' . trim($active) . '" href="index.php?page=' . h($page) . '">' . h($label) . '</a>';
        }
        echo '</div>';
    }

    echo '<div class="nav-group nav-group-end">';
    echo '<a href="index.php?page=logout">Logout</a>';
    echo '</div>';
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
