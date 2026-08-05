<?php

use PHPUnit\Framework\TestCase;

final class Sf1ParserTest extends TestCase
{
    public function testParsesExcelSerialBirthdate(): void
    {
        $this->assertSame('2008-01-15', Sf1Parser::parseBirthdate('39462'));
    }

    public function testParsesSlashBirthdate(): void
    {
        $this->assertSame('2008-01-15', Sf1Parser::parseBirthdate('01/15/2008'));
    }

    public function testParsesSampleWorkbookWithFallbackReader(): void
    {
        $samplePath = getenv('SF1_SAMPLE_PATH') ?: '';
        if ($samplePath === '' || !is_file($samplePath)) {
            $samplePath = __DIR__ . '/../deped/SF1_Senior_High_School.xlsx';
        }

        if (!is_file($samplePath)) {
            $this->markTestSkipped('SF1 sample workbook is not available.');
        }

        putenv('APP_FORCE_XLSX_FALLBACK=1');
        $parser = new Sf1Parser($samplePath);
        $parsed = $parser->parse();
        putenv('APP_FORCE_XLSX_FALLBACK');

        $this->assertIsArray($parsed['errors']);
        $this->assertIsArray($parsed['students']);
        $this->assertIsArray($parsed['header']);
    }

    public function testSimpleXlsxWriterCreatesParseableWorkbook(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SHSF-1');
        $sheet->setCellValue('A1', 'School Form 1');
        $sheet->setCellValueExplicit('B2', '123456789012', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('C2', 'Learner Name');

        $path = tempnam(sys_get_temp_dir(), 'simple-xlsx-test-');
        $this->assertIsString($path);

        try {
            SimpleXlsxWriter::save($spreadsheet, $path);
            putenv('APP_FORCE_XLSX_FALLBACK=1');
            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);
            putenv('APP_FORCE_XLSX_FALLBACK');

            $this->assertSame('School Form 1', $rows[1][0] ?? null);
            $this->assertSame('123456789012', $rows[2][1] ?? null);
            $this->assertSame('Learner Name', $rows[2][2] ?? null);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function testSf1TemplateEditorPreservesOfficialStructure(): void
    {
        $templatePath = __DIR__ . '/../deped/SF1_Senior_High_School.xlsx';
        $this->assertFileExists($templatePath);

        $path = tempnam(sys_get_temp_dir(), 'sf1-template-test-');
        $this->assertIsString($path);

        try {
            $editor = new SimpleXlsxTemplateEditor($templatePath);
            $editor->setCell('F3', 'Balingasag Senior High School');
            $editor->setCell('M3', '341227');
            $editor->setCell('F5', 'N/A (SSHS - Three-Term)');
            $editor->setCell('M5', '2026-2027');
            $editor->setCell('W5', 'Grade 11');
            $editor->setCell('AC5', 'Academic Track');
            $editor->setCell('F7', 'Amethyst');
            $editor->clearRange('A', 'AE', 12, 120);
            $editor->setCell('A12', '123456789012');
            $editor->setCell('C12', 'Dagohoy, Dave Santos');
            $editor->setCell('G12', 'M');
            $editor->setCell('H12', '05/08/2008');
            $editor->setCell('M12', '36');
            $editor->setCell('N12', 'Mambayaan');
            $editor->save($path);

            putenv('APP_FORCE_XLSX_FALLBACK=1');
            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);
            putenv('APP_FORCE_XLSX_FALLBACK');

            $this->assertSame(['SHSF-1'], $parser->getSheetNames());
            $this->assertSame('NAME' . "\n" . '(Last Name, First Name, Name Extension, Middle Name)', $rows[9][2] ?? null);
            $this->assertSame('House No./ Street/ Sitio/ Purok', $rows[10][12] ?? null);
            $this->assertSame('123456789012', $rows[12][0] ?? null);
            $this->assertSame('Dagohoy, Dave Santos', $rows[12][2] ?? null);
            $this->assertSame('Mambayaan', $rows[12][13] ?? null);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }
}
