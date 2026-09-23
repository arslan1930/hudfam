<?php
/**
 * Serve a custom invoice logo upload (PNG/JPG/WEBP/GIF).
 * Use: invoice_logo.php?f=inv_12_abcdef.png
 * Same-origin session cookie is enough for print preview <img> tags.
 */
require __DIR__ . '/includes/helpers.php';
txf_secure_session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/invoices.php';

$user = current_user();
if (!$user) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Login required.';
    exit;
}

$f = basename(str_replace('\\', '/', (string) ($_GET['f'] ?? '')));
if ($f === '' || !invoice_logo_filename_valid($f)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Logo not found.';
    exit;
}

$path = invoice_logo_storage_dir() . '/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Logo file missing.';
    exit;
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$types = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
];
$mime = $types[$ext] ?? 'application/octet-stream';
$mtime = filemtime($path) ?: time();
$etag = '"' . md5($path . $mtime . filesize($path)) . '"';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
readfile($path);
exit;
