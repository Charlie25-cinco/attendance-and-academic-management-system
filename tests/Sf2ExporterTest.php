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
}
