<?php
declare(strict_types=1);

function repair_status_options(): array
{
    return [
        'Giris Yapti' => 'Giris Yapti',
        'Eksper Bekliyor' => 'Eksper Bekliyor',
        'Sigorta Onayi Bekliyor' => 'Sigorta Onayi Bekliyor',
        'Onaylandi' => 'Onaylandi',
        'Parca Bekliyor' => 'Parca Bekliyor',
        'Tamirde' => 'Tamirde',
        'Boya Kaporta' => 'Boya Kaporta',
        'Kalite Kontrol' => 'Kalite Kontrol',
        'Cikisa Hazir' => 'Cikisa Hazir',
        'Teslim Edildi' => 'Teslim Edildi',
        'Eksik Evrak' => 'Eksik Evrak',
        'Iptal / Red' => 'Iptal / Red',
    ];
}

function normalize_repair_status(string $status): string
{
    $status = trim($status);
    return $status === '' ? 'Giris Yapti' : $status;
}

function repair_status_tone(string $status): string
{
    $key = strtolower($status);
    if (str_contains($key, 'teslim') || str_contains($key, 'hazir') || str_contains($key, 'onaylandi') || str_contains($key, 'kalite')) {
        return 'status-green';
    }
    if (str_contains($key, 'eksik') || str_contains($key, 'iptal') || str_contains($key, 'red')) {
        return 'status-red';
    }

    return 'status-yellow';
}

function insurance_type_options(): array
{
    return [
        'kasko' => 'Kasko',
        'trafik' => 'Trafik',
        'filo' => 'Filo',
    ];
}

function insurance_type_label(?string $type): string
{
    $options = insurance_type_options();
    return $options[(string)$type] ?? 'Kasko';
}

function valid_insurance_type(?string $type): bool
{
    return array_key_exists((string)$type, insurance_type_options());
}

function format_tr_date(?string $value, string $fallback = '-'): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function format_tr_datetime(?string $value, string $fallback = '-'): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}
