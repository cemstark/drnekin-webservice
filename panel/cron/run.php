<?php
declare(strict_types=1);

/**
 * Web tabanli cron endpoint.
 * Hostinger Cron Jobs veya cron-job.org gibi harici servislerden cagrilabilir.
 *
 * Kullanim:
 *   GET https://panel.<domain>/cron/run.php?token=<config.policy_reminder.cron_token>
 *
 * Token panel/config.php icindeki 'policy_reminder.cron_token' degeriyle eslesmelidir.
 */

require_once __DIR__ . '/../includes/policy_runner.php';

header('Content-Type: application/json; charset=utf-8');

$expected = panel_config('policy_reminder.cron_token');
$provided = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

if (!$expected || !is_string($provided) || !hash_equals((string)$expected, (string)$provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

$force = isset($_GET['force']) && $_GET['force'] === '1';
$result = maybe_run_policy_reminder($force);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
