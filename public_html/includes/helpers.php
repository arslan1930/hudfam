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

function txf_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** HttpOnly + SameSite cookies. Call instead of bare session_start(). */
function txf_secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => txf_request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header_remove('X-Powered-By');
    }
    session_start();
}

function txf_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (txf_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Do not gzip in PHP. Hostinger LiteSpeed already compresses HTML/CSS/JS.
 * PHP gzip + the proxy gzip = ERR_CONTENT_DECODING_FAILED (Filter & add
 * looks crashed because sites-form.js never parses). .htaccess mod_deflate
 * is the safe path.
 */
function txf_start_output_compression(): void
{
}

/**
 * Hostinger schema probes (SHOW COLUMNS / SHOW INDEX) are a Hostinger safety net.
 * After a successful run, skip them until this PHP file is redeployed (mtime).
 * CLI tests always re-run so schema stays explicit. upgrade.php clears stamps.
 */
function txf_schema_stamp_path(string $key): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $key) ?: 'schema';
    return rtrim(sys_get_temp_dir(), '/') . '/txf_schema_' . $safe . '.stamp';
}

function txf_schema_stamps_enabled(): bool
{
    return PHP_SAPI !== 'cli';
}

function txf_schema_is_current(string $key, string $sourceFile = ''): bool
{
    static $mem = [];
    if (!empty($mem[$key])) {
        return true;
    }
    if (!txf_schema_stamps_enabled()) {
        return false;
    }
    $path = txf_schema_stamp_path($key);
    if (!is_file($path)) {
        return false;
    }
    $stampMtime = (int) @filemtime($path);
    if ($stampMtime < 1) {
        return false;
    }
    $src = $sourceFile !== '' ? (int) @filemtime($sourceFile) : 0;
    if ($src > 0 && $stampMtime < $src) {
        return false;
    }
    $mem[$key] = true;
    return true;
}

function txf_schema_mark_current(string $key): void
{
    if (!txf_schema_stamps_enabled()) {
        return;
    }
    @file_put_contents(txf_schema_stamp_path($key), (string) time());
}

