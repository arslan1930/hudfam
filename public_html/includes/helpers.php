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

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function reject_reasons(): array
{
    return [
        'already_used' => 'Already used',
        'casino_links' => 'Casino links',
        'weak_site' => 'Weak site',
        'mfa' => 'MFA (Made for advertising)',
        'bad_niche' => 'Bad niche fit',
        'price_high' => 'Price too high',
        'low_metrics' => 'Low traffic / metrics',
        'other' => 'Other',
    ];
}

function site_statuses(): array
{
    return [
        'draft' => 'Draft',
        'negotiating' => 'Negotiating',
        'agreed' => 'Agreed',
        'sent' => 'Sent',
        'rejected' => 'Rejected',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'blocked' => 'Blocked',
    ];
}

/** Admin inventory order status (publication / deal order), not pitch item status. */
function inventory_order_statuses(): array
{
    return [
        '' => '—',
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'on_hold' => 'On hold',
        'cancelled' => 'Cancelled',
    ];
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

function project_statuses(): array
{
    return [
        'active' => 'Active',
        'paused' => 'Paused',
        'archived' => 'Archived',
        'completed' => 'Completed',
    ];
}

function pitch_item_statuses(): array
{
    return [
        'sent' => 'Sent',
        'rejected' => 'Rejected',
        'processing' => 'Processing',
        'completed' => 'Completed',
    ];
}

function publication_order_statuses(): array
{
    return [
        'processing' => 'Processing',
        'completed' => 'Completed',
    ];
}

/** Resolve a status code to a human label across known status maps. */
function status_label(string $status): string
{
    if ($status === '') {
        return '—';
    }
    $maps = [
        site_statuses(),
        prospect_statuses(),
        project_statuses(),
        pitch_item_statuses(),
        publication_order_statuses(),
        inventory_order_statuses(),
    ];
    foreach ($maps as $map) {
        if (array_key_exists($status, $map) && $map[$status] !== '') {
            return (string) $map[$status];
        }
    }
    // Fallback: title-case snake_case codes
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
 * Short glossary clarifying Prospects vs Catalog vs Super search.
 * $panel: 'admin' | 'team'
 */
function render_glossary(string $panel): void
{
    echo '<div class="glossary card" role="note">';
    echo '<h2 class="glossary-title">Quick guide</h2>';
    echo '<dl class="glossary-list">';
    echo '<div><dt>Prospects</dt><dd>Outreach list — domains to contact. <strong>No prices.</strong></dd></div>';
    echo '<div><dt>Catalog</dt><dd>Priced sites inside projects (quotes, mailbox, deal status).</dd></div>';
    if ($panel === 'team') {
        echo '<div><dt>Super search</dt><dd>Duplicate check across catalog — site metrics only (no client secrets).</dd></div>';
        echo '<div><dt>Workflow</dt><dd>Filter uniques → Prospects → Open a project → Add priced sites → Watch Results.</dd></div>';
    } else {
        echo '<div><dt>Super search</dt><dd>Find domains inside a project (or Team’s safe catalog search).</dd></div>';
        echo '<div><dt>Workflow</dt><dd>Create project → Build catalog → Send pack → Track orders.</dd></div>';
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
