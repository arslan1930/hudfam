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
        'admin_projects' => ['admin_project', 'admin_project_form', 'admin_project_filter', 'admin_pitch_create', 'admin_pitch_item'],
        'admin_sites' => ['admin_site_form'],
        'admin_clients' => ['admin_client', 'admin_client_form', 'admin_order_form'],
        'admin_prospects' => ['admin_prospect_add'],
        'admin_prospect_batches' => [],
        'team_projects' => ['team_project', 'team_project_filter', 'team_site_form', 'team_sites'],
        'team_prospects' => ['team_prospect_form'],
        'team_prospect_check' => [],
        'team_prospect_batches' => ['team_prospect_batch'],
        'team_countries' => ['team_country'],
    ];
    return in_array($current, $aliases[$navPage] ?? [], true);
}

function brand_logo_url(): string
{
    // Prefer PHP asset server (Hostinger-safe), with filemtime cache-bust
    $file = dirname(__DIR__) . '/assets/img/techxform-logo.svg';
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=img/techxform-logo.svg&v=' . rawurlencode($v));
}

function render_header(string $title, string $panel = ''): void
{
    $app = app_config()['app_name'] ?? 'TechxForm';
    $user = current_user();
    $base = app_base_path();
    // PHP-served CSS first (Hostinger-safe), then static file as second source
    $cssPhp = stylesheet_url();
    $cssFile = asset_url('assets/css/app.css');
    $logo = brand_logo_url();

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
    echo '<a class="brand" href="' . h($home) . '">';
    echo '<img class="brand-logo" src="' . h($logo) . '" alt="' . h($app) . '">';
    echo '<span>' . h($app) . '</span></a>';
    echo '<div class="sidebar-role">' . h($roleLabel) . ' · ' . h((string) ($user['username'] ?? '')) . '</div>';
    echo '<nav aria-label="' . h($roleLabel) . ' navigation">';

    if ($panel === 'admin') {
        $groups = [
            'Overview' => [
                'admin_dashboard' => 'Dashboard',
            ],
            'Catalog & projects' => [
                'admin_projects' => 'Projects',
                'admin_sites' => 'Catalog',
                'admin_bulk_import' => 'Bulk import',
            ],
            'Outreach' => [
                'admin_prospects' => 'Our inventory',
                'admin_prospect_batches' => 'Prospect batches',
            ],
            'Clients & orders' => [
                'admin_clients' => 'Clients',
                'admin_orders_export' => 'Orders export',
                'admin_published' => 'Published',
            ],
            'Settings' => [
                'admin_countries' => 'Countries',
                'admin_users' => 'Admins & users',
            ],
        ];
    } else {
        $groups = [
            'Overview' => [
                'team_dashboard' => 'Dashboard',
            ],
            'Prospects' => [
                'team_prospect_check' => 'Filter & add',
                'team_prospects' => 'Our inventory',
                'team_prospect_batches' => 'Dated batches',
            ],
            'Catalog' => [
                'team_search' => 'Search all sheets',
                'team_projects' => 'Projects',
                'team_results' => 'Results',
            ],
            'Reference' => [
                'team_countries' => 'Countries',
            ],
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
