<?php
declare(strict_types=1);

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsx_read_first_sheet(string $path): array
{
    $sheets = xlsx_read_sheets($path);
    return $sheets[0]['rows'] ?? [];
}

function xlsx_read_sheets(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Sunucuda ZipArchive PHP eklentisi aktif degil.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excel dosyasi acilamadi.');
    }

    $sharedStrings = xlsx_shared_strings($zip);
    $sheetPaths = xlsx_sheet_paths($zip);
    $sheetPaths = xlsx_add_worksheet_fallbacks($zip, $sheetPaths);
    $sheets = [];
    foreach ($sheetPaths as $sheet) {
        $sheetXml = $zip->getFromName($sheet['path']);
        if ($sheetXml === false) {
            continue;
        }

        $sheets[] = [
            'name' => $sheet['name'],
            'rows' => xlsx_parse_sheet_rows($sheetXml, $sharedStrings),
        ];
    }
    $zip->close();

    if ($sheets === []) {
        throw new RuntimeException('Excel sayfasi okunamadi.');
    }

    return $sheets;
}

function xlsx_parse_sheet_rows(string $sheetXml, array $sharedStrings): array
{
    $xml = simplexml_load_string($sheetXml);
    if (!$xml) {
        throw new RuntimeException('Excel XML verisi okunamadi.');
    }

    $rows = [];
    $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
    foreach ($rowNodes as $row) {
        $values = [];
        $cellNodes = $row->xpath('./*[local-name()="c"]') ?: [];
        foreach ($cellNodes as $cell) {
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
    $items = $xml->xpath('//*[local-name()="si"]') ?: [];
    foreach ($items as $si) {
        $texts = $si->xpath('.//*[local-name()="t"]') ?: [];
        if ($texts !== []) {
            $text = '';
            foreach ($texts as $node) {
                $text .= (string)$node;
            }
            $strings[] = $text;
            continue;
        }

        $strings[] = '';
    }

    return $strings;
}

function xlsx_sheet_paths(ZipArchive $zip): array
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false || $rels === false) {
        return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml']];
    }

    $workbookXml = simplexml_load_string($workbook);
    $relsXml = simplexml_load_string($rels);
    if (!$workbookXml || !$relsXml) {
        return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml']];
    }

    $workbookXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheets = $workbookXml->xpath('//main:sheet');
    if (!$sheets || !isset($sheets[0])) {
        return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml']];
    }

    $pathsByRel = [];
    $relationships = $relsXml->children('http://schemas.openxmlformats.org/package/2006/relationships');
    foreach ($relationships->Relationship as $rel) {
        $target = (string)$rel['Target'];
        $pathsByRel[(string)$rel['Id']] = xlsx_target_path($target);
    }

    $result = [];
    foreach ($sheets as $sheet) {
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relId = (string)($attrs['id'] ?? '');
        if (isset($pathsByRel[$relId])) {
            $result[] = [
                'name' => (string)($sheet['name'] ?? 'Sheet'),
                'path' => $pathsByRel[$relId],
            ];
        }
    }

    return $result !== [] ? $result : [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml']];
}

function xlsx_add_worksheet_fallbacks(ZipArchive $zip, array $sheetPaths): array
{
    $known = [];
    foreach ($sheetPaths as $sheet) {
        $known[$sheet['path']] = true;
    }

    $fallbacks = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || !preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $matches)) {
            continue;
        }

        if (!isset($known[$name])) {
            $fallbacks[] = [
                'name' => 'Sheet' . $matches[1],
                'path' => $name,
            ];
            $known[$name] = true;
        }
    }

    usort($fallbacks, static function (array $a, array $b): int {
        preg_match('/(\d+)/', $a['name'], $aMatch);
        preg_match('/(\d+)/', $b['name'], $bMatch);
        return ((int)($aMatch[1] ?? 0)) <=> ((int)($bMatch[1] ?? 0));
    });

    return array_merge($sheetPaths, $fallbacks);
}

function xlsx_target_path(string $target): string
{
    if (str_starts_with($target, '/xl/')) {
        return ltrim($target, '/');
    }

    if (str_starts_with($target, 'xl/')) {
        return $target;
    }

    $path = 'xl/' . ltrim($target, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
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
    $values = $cell->xpath('./*[local-name()="v"]') ?: [];
    $inline = $cell->xpath('.//*[local-name()="t"]') ?: [];
    if ($type === 's') {
        $index = isset($values[0]) ? (int)$values[0] : -1;
        return trim((string)($sharedStrings[$index] ?? ''));
    }

    if ($type === 'inlineStr') {
        return isset($inline[0]) ? trim((string)$inline[0]) : '';
    }

    return isset($values[0]) ? trim((string)$values[0]) : '';
}
