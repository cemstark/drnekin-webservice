<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/options.php';
require_once __DIR__ . '/xlsx_reader.php';

function import_excel_file(string $path, string $sourceName): array
{
    $started = microtime(true);
    $sheets = xlsx_read_sheets($path);
    $errors = [];
    $sheetSummaries = [];
    $imported = 0;
    $skipped = 0;
    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($sheets as $sheet) {
            $rows = $sheet['rows'];
            $sheetImported = 0;
            if (count($rows) < 2) {
                $errors[] = 'Sayfa ' . $sheet['name'] . ': veri satiri okunamadi.';
                $sheetSummaries[] = $sheet['name'] . ': 0 kayit';
                continue;
            }

            $header = array_map('header_key', array_shift($rows));
            $map = build_header_map($header);
            if (count($map) < 2) {
                $errors[] = 'Sayfa ' . $sheet['name'] . ': basliklar taninamadi: ' . implode(' | ', array_slice($header, 0, 12));
                $sheetSummaries[] = $sheet['name'] . ': 0 kayit';
                continue;
            }

            $missing = [];
            foreach (['plate', 'customer_name', 'service_entry_date'] as $field) {
                if (!array_key_exists($field, $map)) {
                    $missing[] = required_label($field);
                }
            }
            if ($missing !== []) {
                $errors[] = 'Sayfa ' . $sheet['name'] . ': eksik kolonlar: ' . implode(', ', $missing);
                $sheetSummaries[] = $sheet['name'] . ': 0 kayit';
                continue;
            }

            foreach ($rows as $rowIndex => $row) {
                $line = $rowIndex + 2;
                if (row_is_empty($row)) {
                    continue;
                }

                $entryDate = normalize_date(cell($row, $map, 'service_entry_date'));
                $record = [
                    'record_no' => cell($row, $map, 'record_no'),
                    'plate' => normalize_plate(cell($row, $map, 'plate')),
                    'customer_name' => cell($row, $map, 'customer_name'),
                    'insurance_company' => cell($row, $map, 'insurance_company'),
                    'repair_status' => normalize_repair_status(cell($row, $map, 'repair_status')),
                    'mini_repair_part' => cell($row, $map, 'mini_repair_part'),
                    'service_entry_date' => $entryDate,
                    'service_exit_date' => normalize_date(cell($row, $map, 'service_exit_date')),
                ];
                $record['insurance_type'] = infer_insurance_type_from_company($record['insurance_company']);

                if ($record['record_no'] === '') {
                    $record['record_no'] = generated_record_no(
                        $sheet['name'],
                        $record['plate'],
                        $entryDate,
                        cell($row, $map, 'file_no'),
                        $line
                    );
                }

                $hasMini = parse_bool(cell($row, $map, 'mini_repair_has'));
                if ($record['mini_repair_part'] !== '') {
                    $hasMini = true;
                }
                $record['mini_repair_has'] = $hasMini ? 1 : 0;

                $rowErrors = validate_record($record);
                if ($rowErrors !== []) {
                    $skipped++;
                    $errors[] = 'Sayfa ' . $sheet['name'] . ' satir ' . $line . ': ' . implode(', ', $rowErrors);
                    continue;
                }

                if (service_record_duplicate_exists($pdo, $record)) {
                    $skipped++;
                    continue;
                }

                if (mark_existing_plate_return($pdo, (string)$record['plate'])) {
                    $skipped++;
                    continue;
                }

                upsert_service_record($pdo, $record);
                $imported++;
                $sheetImported++;
            }

            $sheetSummaries[] = $sheet['name'] . ': ' . $sheetImported . ' kayit';
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($sheetSummaries !== []) {
        array_unshift($errors, 'Sayfa ozeti: ' . implode(', ', $sheetSummaries));
    }

    if ($imported === 0 && count($errors) <= 1) {
        $errors[] = 'Excel dosyasinda aktarilabilir veri bulunamadi.';
    }

    return import_log_result($sourceName, $imported, $skipped, $errors, $started);
}

function build_header_map(array $headers): array
{
    $aliases = [
        'record_no' => ['kayit no', 'kayit numarasi', 'record no'],
        'plate' => ['plaka', 'arac plakasi', 'arac plaka'],
        'customer_name' => ['ad soyad', 'adi soyadi', 'isim soyisim', 'kullanici isim soyisim', 'musteri', 'musteri ad soyad'],
        'insurance_company' => ['sigorta sirketi', 'sigorta', 'si gorta'],
        'repair_status' => ['tamir durumu', 'guncel durum', 'servisteki guncel durum', 'durum'],
        'mini_repair_has' => ['mini onarim', 'mini onarim var mi'],
        'mini_repair_part' => ['mini onarim parca', 'hangi parca', 'parca', 'prc'],
        'service_entry_date' => ['giris tarihi', 'servise giris tarihi', 'ser giris', 'ser gi ri'],
        'service_exit_date' => ['cikis tarihi', 'servisten cikis tarihi', 'tes tarihi', 'tes tari hi'],
        'file_no' => ['dosya no', 'dosya numarasi'],
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
    $value = trim((string)$value);
    $value = strtr($value, [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ğ' => 'g', 'ğ' => 'g',
        'Ü' => 'u', 'ü' => 'u', 'Ş' => 's', 'ş' => 's', 'Ö' => 'o',
        'ö' => 'o', 'Ç' => 'c', 'ç' => 'c',
    ]);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
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

    $value = preg_replace('/\.+/', '.', $value) ?? $value;
    $value = strtr($value, ['O' => '0', 'o' => '0']);
    if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(206)$/', $value, $matches)) {
        return '2026-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
    }

    if (is_numeric($value)) {
        $days = (int)floor((float)$value);
        if ($days > 25000 && $days < 90000) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->format('Y-m-d');
        }
        return null;
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

function generated_record_no(string $sheetName, string $plate, ?string $entryDate, string $fileNo, int $line): string
{
    $fileNo = trim($fileNo);
    if (str_starts_with($fileNo, 'manual-')) {
        return substr($fileNo, 0, 80);
    }

    $parts = [
        header_key($sheetName),
        header_key($plate),
        $entryDate ?: 'tarihsiz',
        $fileNo !== '' ? header_key($fileNo) : 'satir-' . $line,
    ];
    return substr(implode('-', array_filter($parts)), 0, 80);
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
    $hasInsuranceType = importer_insurance_type_column_exists($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO service_records
        (record_no, plate, customer_name, ' . ($hasInsuranceType ? 'insurance_type, ' : '') . 'insurance_company, repair_status, mini_repair_has, mini_repair_part, service_entry_date, service_exit_date, service_month, updated_at)
        VALUES
        (:record_no, :plate, :customer_name, ' . ($hasInsuranceType ? ':insurance_type, ' : '') . ':insurance_company, :repair_status, :mini_repair_has, :mini_repair_part, :service_entry_date, :service_exit_date, :service_month, NOW())
        ON DUPLICATE KEY UPDATE
        plate = VALUES(plate),
        customer_name = VALUES(customer_name),
        ' . ($hasInsuranceType ? 'insurance_type = VALUES(insurance_type),' : '') . '
        insurance_company = VALUES(insurance_company),
        repair_status = VALUES(repair_status),
        mini_repair_has = VALUES(mini_repair_has),
        mini_repair_part = VALUES(mini_repair_part),
        service_entry_date = VALUES(service_entry_date),
        service_exit_date = VALUES(service_exit_date),
        service_month = VALUES(service_month),
        updated_at = NOW()'
    );

    $params = [
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
    ];
    if ($hasInsuranceType) {
        $params[':insurance_type'] = $record['insurance_type'] ?? infer_insurance_type_from_company((string)$record['insurance_company']);
    }
    $stmt->execute($params);
}

function service_record_duplicate_exists(PDO $pdo, array $record): bool
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM service_records
         WHERE plate = ?
           AND service_entry_date = ?
           AND customer_name = ?
         LIMIT 1'
    );
    $stmt->execute([
        $record['plate'],
        $record['service_entry_date'],
        $record['customer_name'],
    ]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Ayni plakali kayit varsa, en eski (orijinal) kaydin return_count
 * sayacini artirir, last_return_at'i guncel zamana ceker ve true doner.
 * Yoksa false donerek normal insert akisini surdurur.
 */
function mark_existing_plate_return(PDO $pdo, string $plate): bool
{
    $plate = trim($plate);
    if ($plate === '' || !importer_return_columns_exist($pdo)) {
        return false;
    }

    $find = $pdo->prepare('SELECT id FROM service_records WHERE plate = ? ORDER BY service_entry_date ASC, id ASC LIMIT 1');
    $find->execute([$plate]);
    $existingId = (int)($find->fetchColumn() ?: 0);
    if ($existingId === 0) {
        return false;
    }

    $update = $pdo->prepare('UPDATE service_records SET return_count = COALESCE(return_count, 1) + 1, last_return_at = NOW(), updated_at = NOW() WHERE id = ?');
    $update->execute([$existingId]);
    return true;
}

function importer_return_columns_exist(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $pdo->query('SELECT return_count, last_return_at FROM service_records LIMIT 0');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function importer_insurance_type_column_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo->query('SELECT insurance_type FROM service_records LIMIT 0');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
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

/* ── Deger Kaybi Importer ───────────────────────────────────────────────────── */

function import_dk_excel_file(string $path, string $sourceName): array
{
    $started = microtime(true);
    $sheets  = xlsx_read_sheets($path);
    $errors  = [];
    $summaries = [];
    $imported = 0;
    $skipped  = 0;
    $pdo = db();
    $pdo->beginTransaction();

    $skipSheets = ['ozet', 'summary'];

    try {
        foreach ($sheets as $sheet) {
            $sheetKey = header_key($sheet['name']);
            if (in_array($sheetKey, $skipSheets, true)) {
                continue;
            }

            $rows = $sheet['rows'];
            if (count($rows) < 2) {
                $summaries[] = $sheet['name'] . ': veri yok';
                continue;
            }

            $header = array_map('header_key', array_shift($rows));
            $map    = build_dk_header_map($header);

            if (!array_key_exists('plaka', $map)) {
                $errors[]   = 'Sayfa ' . $sheet['name'] . ': PLAKA sutunu bulunamadi';
                $summaries[] = $sheet['name'] . ': 0 kayit';
                continue;
            }

            $sheetImported = 0;
            foreach ($rows as $rowIndex => $row) {
                if (row_is_empty($row)) {
                    continue;
                }
                $line = $rowIndex + 2;

                $plaka = normalize_plate(cell($row, $map, 'plaka'));
                if ($plaka === '') {
                    $skipped++;
                    continue;
                }

                $rec = [
                    'plaka'          => $plaka,
                    'adi_soyadi'     => cell($row, $map, 'adi_soyadi'),
                    'tel'            => cell($row, $map, 'tel'),
                    'sigorta'        => cell($row, $map, 'sigorta'),
                    'hasar_tarihi'   => normalize_date(cell($row, $map, 'hasar_tarihi')),
                    'police_no'      => cell($row, $map, 'police_no'),
                    'dosya_no'       => cell($row, $map, 'dosya_no'),
                    'fatura_tarihi'  => normalize_date(cell($row, $map, 'fatura_tarihi')),
                    'eksper'         => cell($row, $map, 'eksper'),
                    'teminat'        => normalize_dk_money(cell($row, $map, 'teminat')),
                    'fatura_tutari'  => normalize_dk_money(cell($row, $map, 'fatura_tutari')),
                    'yatma_parasi'   => normalize_dk_money(cell($row, $map, 'yatma_parasi')),
                    'takip'          => cell($row, $map, 'takip'),
                    'durum'          => cell($row, $map, 'durum'),
                    'acente'         => cell($row, $map, 'acente'),
                    'aciklama'       => cell($row, $map, 'aciklama'),
                ];

                if (dk_duplicate_exists($pdo, $rec)) {
                    $skipped++;
                    continue;
                }

                insert_dk_record($pdo, $rec);
                $imported++;
                $sheetImported++;
            }

            $summaries[] = $sheet['name'] . ': ' . $sheetImported . ' kayit';
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($summaries !== []) {
        array_unshift($errors, 'Sayfa ozeti: ' . implode(', ', $summaries));
    }
    if ($imported === 0 && count($errors) <= 1) {
        $errors[] = 'Aktarilabilir deger kaybi verisi bulunamadi.';
    }

    return import_log_result($sourceName, $imported, $skipped, $errors, $started);
}

function build_dk_header_map(array $headers): array
{
    $aliases = [
        'plaka'         => ['plaka', 'arac plakasi', 'arac plaka'],
        'adi_soyadi'    => ['adi soyadi', 'ad soyad', 'isim', 'musteri'],
        'tel'           => ['tel', 'telefon', 'gsm'],
        'sigorta'       => ['kasko trafik', 'kasko', 'trafik', 'sigorta', 'sigorta turu'],
        'hasar_tarihi'  => ['hasar tarihi', 'hasar tarih'],
        'police_no'     => ['police no', 'police numarasi', 'police'],
        'dosya_no'      => ['dosya no', 'dosya numarasi', 'dosya'],
        'fatura_tarihi' => ['fatura tarih', 'fatura tarihi'],
        'eksper'        => ['eksper', 'eksper adi'],
        'teminat'       => ['teminat'],
        'fatura_tutari' => ['fat tut', 'fatura tutari', 'fatura tutar', 'tutar'],
        'yatma_parasi'  => ['yat para', 'yatma parasi', 'hak mahrum', 'yat', 'yatma'],
        'takip'         => ['takip'],
        'durum'         => ['durum'],
        'acente'        => ['acente'],
        'aciklama'      => ['aciklama', 'not', 'notlar', 'aciklamalar'],
    ];

    $map = [];
    foreach ($headers as $index => $header) {
        foreach ($aliases as $field => $names) {
            if (!array_key_exists($field, $map) && in_array($header, array_map('header_key', $names), true)) {
                $map[$field] = $index;
            }
        }
    }
    return $map;
}

function normalize_dk_money(string $value): ?float
{
    $value = trim($value);
    if ($value === '' || $value === '-') {
        return null;
    }
    $value = preg_replace('/[₺TL\s]/u', '', $value) ?? $value;
    // Turkish thousands: 200.000 — strip dots then replace comma with dot
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float)$value : null;
}

function dk_duplicate_exists(PDO $pdo, array $rec): bool
{
    if ($rec['dosya_no'] !== '') {
        $stmt = $pdo->prepare('SELECT id FROM deger_kaybi_records WHERE plaka = ? AND dosya_no = ? LIMIT 1');
        $stmt->execute([$rec['plaka'], $rec['dosya_no']]);
        return (bool)$stmt->fetchColumn();
    }

    if ($rec['hasar_tarihi'] !== null) {
        $stmt = $pdo->prepare('SELECT id FROM deger_kaybi_records WHERE plaka = ? AND adi_soyadi = ? AND hasar_tarihi = ? LIMIT 1');
        $stmt->execute([$rec['plaka'], $rec['adi_soyadi'], $rec['hasar_tarihi']]);
        return (bool)$stmt->fetchColumn();
    }

    return false;
}

function insert_dk_record(PDO $pdo, array $rec): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO deger_kaybi_records
         (plaka, adi_soyadi, tel, sigorta, hasar_tarihi, police_no, dosya_no,
          fatura_tarihi, eksper, teminat, fatura_tutari, yatma_parasi,
          takip, durum, acente, aciklama)
         VALUES
         (:plaka, :adi_soyadi, :tel, :sigorta, :hasar_tarihi, :police_no, :dosya_no,
          :fatura_tarihi, :eksper, :teminat, :fatura_tutari, :yatma_parasi,
          :takip, :durum, :acente, :aciklama)'
    );
    $stmt->execute([
        ':plaka'         => $rec['plaka'],
        ':adi_soyadi'    => $rec['adi_soyadi'],
        ':tel'           => $rec['tel'],
        ':sigorta'       => $rec['sigorta'],
        ':hasar_tarihi'  => $rec['hasar_tarihi'],
        ':police_no'     => $rec['police_no'],
        ':dosya_no'      => $rec['dosya_no'],
        ':fatura_tarihi' => $rec['fatura_tarihi'],
        ':eksper'        => $rec['eksper'],
        ':teminat'       => $rec['teminat'],
        ':fatura_tutari' => $rec['fatura_tutari'],
        ':yatma_parasi'  => $rec['yatma_parasi'],
        ':takip'         => $rec['takip'],
        ':durum'         => $rec['durum'],
        ':acente'        => $rec['acente'],
        ':aciklama'      => $rec['aciklama'],
    ]);
}
