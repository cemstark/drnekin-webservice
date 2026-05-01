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

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
