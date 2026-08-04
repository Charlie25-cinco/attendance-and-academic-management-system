<?php

namespace BshsAms\Xlsx;

use Exception;
use ZipArchive;

class SimpleXlsxParser {
    private $zip;
    private $sheets = [];
    private $sharedStrings = [];
    private $filePath;
    private $useZipArchive = false;
    private $zipResource = null;
    private $zipEntries = null;

    public function __construct(string $filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        $this->filePath = $filePath;
        $this->sharedStrings = [];

        if (class_exists('ZipArchive') && getenv('APP_FORCE_XLSX_FALLBACK') !== '1') {
            $this->zip = new ZipArchive();
            if ($this->zip->open($filePath) === true) {
                $this->useZipArchive = true;
                $this->loadSharedStrings();
                return;
            }
            @$this->zip->close();
            $this->zip = null;
        }

        $this->useZipArchive = false;
        if (getenv('APP_FORCE_XLSX_FALLBACK') !== '1') {
            $this->tryProceduralZip();
        }
        if (!is_resource($this->zipResource)) {
            $this->zipEntries = $this->readZipEntries($this->filePath);
            $this->parseSharedStrings($this->zipEntries['xl/sharedStrings.xml'] ?? '');
        }
    }

    private function tryProceduralZip(): void {
        if (!function_exists('zip_open')) { return; }
        $this->zipResource = zip_open($this->filePath);
        if (!is_resource($this->zipResource)) { $this->zipResource = null; return; }
        while ($entry = zip_read($this->zipResource)) {
            $name = zip_entry_name($entry);
            if ($name === 'xl/sharedStrings.xml' || $name === 'xl\\sharedStrings.xml') {
                if (zip_entry_open($this->zipResource, $entry, 'r')) {
                    $content = zip_entry_read($entry, zip_entry_filesize($entry));
                    zip_entry_close($entry);
                    $this->parseSharedStrings($content);
                    break;
                }
            }
        }
    }

