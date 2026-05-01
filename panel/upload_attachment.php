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

$recordId = (int)($_POST['record_id'] ?? 0);
$category = (string)($_POST['category'] ?? 'diger');

$check = db()->prepare('SELECT id FROM service_records WHERE id = ?');
$check->execute([$recordId]);
if (!$check->fetch()) {
    http_response_code(404);
    exit('Kayit bulunamadi.');
}

$file = $_FILES['file'] ?? null;
if (!is_array($file)) {
    redirect_to('view.php?id=' . $recordId);
}

$errors = attachment_validate_upload($file);
if ($errors) {
    $_SESSION['flash_error'] = implode(' ', $errors);
    redirect_to('view.php?id=' . $recordId);
}

attachment_save($recordId, $file, $category, current_user()['id'] ?? null);
redirect_to('view.php?id=' . $recordId);
