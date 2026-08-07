<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Web path of the folder that contains index.php ('' at domain root, '/subdir' otherwise).
 * Fixes broken CSS/JS when the app is not at the domain root on Hostinger.
 */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    // asset.php / install.php / upgrade.php also live in the app root
    $dir = dirname($script);
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        $base = '';
    } else {
        $base = rtrim($dir, '/');
    }
    return $base;
}

/** App-root URL for a relative path like "index.php?page=login" or "assets/css/app.css". */
function app_url(string $path = ''): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = app_base_path();
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

/**
 * URL for a file under public_html/ (adds ?v=mtime cache-bust).
 * Prefer this for CSS so styles always load in subfolders / after deploy.
 */
function asset_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $url = app_url($relativePath);
    $file = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($file)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
    }
    return $url;
}

/**
 * Stylesheet URL that always works via PHP (Hostinger-safe fallback).
 * Use when the static /assets/ path 404s due to upload/path mistakes.
 */
function stylesheet_url(): string
{
    $file = dirname(__DIR__) . '/assets/css/app.css';
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=css/app.css&v=' . rawurlencode($v));
}

/** Hostinger-safe JS URL via asset.php allowlist. */
function script_asset_url(string $f): string
{
    $f = ltrim(str_replace('\\', '/', $f), '/');
    if (str_starts_with($f, 'assets/')) {
        $f = substr($f, strlen('assets/'));
    }
    $file = dirname(__DIR__) . '/assets/' . $f;
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=' . rawurlencode($f) . '&v=' . rawurlencode($v));
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Pretty alert / flash message box used across the app. */
function render_alert_box(string $type, string $message): void
{
    $isError = $type === 'error';
    $cls = $isError ? 'alert-error' : 'alert-ok';
    $alertTitle = $isError ? 'Error' : 'Success';
    echo '<div class="alert-box ' . h($cls) . '" role="alert">';
    echo '<div class="alert-icon" aria-hidden="true">' . ($isError ? '!' : '✓') . '</div>';
    echo '<div class="alert-body">';
    echo '<strong class="alert-title">' . h($alertTitle) . '</strong>';
    echo '<p class="alert-text">' . h($message) . '</p>';
    echo '</div></div>';
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function prospect_statuses(): array
{
    return [
        'new' => 'New',
        'contacting' => 'Contacting',
        'replied' => 'Replied',
        'skipped' => 'Skipped',
    ];
}

/** Resolve a status code to a human label. */
function status_label(string $status): string
{
    if ($status === '') {
        return '—';
    }
    $map = prospect_statuses();
    if (array_key_exists($status, $map) && $map[$status] !== '') {
        return (string) $map[$status];
    }
    return ucwords(str_replace('_', ' ', $status));
}

function badge(string $status): string
{
    $label = status_label($status);
    $cls = preg_replace('/[^a-z0-9_-]/i', '', $status) ?: 'unknown';
    return '<span class="badge ' . h($cls) . '">' . h($label) . '</span>';
}

function money_or_dash($value): string
{
    return $value === null || $value === '' ? '—' : h((string) $value);
}

/**
 * Small “i” info icon with tooltip (hover / focus / tap).
 */
function info_icon(string $tip, string $aria = 'More info'): string
{
    $tip = trim($tip);
    if ($tip === '') {
        return '';
    }
    return '<button type="button" class="info-tip" aria-label="' . h($aria) . '">'
        . '<span class="info-tip-mark" aria-hidden="true">i</span>'
        . '<span class="info-tip-bubble" role="tooltip">' . h($tip) . '</span>'
        . '</button>';
}

/**
 * Label text + info icon (escaped label).
 */
function label_with_info(string $label, string $tip): string
{
    return '<span class="with-info"><span class="with-info-label">' . h($label) . '</span>'
        . info_icon($tip, 'About ' . $label) . '</span>';
}

/**
 * Breadcrumb trail. Each crumb: ['label' => string, 'href' => ?string]
 */
function render_breadcrumbs(array $crumbs): void
{
    if (!$crumbs) {
        return;
    }
    echo '<nav class="breadcrumbs" aria-label="Breadcrumb">';
    $last = count($crumbs) - 1;
    foreach ($crumbs as $i => $crumb) {
        if ($i > 0) {
            echo '<span class="sep" aria-hidden="true">›</span>';
        }
        $label = (string) ($crumb['label'] ?? '');
        $href = $crumb['href'] ?? null;
        if ($href && $i < $last) {
            echo '<a href="' . h((string) $href) . '">' . h($label) . '</a>';
        } else {
            echo '<span class="current">' . h($label) . '</span>';
        }
    }
    echo '</nav>';
}

/**
 * Short glossary for the simple inventory panel.
 * $panel: 'admin' | 'team'
 */
function render_glossary(string $panel): void
{
    echo '<div class="glossary card" role="note">';
    echo '<h2 class="glossary-title">How this works</h2>';
    echo '<dl class="glossary-list">';
    if ($panel === 'admin') {
        echo '<div><dt>Our database</dt><dd>Country folders — browse and add sites (Admin only).</dd></div>';
        echo '<div><dt>Extracted URLs</dt><dd>Extracted Sites from Team Push.</dd></div>';
        echo '<div><dt>Emails DATA</dt><dd>Admin/Final archives + Email campaign sheets for Communication Team search.</dd></div>';
        echo '<div><dt>New badge</dt><dd>Reminder when Team adds Our database sites, Extracted Sites, or Admin emails — clears when you open that section.</dd></div>';
        echo '<div><dt>Filter &amp; add</dt><dd>Team pastes a list → remove domains already in the database → save only new ones.</dd></div>';
        echo '<div><dt>Add history</dt><dd>Who added which sites, saved by person and day.</dd></div>';
        echo '<div><dt>Your job</dt><dd>Manage Our database and Team users.</dd></div>';
    } else {
        echo '<div><dt>Filter &amp; add</dt><dd>Paste a list → duplicates are removed privately → save only new unique sites.</dd></div>';
        echo '<div><dt>Extracting sites</dt><dd>Per-country Sites list + Extracting Results. Appears after teammates add sites.</dd></div>';
        echo '<div><dt>Sites with emails - Team</dt><dd>From Extracting Results Push → add emails → Push to Admin.</dd></div>';
        echo '<div><dt>Add history</dt><dd>Sites you added, saved by day.</dd></div>';
        echo '<div><dt>Your job</dt><dd>Filter new sites and add only the unique ones. Existing country lists stay private.</dd></div>';
    }
    echo '</dl></div>';
}

/**
 * Compact numbered workflow strip for dashboards.
 * $steps: list of ['label' => string, 'href' => ?string, 'hint' => ?string]
 */
function render_workflow(array $steps): void
{
    if (!$steps) {
        return;
    }
    echo '<ol class="workflow">';
    foreach ($steps as $i => $step) {
        $n = $i + 1;
        $label = (string) ($step['label'] ?? '');
        $href = $step['href'] ?? null;
        $hint = (string) ($step['hint'] ?? '');
        echo '<li>';
        echo '<span class="workflow-n">' . $n . '</span>';
        echo '<span class="workflow-body">';
        if ($href) {
            echo '<a href="' . h((string) $href) . '">' . h($label) . '</a>';
        } else {
            echo '<strong>' . h($label) . '</strong>';
        }
        if ($hint !== '') {
            echo '<span class="muted">' . h($hint) . '</span>';
        }
        echo '</span></li>';
    }
    echo '</ol>';
}