function txf_schema_clear_stamps(): void
{
    $dir = rtrim(sys_get_temp_dir(), '/');
    foreach (glob($dir . '/txf_schema_*.stamp') ?: [] as $file) {
        @unlink($file);
    }
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
    // Wrap + inline display:none. Clip-based .visually-hidden does not hide
    // <textarea> (UA rows + global textarea width/padding paint a visible box).
    return '<div hidden style="display:none" aria-hidden="true">'
        . '<textarea name="' . h($name) . '"'
        . ($id !== '' ? ' id="' . h($id) . '"' : '')
        . ' class="' . h($class) . '" hidden aria-hidden="true" tabindex="-1" style="display:none">'
        . h($value)
        . '</textarea></div>';
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

/** UI overlay after app.css (Hostinger-safe via asset.php allowlist). */
function stylesheet_new_url(): string
{
    $file = dirname(__DIR__) . '/assets/css/style-new.css';
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=css/style-new.css&v=' . rawurlencode($v));
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

/** Short “Last saved by Name · YYYY-MM-DD HH:MM” (empty when unknown). */
function last_writer_label(string $name, string $at): string
{
    $name = trim($name);
    $at = $at !== '' ? substr($at, 0, 16) : '';
    if ($name === '' && $at === '') {
        return '';
    }
    $who = $name !== '' ? $name : 'Someone';
    return $at !== '' ? ('Last saved by ' . $who . ' · ' . $at) : ('Last saved by ' . $who);
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

/** One-line emailed-rule heading; full copy lives in the info tip. */
function render_sheet_checkpoint_compact(string $tip): void
{
    echo '<p class="swe-checkpoint-rule swe-checkpoint-compact">';
    echo label_with_info('Emailed selection rule', $tip);
    echo '</p>';
}

/**
 * Title-row country switcher: pick another country on this sheet without going back to the hub.
 *
 * @param list<array{value:string,label:string}> $options
 * @param array<string, scalar|null> $hiddenQuery GET fields other than $selectName
 */
function render_sheet_country_jump(
    string $selectName,
    string $currentValue,
    array $options,
    array $hiddenQuery,
    string $infoTip,
    string $selectId = 'sheet-country-jump',
    string $ariaLabel = 'Open another country'
): void {
    $seen = [];
    $clean = [];
    foreach ($options as $opt) {
        $value = trim((string) ($opt['value'] ?? ''));
        $label = trim((string) ($opt['label'] ?? ''));
        if ($value === '' || $label === '') {
            continue;
        }
        if (isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $clean[] = ['value' => $value, 'label' => $label];
    }
    if ($currentValue !== '' && !isset($seen[$currentValue])) {
        array_unshift($clean, ['value' => $currentValue, 'label' => $currentValue]);
    }
    if ($clean === []) {
        $fallback = $currentValue !== '' ? $currentValue : 'Country';
        $clean[] = ['value' => $currentValue !== '' ? $currentValue : 'country', 'label' => $fallback];
    }
    echo '<form class="sheet-country-jump camp-country-jump" method="get" action="index.php" data-no-draft>';
    foreach ($hiddenQuery as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $k = (string) $key;
        if ($k === $selectName || $k === 'p' || $k === 'q') {
            continue;
        }
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string) $value) . '">';
    }
    echo '<h1 class="camp-sheet-title">';
    echo '<label class="with-info camp-country-jump-label" for="' . h($selectId) . '">';
    echo '<span class="visually-hidden">' . h($ariaLabel) . '</span>';
    echo '<select id="' . h($selectId) . '" name="' . h($selectName) . '" onchange="this.form.submit()"'
        . ' title="Open another country without going back" aria-label="' . h($ariaLabel) . '">';
    foreach ($clean as $opt) {
        $sel = (string) $opt['value'] === $currentValue ? ' selected' : '';
        echo '<option value="' . h($opt['value']) . '"' . $sel . '>' . h($opt['label']) . '</option>';
    }
    echo '</select>';
    echo info_icon($infoTip, 'About this country sheet');
    echo '</label>';
    echo '<noscript><button class="btn small" type="submit">Open</button></noscript>';
    echo '</h1></form>';
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
 * Visible Open cue on clickable folder/tool cards (not a filled button).
 */
function folder_open_cue(string $label = 'Open'): void
{
    echo '<span class="folder-open">' . h($label) . '</span>';
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
        echo '<div><dt>Departments</dt><dd>Assign Team users to Finding, Extracting, Email, or Communication so they only see those tools.</dd></div>';
        echo '<div><dt>Our database</dt><dd>Country folders — browse and add sites (Admin only).</dd></div>';
        echo '<div><dt>Extracted Sites</dt><dd>From Team Extracting Results Push.</dd></div>';
        echo '<div><dt>Emails data</dt><dd>Admin/Final archives + Email campaign projects. Communication Team uses search and drafts, not the full Admin sheet.</dd></div>';
        echo '<div><dt>Orders + Invoices</dt><dd>One order sheet (country, date, admin, client email or name). Push unpaid LIVE rows to printable invoices.</dd></div>';
        echo '<div><dt>Filter &amp; add</dt><dd>Team pastes a list → remove domains already in the database → save only new ones.</dd></div>';
        echo '<div><dt>Site adding history</dt><dd>Who added which sites, saved by person and day.</dd></div>';
        echo '<div><dt>Your job</dt><dd>Manage Our database, departments, campaign projects, orders, and Team users.</dd></div>';
    } else {
        echo '<div><dt>My departments</dt><dd>Admin assigns you to Finding, Extracting, Email, or Communication. Tools appear after that.</dd></div>';
        echo '<div><dt>Filter &amp; add</dt><dd>Paste a list → duplicates are removed privately → save only new unique sites. Our database lists stay hidden.</dd></div>';
        echo '<div><dt>Extracting sites</dt><dd>Per-country Sites list + Extracting Results. Open it from Your work or the sidebar. Appears after teammates add sites.</dd></div>';
        echo '<div><dt>Sites with emails</dt><dd>From Extracting Results Push → add emails → Push to Admin.</dd></div>';
        echo '<div><dt>Admin emails search</dt><dd>Super search Admin across all countries.</dd></div>';
        echo '<div><dt>Campaign search</dt><dd>Find a site, copy emails, then open drafts and paste into your email client. This app does not send mail.</dd></div>';
        echo '<div><dt>Site adding history</dt><dd>Sites you added, saved by day.</dd></div>';
        echo '<div><dt>Your job</dt><dd>Use only the tools for your department. Existing country lists stay private to Admin.</dd></div>';
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
    // 100 keeps Emails data / campaign sheets usable; 1000 is still an explicit choice.
    return 100;
}

/**
 * True when a table has at least one row. Uses LIMIT 1 — never COUNT(*) on large sheets.
 */
function table_has_any_row(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $st = $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        return $st !== false && $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function table_has_index(PDO $pdo, string $table, string $indexName): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1'
        );
        $st->execute([$table, $indexName]);
        return $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Cache a dashboard-style COUNT for a few seconds so huge tables do not get
 * scanned on every Admin home load. Live sheet pagination still uses exact counts.
 */
function cached_scalar_count(string $cacheKey, callable $fn, int $ttlSeconds = 45): int
{
    static $local = [];
    if (isset($local[$cacheKey])) {
        return $local[$cacheKey];
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $pack = $_SESSION['_count_cache'][$cacheKey] ?? null;
        if (is_array($pack) && isset($pack['n'], $pack['exp']) && (int) $pack['exp'] > time()) {
            return $local[$cacheKey] = (int) $pack['n'];
        }
    }
    try {
        $n = (int) $fn();
    } catch (Throwable $e) {
        $n = 0;
    }
    $local[$cacheKey] = $n;
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['_count_cache']) || !is_array($_SESSION['_count_cache'])) {
            $_SESSION['_count_cache'] = [];
        }
        $_SESSION['_count_cache'][$cacheKey] = ['n' => $n, 'exp' => time() + $ttlSeconds];
    }
    return $n;
}

