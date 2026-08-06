<?php

use PHPUnit\Framework\TestCase;

final class EcrParserTest extends TestCase
{
    public function testEcrTemplateFileExists(): void
    {
        $exporter = new EcrExporter();
        $templateInfo = $exporter->getTemplateInfo();
        $this->assertTrue($templateInfo['exists'], 'ECR template file should exist in deped/ directory.');
        $this->assertFileExists($templateInfo['path']);
    }

    public function testConvertedEcrXlsxTemplateIsPreferred(): void
    {
        $exporter = new EcrExporter();
        $templateInfo = $exporter->getTemplateInfo();

        $this->assertSame('ecr_template.xlsx', basename((string)$templateInfo['path']));
        $this->assertTrue($templateInfo['compatible']);
        $this->assertContains('INPUT DATA', $templateInfo['sheets']);
        $this->assertContains('TERM 1', $templateInfo['sheets']);
        $this->assertContains('TERM 2', $templateInfo['sheets']);
        $this->assertContains('TERM 3', $templateInfo['sheets']);
    }

    public function testEcrParserHandlesNonexistentFile(): void
    {
        $parser = new EcrParser();
        $result = $parser->parse(__DIR__ . '/../deped/nonexistent_file.xlsx');
        $this->assertFalse($result);
        $this->assertNotEmpty($parser->getErrors());
    }

    public function testNormalizesTermStrings(): void
    {
        $this->assertSame('Term1', SshsGradeCalculator::normalizeTerm('term1'));
        $this->assertSame('Term2', SshsGradeCalculator::normalizeTerm('  TERM2  '));
        $this->assertSame('Q1', SshsGradeCalculator::normalizeTerm('q1'));
        $this->assertSame('Term1', SshsGradeCalculator::normalizeTerm('invalid_term'));
    }

    public function testEcrTemplateStructureWithFallbackReader(): void
    {
        $exporter = new EcrExporter();
        $templateInfo = $exporter->getTemplateInfo();
        if (!$templateInfo['exists']) {
            $this->markTestSkipped('ECR template file does not exist.');
        }

        putenv('APP_FORCE_XLSX_FALLBACK=1');
        try {
            $parser = new SimpleXlsxParser($templateInfo['path']);
            $sheets = $parser->getSheetNames();
            $this->assertNotEmpty($sheets);
            $this->assertTrue($templateInfo['zip_available']);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
        }
    }

    public function testGeneratedEcrXlsxFallbackCreatesParseableWorkbook(): void
    {
        $exporter = new EcrExporter();
        $exporter->setHeader([
            'school' => 'Balingasag Senior High School',
            'school_id' => '341227',
            'division' => 'Misamis Oriental',
            'region' => 'Region X',
            'grade_level' => 11,
            'section' => 'Amethyst',
            'teacher' => 'Test Teacher',
            'subject' => 'Filipino 1',
            'subject_type' => 'Core Subject',
        ]);
        $exporter->setStudents([
            [
                'id' => 1,
                'lrn' => '123456789012',
                'export_key' => 'student:1',
                'name' => 'Dagohoy, Dave Santos',
                'sex' => 'male',
            ],
        ]);
        $exporter->setGradeItems([
            [
                'export_key' => 'student:1',
                'item_id' => 10,
                'component' => 'ww',
                'total_score' => 20,
                'scores' => [18],
            ],
        ]);
        $exporter->setAcademicYear('2026-2027');
        $exporter->setTerm('Term1');

        $path = tempnam(sys_get_temp_dir(), 'ecr-generated-test-');
        $this->assertIsString($path);

        try {
            $method = (new ReflectionClass(EcrExporter::class))->getMethod('exportToGeneratedXlsx');
            $method->setAccessible(true);
            $this->assertTrue($method->invoke($exporter, $path));

            putenv('APP_FORCE_XLSX_FALLBACK=1');
            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);
            putenv('APP_FORCE_XLSX_FALLBACK');

            $this->assertSame('ECR Export', $parser->getSheetNames()[0] ?? null);
            $this->assertSame('Senior High School E-Class Record', $rows[1][0] ?? null);
            $this->assertSame('123456789012', $rows[11][1] ?? null);
            $this->assertSame('Dagohoy, Dave Santos', $rows[11][2] ?? null);
            $this->assertSame('18', $rows[11][5] ?? null);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }

    public function testEcrXlsxExportPreservesOfficialTemplateSheets(): void
    {
        $exporter = new EcrExporter();
        $exporter->setHeader([
            'school' => 'Balingasag Senior High School',
            'school_id' => '341227',
            'division' => 'Misamis Oriental',
            'region' => 'Region X',
            'grade_level' => 11,
            'section' => 'Amethyst',
            'grade_section' => 'Grade 11 - Amethyst',
            'teacher' => 'Test Teacher',
            'subject' => 'Filipino 1',
            'subject_type' => 'Core Subject',
        ]);
        $exporter->setStudents([
            [
                'id' => 1,
                'lrn' => '123456789012',
                'export_key' => 'student:1',
                'name' => 'Dagohoy, Dave Santos',
                'sex' => 'male',
            ],
        ]);
        $exporter->setGradeItems([
            [
                'export_key' => 'student:1',
                'item_id' => 10,
                'component' => 'ww',
                'total_score' => 20,
                'scores' => [18],
            ],
        ]);
        $exporter->setAcademicYear('2026-2027');
        $exporter->setTerm('Term1');

        $path = tempnam(sys_get_temp_dir(), 'ecr-template-export-test-');
        $this->assertIsString($path);

        try {
            $this->assertTrue($exporter->exportToXlsx($path));

            putenv('APP_FORCE_XLSX_FALLBACK=1');
            $parser = new SimpleXlsxParser($path);
            $sheetNames = $parser->getSheetNames();
            $termRows = $parser->getSheet((int)array_search('TERM 1', $sheetNames, true));
            putenv('APP_FORCE_XLSX_FALLBACK');

            $this->assertContains('INPUT DATA', $sheetNames);
            $this->assertContains('TERM 1', $sheetNames);
            $this->assertContains('TERM 2', $sheetNames);
            $this->assertContains('TERM 3', $sheetNames);
            $this->assertNotContains('ECR Export', $sheetNames);
            $this->assertSame('Strengthened Senior High School Class Record', $termRows[1][0] ?? null);
            $this->assertSame('Dagohoy, Dave Santos', $termRows[14][1] ?? null);
            $this->assertSame('123456789012', $termRows[14][2] ?? null);
            $this->assertSame('18', $termRows[14][5] ?? null);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }
}
