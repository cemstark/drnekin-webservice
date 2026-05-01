<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$file = current_user_profile_photo_file();
if ($file === null) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/storage/profile_photos/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mtime = filemtime($path) ?: time();
$etag = '"' . sha1($file . ':' . filesize($path) . ':' . $mtime) . '"';

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    header('Cache-Control: private, max-age=3600, stale-while-revalidate=86400');
    header('ETag: ' . $etag);
    http_response_code(304);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=3600, stale-while-revalidate=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
readfile($path);
