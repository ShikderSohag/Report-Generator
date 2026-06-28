<?php
declare(strict_types=1);

function createDailyReportWorkbook(array $payload, string $targetPath): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for Excel export.');
    }

    $reportDate = trim((string) ($payload['report_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        throw new RuntimeException('A valid report date is required.');
    }

    $items = array_values(array_filter($payload['items'] ?? [], 'is_array'));
    $pidItems = array_values(array_filter($payload['pid_items'] ?? [], 'is_array'));
    $ancillaryItems = array_values(array_filter($payload['ancillary_items'] ?? [], 'is_array'));
    if (!$items && !$pidItems && !$ancillaryItems) {
        throw new RuntimeException('Add at least one report row before exporting.');
    }

    [$sheetXml, $lastRow] = buildDailyReportSheet($reportDate, $items, $pidItems, $ancillaryItems);
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');
    $sheetName = 'Daily Report';

    $zip = new ZipArchive();
    if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the Excel file.');
    }

    $zip->addFromString('[Content_Types].xml', excelContentTypes());
    $zip->addFromString('_rels/.rels', excelRootRelationships());
    $zip->addFromString('docProps/app.xml', excelAppProperties());
    $zip->addFromString('docProps/core.xml', excelCoreProperties($createdAt));
    $zip->addFromString('xl/workbook.xml', excelWorkbook($sheetName, $lastRow));
    $zip->addFromString('xl/_rels/workbook.xml.rels', excelWorkbookRelationships());
    $zip->addFromString('xl/styles.xml', excelStyles());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    if (!$zip->close()) {
        throw new RuntimeException('Could not finish the Excel file.');
    }
}

