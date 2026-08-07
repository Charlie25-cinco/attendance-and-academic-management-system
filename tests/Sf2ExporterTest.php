<?php

use PHPUnit\Framework\TestCase;

final class Sf2ExporterTest extends TestCase
{
    public function testSf2TemplateFileExists(): void
    {
        $exporter = new Sf2Exporter();
        $templatePath = $exporter->getTemplatePath();
        $this->assertNotEmpty($templatePath, 'SF2 template path should not be empty.');
        $this->assertFileExists($templatePath, 'SF2 template file should exist in deped/ directory.');
    }

    public function testSf2TemplateStructureWithFallbackReader(): void
    {
        $exporter = new Sf2Exporter();
        $templatePath = $exporter->getTemplatePath();

        putenv('APP_FORCE_XLSX_FALLBACK=1');
        try {
            $parser = new SimpleXlsxParser($templatePath);
            $sheets = $parser->getSheetNames();
            $this->assertNotEmpty($sheets, 'SF2 template should contain workbook sheets.');
            $this->assertContains('SHSF-2', $sheets, 'SF2 template should contain SHSF-2 sheet.');
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
        }
    }

    public function testSf2XlsxExportWorksWithFallbackWriter(): void
    {
        $exporter = new Sf2Exporter();
        $exporter->setClass([
            'class_name' => 'Filipino 1',
            'grade_level' => 11,
            'section' => 'Amethyst',
            'school_id' => '341227',
        ]);
        $exporter->setStudents([
            [
                'id' => 1,
                'first_name' => 'Dave',
                'middle_name' => 'Santos',
                'last_name' => 'Dagohoy',
                'lrn' => '123456789012',
                'sex' => 'male',
            ],
            [
                'id' => 2,
                'first_name' => 'Ana',
                'middle_name' => 'Reyes',
                'last_name' => 'Cruz',
                'lrn' => '123456789013',
                'sex' => 'female',
            ],
        ]);
        $exporter->setAttendance([
            1 => ['2026-08-03' => 'present', '2026-08-04' => 'absent'],
            2 => ['2026-08-03' => 'late', '2026-08-04' => 'present'],
        ]);
        $exporter->setMonth(8);
        $exporter->setYear(2026);
        $exporter->setTeacherName('Test Teacher');

        $path = tempnam(sys_get_temp_dir(), 'sf2_export_') . '.xlsx';
        putenv('APP_FORCE_XLSX_FALLBACK=1');
        try {
            $this->assertTrue($exporter->export($path));
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));

            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);
            $this->assertSame('341227', $rows[6][2] ?? null);
            $this->assertSame('2026-2027', $rows[6][10] ?? null);
            $this->assertSame('August 2026', $rows[6][23] ?? null);
            $this->assertSame('Balingasag Senior High School', $rows[8][2] ?? null);
            $this->assertSame('Dagohoy, Dave S.', $rows[12][2] ?? null);
            $this->assertSame('/', $rows[12][5] ?? null);
            $this->assertSame('A', $rows[12][6] ?? null);
            $this->assertSame('Cruz, Ana R.', $rows[30][2] ?? null);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }
}
