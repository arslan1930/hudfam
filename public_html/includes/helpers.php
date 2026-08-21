<?php

// Soft polyfills when php-mbstring is missing (Hostinger usually has it).
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Hidden multi-line POST field (domain lists, notes, etc.).
 * Never put multi-line values in <input type="hidden"> — browsers turn
 * newlines in attribute values into spaces, which breaks domain parsing.
 */
function render_hidden_multiline(string $name, string $value, array $opts = []): string
{
    $id = trim((string) ($opts['id'] ?? ''));
    $extraClass = trim((string) ($opts['class'] ?? ''));
    $class = trim('visually-hidden ' . $extraClass);
    return '<textarea name="' . h($name) . '"'
        . ($id !== '' ? ' id="' . h($id) . '"' : '')
        . ' class="' . h($class) . '" aria-hidden="true" tabindex="-1">'
        . h($value)
        . '</textarea>';
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
    $fade = $type === 'fade' || $type === 'ok-fade' || $type === 'dup';
    $cls = $isError ? 'alert-error' : 'alert-ok';
    if ($fade) {
        $cls .= ' alert-fade';
    }
    $alertTitle = $isError ? 'Error' : ($fade ? 'Notice' : 'Success');
    echo '<div class="alert-box ' . h($cls) . '" role="alert"'
        . ($fade ? ' data-alert-fade="1"' : '') . '>';
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

/** Session CSRF token (creates one if missing). */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Hidden input for HTML forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** Read token from POST body or X-CSRF-Token header. */
function csrf_request_token(): string
{
    $fromPost = (string) ($_POST['_csrf'] ?? '');
    if ($fromPost !== '') {
        return $fromPost;
    }
    $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }
    // Some stacks expose custom headers as HTTP_X_CSRF_TOKEN only; also check REDIRECT_ variants.
    $alt = (string) ($_SERVER['REDIRECT_HTTP_X_CSRF_TOKEN'] ?? '');
    return $alt;
}

function csrf_token_valid(?string $token = null): bool
{
    $expected = csrf_token();
    $got = $token ?? csrf_request_token();
    return $got !== '' && hash_equals($expected, $got);
}

/**
 * Reject the request when CSRF is missing/invalid.
 * JSON POSTs (ajax=1 or Accept: application/json) get a JSON 403.
 */
function require_csrf(): void
{
    if (csrf_token_valid()) {
        return;
    }
    $wantsJson = (string) ($_POST['ajax'] ?? '') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($wantsJson) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token. Refresh the page and try again.']);
        exit;
    }
    flash('error', 'Invalid or missing security token. Refresh the page and try again.');
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $refHost = (string) (parse_url($ref, PHP_URL_HOST) ?? '');
    $ownHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (
        $ref !== ''
        && $refHost !== ''
        && $ownHost !== ''
        && strcasecmp($refHost, $ownHost) === 0
        && str_contains($ref, 'index.php')
    ) {
        redirect($ref);
    }
    redirect('index.php?page=admin_dashboard');
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
 * $showTitle: false when nested inside a collapsible help block.
 */
