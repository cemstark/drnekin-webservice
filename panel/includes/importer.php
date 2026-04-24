<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/xlsx_reader.php';

function import_excel_file(string $path, string $sourceName): array
{
    $started = microtime(true);
    $rows = xlsx_read_first_sheet($path);
    if (count($rows) < 2) {
        return import_log_result($sourceName, 0, 0, ['Excel dosyasinda veri satiri bulunamadi.'], $started);
    }

    $header = array_map('header_key', array_shift($rows));
    $map = build_header_map($header);
    $required = ['record_no', 'plate', 'customer_name', 'service_entry_date'];
    $errors = [];

    foreach ($required as $field) {
        if (!array_key_exists($field, $map)) {
            $errors[] = required_label($field) . ' kolonu bulunamadi.';
        }
    }

    if ($errors !== []) {
        return import_log_result($sourceName, 0, 0, $errors, $started);
    }

    $imported = 0;
    $skipped = 0;
    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($rows as $rowIndex => $row) {
            $line = $rowIndex + 2;
            if (row_is_empty($row)) {
                continue;
            }

            $record = [
                'record_no' => cell($row, $map, 'record_no'),
                'plate' => normalize_plate(cell($row, $map, 'plate')),
                'customer_name' => cell($row, $map, 'customer_name'),
                'insurance_company' => cell($row, $map, 'insurance_company'),
                'repair_status' => cell($row, $map, 'repair_status') ?: 'Belirtilmedi',
                'mini_repair_part' => cell($row, $map, 'mini_repair_part'),
                'service_entry_date' => normalize_date(cell($row, $map, 'service_entry_date')),
                'service_exit_date' => normalize_date(cell($row, $map, 'service_exit_date')),
            ];

            $hasMini = parse_bool(cell($row, $map, 'mini_repair_has'));
            if ($record['mini_repair_part'] !== '') {
                $hasMini = true;
            }
            $record['mini_repair_has'] = $hasMini ? 1 : 0;

            $rowErrors = validate_record($record);
            if ($rowErrors !== []) {
                $skipped++;
                $errors[] = 'Satir ' . $line . ': ' . implode(', ', $rowErrors);
                continue;
            }

            upsert_service_record($pdo, $record);
            $imported++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return import_log_result($sourceName, $imported, $skipped, $errors, $started);
}

function build_header_map(array $headers): array
{
    $aliases = [
        'record_no' => ['kayit no', 'kayit numarasi', 'kayit', 'record no', 'kayıt no', 'kayıt numarası'],
        'plate' => ['plaka', 'arac plakasi', 'araç plakası', 'arac plaka', 'araç plaka'],
        'customer_name' => ['ad soyad', 'isim soyisim', 'kullanici isim soyisim', 'kullanıcı isim soyisim', 'musteri', 'müşteri', 'musteri ad soyad', 'müşteri ad soyad'],
        'insurance_company' => ['sigorta sirketi', 'sigorta şirketi', 'sigorta'],
        'repair_status' => ['tamir durumu', 'guncel durum', 'güncel durum', 'servisteki guncel durum', 'servisteki güncel durum'],
        'mini_repair_has' => ['mini onarim', 'mini onarım', 'mini onarim var mi', 'mini onarım var mı'],
        'mini_repair_part' => ['mini onarim parca', 'mini onarım parça', 'hangi parca', 'hangi parça', 'parca', 'parça'],
        'service_entry_date' => ['giris tarihi', 'giriş tarihi', 'servise giris tarihi', 'servise giriş tarihi'],
        'service_exit_date' => ['cikis tarihi', 'çıkış tarihi', 'servisten cikis tarihi', 'servisten çıkış tarihi'],
    ];

    $map = [];
    foreach ($headers as $index => $header) {
        foreach ($aliases as $field => $names) {
            if (in_array($header, array_map('header_key', $names), true)) {
                $map[$field] = $index;
            }
        }
    }

    return $map;
}

function header_key(mixed $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$value), 'UTF-8') : strtolower(trim((string)$value));
    $value = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ'], ['i', 'g', 'u', 's', 'o', 'c', 'i'], $value);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function cell(array $row, array $map, string $field): string
{
    if (!array_key_exists($field, $map)) {
        return '';
    }
    return trim((string)($row[$map[$field]] ?? ''));
}