function buildDailyReportSheet(string $reportDate, array $items, array $pidItems, array $ancillaryItems): array
{
    $rows = [];
    $merges = [];
    $row = 1;

    $rows[] = excelRow($row, [excelTextCell('A' . $row, 'Duct & Fittings - Daily Delivery Report', 1)], 28);
    $merges[] = 'A1:P1';
    $row += 2;

    if ($items) {
        $headers = ['#', 'Customer', 'Project', 'Delivery Note#', 'DN #', 'Destination', 'Added to Delivery', 'WOs Qty', 'MNF', 'Fix Anc.', 'Total', 'MNF Qty', '%', 'Previously Delivered %', 'Total Delivered %', 'Remark'];
        $rows[] = excelSectionRow($row, 'Duct & Fittings Delivered WOs (Metal Ducts)', $reportDate, $merges);
        $row++;
        $rows[] = excelHeaderRow($row, $headers);
        $dataStart = ++$row;

        foreach ($items as $index => $item) {
            $woQty = excelPayloadNumber($item['wo_qty'] ?? 0);
            $mnf = excelPayloadNumber($item['mnf_weight'] ?? 0);
            $fix = excelPayloadNumber($item['fix_anc_weight'] ?? 0);
            $mnfQty = excelPayloadNumber($item['mnf_qty'] ?? 0);
            $previous = excelPayloadNumber($item['previous_delivered_percent'] ?? 0) / 100;
            $shipment = $woQty > 0 ? $mnf / $woQty : 0;

            $cells = [
                excelNumberCell('A' . $row, $index + 1, 14),
                excelTextCell('B' . $row, $item['customer_name'] ?? '', 5),
                excelTextCell('C' . $row, $item['project_name'] ?? '', 5),
                excelTextCell('D' . $row, $item['delivery_note'] ?? '', 12),
                excelTextCell('E' . $row, $item['dn_number'] ?? '', 12),
                excelTextCell('F' . $row, $item['destination'] ?? '', 12),
                excelTextCell('G' . $row, $item['added_to_delivery'] ?? '', 12),
                excelNumberCell('H' . $row, $woQty, 6),
                excelNumberCell('I' . $row, $mnf, 6),
                excelNumberCell('J' . $row, $fix, 6),
                excelFormulaCell('K' . $row, "I{$row}+J{$row}", $mnf + $fix, 7),
                excelNumberCell('L' . $row, $mnfQty, 14),
                excelFormulaCell('M' . $row, "IF(H{$row}>0,I{$row}/H{$row},0)", $shipment, 9),
                excelNumberCell('N' . $row, $previous, 8),
                excelFormulaCell('O' . $row, "N{$row}+M{$row}", $previous + $shipment, 9),
                excelTextCell('P' . $row, $item['remark'] ?? '', 5),
            ];
            $rows[] = excelRow($row, $cells, 28);
            $row++;
        }

        $dataEnd = $row - 1;
        $rows[] = excelTotalsRow($row, $dataStart, $dataEnd, [
            'H' => 11, 'I' => 11, 'J' => 11, 'K' => 11, 'L' => 13,
        ], 'F');
        $merges[] = 'F' . $row . ':G' . $row;
        $row += 2;
    }

    if ($pidItems) {
        $headers = ['#', 'Customer', 'Project', 'Delivery Note#', 'DN #', 'Added to Delivery', 'WOs Qty', 'MNF', 'Supp. Rod', 'Total', 'MNF Qty', '%', 'Previously Delivered %', 'Total Delivered %', 'Duct Type/Material', 'Remark'];
        $rows[] = excelSectionRow($row, 'Duct & Fittings Delivered WOs (PID)', $reportDate, $merges);
        $row++;
        $rows[] = excelHeaderRow($row, $headers);
        $dataStart = ++$row;

        foreach ($pidItems as $index => $item) {
            $woQty = excelPayloadNumber($item['wo_qty'] ?? 0);
            $mnf = excelPayloadNumber($item['mnf_area'] ?? 0);
            $suppRod = excelPayloadNumber($item['supp_rod'] ?? 0);
            $mnfQty = excelPayloadNumber($item['mnf_qty'] ?? 0);
            $previous = excelPayloadNumber($item['previous_delivered_percent'] ?? 0) / 100;
            $shipment = $woQty > 0 ? $mnf / $woQty : 0;

            $cells = [
                excelNumberCell('A' . $row, $index + 1, 14),
                excelTextCell('B' . $row, $item['customer_name'] ?? '', 5),
                excelTextCell('C' . $row, $item['project_name'] ?? '', 5),
                excelTextCell('D' . $row, $item['delivery_note'] ?? '', 12),
                excelTextCell('E' . $row, $item['dn_number'] ?? '', 12),
                excelTextCell('F' . $row, $item['added_to_delivery'] ?? '', 12),
                excelNumberCell('G' . $row, $woQty, 6),
                excelNumberCell('H' . $row, $mnf, 6),
                excelNumberCell('I' . $row, $suppRod, 6),
                excelFormulaCell('J' . $row, "H{$row}", $mnf, 7),
                excelNumberCell('K' . $row, $mnfQty, 14),
                excelFormulaCell('L' . $row, "IF(G{$row}>0,H{$row}/G{$row},0)", $shipment, 9),
                excelNumberCell('M' . $row, $previous, 8),
                excelFormulaCell('N' . $row, "M{$row}+L{$row}", $previous + $shipment, 9),
                excelTextCell('O' . $row, $item['material'] ?? '', 5),
                excelTextCell('P' . $row, $item['remark'] ?? '', 5),
            ];
            $rows[] = excelRow($row, $cells, 28);
            $row++;
        }

        $dataEnd = $row - 1;
        $rows[] = excelTotalsRow($row, $dataStart, $dataEnd, [
            'G' => 11, 'H' => 11, 'I' => 11, 'J' => 11, 'K' => 13,
        ], 'E');
        $merges[] = 'E' . $row . ':F' . $row;
        $row += 2;
    }

    if ($ancillaryItems) {
        $headers = ['#', 'Customer', 'Project', 'Delivery Note#', 'DN #', 'Item No', 'Item Name', 'Qty', 'Previously Delivered %', 'Total Delivered %', 'Remark'];
        $rows[] = excelRow($row, [excelTextCell('A' . $row, 'Ancillaries', 2), excelTextCell('J' . $row, 'Report Date: ' . $reportDate, 3)], 20);
        $merges[] = 'A' . $row . ':I' . $row;
        $merges[] = 'J' . $row . ':K' . $row;
        $row++;
        $rows[] = excelHeaderRow($row, $headers);
        $dataStart = ++$row;

        foreach ($ancillaryItems as $index => $item) {
            $previous = excelPayloadNumber($item['previous_delivered_percent'] ?? 0) / 100;
            $total = excelPayloadNumber($item['total_delivered_percent'] ?? 0) / 100;
            $cells = [
                excelNumberCell('A' . $row, $index + 1, 14),
                excelTextCell('B' . $row, $item['customer_name'] ?? '', 5),
                excelTextCell('C' . $row, $item['project_name'] ?? '', 5),
                excelTextCell('D' . $row, $item['delivery_note'] ?? '', 12),
                excelTextCell('E' . $row, $item['dn_number'] ?? '', 12),
                excelTextCell('F' . $row, $item['item_no'] ?? '', 12),
                excelTextCell('G' . $row, $item['item_name'] ?? '', 5),
                excelNumberCell('H' . $row, excelPayloadNumber($item['qty'] ?? 0), 6),
                excelNumberCell('I' . $row, $previous, 8),
                excelNumberCell('J' . $row, $total, 8),
                excelTextCell('K' . $row, $item['remark'] ?? '', 5),
            ];
            $rows[] = excelRow($row, $cells, 28);
            $row++;
        }

        $dataEnd = $row - 1;
        $cells = [excelTextCell('F' . $row, 'Grand Totals:', 10)];
        $cells[] = excelFormulaCell('H' . $row, "SUM(H{$dataStart}:H{$dataEnd})", 0, 11);
        $rows[] = excelRow($row, $cells, 20);
        $merges[] = 'F' . $row . ':G' . $row;
        $row++;
    }

    $lastRow = max(1, $row - 1);
    $mergeXml = $merges
        ? '<mergeCells count="' . count($merges) . '">' . implode('', array_map(static fn (string $range): string => '<mergeCell ref="' . $range . '"/>', $merges)) . '</mergeCells>'
        : '';
    $columns = [4, 25, 23, 16, 10, 15, 18, 12, 12, 12, 12, 11, 12, 15, 18, 24];
    $columnXml = '<cols>';
    foreach ($columns as $index => $width) {
        $column = $index + 1;
        $columnXml .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }
    $columnXml .= '</cols>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:P' . $lastRow . '"/>'
        . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $columnXml
        . '<sheetData>' . implode('', $rows) . '</sheetData>'
        . $mergeXml
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    return [$sheetXml, $lastRow];
}

