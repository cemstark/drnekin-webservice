<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function updates_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$apiKey = (string)panel_config('api_key', '');
$provided = $_SERVER['HTTP_X_PANEL_API_KEY'] ?? ($_GET['api_key'] ?? '');
if ($apiKey === '' || !is_string($provided) || !hash_equals($apiKey, $provided)) {
    updates_response(['ok' => false, 'error' => 'API anahtari gecersiz.'], 401);
}

ensure_excel_updates_table();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->query("SELECT id, record_no, fields_json FROM pending_excel_updates WHERE status = 'pending' ORDER BY id ASC LIMIT 100");
    $updates = [];
    foreach ($stmt->fetchAll() as $row) {
        $updates[] = [
            'id' => (int)$row['id'],
            'record_no' => $row['record_no'],
            'fields' => json_decode((string)$row['fields_json'], true) ?: [],
        ];
    }
    updates_response(['ok' => true, 'updates' => $updates]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        updates_response(['ok' => false, 'error' => 'JSON bekleniyor.'], 422);
    }

    foreach (($payload['applied'] ?? []) as $id) {
        $stmt = db()->prepare("UPDATE pending_excel_updates SET status = 'applied', applied_at = NOW(), error_message = NULL WHERE id = ?");
        $stmt->execute([(int)$id]);
    }

    foreach (($payload['failed'] ?? []) as $failure) {
        $id = (int)($failure['id'] ?? 0);
        $message = substr((string)($failure['error'] ?? 'Bilinmeyen hata'), 0, 1000);
        if ($id > 0) {
            $stmt = db()->prepare("UPDATE pending_excel_updates SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->execute([$message, $id]);
        }
    }

    updates_response(['ok' => true]);
}

updates_response(['ok' => false, 'error' => 'Method desteklenmiyor.'], 405);
