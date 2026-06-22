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
    $sheetPath = firstWorksheetPath($zip);
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

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
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbookXml === false || $relsXml === false) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);

    if (!$workbook || !$rels) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbook->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheets = $workbook->xpath('//rel:sheet');

    if (!$sheets || !isset($sheets[0])) {
        return 'xl/worksheets/sheet1.xml';
    }

    $relationId = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

    foreach ($rels->Relationship as $relationship) {
        if ((string) $relationship['Id'] === $relationId) {
            $target = (string) $relationship['Target'];
            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
    }

    return 'xl/worksheets/sheet1.xml';
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
