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

    public function __construct(string $templatePath, string $sheetPath = 'xl/worksheets/sheet1.xml')
    {
        $this->entries = SimpleXlsxWriter::readZipEntries($templatePath);
        $this->sheetPath = $sheetPath;
        $sheetXml = $this->entries[$sheetPath] ?? '';
        if ($sheetXml === '') {
            throw new RuntimeException('XLSX template is missing worksheet XML.');
        }

        $this->sheetDom = new DOMDocument('1.0', 'UTF-8');
        $this->sheetDom->preserveWhiteSpace = false;
        $this->sheetDom->formatOutput = false;
        if (!$this->sheetDom->loadXML($sheetXml)) {
            throw new RuntimeException('XLSX template worksheet XML is invalid.');
        }

        $this->xpath = new DOMXPath($this->sheetDom);
        $this->xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
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