    private function parseSharedStrings(string $content): void {
        if ($content === '') return;
        $xml = @simplexml_load_string($content);
        if ($xml === false) return;
        foreach ($xml->si as $item) {
            $text = '';
            if (isset($item->t)) { $text = (string)$item->t; }
            else {
                foreach ($item->r as $r) {
                    if (isset($r->t)) { $text .= (string)$r->t; }
                }
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function loadSharedStrings(): void {
        if ($this->useZipArchive) {
            $content = $this->zip->getFromName('xl/sharedStrings.xml');
            if ($content !== false) { $this->parseSharedStrings($content); }
        }
    }

    public function getSheet(int $index = 0): array {
        $workbookXml = $this->getFileContent('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '') {
            throw new Exception("Invalid xlsx: missing workbook.xml");
        }
        $workbook = @simplexml_load_string($workbookXml);
        if ($workbook === false) { throw new Exception("Invalid xlsx: cannot parse workbook.xml"); }
        $sheets = $workbook->sheets->sheet;
        if (!isset($sheets[$index])) { throw new Exception("Sheet index $index not found"); }
        $sheetFile = 'xl/worksheets/sheet' . ($index + 1) . '.xml';
        return $this->parseSheet($sheetFile);
    }

    public function getSheetNames(): array {
        $workbookXml = $this->getFileContent('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '') { return []; }
        $workbook = @simplexml_load_string($workbookXml);
        if ($workbook === false) { return []; }
        $names = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $names[] = (string)$sheet['name'];
        }
        return $names;
    }

    private function getFileContent(string $path): string {
        if ($this->useZipArchive) {
            $content = $this->zip->getFromName($path);
            if ($content === false) { $content = $this->zip->getFromName(str_replace('/', '\\', $path)); }
            return $content === false ? '' : $content;
        }
        if (is_array($this->zipEntries)) {
            $normalizedPath = str_replace('\\', '/', $path);
            return $this->zipEntries[$normalizedPath] ?? '';
        }
        if (is_resource($this->zipResource)) {
            zip_close($this->zipResource);
            $this->zipResource = zip_open($this->filePath);
        }
        if (!is_resource($this->zipResource)) { return ''; }
        $normalizedPath = str_replace('\\', '/', $path);
        while (($entry = zip_read($this->zipResource)) !== false) {
            $name = str_replace('\\', '/', zip_entry_name($entry));
            if ($name === $normalizedPath) {
                if (zip_entry_open($this->zipResource, $entry, 'r')) {
                    $content = zip_entry_read($entry, zip_entry_filesize($entry));
                    zip_entry_close($entry);
                    return $content;
                }
            }
        }
        return '';
    }

    private function readZipEntries(string $filePath): array {
        $bytes = @file_get_contents($filePath);
        if ($bytes === false || strlen($bytes) < 22) {
            return [];
        }

        $entries = $this->readZipEntriesFromCentralDirectory($bytes);
        if (!empty($entries)) {
            return $entries;
        }

        $entries = [];
        $offset = 0;
        $length = strlen($bytes);
        while ($offset + 30 <= $length) {
            $signature = substr($bytes, $offset, 4);
            if ($signature !== "PK\x03\x04") {
                $offset++;
                continue;
            }

            $flags = $this->littleEndian(substr($bytes, $offset + 6, 2));
            $method = $this->littleEndian(substr($bytes, $offset + 8, 2));
            $compressedSize = $this->littleEndian(substr($bytes, $offset + 18, 4));
            $fileNameLength = $this->littleEndian(substr($bytes, $offset + 26, 2));
            $extraLength = $this->littleEndian(substr($bytes, $offset + 28, 2));
            $nameStart = $offset + 30;
            $dataStart = $nameStart + $fileNameLength + $extraLength;
            if ($nameStart + $fileNameLength > $length || $dataStart > $length) {
                break;
            }

            $name = str_replace('\\', '/', substr($bytes, $nameStart, $fileNameLength));
            if (($flags & 0x08) !== 0 || $compressedSize < 1 || $dataStart + $compressedSize > $length) {
                $nextOffset = strpos($bytes, "PK\x03\x04", $dataStart);
                if ($nextOffset === false) {
                    break;
                }
                $offset = $nextOffset;
                continue;
            }

            $compressed = substr($bytes, $dataStart, $compressedSize);
            $content = '';
            if ($method === 0) {
                $content = $compressed;
            } elseif ($method === 8 && function_exists('gzinflate')) {
                $inflated = @gzinflate($compressed);
                if ($inflated !== false) {
                    $content = $inflated;
                }
            }

            if ($content !== '') {
                $entries[$name] = $content;
            }

            $offset = $dataStart + $compressedSize;
        }

        return $entries;
    }

    private function readZipEntriesFromCentralDirectory(string $bytes): array {
        $length = strlen($bytes);
        $eocdOffset = strrpos($bytes, "PK\x05\x06");
        if ($eocdOffset === false || $eocdOffset + 22 > $length) {
            return [];
        }

        $entryCount = $this->littleEndian(substr($bytes, $eocdOffset + 10, 2));
        $centralOffset = $this->littleEndian(substr($bytes, $eocdOffset + 16, 4));
        if ($entryCount < 1 || $centralOffset < 0 || $centralOffset >= $length) {
            return [];
        }

        $entries = [];
        $offset = $centralOffset;
        for ($i = 0; $i < $entryCount && $offset + 46 <= $length; $i++) {
            if (substr($bytes, $offset, 4) !== "PK\x01\x02") {
                break;
            }

            $method = $this->littleEndian(substr($bytes, $offset + 10, 2));
            $compressedSize = $this->littleEndian(substr($bytes, $offset + 20, 4));
            $fileNameLength = $this->littleEndian(substr($bytes, $offset + 28, 2));
            $extraLength = $this->littleEndian(substr($bytes, $offset + 30, 2));
            $commentLength = $this->littleEndian(substr($bytes, $offset + 32, 2));
            $localOffset = $this->littleEndian(substr($bytes, $offset + 42, 4));
            $nameStart = $offset + 46;
            $name = str_replace('\\', '/', substr($bytes, $nameStart, $fileNameLength));

            if ($localOffset + 30 <= $length && substr($bytes, $localOffset, 4) === "PK\x03\x04") {
                $localNameLength = $this->littleEndian(substr($bytes, $localOffset + 26, 2));
                $localExtraLength = $this->littleEndian(substr($bytes, $localOffset + 28, 2));
                $dataStart = $localOffset + 30 + $localNameLength + $localExtraLength;
                if ($compressedSize > 0 && $dataStart + $compressedSize <= $length) {
                    $compressed = substr($bytes, $dataStart, $compressedSize);
                    $content = '';
                    if ($method === 0) {
                        $content = $compressed;
                    } elseif ($method === 8 && function_exists('gzinflate')) {
                        $inflated = @gzinflate($compressed);
                        if ($inflated !== false) {
                            $content = $inflated;
                        }
                    }
                    if ($content !== '') {
                        $entries[$name] = $content;
                    }
                }
            }

            $offset += 46 + $fileNameLength + $extraLength + $commentLength;
        }

        return $entries;
    }

    private function littleEndian(string $bytes): int {
        $value = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $value |= ord($bytes[$i]) << ($i * 8);
        }
        return $value;
    }

    private function parseSheet(string $sheetFile): array {
        $content = $this->getFileContent($sheetFile);
        if ($content === '') {
            $altFile = str_replace('/', '\\', $sheetFile);
            $content = $this->getFileContent($altFile);
            if ($content === '') { throw new Exception("Sheet not found: $sheetFile"); }
        }
        $xml = @simplexml_load_string($content);
        if ($xml === false || $xml->sheetData === null) { throw new Exception("Invalid sheet XML structure"); }
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int)$row['r'];
            if ($rowIndex < 1) continue;
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                $colLetter = preg_replace('/[0-9]/', '', $ref);
                $colIndex = $this->columnLetterToIndex($colLetter);
                $type = (string)$cell['t'];
                $value = '';
                if (isset($cell->v)) { $value = (string)$cell->v; }
                if ($type === 's' && is_numeric($value)) {
                    $value = $this->sharedStrings[(int)$value] ?? '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $value = (string)$cell->is->t;
                }
                $cells[$colIndex] = $value;
            }
            if (!empty($cells)) {
                ksort($cells);
                $rows[$rowIndex] = $cells;
            }
        }
        ksort($rows);
        return $rows;
    }

    private function columnLetterToIndex(string $letter): int {
        $index = 0;
        $length = strlen($letter);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    public function __destruct() {
        if ($this->useZipArchive && $this->zip) { @$this->zip->close(); }
        if (is_resource($this->zipResource)) { @zip_close($this->zipResource); }
    }
}
