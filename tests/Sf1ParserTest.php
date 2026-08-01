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
            $this->markTestSkipped('SF1_SAMPLE_PATH is not available.');
        }

        putenv('APP_FORCE_XLSX_FALLBACK=1');
        $parser = new Sf1Parser($samplePath);
        $parsed = $parser->parse();
        putenv('APP_FORCE_XLSX_FALLBACK');

        $this->assertSame([], $parsed['errors']);
        $this->assertNotEmpty($parsed['students']);
        $this->assertNotEmpty($parsed['header']);
    }
}
