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
        'admin_prospect_batches' => ['admin_prospect_batch'],
        'team_projects' => ['team_project', 'team_site_form', 'team_sites'],
        'team_prospects' => ['team_prospect_form'],
        'team_prospect_check' => [],
        'team_prospect_batches' => ['team_prospect_batch'],
        'team_countries' => ['team_country'],
        'team_email_campaigns' => ['team_email_search'],
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
    $current = (string) ($_GET['page'] ?? '');
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
    $displayName = trim((string) ($user['full_name'] ?? '')) !== ''
        ? $user['full_name']
        : $user['username'];

    echo '<div class="shell"><aside class="sidebar">';
    echo '<a class="brand" href="' . h($home) . '">' . h($app) . '</a>';
    echo '<div class="sidebar-user">' . h($displayName);
    echo '<span>' . h($panel === 'admin' ? 'Admin' : 'Team') . '</span></div>';
    echo '<nav>';

    if ($panel === 'admin') {
        $sections = [
            '' => [
                'admin_dashboard' => 'Dashboard',
            ],
            'Campaigns' => [
                'admin_projects' => 'Projects',
                'admin_sites' => 'Catalog',
                'admin_bulk_import' => 'Bulk import',
                'admin_clients' => 'Clients',
                'admin_orders_export' => 'Orders',
                'admin_published' => 'Published',
            ],
            'Team list' => [
                'admin_prospects' => 'Prospects',
                'admin_prospect_batches' => 'Batches',
            ],
            'Setup' => [
                'admin_countries' => 'Countries',
                'admin_users' => 'Users',
            ],
        ];
    } else {
        $sections = [
            '' => [
                'team_dashboard' => 'Dashboard',
            ],
            'Prospects' => [
                'team_prospect_check' => 'Filter & add',
                'team_prospect_batches' => 'My batches',
                'team_prospects' => 'All sites',
            ],
            'Work' => [
                'team_search' => 'Catalog search',
                'team_projects' => 'Projects',
                'team_results' => 'Results',
                'team_countries' => 'Countries',
            ],
        ];
    }

    foreach ($sections as $section => $links) {
        if ($section !== '') {
            echo '<div class="nav-section">' . h($section) . '</div>';
        }
        foreach ($links as $page => $label) {
            $cls = $current === $page ? ' class="active"' : '';
            // Highlight related detail pages under parent nav
            if ($page === 'team_prospect_batches' && $current === 'team_prospect_batch') {
                $cls = ' class="active"';
            }
            if ($page === 'admin_projects' && in_array($current, ['admin_project', 'admin_project_form', 'admin_pitch_create', 'admin_pitch_item'], true)) {
                $cls = ' class="active"';
            }
            if ($page === 'admin_sites' && $current === 'admin_site_form') {
                $cls = ' class="active"';
            }
            if ($page === 'admin_clients' && in_array($current, ['admin_client', 'admin_client_form', 'admin_order_form'], true)) {
                $cls = ' class="active"';
            }
            if ($page === 'team_projects' && $current === 'team_project') {
                $cls = ' class="active"';
            }
            if ($page === 'team_prospect_check' && $current === 'team_prospect_form') {
                $cls = ' class="active"';
            }
            echo '<a href="index.php?page=' . h($page) . '"' . $cls . '>' . h($label) . '</a>';
        }
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
