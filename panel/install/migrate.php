<?php
declare(strict_types=1);

/**
 * Idempotent migration runner. Eksik kolonlari ekler.
 * Kullanim: admin olarak giris yaptiktan sonra
 *   https://panel.<domain>/install/migrate.php?run=1
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!isset($_GET['run'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>Migration</title>';
    echo '<body style="font-family:system-ui;padding:24px;max-width:720px;margin:auto">';
    echo '<h1>Veritabani Migration</h1>';
    echo '<p>Bu sayfa eksik kolonlari ekler. Var olan veriyi degistirmez.</p>';
    echo '<p><a href="?run=1" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Migrationlari calistir</a></p>';
    echo '</body>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

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

function column_exists(PDO $pdo, string $db, string $table, string $col): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$db, $table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

function drop_column_if_exists(PDO $pdo, string $db, string $table, string $col, array &$log): void
{
    if (column_exists($pdo, $db, $table, $col)) {
        $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$col`");
        $log[] = "- kolon kaldirildi: $table.$col";
    } else {
        $log[] = "  kolon zaten yok: $table.$col";
    }
}

function add_unique_index_if_possible(PDO $pdo, string $db, string $table, string $index, string $defSql, string $duplicateSql, array &$log): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$db, $table, $index]);
    if ((int)$stmt->fetchColumn() > 0) {
        $log[] = "  unique index zaten var: $table.$index";
        return;
    }

    $duplicates = (int)$pdo->query($duplicateSql)->fetchColumn();
    if ($duplicates > 0) {
        $log[] = "! unique index eklenemedi: $table.$index icin $duplicates cakisan kayit grubu var";
        return;
    }

    $pdo->exec("ALTER TABLE `$table` ADD $defSql");
    $log[] = "+ unique index eklendi: $table.$index";
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

function migrate_attachment_blobs_to_files(PDO $pdo, string $db, array &$log): void
{
    if (!column_exists($pdo, $db, 'service_attachments', 'file_path')) {
        return;
    }
    if (!column_exists($pdo, $db, 'service_attachments', 'file_data')) {
        $remaining = (int)$pdo->query("SELECT COUNT(*) FROM service_attachments WHERE file_path IS NULL OR file_path = ''")->fetchColumn();
        if ($remaining === 0) {
            $pdo->exec('ALTER TABLE service_attachments MODIFY COLUMN file_path VARCHAR(500) NOT NULL');
            $log[] = '  attachment file_path kolonu zorunlu yapildi';
        }
        return;
    }

    $storageRoot = dirname(__DIR__) . '/storage';
    $attachmentsRoot = $storageRoot . '/attachments';
    if (!is_dir($attachmentsRoot) && !mkdir($attachmentsRoot, 0775, true) && !is_dir($attachmentsRoot)) {
        $log[] = '! attachment storage klasoru olusturulamadi';
        return;
    }
    $denyFile = $attachmentsRoot . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n");
    }

    $stmt = $pdo->query("SELECT id, record_id, original_name, file_data FROM service_attachments WHERE (file_path IS NULL OR file_path = '') AND file_data IS NOT NULL");
    $update = $pdo->prepare('UPDATE service_attachments SET file_path = ? WHERE id = ?');
    $migrated = 0;
    $failed = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recordId = (int)$row['record_id'];
        $dir = $attachmentsRoot . '/' . $recordId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $failed++;
            continue;
        }

        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', (string)$row['original_name']) ?: 'dosya';
        $ext = preg_replace('/[^A-Za-z0-9]+/', '', (string)pathinfo($name, PATHINFO_EXTENSION));
        $relative = 'attachments/' . $recordId . '/' . (int)$row['id'] . '-' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . strtolower($ext) : '');
        $absolute = $storageRoot . '/' . $relative;
        $data = $row['file_data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        if (file_put_contents($absolute, (string)$data, LOCK_EX) === false) {
            $failed++;
            continue;
        }

        $update->execute([$relative, (int)$row['id']]);
        $migrated++;
    }

    $log[] = "  attachment blob->dosya tasindi: $migrated";
    if ($failed > 0) {
        $log[] = "! attachment tasima hatasi: $failed";
        return;
    }

    $remaining = (int)$pdo->query("SELECT COUNT(*) FROM service_attachments WHERE file_path IS NULL OR file_path = ''")->fetchColumn();
    if ($remaining === 0) {
        $pdo->exec('ALTER TABLE service_attachments MODIFY COLUMN file_path VARCHAR(500) NOT NULL');
        drop_column_if_exists($pdo, $db, 'service_attachments', 'file_data', $log);
    } else {
        $log[] = "! file_data kolonu korundu: $remaining kayitta file_path eksik";
    }
}

function modify_column_if_needed(PDO $pdo, string $db, string $table, string $col, string $expectedType, string $defSql, array &$log): void
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$db, $table, $col]);
    $currentType = (string)($stmt->fetchColumn() ?: '');
    if (strtolower($currentType) !== strtolower($expectedType)) {
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN $defSql");
        $log[] = "~ kolon guncellendi: $table.$col";
    } else {
        $log[] = "  kolon tipi zaten guncel: $table.$col";
    }
}

function upsert_user(PDO $pdo, string $username, string $fullName, string $password, array &$log): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();

    if ($id) {
        $update = $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, role = ?, active = 1 WHERE id = ?');
        $update->execute([$hash, $fullName, 'staff', (int)$id]);
        $log[] = "  kullanici guncellendi: $username";
        return;
    }

    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (?, ?, ?, ?, 1)');
    $insert->execute([$username, $hash, $fullName, 'staff']);
    $log[] = "+ kullanici olusturuldu: $username";
}

try {
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_start_date', 'policy_start_date DATE NULL AFTER service_exit_date', $log);
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_end_date',   'policy_end_date DATE NULL AFTER policy_start_date', $log);
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_reminder_sent_at', 'policy_reminder_sent_at DATETIME NULL AFTER policy_end_date', $log);
    add_column_if_missing($pdo, $dbName, 'service_records', 'insurance_type', "insurance_type ENUM('kasko','trafik','filo','ucretli') NOT NULL DEFAULT 'kasko' AFTER insurance_company", $log);
    modify_column_if_needed($pdo, $dbName, 'service_records', 'insurance_type', "enum('kasko','trafik','filo','ucretli')", "insurance_type ENUM('kasko','trafik','filo','ucretli') NOT NULL DEFAULT 'kasko' AFTER insurance_company", $log);
    add_index_if_missing($pdo, $dbName, 'service_records', 'service_records_policy_end_index', 'INDEX service_records_policy_end_index (policy_end_date)', $log);
    add_index_if_missing($pdo, $dbName, 'service_records', 'service_records_insurance_type_index', 'INDEX service_records_insurance_type_index (insurance_type)', $log);
    add_unique_index_if_possible(
        $pdo,
        $dbName,
        'service_records',
        'service_records_vehicle_entry_customer_unique',
        'UNIQUE KEY service_records_vehicle_entry_customer_unique (plate, service_entry_date, customer_name)',
        "SELECT COUNT(*) FROM (
            SELECT plate, service_entry_date, customer_name
            FROM service_records
            GROUP BY plate, service_entry_date, customer_name
            HAVING COUNT(*) > 1
        ) dup",
        $log
    );

    $pdo->exec("UPDATE service_records SET insurance_type = 'filo' WHERE insurance_company LIKE '%filo%'");
    $pdo->exec("UPDATE service_records SET insurance_type = 'trafik' WHERE insurance_company LIKE '%trafik%'");
    $pdo->exec("UPDATE service_records SET insurance_type = 'ucretli' WHERE insurance_company LIKE '%ucret%' OR insurance_company LIKE '%ücret%'");
    $log[] = "  arac filtre tipleri sigorta metnine gore eslestirildi";

    create_table_if_missing($pdo, $dbName, 'service_attachments',
        "CREATE TABLE service_attachments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id INT UNSIGNED NOT NULL,
            category ENUM('avukat','ruhsat','kaza','police','fotograf','diger') NOT NULL DEFAULT 'diger',
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
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
    add_column_if_missing($pdo, $dbName, 'service_attachments', 'file_path', 'file_path VARCHAR(500) NULL AFTER file_size', $log);
    migrate_attachment_blobs_to_files($pdo, $dbName, $log);

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

    foreach ([
        ['özgür', 'Özgür'],
        ['çetin', 'Çetin'],
        ['doğrul', 'Doğrul'],
        ['emirhan', 'Emirhan'],
        ['nurşen', 'Nurşen'],
        ['özlem', 'Özlem'],
    ] as [$username, $fullName]) {
        upsert_user($pdo, $username, $fullName, 'tamirci1', $log);
    }

    $log[] = '';
    $log[] = 'Migration tamamlandi.';
} catch (Throwable $e) {
    $log[] = 'HATA: ' . $e->getMessage();
}

echo implode("\n", $log);