function render_glossary(string $panel, bool $showTitle = true): void
{
    echo '<div class="glossary card" role="note">';
    if ($showTitle) {
        echo '<h2 class="glossary-title">How this works</h2>';
    }
    echo '<dl class="glossary-list">';
    if ($panel === 'admin') {
        echo '<div><dt>Our database</dt><dd>Country folders — browse, search, copy, download. Admin can add/remove; Team Site Finding can browse (read-only).</dd></div>';
        echo '<div><dt>Extracted Sites</dt><dd>From Team Extracting Results Push.</dd></div>';
        echo '<div><dt>Emails data</dt><dd>Admin/Final archives + Email campaign sheets for Communication Team search.</dd></div>';
        echo '<div><dt>Filter &amp; add</dt><dd>Team pastes a list → remove domains already in the database → save only new ones.</dd></div>';
        echo '<div><dt>Site adding history</dt><dd>Who added which sites, saved by person and day.</dd></div>';
        echo '<div><dt>Your job</dt><dd>Manage Our database and Team users.</dd></div>';
    } else {
        echo '<div><dt>Filter &amp; add</dt><dd>Paste a list → duplicates are removed privately → save only new unique sites.</dd></div>';
        echo '<div><dt>Extracting sites</dt><dd>Per-country Sites list + Extracting Results. Appears after teammates add sites.</dd></div>';
        echo '<div><dt>Sites with emails - Team</dt><dd>From Extracting Results Push → add emails → Push to Admin.</dd></div>';
        echo '<div><dt>Admin emails search</dt><dd>Super search Sites with emails - Admin across all countries.</dd></div>';
        echo '<div><dt>Campaign search</dt><dd>Super search Email campaign sheets across all countries.</dd></div>';
        echo '<div><dt>Site adding history</dt><dd>Sites you added, saved by day.</dd></div>';
        echo '<div><dt>Your job</dt><dd>Filter new sites and add only the unique ones. Existing country lists stay private.</dd></div>';
    }
    echo '</dl></div>';
}

/** Dashboard help collapsed by default so work cards stay above the fold. */
function render_dashboard_help(string $panel): void
{
    echo '<details class="help-details">';
    echo '<summary>How this works</summary>';
    echo '<div class="help-details-body">';
    render_glossary($panel, false);
    echo $panel === 'admin' ? render_admin_panel_guide() : render_team_panel_guide();
    echo '</div></details>';
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

/**
 * Allowed rows-per-page choices for country / campaign sheets (sitewide).
 *
 * @return list<int>
 */
function sheet_per_page_options(): array
{
    return [100, 250, 500, 1000];
}

function sheet_per_page_default(): int
{
    return 1000;
}

function normalize_sheet_per_page(int $n): int
{
    return in_array($n, sheet_per_page_options(), true) ? $n : sheet_per_page_default();
}

/**
 * Resolve rows-per-page from GET/POST, remembering the choice in session.
 */
function resolve_sheet_per_page(): int
{
    $raw = get('per_page', '');
    if ($raw === '' || $raw === null) {
        $raw = post('per_page', '');
    }
    if ($raw !== '' && $raw !== null) {
        $n = normalize_sheet_per_page((int) $raw);
        $_SESSION['sheet_per_page'] = $n;
        return $n;
    }
    if (isset($_SESSION['sheet_per_page'])) {
        return normalize_sheet_per_page((int) $_SESSION['sheet_per_page']);
    }
    return sheet_per_page_default();
}

/** Append &per_page=N to a relative app URL (idempotent). */
function append_sheet_per_page_query(string $url, int $perPage): string
{
    $perPage = normalize_sheet_per_page($perPage);
    if (preg_match('/([?&])per_page=\d+/', $url)) {
        return preg_replace('/([?&])per_page=\d+/', '${1}per_page=' . $perPage, $url) ?? $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'per_page=' . $perPage;
}

/**
 * GET filter: “Per page” select. Resets to page 1 when changed.
 *
 * @param array<string, scalar|null> $baseQuery query params without p / per_page
 */
function render_sheet_per_page_filter(array $baseQuery, int $current): void
{
    $current = normalize_sheet_per_page($current);
    echo '<form class="sheet-per-page-filter" method="get" action="index.php">';
    foreach ($baseQuery as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $k = (string) $key;
        if ($k === 'p' || $k === 'per_page') {
            continue;
        }
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string) $value) . '">';
    }
    echo '<label for="sheet_per_page_select">Per page</label>';
    echo '<select id="sheet_per_page_select" name="per_page" onchange="this.form.submit()" title="How many rows to show on each page">';
    foreach (sheet_per_page_options() as $n) {
        echo '<option value="' . (int) $n . '"' . ($n === $current ? ' selected' : '') . '>'
            . (int) $n
            . '</option>';
    }
    echo '</select></form>';
}
