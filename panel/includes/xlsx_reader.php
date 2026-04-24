<?php
declare(strict_types=1);

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsx_read_first_sheet(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Sunucuda ZipArchive PHP eklentisi aktif degil.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excel dosyasi acilamadi.');
    }

    $sharedStrings = xlsx_shared_strings($zip);
    $sheetPath = xlsx_first_sheet_path($zip);
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Excel sayfasi okunamadi.');
    }

    $xml = simplexml_load_string($sheetXml);
    if (!$xml) {
        throw new RuntimeException('Excel XML verisi okunamadi.');
    }

    $sheet = $xml->children(XLSX_NS);
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        $rowCells = $row->children(XLSX_NS);
        foreach ($rowCells->c as $cell) {
            $ref = (string)$cell['r'];
            $column = xlsx_column_index($ref);
            $values[$column] = xlsx_cell_value($cell, $sharedStrings);
        }

        if ($values === []) {
            continue;
        }

        $max = max(array_keys($values));
        $normalized = [];
        for ($i = 0; $i <= $max; $i++) {
            $normalized[] = $values[$i] ?? '';
        }
        $rows[] = $normalized;
    }

    return $rows;
}

function xlsx_shared_strings(ZipArchive $zip): array
{
    $raw = $zip->getFromName('xl/sharedStrings.xml');
    if ($raw === false) {
        return [];
    }

    $xml = simplexml_load_string($raw);
    if (!$xml) {
        return [];
    }

    $strings = [];
    $sst = $xml->children(XLSX_NS);
    foreach ($sst->si as $si) {
        $item = $si->children(XLSX_NS);
        if (isset($item->t)) {
            $strings[] = (string)$item->t;
            continue;
        }

        $text = '';
        foreach ($item->r as $run) {
            $runChildren = $run->children(XLSX_NS);
            $text .= (string)$runChildren->t;
        }
        $strings[] = $text;
    }

    return $strings;
}

function xlsx_first_sheet_path(ZipArchive $zip): string
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false || $rels === false) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbookXml = simplexml_load_string($workbook);
    $relsXml = simplexml_load_string($rels);
    if (!$workbookXml || !$relsXml) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbookXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheets = $workbookXml->xpath('//main:sheet');
    if (!$sheets || !isset($sheets[0])) {
        return 'xl/worksheets/sheet1.xml';
    }

    $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relId = (string)($attrs['id'] ?? '');
    $relationships = $relsXml->children('http://schemas.openxmlformats.org/package/2006/relationships');
    foreach ($relationships->Relationship as $rel) {
        if ((string)$rel['Id'] === $relId) {
            $target = (string)$rel['Target'];
            if (str_starts_with($target, '/xl/')) {
                return ltrim($target, '/');
            }
            return str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
        }
    }

    return 'xl/worksheets/sheet1.xml';
}

function xlsx_column_index(string $cellRef): int
{
    preg_match('/^[A-Z]+/i', $cellRef, $matches);
    $letters = strtoupper($matches[0] ?? 'A');
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function xlsx_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type = (string)$cell['t'];
    $value = $cell->children(XLSX_NS);
    if ($type === 's') {
        $index = (int)($value->v ?? -1);
        return trim((string)($sharedStrings[$index] ?? ''));
    }

    if ($type === 'inlineStr') {
        return trim((string)($value->is->t ?? ''));
    }

    return trim((string)($value->v ?? ''));
}
