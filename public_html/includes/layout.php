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
        'admin_sites' => ['admin_site_form', 'admin_catalog_site_form', 'admin_bulk_import'],
        'admin_clients' => ['admin_client', 'admin_client_form', 'admin_order_form'],
        'admin_prospects' => ['admin_prospect_add'],
        'admin_prospect_batches' => ['admin_prospect_batch'],
        'admin_email_campaigns' => ['admin_email_campaign_import'],
        'team_projects' => ['team_project', 'team_project_filter', 'team_site_form', 'team_sites'],
        'team_prospects' => ['team_prospect_form'],
        'team_prospect_check' => [],
        'team_prospect_batches' => ['team_prospect_batch'],
        'team_countries' => ['team_country'],
        'team_email_campaigns' => [],
        'team_email_search' => [],
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
                'admin_dashboard' => ['Dashboard', 'How the panel works + stats'],
            ],
            'Catalog & projects' => [
                'admin_projects' => ['Projects', 'Client/campaign folders'],
                'admin_sites' => ['Catalog', 'Priced sites: project → country'],
                'admin_bulk_import' => ['Bulk import', 'CSV into one project country sheet'],
            ],
            'Outreach' => [
                'admin_prospects' => ['Our inventory', 'Unique domains · no prices'],
                'admin_prospect_batches' => ['Prospect batches', 'Who added what, by day'],
                'admin_email_campaigns' => ['Email campaigns', 'URL + email by country'],
            ],
            'Clients & orders' => [
                'admin_clients' => ['Clients', 'Who receives packs'],
                'admin_orders_export' => ['Orders export', 'Export agreed/order rows'],
                'admin_published' => ['Published', 'Live published placements'],
            ],
            'Settings' => [
                'admin_countries' => ['Countries', 'Master country list'],
                'admin_users' => ['Admins & users', 'Logins for Admin and Team'],
            ],
        ];
    } else {
        $groups = [
            'Overview' => [
                'team_dashboard' => ['Dashboard', 'How the panel works'],
            ],
            'Prospects' => [
                'team_prospect_check' => ['Filter & add', 'Paste domains → keep uniques'],
                'team_prospects' => ['Our inventory', 'Unique domains · no prices'],
                'team_prospect_batches' => ['Dated batches', 'What you added by day'],
            ],
            'Email campaigns' => [
                'team_email_search' => ['Cut replied emails', 'Remove replied from Ready list'],
                'team_email_campaigns' => ['Country sheets', 'Browse URL + email by country'],
            ],
            'Catalog' => [
                'team_search' => ['Catalog search', 'Project → country → language'],
                'team_projects' => ['Projects', 'Your assigned client folders'],
                'team_results' => ['Results', 'Client agreed / rejected feedback'],
            ],
            'Reference' => [
                'team_countries' => ['Countries', 'Country reference list'],
            ],
        ];
    }

    foreach ($groups as $groupLabel => $links) {
        echo '<div class="nav-group">';
        echo '<div class="nav-group-label">' . h($groupLabel) . '</div>';
        foreach ($links as $page => $meta) {
            $label = is_array($meta) ? (string) $meta[0] : (string) $meta;
            $hint = is_array($meta) ? (string) ($meta[1] ?? '') : '';
            $active = nav_is_active($page, $current) ? ' active' : '';
            $titleAttr = $hint !== '' ? ' title="' . h($hint) . '"' : '';
            echo '<a class="' . trim($active) . '" href="index.php?page=' . h($page) . '"' . $titleAttr . '>';
            echo '<span class="nav-label">' . h($label) . '</span>';
            if ($hint !== '') {
                echo '<span class="nav-hint">' . h($hint) . '</span>';
            }
            echo '</a>';
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
