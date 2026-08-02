<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SimpleXlsxWriter
{
    public static function save(Spreadsheet $spreadsheet, string $filePath): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $title = self::escapeXml($sheet->getTitle() ?: 'Sheet1');
        $highestRow = max(1, (int)$sheet->getHighestDataRow());
        $highestColumnIndex = max(1, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        $sharedStrings = [];
        $sharedStringMap = [];
        $sheetXml = self::buildSheetXml($sheet, $highestRow, $highestColumnIndex, $sharedStrings, $sharedStringMap);

        self::writeZip($filePath, [
            '[Content_Types].xml' => self::contentTypesXml(),
            '_rels/.rels' => self::rootRelsXml(),
            'xl/workbook.xml' => self::workbookXml($title),
            'xl/_rels/workbook.xml.rels' => self::workbookRelsXml(),
            'xl/styles.xml' => self::stylesXml(),
            'xl/sharedStrings.xml' => self::sharedStringsXml($sharedStrings),
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ]);
    }

    public static function output(Spreadsheet $spreadsheet): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'sf1-xlsx-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary XLSX file.');
        }

        try {
            self::save($spreadsheet, $tempPath);
            readfile($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    private static function buildSheetXml($sheet, int $highestRow, int $highestColumnIndex, array &$sharedStrings, array &$sharedStringMap): string
    {
        $rows = [];
        for ($row = 1; $row <= $highestRow; $row++) {
            $cells = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $coordinate = Coordinate::stringFromColumnIndex($col) . $row;
                $value = $sheet->getCell($coordinate)->getValue();
                if ($value === null || $value === '') {
                    continue;
                }
                $cells[] = self::cellXml($coordinate, $value, $sharedStrings, $sharedStringMap);
            }
            if (!empty($cells)) {
                $rows[] = '<row r="' . $row . '">' . implode('', $cells) . '</row>';
            }
        }

        $dimension = 'A1:' . Coordinate::stringFromColumnIndex($highestColumnIndex) . $highestRow;
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . self::escapeXml($dimension) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '</worksheet>';
    }

    private static function cellXml(string $coordinate, $value, array &$sharedStrings, array &$sharedStringMap): string
    {
        if (is_int($value) || is_float($value)) {
            return '<c r="' . self::escapeXml($coordinate) . '"><v>' . self::escapeXml((string)$value) . '</v></c>';
        }

        $text = (string)$value;
        if (!array_key_exists($text, $sharedStringMap)) {
            $sharedStringMap[$text] = count($sharedStrings);
            $sharedStrings[] = $text;
        }

        return '<c r="' . self::escapeXml($coordinate) . '" t="s"><v>' . $sharedStringMap[$text] . '</v></c>';
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $title): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $title . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    private static function sharedStringsXml(array $sharedStrings): string
    {
        $items = [];
        foreach ($sharedStrings as $value) {
            $items[] = '<si><t>' . self::escapeXml((string)$value) . '</t></si>';
        }

        $count = count($sharedStrings);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">'
            . implode('', $items)
            . '</sst>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private static function writeZip(string $filePath, array $entries): void
    {
        $handle = fopen($filePath, 'wb');
        if (!$handle) {
            throw new RuntimeException('Unable to write XLSX file.');
        }

        $centralDirectory = '';
        $offset = 0;

        foreach ($entries as $name => $content) {
            $nameBytes = (string)$name;
            $content = (string)$content;
            $crcHex = hash('crc32b', $content);
            $size = strlen($content);

            $localHeader = "PK\x03\x04"
                . self::packUint16(20)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packHexUint32($crcHex)
                . self::packUint32($size)
                . self::packUint32($size)
                . self::packUint16(strlen($nameBytes))
                . self::packUint16(0)
                . $nameBytes;
            fwrite($handle, $localHeader . $content);

            $centralDirectory .= "PK\x01\x02"
                . self::packUint16(20)
                . self::packUint16(20)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packHexUint32($crcHex)
                . self::packUint32($size)
                . self::packUint32($size)
                . self::packUint16(strlen($nameBytes))
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint16(0)
                . self::packUint32(0)
                . self::packUint32($offset)
                . $nameBytes;
            $offset += strlen($localHeader) + $size;
        }

        fwrite($handle, $centralDirectory);
        fwrite($handle, "PK\x05\x06"
            . self::packUint16(0)
            . self::packUint16(0)
            . self::packUint16(count($entries))
            . self::packUint16(count($entries))
            . self::packUint32(strlen($centralDirectory))
            . self::packUint32($offset)
            . self::packUint16(0));
        fclose($handle);
    }

    private static function packUint16(int $value): string
    {
        return pack('v', $value);
    }

    private static function packUint32(int $value): string
    {
        $hex = str_pad(dechex($value), 8, '0', STR_PAD_LEFT);
        return self::packHexUint32($hex);
    }

    private static function packHexUint32(string $hex): string
    {
        $hex = str_pad(substr($hex, -8), 8, '0', STR_PAD_LEFT);
        return pack('H*', substr($hex, 6, 2) . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2));
    }

    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
