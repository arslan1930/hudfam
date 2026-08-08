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
        'admin_extracted' => [],
        'admin_emails_data' => [],
        'admin_departments' => [],
        'admin_orders' => ['admin_order_sheet'],
        'admin_invoices' => ['admin_invoice_generate', 'admin_invoice_manual', 'admin_invoice_view'],
        'team_prospect_check' => [],
        'team_prospect_batches' => ['team_prospect_batch'],
        'team_extracting' => ['team_extract_batch'],
        'team_departments' => [],
        'team_admin_emails_delete' => [],
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
    $flashes = get_flashes();
    $clearDraft = false;
    foreach ($flashes as $flash) {
        if (($flash['type'] ?? '') === 'ok') {
            $clearDraft = true;
            break;
        }
    }

    echo '<div class="shell">';
    echo '<div class="mobile-bar">';
    echo '<button type="button" class="nav-toggle" data-nav-toggle aria-controls="app-sidebar" aria-expanded="false">Menu</button>';
    echo '<a class="mobile-brand" href="' . h($home) . '">';
    echo '<img class="brand-logo" src="' . h($logo) . '" alt="">';
    echo '<span>' . h($app) . '</span></a>';
    echo '</div>';
    echo '<div class="sidebar-backdrop" data-nav-backdrop hidden></div>';
    echo '<aside class="sidebar" id="app-sidebar">';
    echo '<a class="brand" href="' . h($home) . '">';
    echo '<img class="brand-logo" src="' . h($logo) . '" alt="' . h($app) . '">';
    echo '<span>' . h($app) . '</span></a>';
    echo '<div class="sidebar-role">' . h($roleLabel) . ' · ' . h((string) ($user['username'] ?? '')) . '</div>';
    echo '<nav aria-label="' . h($roleLabel) . ' navigation">';

    if ($panel === 'admin') {
        $groups = [
            'Main' => [
                'admin_dashboard' => ['Dashboard', 'Overview'],
                'admin_departments' => ['Departments', 'Site Finding · Extracting · Email · Communication'],
                'admin_prospects' => ['Our database', 'Country folders · add sites · browse'],
                'admin_prospect_batches' => ['Site adding history', 'Who added what, by day'],
                'admin_extracted' => ['Extracted Sites', 'From Team Extracting Results Push'],
                'admin_emails_data' => ['Emails data', 'Archives · campaign sheets'],
                'admin_orders' => ['Order management', 'Client sheets · prices · live URLs'],
                'admin_invoices' => ['Invoices', 'Generate printable client invoices'],
                'admin_users' => ['Users', 'Admin and Team logins'],
            ],
        ];
    } else {
        $deptScoped = function_exists('user_is_department_scoped') && user_is_department_scoped($user);
        if ($deptScoped) {
            // Department members: tasks + tools for their departments.
            $groups = [
                'Main' => [
                    'team_dashboard' => ['Dashboard', 'Your assigned tasks and tools'],
                    'team_departments' => ['My departments', 'Only departments you belong to'],
                ],
            ];
            if (function_exists('list_departments_for_user')) {
                $mine = list_departments_for_user((int) ($user['id'] ?? 0));
                $toolPages = function_exists('department_tool_pages_for_user')
                    ? department_tool_pages_for_user($user)
                    : [];
                $toolSet = array_fill_keys($toolPages, true);
                if ($mine) {
                    $deptLinks = [];
                    foreach ($mine as $d) {
                        $slug = (string) $d['slug'];
                        $deptLinks['team_departments&folder=' . rawurlencode($slug)] = [
                            (string) $d['name'],
                            'Tasks for this department',
                        ];
                    }
                    $groups['Departments'] = $deptLinks;
                }
                if (!empty($toolSet['team_prospect_check'])) {
                    $groups['Main']['team_prospect_check'] = [
                        'Filter & add',
                        'Paste → filter → add new unique only',
                    ];
                    $groups['Main']['team_prospect_batches'] = [
                        'Site adding history',
                        'Your daily adds',
                    ];
                }
                if (!empty($toolSet['team_extracting'])) {
                    $groups['Main']['team_extracting'] = [
                        'Extracting sites',
                        'Sites list + Extracting Results per country',
                    ];
                }
                if (!empty($toolSet['team_sites_emails'])) {
                    $groups['Main']['team_sites_emails'] = [
                        'Sites with emails - Team',
                        'Add emails · Push to Admin',
                    ];
                }
                if (!empty($toolSet['team_admin_emails_delete'])) {
                    $groups['Main']['team_admin_emails_delete'] = [
                        'Admin emails search',
                        'Sites with emails - Admin · all countries',
                    ];
                }
                if (!empty($toolSet['team_email_campaigns'])) {
                    $groups['Main']['team_email_campaigns'] = [
                        'Campaign search',
                        'Email campaign sheets · all countries',
                    ];
                }
            }
        } elseif (function_exists('team_user_awaits_department') && team_user_awaits_department($user)) {
            // Team login with no department yet — no tools until Admin assigns one.
            $groups = [
                'Main' => [
                    'team_dashboard' => ['Dashboard', 'Waiting for department assignment'],
                    'team_departments' => ['My departments', 'Ask Admin to assign you'],
                ],
            ];
        } else {
            // Admin browsing Team UI (or non-scoped): full tool set.
            $groups = [
                'Main' => [
                    'team_dashboard' => ['Dashboard', 'Overview'],
                    'team_prospect_check' => ['Filter & add', 'Paste → filter → add new unique only'],
                    'team_extracting' => ['Extracting sites', 'Sites list + Extracting Results per country'],
                    'team_sites_emails' => ['Sites with emails - Team', 'Add emails · Push final list to Admin'],
                    'team_admin_emails_delete' => ['Admin emails search', 'Sites with emails - Admin · all countries'],
                    'team_email_campaigns' => ['Campaign search', 'Email campaign sheets · all countries'],
                    'team_departments' => ['My departments', 'If Admin assigns you to a department'],
                    'team_prospect_batches' => ['Site adding history', 'Your daily adds'],
                ],
            ];
        }
    }

    foreach ($groups as $groupLabel => $links) {
        echo '<div class="nav-group">';
        echo '<div class="nav-group-label">' . h($groupLabel) . '</div>';
        foreach ($links as $page => $meta) {
            $label = is_array($meta) ? (string) $meta[0] : (string) $meta;
            // Support "page&folder=slug" keys for department shortcuts
            $hrefPage = $page;
            $activePage = $page;
            if (str_contains($page, '&')) {
                $parts = explode('&', $page, 2);
                $activePage = $parts[0];
                $hrefPage = $page;
            }
            $active = '';
            if ($current === $activePage) {
                if (str_contains($page, 'folder=')) {
                    parse_str(substr($page, strpos($page, '&') + 1), $qs);
                    $active = ((string) ($_GET['folder'] ?? '') === (string) ($qs['folder'] ?? '')) ? ' active' : '';
                } else {
                    $active = nav_is_active($activePage, $current) ? ' active' : '';
                }
            }
            $ariaCurrent = trim($active) !== '' ? ' aria-current="page"' : '';
            echo '<a class="' . trim($active) . '" href="index.php?page=' . h($hrefPage) . '"' . $ariaCurrent . '>';
            echo '<span class="nav-label">' . h($label) . '</span>';
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
            $navVersion = (string) (@filemtime(dirname(__DIR__) . '/assets/js/nav-shell.js') ?: time());
            $navPhp = app_url('asset.php?f=js/nav-shell.js&v=' . rawurlencode($navVersion));
            $navFile = asset_url('assets/js/nav-shell.js');
            $procVersion = (string) (@filemtime(dirname(__DIR__) . '/assets/js/app-processing.js') ?: time());
            $procPhp = app_url('asset.php?f=js/app-processing.js&v=' . rawurlencode($procVersion));
            $procFile = asset_url('assets/js/app-processing.js');
            echo '<script>';
            echo 'window.TXF_DRAFT=' . json_encode([
                'panel' => $panel,
                'userId' => (int) ($user['id'] ?? 0),
                'clearDraft' => false,
            ], JSON_UNESCAPED_UNICODE) . ';';
            echo 'if(document.querySelector("main.main[data-draft-clear=\\"1\\"]")){window.TXF_DRAFT.clearDraft=true;}';
            echo '</script>';
            echo '<script src="' . h($procPhp) . '" defer></script>';
            echo '<script src="' . h($procFile) . '" defer></script>';
            echo '<script src="' . h($jsPhp) . '" defer></script>';
            echo '<script src="' . h($jsFile) . '" defer></script>';
            echo '<script src="' . h($tipPhp) . '" defer></script>';
            echo '<script src="' . h($tipFile) . '" defer></script>';
            echo '<script src="' . h($navPhp) . '" defer></script>';
            echo '<script src="' . h($navFile) . '" defer></script>';
        }
        echo '</main></div>';
        // Global Processing / Loading overlay (Admin + Team shell).
        echo '<div id="app-processing" class="app-processing" hidden aria-busy="false" aria-live="assertive" role="alert">';
        echo '<div class="app-processing-card">';
        echo '<div class="app-processing-spinner" aria-hidden="true"></div>';
        echo '<p class="app-processing-msg" data-processing-msg>Processing…</p>';
        echo '<p class="app-processing-sub muted">Please wait — do not close this page.</p>';
        echo '</div></div>';
    }
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
