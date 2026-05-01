<?php
declare(strict_types=1);

/**
 * CLI'den manuel calistirma. Lock'a takilmamak icin --force ile zorla cagirilabilir.
 *   php panel/cron/policy_reminder.php
 *   php panel/cron/policy_reminder.php --force
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only. Web icin /cron/run.php kullanin.');
}

require_once __DIR__ . '/../includes/policy_runner.php';

$force = in_array('--force', $argv ?? [], true);
$result = maybe_run_policy_reminder($force);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
