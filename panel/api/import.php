<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/importer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(['ok' => false, 'error' => 'Sadece POST desteklenir.'], 405);
}

$apiKey = (string)panel_config('api_key', '');
$provided = $_SERVER['HTTP_X_PANEL_API_KEY'] ?? ($_POST['api_key'] ?? '');
if ($apiKey === '' || !is_string($provided) || !hash_equals($apiKey, $provided)) {
    api_response(['ok' => false, 'error' => 'API anahtari gecersiz.'], 401);
}

if (empty($_FILES['excel']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
    api_response(['ok' => false, 'error' => 'excel alaninda .xlsx dosyasi bekleniyor.'], 422);
}

$file = $_FILES['excel'];
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    api_response(['ok' => false, 'error' => 'Dosya yukleme hatasi: ' . (int)$file['error']], 422);
}

$name = (string)($file['name'] ?? 'excel.xlsx');
$lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
if (!str_ends_with($lowerName, '.xlsx')) {
    api_response(['ok' => false, 'error' => 'Sadece .xlsx dosyalari desteklenir.'], 422);
}

try {
    $result = import_excel_file((string)$file['tmp_name'], $name);
    api_response(['ok' => $result['status'] !== 'failed', 'result' => $result], $result['status'] === 'failed' ? 422 : 200);
} catch (Throwable $e) {
    api_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
