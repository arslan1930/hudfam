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
        'team_prospect_check' => [],
        'team_prospect_batches' => ['team_prospect_batch'],
        'account_password' => [],
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
                'admin_prospects' => ['Our database', 'Country folders → sites'],
                'admin_prospect_add' => ['Add sites', 'Paste into a country database'],
                'admin_prospect_batches' => ['Site adding history', 'Who added what, by day'],
                'admin_users' => ['Users', 'Add & edit who can log in'],
            ],
        ];
    } else {
        $groups = [
            'Main' => [
                'team_dashboard' => ['Dashboard', 'Overview'],
                'team_prospect_check' => ['Filter & add', 'Per country → paste → add unique'],
                'team_prospect_batches' => ['Site adding history', 'Your daily adds'],
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
    echo '<script src="' . h(script_url('js/password-toggle.js')) . '" defer></script>';
    // Move Actions menus to <body> + position:fixed so table/card overflow cannot clip options.
    echo '<script>(function(){';
    echo 'function placeMenu(details){';
    echo 'var menu=details._menu||details.querySelector(".more-actions-menu");';
    echo 'var btn=details.querySelector("summary");';
    echo 'if(!menu||!btn)return;';
    echo 'if(menu.parentNode!==document.body){details._menu=menu;details._menuHome=menu.parentNode;document.body.appendChild(menu);}';
    echo 'menu.hidden=false;';
    echo 'var r=btn.getBoundingClientRect();var mw=Math.max(180,menu.offsetWidth||180);';
    echo 'var left=Math.min(Math.max(8,r.right-mw),window.innerWidth-mw-8);';
    echo 'var top=r.bottom+6;';
    echo 'if(top+menu.offsetHeight>window.innerHeight-8){top=Math.max(8,r.top-menu.offsetHeight-6);}';
    echo 'menu.style.left=left+"px";menu.style.top=top+"px";';
    echo '}';
    echo 'function restoreMenu(details){';
    echo 'var menu=details._menu;if(!menu||!details._menuHome)return;';
    echo 'menu.hidden=true;menu.style.left="";menu.style.top="";';
    echo 'details._menuHome.appendChild(menu);';
    echo '}';
    echo 'document.addEventListener("toggle",function(e){';
    echo 'var t=e.target;if(!t||!t.classList||!t.classList.contains("more-actions"))return;';
    echo 'if(t.open){document.querySelectorAll("details.more-actions[open]").forEach(function(d){if(d!==t){d.removeAttribute("open");restoreMenu(d);}});placeMenu(t);}';
    echo 'else{restoreMenu(t);}';
    echo '},true);';
    echo 'document.addEventListener("click",function(e){';
    echo 'var open=document.querySelector("details.more-actions[open]");if(!open)return;';
    echo 'var menu=open._menu||open.querySelector(".more-actions-menu");';
    echo 'if(open.contains(e.target)||(menu&&menu.contains(e.target)))return;';
    echo 'open.removeAttribute("open");restoreMenu(open);';
    echo '});';
    echo 'window.addEventListener("resize",function(){var o=document.querySelector("details.more-actions[open]");if(o)placeMenu(o);});';
    echo 'window.addEventListener("scroll",function(){var o=document.querySelector("details.more-actions[open]");if(o)placeMenu(o);},true);';
    echo '})();</script>';
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
