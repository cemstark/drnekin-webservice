<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * SMTP uzerinden HTML mail gonderir.
 * PHPMailer Composer ile (vendor/autoload.php) veya manuel (vendor/PHPMailer/src/) yuklenmis olmalidir.
 *
 * Konfig: panel/config.php icinde 'mail' anahtari altinda:
 *   host, port, secure (tls/ssl), username, password, from, from_name, reply_to (opsiyonel)
 */
function send_mail(string $to, string $subject, string $htmlBody, ?string $altBody = null): array
{
    $autoloads = [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];
    $loaded = false;
    foreach ($autoloads as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = true;
            break;
        }
    }
    if (!$loaded) {
        $manual = [
            dirname(__DIR__) . '/vendor/PHPMailer/src/PHPMailer.php',
            dirname(__DIR__) . '/vendor/PHPMailer/src/SMTP.php',
            dirname(__DIR__) . '/vendor/PHPMailer/src/Exception.php',
        ];
        foreach ($manual as $f) {
            if (!is_file($f)) {
                return ['ok' => false, 'error' => 'PHPMailer bulunamadi: composer require phpmailer/phpmailer veya vendor/PHPMailer/ ekleyin.'];
            }
            require_once $f;
        }
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return ['ok' => false, 'error' => 'PHPMailer sinifi yuklenemedi.'];
    }

    $cfg = panel_config('mail') ?? [];
    $host = (string)($cfg['host'] ?? '');
    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP konfigurasyonu eksik (mail.host).'];
    }

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = (int)($cfg['port'] ?? 587);
        $mailer->SMTPAuth = (bool)($cfg['username'] ?? false);
        if ($mailer->SMTPAuth) {
            $mailer->Username = (string)$cfg['username'];
            $mailer->Password = (string)($cfg['password'] ?? '');
        }
        $secure = strtolower((string)($cfg['secure'] ?? 'tls'));
        if ($secure === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = false;
            $mailer->SMTPAutoTLS = false;
        }
        $mailer->CharSet = 'UTF-8';

        $from = (string)($cfg['from'] ?? $cfg['username'] ?? '');
        $fromName = (string)($cfg['from_name'] ?? 'DRN Servis Paneli');
        if ($from === '') {
            return ['ok' => false, 'error' => 'mail.from konfigurasyonu eksik.'];
        }
        $mailer->setFrom($from, $fromName);
        if (!empty($cfg['reply_to'])) {
            $mailer->addReplyTo((string)$cfg['reply_to']);
        }
        $mailer->addAddress($to);

        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $altBody ?? trim(strip_tags($htmlBody));

        $mailer->send();
        return ['ok' => true];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
