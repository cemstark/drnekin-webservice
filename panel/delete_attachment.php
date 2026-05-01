<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
$recordId = (int)($_POST['record_id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Gecersiz id.');
}

$meta = attachment_fetch_meta($id);
if (!$meta) {
    http_response_code(404);
    exit('Dosya bulunamadi.');
}
if ($recordId === 0) {
    $recordId = (int)$meta['record_id'];
}

attachment_delete($id);
redirect_to('view.php?id=' . $recordId);