function excelSectionRow(int $row, string $title, string $reportDate, array &$merges): string
{
    $merges[] = 'A' . $row . ':N' . $row;
    $merges[] = 'O' . $row . ':P' . $row;
    return excelRow($row, [
        excelTextCell('A' . $row, $title, 2),
        excelTextCell('O' . $row, 'Report Date: ' . $reportDate, 3),
    ], 20);
}

function excelHeaderRow(int $row, array $headers): string
{
    $cells = [];
    foreach ($headers as $index => $header) {
        $cells[] = excelTextCell(excelColumnName($index + 1) . $row, $header, 4);
    }
    return excelRow($row, $cells, 34);
}

function excelTotalsRow(int $row, int $start, int $end, array $sumColumns, string $labelStart): string
{
    $cells = [excelTextCell($labelStart . $row, 'Grand Totals:', 10)];
    foreach ($sumColumns as $column => $style) {
        $cells[] = excelFormulaCell($column . $row, "SUM({$column}{$start}:{$column}{$end})", 0, $style);
    }
    return excelRow($row, $cells, 20);
}

function excelRow(int $row, array $cells, int $height = 18): string
{
    return '<row r="' . $row . '" ht="' . $height . '" customHeight="1">' . implode('', $cells) . '</row>';
}

function excelTextCell(string $reference, mixed $value, int $style): string
{
    $text = excelXml((string) $value);
    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
}

function excelNumberCell(string $reference, float|int $value, int $style): string
{
    return '<c r="' . $reference . '" s="' . $style . '"><v>' . excelNumber($value) . '</v></c>';
}

function excelFormulaCell(string $reference, string $formula, float|int $cachedValue, int $style): string
{
    return '<c r="' . $reference . '" s="' . $style . '"><f>' . excelXml($formula) . '</f><v>' . excelNumber($cachedValue) . '</v></c>';
}

function excelColumnName(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function excelPayloadNumber(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace([',', '%'], '', trim($value));
    }
    return is_numeric($value) ? (float) $value : 0.0;
}

function excelNumber(float|int $value): string
{
    $number = is_finite((float) $value) ? (float) $value : 0.0;
    return rtrim(rtrim(sprintf('%.10F', $number), '0'), '.') ?: '0';
}

function excelXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function excelContentTypes(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';
}

function excelRootRelationships(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function excelWorkbookRelationships(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

function excelWorkbook(string $sheetName, int $lastRow): string
{
    $printArea = '&apos;' . excelXml($sheetName) . '&apos;!$A$1:$P$' . $lastRow;
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr date1904="0"/>'
        . '<sheets><sheet name="' . excelXml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">' . $printArea . '</definedName></definedNames>'
        . '<calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/>'
        . '</workbook>';
}

function excelCoreProperties(string $createdAt): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Daily Delivery Report</dc:title><dc:creator>Report Generator</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function excelAppProperties(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Daily Report Generator</Application><AppVersion>1.0</AppVersion>'
        . '</Properties>';
}

function excelStyles(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="0%"/><numFmt numFmtId="166" formatCode="#,##0"/></numFmts>
  <fonts count="4">
    <font><sz val="9"/><name val="Arial"/></font>
    <font><b/><color rgb="FFFFFFFF"/><sz val="9"/><name val="Arial"/></font>
    <font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Arial"/></font>
    <font><b/><color rgb="FFFBBF24"/><sz val="9"/><name val="Arial"/></font>
  </fonts>
  <fills count="6">
    <fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF14213D"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2F61AD"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE2F0D9"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2"><border/><border><left style="thin"><color rgb="FFB7C3D0"/></left><right style="thin"><color rgb="FFB7C3D0"/></right><top style="thin"><color rgb="FFB7C3D0"/></top><bottom style="thin"><color rgb="FFB7C3D0"/></bottom><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="15">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="165" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="165" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="164" fontId="3" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="166" fontId="3" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
}
