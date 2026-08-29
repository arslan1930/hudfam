<?php
/**
 * Hostinger-safe static asset server (plain PHP — no Node/React).
 *
 * Use: asset.php?f=css/app.css
 * Serves files from /assets with the correct Content-Type even when
 * relative /assets/... URLs break (wrong folder upload, subfolder install).
 */
$allowed = [
    'css/app.css' => 'text/css; charset=utf-8',
    'js/sites-form.js' => 'application/javascript; charset=utf-8',
    'js/extract-sites-list.js' => 'application/javascript; charset=utf-8',
    'js/extracted-admin.js' => 'application/javascript; charset=utf-8',
    'js/sites-with-emails.js' => 'application/javascript; charset=utf-8',
    'js/open-site.js' => 'application/javascript; charset=utf-8',
    'js/admin-emails-delete.js' => 'application/javascript; charset=utf-8',
    'js/email-campaign-sheet.js' => 'application/javascript; charset=utf-8',
    'js/email-campaign-search.js' => 'application/javascript; charset=utf-8',
    'js/email-campaign-drafts.js' => 'application/javascript; charset=utf-8',
    'js/semrush-sheet.js' => 'application/javascript; charset=utf-8',
    'js/email-field-clear.js' => 'application/javascript; charset=utf-8',
    'js/app-processing.js' => 'application/javascript; charset=utf-8',
    'js/sheet-select-undo.js' => 'application/javascript; charset=utf-8',
    'js/stay-scroll.js' => 'application/javascript; charset=utf-8',
    'js/task-presence.js' => 'application/javascript; charset=utf-8',
    'js/draft-autosave.js' => 'application/javascript; charset=utf-8',
    'js/info-tips.js' => 'application/javascript; charset=utf-8',
    'js/nav-shell.js' => 'application/javascript; charset=utf-8',
    'js/password-toggle.js' => 'application/javascript; charset=utf-8',
    'js/prospect-batch-sheet.js' => 'application/javascript; charset=utf-8',
    'js/prospects-country.js' => 'application/javascript; charset=utf-8',
    'js/niche-chips.js' => 'application/javascript; charset=utf-8',
    'js/alert-fade.js' => 'application/javascript; charset=utf-8',
    'js/csrf.js' => 'application/javascript; charset=utf-8',
    'js/tld-separate.js' => 'application/javascript; charset=utf-8',
    'js/searchable-select.js' => 'application/javascript; charset=utf-8',
    'js/site-prices.js' => 'application/javascript; charset=utf-8',
    'img/techxform-logo.svg' => 'image/svg+xml',
    'img/topurlz-logo.svg' => 'image/svg+xml',
    'img/topurlz-logo.png' => 'image/png',
];

$f = (string) ($_GET['f'] ?? '');
$f = str_replace('\\', '/', $f);
$f = ltrim($f, '/');
if (str_starts_with($f, 'assets/')) {
    $f = substr($f, strlen('assets/'));
}

if (!isset($allowed[$f])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Asset not found.';
    exit;
}

$path = __DIR__ . '/assets/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Asset file missing on server. Re-upload the assets/ folder from public_html.';
    exit;
}

$mtime = filemtime($path) ?: time();
$etag = '"' . md5($path . $mtime . filesize($path)) . '"';
header('Content-Type: ' . $allowed[$f]);
header('X-Content-Type-Options: nosniff');
// stylesheet_url() / script_asset_url() append v=filemtime. That URL is immutable.
// Without v=, keep no-cache so a stale /asset.php?f=css/app.css cannot pin broken JS.
$versioned = isset($_GET['v']) && (string) $_GET['v'] !== '';
if ($versioned) {
    header('Cache-Control: public, max-age=31536000, immutable');
} else {
    header('Cache-Control: no-cache, must-revalidate');
}
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($since !== false && $since >= $mtime) {
        http_response_code(304);
        exit;
    }
}

// Do not gzip here. LiteSpeed/Apache gzip PHP output; a second gzip (or a
// Content-Length that no longer matches) breaks CSS/JS on Team Filter & add.
readfile($path);
