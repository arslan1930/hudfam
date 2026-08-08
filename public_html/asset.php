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
    'js/admin-emails-delete.js' => 'application/javascript; charset=utf-8',
    'js/email-campaign-sheet.js' => 'application/javascript; charset=utf-8',
    'js/email-campaign-search.js' => 'application/javascript; charset=utf-8',
    'js/email-field-clear.js' => 'application/javascript; charset=utf-8',
    'js/app-processing.js' => 'application/javascript; charset=utf-8',
    'js/task-presence.js' => 'application/javascript; charset=utf-8',
    'js/draft-autosave.js' => 'application/javascript; charset=utf-8',
    'js/info-tips.js' => 'application/javascript; charset=utf-8',
    'js/nav-shell.js' => 'application/javascript; charset=utf-8',
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
// Always revalidate — max-age=86400 kept teammates on broken JS after deploys.
header('Cache-Control: no-cache, must-revalidate');
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

readfile($path);
