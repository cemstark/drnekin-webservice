<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const POLICY_REMINDER_JOB_KEY = 'policy_reminder';

function policy_reminder_is_configured(): bool
{
    $reminder = panel_config('policy_reminder', []);
    $mail = panel_config('mail', []);

    if (!is_array($reminder) || !is_array($mail)) {
        return false;
    }

    return trim((string)($reminder['recipient'] ?? '')) !== ''
        && (int)($reminder['days_before'] ?? 0) > 0
        && trim((string)($mail['host'] ?? '')) !== ''
        && trim((string)($mail['from'] ?? $mail['username'] ?? '')) !== '';
}

/**
 * Gunluk kilitleme ile hatirlatma isini calistirir.
 *
 * @return array{ran: bool, reason: string, report: array<string,mixed>|null}
 */
function maybe_run_policy_reminder(bool $force = false): array
{
    if (!policy_reminder_is_configured()) {
        return ['ran' => false, 'reason' => 'disabled', 'report' => null];
    }

    try {
        $pdo = db();
        $pdo->query('SELECT 1 FROM cron_runs LIMIT 1');
    } catch (Throwable $e) {
        return ['ran' => false, 'reason' => 'migration_required', 'report' => null];
    }

    try {
        $pdo->prepare(
            "INSERT IGNORE INTO cron_runs (job_key, last_run_date, last_run_at, last_status)
             VALUES (?, '1970-01-01', NOW(), 'idle')"
        )->execute([POLICY_REMINDER_JOB_KEY]);

        $claimSql = "UPDATE cron_runs
             SET last_run_date = CURDATE(),
                 last_run_at = NOW(),
                 last_status = 'running',
                 last_payload = NULL
             WHERE job_key = ?
               AND (
                 last_run_date < CURDATE()
                 OR (last_status = 'running' AND last_run_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))";
        if ($force) {
            $claimSql .= "
                 OR last_status IN ('failed', 'partial_failed')";
        }
        $claimSql .= '
               )';

        $claim = $pdo->prepare($claimSql);
        $claim->execute([POLICY_REMINDER_JOB_KEY]);

        if ($claim->rowCount() === 0) {
            $state = $pdo->prepare('SELECT last_status FROM cron_runs WHERE job_key = ?');
            $state->execute([POLICY_REMINDER_JOB_KEY]);
            $status = (string)($state->fetchColumn() ?: '');

            return [
                'ran' => false,
                'reason' => $status === 'running' ? 'already_running' : 'already_ran_today',
                'report' => null,
            ];
        }

        $report = run_policy_reminder_job();
        $status = $report['failed'] > 0
            ? ((int)$report['sent'] > 0 ? 'partial_failed' : 'failed')
            : 'ok';

        $update = $pdo->prepare(
            "UPDATE cron_runs
             SET last_run_date = CURDATE(),
                 last_run_at = NOW(),
                 last_status = :status,
                 last_payload = :payload
             WHERE job_key = :job_key"
        );
        $update->execute([
            ':status' => $status,
            ':payload' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':job_key' => POLICY_REMINDER_JOB_KEY,
        ]);

        return ['ran' => true, 'reason' => 'ran', 'report' => $report];
    } catch (Throwable $e) {
        try {
            $failedReport = [
                'started_at' => date('c'),
                'finished_at' => date('c'),
                'sent' => 0,
                'failed' => 1,
                'errors' => [['error' => $e->getMessage()]],
            ];

            $pdo->prepare(
                "UPDATE cron_runs
                 SET last_run_date = CURDATE(),
                     last_run_at = NOW(),
                     last_status = 'failed',
                     last_payload = ?
                 WHERE job_key = ?"
            )->execute([
                json_encode($failedReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                POLICY_REMINDER_JOB_KEY,
            ]);
        } catch (Throwable $ignored) {
        }

        return ['ran' => false, 'reason' => 'error: ' . $e->getMessage(), 'report' => null];
    }
}

/**
 * Aktif isi yapar: 0-30 gun arasi biten ve daha once mail gitmeyen policeler.
 *
 * @return array<string,mixed>
 */