function row_is_empty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') {
            return false;
        }
    }
    return true;
}

function normalize_plate(string $plate): string
{
    $plate = function_exists('mb_strtoupper') ? mb_strtoupper(trim($plate), 'UTF-8') : strtoupper(trim($plate));
    return preg_replace('/\s+/', ' ', $plate) ?? $plate;
}

function normalize_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $days = (int)floor((float)$value);
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function parse_bool(string $value): bool
{
    $value = header_key($value);
    return in_array($value, ['1', 'evet', 'var', 'true', 'yes', 'mini onarim'], true);
}

function validate_record(array $record): array
{
    $errors = [];
    foreach (['record_no' => 'Kayit No', 'plate' => 'Plaka', 'customer_name' => 'Ad Soyad'] as $field => $label) {
        if ((string)$record[$field] === '') {
            $errors[] = $label . ' bos';
        }
    }
    if ($record['service_entry_date'] === null) {
        $errors[] = 'Giris tarihi hatali veya bos';
    }
    return $errors;
}

function upsert_service_record(PDO $pdo, array $record): void
{
    $month = substr((string)$record['service_entry_date'], 0, 7);
    $stmt = $pdo->prepare(
        'INSERT INTO service_records
        (record_no, plate, customer_name, insurance_company, repair_status, mini_repair_has, mini_repair_part, service_entry_date, service_exit_date, service_month, updated_at)
        VALUES
        (:record_no, :plate, :customer_name, :insurance_company, :repair_status, :mini_repair_has, :mini_repair_part, :service_entry_date, :service_exit_date, :service_month, NOW())
        ON DUPLICATE KEY UPDATE
        plate = VALUES(plate),
        customer_name = VALUES(customer_name),
        insurance_company = VALUES(insurance_company),
        repair_status = VALUES(repair_status),
        mini_repair_has = VALUES(mini_repair_has),
        mini_repair_part = VALUES(mini_repair_part),
        service_entry_date = VALUES(service_entry_date),
        service_exit_date = VALUES(service_exit_date),
        service_month = VALUES(service_month),
        updated_at = NOW()'
    );

    $stmt->execute([
        ':record_no' => $record['record_no'],
        ':plate' => $record['plate'],
        ':customer_name' => $record['customer_name'],
        ':insurance_company' => $record['insurance_company'],
        ':repair_status' => $record['repair_status'],
        ':mini_repair_has' => $record['mini_repair_has'],
        ':mini_repair_part' => $record['mini_repair_part'],
        ':service_entry_date' => $record['service_entry_date'],
        ':service_exit_date' => $record['service_exit_date'],
        ':service_month' => $month,
    ]);
}

function import_log_result(string $sourceName, int $imported, int $skipped, array $errors, float $started): array
{
    $status = $errors === [] ? 'success' : ($imported > 0 ? 'partial' : 'failed');
    $duration = (int)round((microtime(true) - $started) * 1000);

    $stmt = db()->prepare(
        'INSERT INTO import_logs (source_name, status, imported_count, skipped_count, error_summary, duration_ms, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $sourceName,
        $status,
        $imported,
        $skipped,
        $errors === [] ? null : implode("\n", array_slice($errors, 0, 50)),
        $duration,
    ]);

    return [
        'status' => $status,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
        'duration_ms' => $duration,
    ];
}

function required_label(string $field): string
{
    return [
        'record_no' => 'Kayit No',
        'plate' => 'Plaka',
        'customer_name' => 'Ad Soyad',
        'service_entry_date' => 'Giris Tarihi',
    ][$field] ?? $field;
}
