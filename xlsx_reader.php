<?php
declare(strict_types=1);

function readCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not open CSV file.');
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function readXlsxRows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open XLSX file.');
    }

    $sharedStrings = readSharedStrings($zip);
    $worksheets = worksheetPaths($zip);
    $sheetPath = $worksheets ? reset($worksheets) : 'xl/worksheets/sheet1.xml';
    $rows = readWorksheetRows($zip, $sheetPath, $sharedStrings);
    $zip->close();

    return $rows;
}

function readAllXlsxWorksheets(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open Excel workbook.');
    }

    $sharedStrings = readSharedStrings($zip);
    $worksheets = worksheetPaths($zip);
    $result = [];
    foreach ($worksheets as $name => $sheetPath) {
        $result[$name] = readWorksheetRows($zip, $sheetPath, $sharedStrings);
    }
    $zip->close();

    return $result;
}

function readWorksheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
{
    $sheetXml = $zip->getFromName($sheetPath);

    if ($sheetXml === false) {
        throw new RuntimeException('Could not read worksheet XML.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Worksheet XML is invalid.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowNode) {
        $row = [];
        foreach ($rowNode->c as $cell) {
            $reference = (string) $cell['r'];
            $columnIndex = columnIndexFromReference($reference);
            $row[$columnIndex] = cellValue($cell, $sharedStrings);
        }

        if ($row) {
            ksort($row);
            $rows[] = fillMissingCells($row);
        }
    }

    return $rows;
}

function readSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $shared = simplexml_load_string($xml);
    if (!$shared) {
        return [];
    }

    $strings = [];
    foreach ($shared->si as $item) {
        if (isset($item->t)) {
            $strings[] = (string) $item->t;
            continue;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }
        $strings[] = $text;
    }

    return $strings;
}

function firstWorksheetPath(ZipArchive $zip): string
{
    $worksheets = worksheetPaths($zip);
    return $worksheets ? (string) reset($worksheets) : 'xl/worksheets/sheet1.xml';
}

function worksheetPaths(ZipArchive $zip): array
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbookXml === false || $relsXml === false) {
        return ['Sheet1' => 'xl/worksheets/sheet1.xml'];
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);

    if (!$workbook || !$rels) {
        return ['Sheet1' => 'xl/worksheets/sheet1.xml'];
    }

    $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rels->registerXPathNamespace('pkg', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relationshipTargets = [];
    foreach ($rels->xpath('//pkg:Relationship') ?: [] as $relationship) {
        $relationshipTargets[(string) $relationship['Id']] = (string) $relationship['Target'];
    }

    $paths = [];
    foreach ($workbook->xpath('//main:sheets/main:sheet') ?: [] as $sheet) {
        $name = (string) $sheet['name'];
        $relationId = (string) $sheet->attributes($relationshipNamespace)['id'];
        $target = $relationshipTargets[$relationId] ?? '';
        if ($name === '' || $target === '') {
            continue;
        }

        $target = ltrim($target, '/');
        $paths[$name] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    return $paths ?: ['Sheet1' => 'xl/worksheets/sheet1.xml'];
}

function cellValue(SimpleXMLElement $cell, array $sharedStrings): ?string
{
    $type = (string) $cell['t'];

    if ($type === 'inlineStr') {
        return isset($cell->is->t) ? (string) $cell->is->t : null;
    }

    $value = isset($cell->v) ? (string) $cell->v : null;

    if ($value === null) {
        return null;
    }

    if ($type === 's') {
        return $sharedStrings[(int) $value] ?? '';
    }

    return $value;
}

function columnIndexFromReference(string $reference): int
{
    preg_match('/^[A-Z]+/', strtoupper($reference), $matches);
    $letters = $matches[0] ?? 'A';
    $index = 0;

    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function fillMissingCells(array $row): array
{
    $filled = [];
    $max = max(array_keys($row));

    for ($i = 0; $i <= $max; $i++) {
        $filled[] = $row[$i] ?? null;
    }

    return $filled;
}