function run_policy_reminder_job(): array
{
    $pdo = db();
    $cfg = panel_config('policy_reminder', []);

    if (!is_array($cfg) || !policy_reminder_is_configured()) {
        return [
            'started_at' => date('c'),
            'finished_at' => date('c'),
            'candidates' => 0,
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
            'disabled' => true,
        ];
    }

    $recipient = trim((string)$cfg['recipient']);
    $daysBefore = max(1, (int)$cfg['days_before']);

    $stmt = $pdo->prepare(
        'SELECT id, record_no, plate, customer_name, insurance_company, policy_start_date, policy_end_date
         FROM service_records
         WHERE policy_end_date IS NOT NULL
           AND policy_reminder_sent_at IS NULL
           AND DATEDIFF(policy_end_date, CURDATE()) BETWEEN 0 AND :days
         ORDER BY policy_end_date ASC, id ASC'
    );
    $stmt->bindValue(':days', $daysBefore, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();

    $report = [
        'started_at' => date('c'),
        'recipient' => $recipient,
        'days_before' => $daysBefore,
        'candidates' => count($records),
        'sent' => 0,
        'failed' => 0,
        'errors' => [],
    ];

    $markSent = $pdo->prepare('UPDATE service_records SET policy_reminder_sent_at = NOW() WHERE id = ?');

    foreach ($records as $record) {
        $policyEnd = (string)$record['policy_end_date'];
        $daysLeft = (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($policyEnd))->format('%r%a');
        $subject = sprintf('[DRN] Police bitisi yaklasti: %s - %s', $record['plate'], $policyEnd);
        $body = policy_reminder_email_html($record, $daysLeft);

        $result = send_mail($recipient, $subject, $body);
        if (!empty($result['ok'])) {
            $markSent->execute([(int)$record['id']]);
            $report['sent']++;
            continue;
        }

        $report['failed']++;
        $report['errors'][] = [
            'id' => (int)$record['id'],
            'plate' => (string)$record['plate'],
            'error' => (string)($result['error'] ?? 'unknown'),
        ];
    }

    $report['finished_at'] = date('c');

    return $report;
}

/**
 * @param array<string,mixed> $record
 */
function policy_reminder_email_html(array $record, int $daysLeft): string
{
    $policyStart = trim((string)($record['policy_start_date'] ?? '')) ?: '-';
    $insurance = trim((string)($record['insurance_company'] ?? '')) ?: '-';

    return '<div style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.5">'
        . '<h2 style="margin:0 0 12px;font-size:20px">Police bitisi yaklasiyor</h2>'
        . '<p style="margin:0 0 16px">Asagidaki aracin policesi <strong>' . e($record['policy_end_date']) . '</strong> tarihinde sona eriyor. Kalan gun: <strong>' . e($daysLeft) . '</strong>.</p>'
        . '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:14px">'
        . '<tr><td style="border:1px solid #e2e8f0;background:#f8fafc;font-weight:bold">Plaka</td><td style="border:1px solid #e2e8f0">' . e($record['plate']) . '</td></tr>'
        . '<tr><td style="border:1px solid #e2e8f0;background:#f8fafc;font-weight:bold">Musteri</td><td style="border:1px solid #e2e8f0">' . e($record['customer_name']) . '</td></tr>'
        . '<tr><td style="border:1px solid #e2e8f0;background:#f8fafc;font-weight:bold">Sigorta</td><td style="border:1px solid #e2e8f0">' . e($insurance) . '</td></tr>'
        . '<tr><td style="border:1px solid #e2e8f0;background:#f8fafc;font-weight:bold">Police baslangic</td><td style="border:1px solid #e2e8f0">' . e($policyStart) . '</td></tr>'
        . '<tr><td style="border:1px solid #e2e8f0;background:#f8fafc;font-weight:bold">Police bitis</td><td style="border:1px solid #e2e8f0">' . e($record['policy_end_date']) . '</td></tr>'
        . '<tr><td style="border:1px solid #e2e8f0;background:#f8fafc;font-weight:bold">Kayit no</td><td style="border:1px solid #e2e8f0">' . e($record['record_no']) . '</td></tr>'
        . '</table>'
        . '<p style="margin:16px 0 0;color:#64748b;font-size:12px">Bu e-posta DRN Servis Paneli tarafindan otomatik olusturulmustur.</p>'
        . '</div>';
}

function fire_policy_reminder_async(): void
{
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            maybe_run_policy_reminder();
        } catch (Throwable $e) {
        }
    });
}