/**
 * Dashboard COUNT that reports failure instead of looking like zero.
 * Successful counts use the same short session cache as cached_scalar_count.
 * Failures are not cached.
 *
 * @return array{ok:bool,n:int}
 */
function cached_count_result(string $cacheKey, callable $fn, int $ttlSeconds = 45): array
{
    static $local = [];
    if (isset($local[$cacheKey]) && is_array($local[$cacheKey])) {
        return $local[$cacheKey];
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $pack = $_SESSION['_count_cache'][$cacheKey] ?? null;
        if (is_array($pack) && isset($pack['n'], $pack['exp']) && (int) $pack['exp'] > time()) {
            $ok = ($pack['ok'] ?? true) !== false;
            return $local[$cacheKey] = ['ok' => $ok, 'n' => (int) $pack['n']];
        }
    }
    try {
        $result = ['ok' => true, 'n' => (int) $fn()];
    } catch (Throwable $e) {
        $result = ['ok' => false, 'n' => 0];
    }
    $local[$cacheKey] = $result;
    if (!empty($result['ok']) && session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['_count_cache']) || !is_array($_SESSION['_count_cache'])) {
            $_SESSION['_count_cache'] = [];
        }
        $_SESSION['_count_cache'][$cacheKey] = [
            'n' => (int) $result['n'],
            'ok' => true,
            'exp' => time() + $ttlSeconds,
        ];
    }
    return $result;
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
    echo '<select id="sheet_per_page_select" name="per_page" onchange="this.form.submit()" title="How many rows to show on each page. Default 100 keeps large Emails data lists from freezing the browser.">';
    foreach (sheet_per_page_options() as $n) {
        echo '<option value="' . (int) $n . '"' . ($n === $current ? ' selected' : '') . '>'
            . (int) $n
            . '</option>';
    }
    echo '</select></form>';
}

/**
 * One hidden form per row action (mark / up-to / remove / push) for the whole sheet.
 * Buttons use data-sheet-action + data-site-id instead of a form copy on every row.
 *
 * @param array{
 *   q?:string,p?:int,sent?:string,filter?:string,
 *   mark?:bool,push?:bool,remove?:bool
 * } $state
 */
function render_sheet_shared_row_action_forms(string $actionUrl, string $prefix, array $state = []): void
{
    $q = (string) ($state['q'] ?? '');
    $p = (int) ($state['p'] ?? 1);
    $sent = (string) ($state['sent'] ?? '');
    $filter = (string) ($state['filter'] ?? '');
    $includeMark = !empty($state['mark']);
    $includePush = !empty($state['push']);
    $includeRemove = ($state['remove'] ?? true) !== false;
    $nav = (function_exists('csrf_field') ? csrf_field() : '')
        . '<input type="hidden" name="q" value="' . h($q) . '" data-swe-q>'
        . '<input type="hidden" name="p" value="' . $p . '">';
    if ($sent !== '') {
        $nav .= '<input type="hidden" name="sent" value="' . h($sent) . '">';
    }
    $batch = (int) ($state['batch'] ?? 0);
    if ($batch > 0) {
        $nav .= '<input type="hidden" name="batch" value="' . $batch . '">';
    }
    if ($filter !== '') {
        $nav .= '<input type="hidden" name="filter" value="' . h($filter) . '">';
    }
    $url = h($actionUrl);
    $pre = h($prefix);
    echo '<div class="sheet-shared-actions" hidden>';
    if ($includeMark) {
        echo '<form id="' . $pre . '-shared-mark" method="post" action="' . $url . '" data-swe-mark>'
            . '<input type="hidden" name="action" value="mark_email_sent">'
            . '<input type="hidden" name="site_id" value="">'
            . '<input type="hidden" name="email_sent" value="1">'
            . $nav . '</form>';
        echo '<form id="' . $pre . '-shared-upto" method="post" action="' . $url . '" data-swe-mark-upto>'
            . '<input type="hidden" name="action" value="mark_emailed_up_to">'
            . '<input type="hidden" name="site_id" value="">'
            . $nav . '</form>';
        echo '<form id="' . $pre . '-shared-clear-upto" method="post" action="' . $url . '" data-swe-clear-upto>'
            . '<input type="hidden" name="action" value="clear_emailed_up_to">'
            . '<input type="hidden" name="site_id" value="">'
            . $nav . '</form>';
    }
    if ($includePush) {
        echo '<form id="' . $pre . '-shared-push" method="post" action="' . $url . '" data-swe-push data-admin-conflict="0">'
            . '<input type="hidden" name="action" value="push_site">'
            . '<input type="hidden" name="site_id" value="">'
            . '<input type="hidden" name="confirm_overwrite" value="0" data-swe-confirm-overwrite>'
            . $nav . '</form>';
    }
    if ($includeRemove) {
        echo '<form id="' . $pre . '-shared-remove" method="post" action="' . $url . '" data-swe-remove>'
            . '<input type="hidden" name="action" value="remove_site">'
            . '<input type="hidden" name="site_id" value="">'
            . $nav . '</form>';
    }
    echo '</div>';
}
