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
    $logo = brand_logo_url();

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h($app) . '</title>';
    if ($base !== '') {
        echo '<base href="' . h($base . '/') . '">';
    }
    // One stylesheet URL (asset.php) — avoid loading CSS twice.
    echo '<link rel="stylesheet" href="' . h($cssPhp) . '">';
    echo '</head><body>';

    if (!$user || $panel === '') {
        return;
    }

    $home = $panel === 'admin' ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard';
    $current = current_route_page();
    $roleLabel = $panel === 'admin' ? 'Admin' : 'Team';
    $flashes = get_flashes();
    $clearDraft = false;
    foreach ($flashes as $flash) {
        if (($flash['type'] ?? '') === 'ok') {
            $clearDraft = true;
            break;
        }
    }

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
                'admin_prospects' => ['Our database', 'Country folders → URLs'],
                'admin_prospect_add' => ['Add sites', 'Paste into a country database'],
                'admin_prospect_batches' => ['Add history', 'Who added what, by day'],
                'admin_orders' => ['Order management', 'Client sheets · prices · live URLs'],
                'admin_invoices' => ['Invoices', 'Generate printable client invoices'],
                'admin_users' => ['Users', 'Admin and Team logins'],
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
    echo '<a href="index.php?page=account_password">Change password</a>';
    echo '<a href="index.php?page=logout">Logout</a>';
    echo '</div>';
    echo '</nav></aside><main class="main" data-draft-panel="' . h($panel) . '" data-draft-clear="' . ($clearDraft ? '1' : '0') . '">';
    foreach ($flashes as $flash) {
        render_alert_box((string) ($flash['type'] ?? 'ok'), (string) ($flash['message'] ?? ''));
    }
}

function render_footer(string $panel = ''): void
{
    if (current_user() && $panel !== '') {
        render_project_credit();
        if ($panel === 'admin' || $panel === 'team') {
            $user = current_user();
            $jsVersion = (string) (@filemtime(dirname(__DIR__) . '/assets/js/draft-autosave.js') ?: time());
            $jsPhp = app_url('asset.php?f=js/draft-autosave.js&v=' . rawurlencode($jsVersion));
            $jsFile = asset_url('assets/js/draft-autosave.js');
            $tipVersion = (string) (@filemtime(dirname(__DIR__) . '/assets/js/info-tips.js') ?: time());
            $tipPhp = app_url('asset.php?f=js/info-tips.js&v=' . rawurlencode($tipVersion));
            $tipFile = asset_url('assets/js/info-tips.js');
            echo '<script>';
            echo 'window.TXF_DRAFT=' . json_encode([
                'panel' => $panel,
                'userId' => (int) ($user['id'] ?? 0),
                'clearDraft' => false,
            ], JSON_UNESCAPED_UNICODE) . ';';
            echo 'if(document.querySelector("main.main[data-draft-clear=\\"1\\"]")){window.TXF_DRAFT.clearDraft=true;}';
            echo '</script>';
            echo '<script src="' . h($jsPhp) . '" defer></script>';
            echo '<script src="' . h($jsFile) . '" defer></script>';
            echo '<script src="' . h($tipPhp) . '" defer></script>';
            echo '<script src="' . h($tipFile) . '" defer></script>';
        }
        echo '</main></div>';
    }
    echo '<script src="' . h(script_url('js/searchable-select.js')) . '" defer></script>';
    echo '<script src="' . h(script_url('js/live-clock.js')) . '" defer></script>';
    echo '</body></html>';
}

/** Footer credit: TechxForm is a project of Teqnowebs. */
function render_project_credit(): void
{
    $app = 'TechxForm';
    try {
        $app = (string) (app_config()['app_name'] ?? 'TechxForm');
    } catch (Throwable $e) {
        $app = 'TechxForm';
    }
    if ($app === '') {
        $app = 'TechxForm';
    }
    echo '<p class="project-credit">';
    echo h($app) . ' is a project of ';
    echo '<a href="https://teqnowebs.com" target="_blank" rel="noopener noreferrer">Teqnowebs</a>';
    echo '</p>';
}
