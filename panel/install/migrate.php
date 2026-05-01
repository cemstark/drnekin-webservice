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

try {
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_start_date', 'policy_start_date DATE NULL AFTER service_exit_date', $log);
    add_column_if_missing($pdo, $dbName, 'service_records', 'policy_end_date',   'policy_end_date DATE NULL AFTER policy_start_date', $log);
    add_index_if_missing($pdo, $dbName, 'service_records', 'service_records_policy_end_index', 'INDEX service_records_policy_end_index (policy_end_date)', $log);

    $log[] = '';
    $log[] = 'Migration tamamlandi.';
} catch (Throwable $e) {
    $log[] = 'HATA: ' . $e->getMessage();
}

echo implode("\n", $log);
