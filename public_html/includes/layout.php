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
    $aliases = [
        'admin_prospects' => ['admin_prospect_add'],
        'admin_prospect_batches' => ['admin_prospect_batch'],
        'admin_extract_sites' => [],
        'admin_extract_emails' => [],
        'admin_users' => ['admin_tasks'],
        'admin_account' => [],
        'team_prospect_check' => [],
        'team_prospect_batches' => ['team_prospect_batch'],
        'team_dashboard' => ['team_tasks'],
        'team_extract_submit' => [],
        'team_extract_queue' => ['team_extract_work'],
        'team_extract_final' => [],
        'team_extract_emails' => [],
    ];
    return in_array($current, $aliases[$navPage] ?? [], true);
}

function brand_logo_url(): string
{
    $file = dirname(__DIR__) . '/assets/img/techxform-logo.svg';
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=img/techxform-logo.svg&v=' . rawurlencode($v));
}

function render_header(string $title, string $panel = ''): void
{
    $app = app_config()['app_name'] ?? 'TechxForm';
    $user = current_user();
    $base = app_base_path();
    $cssPhp = stylesheet_url();
    $cssFile = asset_url('assets/css/app.css');
    $logo = brand_logo_url();

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h($app) . '</title>';
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
            'Main' => [
                'admin_dashboard' => ['Dashboard', 'Overview'],
            ],
            'Sites Data' => [
                'admin_prospects' => ['Countries', 'Browse country folders'],
                'admin_prospect_add' => ['Sites add by admin', 'Paste into a country'],
                'admin_prospect_batches' => ['Added sites', 'Who added what, by day'],
            ],
            'Extracting Sites with Emails' => [
                'admin_extract_sites' => ['Extracted sites', 'Block 1 queue + Block 2 final'],
                'admin_extract_emails' => ['Extracted sites with Emails', 'Emails under each site'],
            ],
            'People' => [
                'admin_users' => ['Users', 'Accounts & assign tasks'],
                'admin_account' => ['Account', 'Email & password'],
            ],
        ];
    } else {
        $groups = [
            'Main' => [
                'team_dashboard' => ['Dashboard', 'Overview'],
                'team_prospect_check' => ['Filter & add', 'Paste → add unique'],
                'team_prospect_batches' => ['Added sites', 'Your daily adds'],
            ],
            'Extraction' => [
                'team_extract_submit' => ['Submit for extraction', 'Block 1 · Team 1'],
                'team_extract_queue' => ['Claim & extract', 'Open Block 1 batch'],
                'team_extract_final' => ['Paste extracted', 'Block 2 · final list'],
                'team_extract_emails' => ['Add emails', 'Emails per site'],
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
    echo '<script src="' . h(script_url('js/searchable-select.js')) . '" defer></script>';
    echo '<script src="' . h(script_url('js/live-clock.js')) . '" defer></script>';
    echo '</body></html>';
}
