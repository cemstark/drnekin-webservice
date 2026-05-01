<?php
declare(strict_types=1);

/**
 * Idempotent migration runner.
 * Tarayicidan https://panel.<domain>/install/migrate.php?run=1 ile veya
 * CLI'da `php panel/install/migrate.php` ile calistirilabilir.
 *
 * Eksik kolonlari ekler, eksik tablolari olusturur. Var olan veriyi degistirmez.
 */

require_once __DIR__ . '/../includes/auth.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    require_login(); // browser uzerinden sadece giris yapmis admin calistirabilir
    if (!isset($_GET['run'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset=utf-8><title>Migration</title>';
        echo '<body style="font-family:system-ui;padding:24px;max-width:720px;margin:auto">';
        echo '<h1>Veritabani Migration</h1>';
        echo '<p>Bu sayfa eksik kolon ve tablolari ekler. Var olan veriyi silmez/degistirmez.</p>';
        echo '<p><a href="?run=1" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Migrationlari calistir</a></p>';
        echo '</body>';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = db();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$log = [];

function add_column_if_missing(PDO $pdo, string $db, string $table, string $col, string $defSql, array &$log): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$db, $table, $col]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $defSql");
        $log[] = "+ kolon eklendi: $table.$col";
    } else {
        $log[] = "  kolon zaten var: $table.$col";
    }
}

function add_index_if_missing(PDO $pdo, string $db, string $table, string $index, string $defSql, array &$log): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$db, $table, $index]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD $defSql");
        $log[] = "+ index eklendi: $table.$index";
    } else {
        $log[] = "  index zaten var: $table.$index";
    }
}

function create_table_if_missing(PDO $pdo, string $db, string $table, string $createSql, array &$log): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$db, $table]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($createSql);
        $log[] = "+ tablo olusturuldu: $table";
    } else {
        $log[] = "  tablo zaten var: $table";
    }
}

try {
    // 1. service_records yeni kolonlar
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_start_date',       'policy_start_date DATE NULL AFTER service_exit_date', $log);
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_end_date',         'policy_end_date DATE NULL AFTER policy_start_date', $log);
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_reminder_sent_at', 'policy_reminder_sent_at DATETIME NULL AFTER policy_end_date', $log);
    add_index_if_missing($pdo, $dbName, 'service_records', 'service_records_policy_end_index', 'INDEX service_records_policy_end_index (policy_end_date)', $log);

    // 2. service_attachments tablosu
    create_table_if_missing($pdo, $dbName, 'service_attachments',
        "CREATE TABLE service_attachments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id INT UNSIGNED NOT NULL,
            category ENUM('avukat','ruhsat','kaza','police','fotograf','diger') NOT NULL DEFAULT 'diger',
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            file_data MEDIUMBLOB NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY service_attachments_record_index (record_id),
            KEY service_attachments_category_index (category),
            CONSTRAINT service_attachments_record_fk FOREIGN KEY (record_id) REFERENCES service_records(id) ON DELETE CASCADE,
            CONSTRAINT service_attachments_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        $log
    );

    // 3. cron_runs lock tablosu (auto-trigger icin)
    create_table_if_missing($pdo, $dbName, 'cron_runs',
        "CREATE TABLE cron_runs (
            job_key VARCHAR(60) NOT NULL,
            last_run_date DATE NOT NULL,
            last_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_status VARCHAR(20) NOT NULL DEFAULT 'ok',
            last_payload TEXT NULL,
            PRIMARY KEY (job_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        $log
    );

    $log[] = '';
    $log[] = 'Migration tamamlandi.';
} catch (Throwable $e) {
    $log[] = 'HATA: ' . $e->getMessage();
}

if (!$cli) {
    echo implode("\n", $log);
} else {
    echo implode("\n", $log) . "\n";
}
