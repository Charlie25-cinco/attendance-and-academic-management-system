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
}
