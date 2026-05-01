<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/policy_runner.php';

header('Content-Type: application/json; charset=utf-8');

$expected = panel_config('policy_reminder.cron_token', null);
$provided = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$result = maybe_run_policy_reminder($force);

echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
