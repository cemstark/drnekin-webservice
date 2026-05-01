<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const POLICY_REMINDER_JOB_KEY = 'policy_reminder';

/**
 * Lock'lu calistirma. Ayni gun icinde 2. kez calismaz.
 * Birden fazla kullanici/server ayni anda tetiklerse atomic UPDATE ile yarisi kazanir.
 *
 * @return array ['ran' => bool, 'reason' => string, 'report' => array|null]
 */
function maybe_run_policy_reminder(bool $force = false): array
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return ['ran' => false, 'reason' => 'db_error: ' . $e->getMessage(), 'report' => null];
    }

    // cron_runs tablosu yoksa atla (migration calistirilmamis)
    try {
        $pdo->query('SELECT 1 FROM cron_runs LIMIT 1');
    } catch (Throwable $e) {
        return ['ran' => false, 'reason' => 'migration_required', 'report' => null];
    }

    if (!$force) {
        // Atomic claim: bugunun tarihiyle insert/update et; ancak tarih degismisse claim eder
        $stmt = $pdo->prepare(
            "INSERT INTO cron_runs (job_key, last_run_date, last_run_at, last_status)
             VALUES (:k, CURDATE(), NOW(), 'running')
             ON DUPLICATE KEY UPDATE
               last_run_date = IF(last_run_date < CURDATE(), CURDATE(), last_run_date),
               last_run_at   = IF(last_run_date < CURDATE(), NOW(), last_run_at),
               last_status   = IF(last_run_date < CURDATE(), 'running', last_status)"
        );
        $stmt->execute([':k' => POLICY_REMINDER_JOB_KEY]);

        // Bu cagri claim'i kazandi mi? last_status='running' VE last_run_at son 2 dakika
        $check = $pdo->prepare(
            "SELECT last_run_date, last_status, TIMESTAMPDIFF(SECOND, last_run_at, NOW()) AS age_sec
             FROM cron_runs WHERE job_key = ?"
        );
        $check->execute([POLICY_REMINDER_JOB_KEY]);
        $row = $check->fetch();

        if (!$row) {
            return ['ran' => false, 'reason' => 'lock_failed', 'report' => null];
        }
        if ($row['last_run_date'] !== date('Y-m-d')) {
            return ['ran' => false, 'reason' => 'date_mismatch', 'report' => null];
        }
        if ($row['last_status'] !== 'running') {
            // Bugun zaten basarili calismis
            return ['ran' => false, 'reason' => 'already_ran_today', 'report' => null];
        }
        if ((int)$row['age_sec'] > 120) {
            // 2dk eski 'running' -> stale, ezecegiz
            $reset = $pdo->prepare("UPDATE cron_runs SET last_run_at = NOW() WHERE job_key = ?");
            $reset->execute([POLICY_REMINDER_JOB_KEY]);
        }
    }

    $report = run_policy_reminder_job();

    $update = $pdo->prepare(
        "UPDATE cron_runs SET last_status = :s, last_payload = :p, last_run_at = NOW(), last_run_date = CURDATE() WHERE job_key = :k"
    );
    $update->execute([
        ':s' => $report['failed'] > 0 && $report['sent'] === 0 ? 'failed' : 'ok',
        ':p' => json_encode($report, JSON_UNESCAPED_UNICODE),
        ':k' => POLICY_REMINDER_JOB_KEY,
    ]);

    return ['ran' => true, 'reason' => 'ran', 'report' => $report];
}

function run_policy_reminder_job(): array
{
    $pdo = db();
    $cfg = panel_config('policy_reminder') ?? [];
    $recipient = (string)($cfg['recipient'] ?? 'ekin@ekinotoizmit.com');
    $daysBefore = (int)($cfg['days_before'] ?? 30);

    $stmt = $pdo->prepare(
        'SELECT id, record_no, plate, customer_name, insurance_company, policy_start_date, policy_end_date
         FROM service_records
         WHERE policy_end_date IS NOT NULL
           AND policy_reminder_sent_at IS NULL
           AND DATEDIFF(policy_end_date, CURDATE()) BETWEEN 0 AND :days
         ORDER BY policy_end_date ASC'
    );
    $stmt->bindValue(':days', $daysBefore, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $report = [
        'started_at' => date('c'),
        'recipient'  => $recipient,
        'candidates' => count($rows),
        'sent'       => 0,
        'failed'     => 0,
        'errors'     => [],
    ];

    $markSent = $pdo->prepare('UPDATE service_records SET policy_reminder_sent_at = NOW() WHERE id = ?');

    foreach ($rows as $r) {
        $daysLeft = (int)((new DateTimeImmutable('today'))
            ->diff(new DateTimeImmutable($r['policy_end_date']))->format('%r%a'));
        $subject = sprintf('[DRN] Police bitisi yaklasti: %s - %s', $r['plate'], $r['policy_end_date']);
        $body = '<p>Merhaba,</p>'
              . '<p>Asagidaki aracin sigorta policesi <strong>' . htmlspecialchars($r['policy_end_date']) . '</strong> tarihinde sona eriyor (kalan <strong>' . $daysLeft . '</strong> gun).</p>'
              . '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">'
              . '<tr><td><strong>Plaka</strong></td><td>' . htmlspecialchars($r['plate']) . '</td></tr>'
              . '<tr><td><strong>Musteri</strong></td><td>' . htmlspecialchars($r['customer_name']) . '</td></tr>'
              . '<tr><td><strong>Sigorta</strong></td><td>' . htmlspecialchars($r['insurance_company'] ?: '-') . '</td></tr>'
              . '<tr><td><strong>Police baslangic</strong></td><td>' . htmlspecialchars((string)$r['policy_start_date'] ?: '-') . '</td></tr>'
              . '<tr><td><strong>Police bitis</strong></td><td>' . htmlspecialchars($r['policy_end_date']) . '</td></tr>'
              . '<tr><td><strong>Kayit no</strong></td><td>' . htmlspecialchars($r['record_no']) . '</td></tr>'
              . '</table>'
              . '<p style="color:#888;font-size:11px;">Bu e-posta DRN servis paneli tarafindan otomatik olusturulmustur.</p>';

        $result = send_mail($recipient, $subject, $body);
        if (!empty($result['ok'])) {
            $markSent->execute([(int)$r['id']]);
            $report['sent']++;
        } else {
            $report['failed']++;
            $report['errors'][] = ['id' => (int)$r['id'], 'plate' => $r['plate'], 'error' => $result['error'] ?? 'unknown'];
        }
    }
    $report['finished_at'] = date('c');
    return $report;
}

/**
 * Sayfa render'inin sonunda arka planda fire eden auto-trigger.
 * Cikti gondermez; hata olursa sessizce yutar.
 */
function fire_policy_reminder_async(): void
{
    if (function_exists('fastcgi_finish_request')) {
        register_shutdown_function(function () {
            @fastcgi_finish_request();
            try { maybe_run_policy_reminder(); } catch (Throwable $e) { /* sessiz */ }
        });
    } else {
        register_shutdown_function(function () {
            try { maybe_run_policy_reminder(); } catch (Throwable $e) { /* sessiz */ }
        });
    }
}
