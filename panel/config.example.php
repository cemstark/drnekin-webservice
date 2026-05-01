<?php
declare(strict_types=1);

return [
    'app_name' => 'DRN Servis Paneli',
    'base_path' => null,
    'timezone' => 'Europe/Istanbul',
    'session_name' => 'drn_panel_session',
    'api_key' => 'CHANGE_THIS_LONG_RANDOM_IMPORT_KEY',
    'db' => [
        'host' => 'localhost',
        'name' => 'u000000000_drn_panel',
        'user' => 'u000000000_drn_panel',
        'pass' => 'CHANGE_THIS_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host'      => 'smtp.example.com',
        'port'      => 587,
        'secure'    => 'tls', // tls | ssl | none
        'username'  => 'no-reply@example.com',
        'password'  => 'CHANGE_THIS_SMTP_PASSWORD',
        'from'      => 'no-reply@example.com',
        'from_name' => 'DRN Servis Paneli',
        'reply_to'  => null,
    ],
    'policy_reminder' => [
        'recipient'   => 'ekin@ekinotoizmit.com',
        'days_before' => 30,
        // Web cron endpoint icin gizli token (cron/run.php?token=...).
        // Hostinger Cron Jobs veya cron-job.org tarafindan kullanilir.
        // Kendi rastgele uzun bir deger atayin.
        'cron_token'  => 'CHANGE_THIS_LONG_RANDOM_CRON_TOKEN',
    ],
];
