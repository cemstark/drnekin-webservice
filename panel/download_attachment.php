<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Gecersiz id.');
}

$meta = attachment_fetch_meta($id);
if (!$meta) {
    http_response_code(404);
    exit('Dosya bulunamadi.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
if ($inline && isset($_GET['thumb']) && $_GET['thumb'] === '1') {
    attachment_stream_thumbnail($id);
}

attachment_stream($id, !$inline);
