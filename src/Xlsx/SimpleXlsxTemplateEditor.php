<?php

namespace BshsAms\Xlsx;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

class SimpleXlsxTemplateEditor
{
    private array $entries;
    private DOMDocument $sheetDom;
    private DOMXPath $xpath;
    private string $sheetPath;
    private string $templateSheetXml;
    private string $templateSheetPath;

    public function __construct(string $templatePath, string $sheetPath = 'xl/worksheets/sheet1.xml')
    {
        $this->entries = SimpleXlsxWriter::readZipEntries($templatePath);
        $this->sheetPath = $sheetPath;
        $this->templateSheetPath = $sheetPath;
        $sheetXml = $this->entries[$sheetPath] ?? '';
        if ($sheetXml === '') {
            throw new RuntimeException('XLSX template is missing worksheet XML.');
        }

        $this->templateSheetXml = $sheetXml;
        $this->loadSheetXml($sheetXml);
    }

    public function duplicateTemplateSheet(string $title): void
    {
        $this->entries[$this->sheetPath] = $this->sheetDom->saveXML();

        $sheetNumber = 1;
        do {
            $sheetNumber++;
            $sheetPath = 'xl/worksheets/sheet' . $sheetNumber . '.xml';
        } while (isset($this->entries[$sheetPath]));

        $relationshipId = $this->addWorkbookRelationship($sheetPath);
        $this->addWorkbookSheet($title, $relationshipId);
        $this->addContentTypeOverride('/' . $sheetPath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');

        $templateRelsPath = preg_replace('#/([^/]+)$#', '/_rels/$1.rels', $this->templateSheetPath);
        $newRelsPath = preg_replace('#/([^/]+)$#', '/_rels/$1.rels', $sheetPath);
        if (is_string($templateRelsPath) && is_string($newRelsPath) && isset($this->entries[$templateRelsPath])) {
            $this->entries[$newRelsPath] = $this->entries[$templateRelsPath];
            $this->addContentTypeOverride('/' . $newRelsPath, 'application/vnd.openxmlformats-package.relationships+xml');
        }

        $this->sheetPath = $sheetPath;
        $this->loadSheetXml($this->templateSheetXml);
    }

    private function loadSheetXml(string $sheetXml): void
    {
        $this->sheetDom = new DOMDocument('1.0', 'UTF-8');
        $this->sheetDom->preserveWhiteSpace = false;
        $this->sheetDom->formatOutput = false;
        if (!$this->sheetDom->loadXML($sheetXml)) {
            throw new RuntimeException('XLSX template worksheet XML is invalid.');
        }

        $this->xpath = new DOMXPath($this->sheetDom);
        $this->xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    }

    private function addWorkbookRelationship(string $sheetPath): string
    {
        $path = 'xl/_rels/workbook.xml.rels';
        $dom = $this->loadEntryDom($path);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $usedIds = [];
        foreach ($xpath->query('//r:Relationship') as $relationship) {
            if ($relationship instanceof DOMElement) {
                $usedIds[$relationship->getAttribute('Id')] = true;
            }
        }
        $index = 1;
        do { $relationshipId = 'rId' . $index++; } while (isset($usedIds[$relationshipId]));

        $relationship = $dom->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship');
        $relationship->setAttribute('Id', $relationshipId);
        $relationship->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet');
        $relationship->setAttribute('Target', preg_replace('#^xl/#', '', $sheetPath));
        $dom->documentElement->appendChild($relationship);
        $this->entries[$path] = $dom->saveXML();
        return $relationshipId;
    }

    private function addWorkbookSheet(string $title, string $relationshipId): void
    {
        $path = 'xl/workbook.xml';
        $dom = $this->loadEntryDom($path);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $xpath->query('//x:sheets')->item(0);
        if (!$sheets instanceof DOMElement) {
            throw new RuntimeException('XLSX workbook is missing sheets metadata.');
        }

        $maxSheetId = 0;
        foreach ($xpath->query('x:sheet', $sheets) as $sheet) {
            if ($sheet instanceof DOMElement) {
                $maxSheetId = max($maxSheetId, (int)$sheet->getAttribute('sheetId'));
            }
        }
        $safeTitle = substr(preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', trim($title)) ?: 'Sheet ' . ($maxSheetId + 1), 0, 31);
        $sheet = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'sheet');
        $sheet->setAttribute('name', $safeTitle);
        $sheet->setAttribute('sheetId', (string)($maxSheetId + 1));
        $sheet->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $relationshipId);
        $sheets->appendChild($sheet);

        $definedNames = $xpath->query('//x:definedNames')->item(0);
        if ($definedNames instanceof DOMElement) {
            foreach ([['_xlnm.Print_Area', '$A$1:$AY$89'], ['_xlnm.Print_Titles', '$9:$11']] as [$name, $range]) {
                $definedName = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'definedName');
                $definedName->setAttribute('name', $name);
                $definedName->setAttribute('localSheetId', (string)$maxSheetId);
                $definedName->appendChild($dom->createTextNode("'" . str_replace("'", "''", $safeTitle) . "'!" . $range));
                $definedNames->appendChild($definedName);
            }
        }
        $this->entries[$path] = $dom->saveXML();
    }

    private function addContentTypeOverride(string $partName, string $contentType): void
    {
        $path = '[Content_Types].xml';
        $dom = $this->loadEntryDom($path);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', 'http://schemas.openxmlformats.org/package/2006/content-types');
        foreach ($xpath->query('//c:Override') as $override) {
            if ($override instanceof DOMElement && $override->getAttribute('PartName') === $partName) {
                return;
            }
        }
        $override = $dom->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Override');
        $override->setAttribute('PartName', $partName);
        $override->setAttribute('ContentType', $contentType);
        $dom->documentElement->appendChild($override);
        $this->entries[$path] = $dom->saveXML();
    }

    private function loadEntryDom(string $path): DOMDocument
    {
        $xml = $this->entries[$path] ?? '';
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if ($xml === '' || !$dom->loadXML($xml)) {
            throw new RuntimeException('XLSX package metadata is missing or invalid: ' . $path);
        }
        return $dom;
    }

    public function setCell(string $coordinate, $value): void
    {
        $cell = $this->ensureCell($coordinate);
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }

        if ($value === null || $value === '') {
            $cell->removeAttribute('t');
            return;
        }

        if (is_int($value) || is_float($value)) {
            $cell->removeAttribute('t');
            $v = $this->sheetDom->createElement('v');
            $v->appendChild($this->sheetDom->createTextNode((string)$value));
            $cell->appendChild($v);
            return;
        }

        $cell->setAttribute('t', 'inlineStr');
        $inline = $this->sheetDom->createElement('is');
        $text = $this->sheetDom->createElement('t');
        $stringValue = (string)$value;
        if (trim($stringValue) !== $stringValue) {
            $text->setAttribute('xml:space', 'preserve');
        }
        $text->appendChild($this->sheetDom->createTextNode($stringValue));
        $inline->appendChild($text);
        $cell->appendChild($inline);
    }

    public function clearRange(string $startColumn, string $endColumn, int $startRow, int $endRow): void
    {
        $start = Coordinate::columnIndexFromString($startColumn);
        $end = Coordinate::columnIndexFromString($endColumn);
        for ($row = $startRow; $row <= $endRow; $row++) {
            for ($col = $start; $col <= $end; $col++) {
                $this->setCell(Coordinate::stringFromColumnIndex($col) . $row, '');
            }
        }
    }

    public function save(string $filePath): void
    {
        $this->entries[$this->sheetPath] = $this->sheetDom->saveXML();
        SimpleXlsxWriter::writeZip($filePath, $this->entries);
    }

    private function ensureCell(string $coordinate): DOMElement
    {
        $rowNumber = (int)preg_replace('/[^0-9]/', '', $coordinate);
        if ($rowNumber < 1) {
            throw new InvalidArgumentException('Invalid XLSX cell coordinate.');
        }

        $row = $this->ensureRow($rowNumber);
        $cell = $this->xpath->query('x:c[@r="' . $coordinate . '"]', $row)->item(0);
        if ($cell instanceof DOMElement) {
            return $cell;
        }

        $cell = $this->sheetDom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
        $cell->setAttribute('r', $coordinate);
        $row->appendChild($cell);
        return $cell;
    }

    private function ensureRow(int $rowNumber): DOMElement
    {
        $row = $this->xpath->query('//x:sheetData/x:row[@r="' . $rowNumber . '"]')->item(0);
        if ($row instanceof DOMElement) {
            return $row;
        }

        $sheetData = $this->xpath->query('//x:sheetData')->item(0);
        if (!$sheetData instanceof DOMElement) {
            throw new RuntimeException('XLSX template worksheet is missing sheetData.');
        }

        $row = $this->sheetDom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'row');
        $row->setAttribute('r', (string)$rowNumber);
        $sheetData->appendChild($row);
        return $row;
    }
}
