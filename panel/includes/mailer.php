<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function load_phpmailer(): ?string
{
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return null;
    }

    $manualFiles = [
        dirname(__DIR__) . '/vendor/PHPMailer/src/Exception.php',
        dirname(__DIR__) . '/vendor/PHPMailer/src/PHPMailer.php',
        dirname(__DIR__) . '/vendor/PHPMailer/src/SMTP.php',
    ];

    foreach ($manualFiles as $file) {
        if (!is_file($file)) {
            return 'PHPMailer bulunamadi: panel/vendor/PHPMailer/ klasoru eksik.';
        }
    }

    foreach ($manualFiles as $file) {
        require_once $file;
    }

    return class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
        ? null
        : 'PHPMailer sinifi yuklenemedi.';
}

/**
 * SMTP uzerinden HTML mail gonderir.
 *
 * Beklenen config anahtari: panel_config('mail')
 * host, port, secure (ssl/tls/none), username, password, from, from_name, reply_to
 *
 * @return array{ok: bool, error?: string}
 */
function send_mail(string $to, string $subject, string $html, ?string $alt = null): array
{
    $loadError = load_phpmailer();
    if ($loadError !== null) {
        return ['ok' => false, 'error' => $loadError];
    }

    $cfg = panel_config('mail', []);
    if (!is_array($cfg)) {
        return ['ok' => false, 'error' => 'mail konfigurasyonu gecersiz.'];
    }

    $host = trim((string)($cfg['host'] ?? ''));
    $from = trim((string)($cfg['from'] ?? $cfg['username'] ?? ''));
    if ($host === '' || $from === '') {
        return ['ok' => false, 'error' => 'SMTP konfigurasyonu eksik.'];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int)($cfg['port'] ?? 587);
        $mail->SMTPAuth = trim((string)($cfg['username'] ?? '')) !== '';

        if ($mail->SMTPAuth) {
            $mail->Username = (string)$cfg['username'];
            $mail->Password = (string)($cfg['password'] ?? '');
        }

        $secure = strtolower(trim((string)($cfg['secure'] ?? 'tls')));
        if ($secure === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, (string)($cfg['from_name'] ?? 'DRN Servis Paneli'));

        $replyTo = trim((string)($cfg['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $alt ?? trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        $mail->send();

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
