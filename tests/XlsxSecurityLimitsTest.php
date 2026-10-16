<?php

use BshsAms\Xlsx\SimpleXlsxParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XlsxSecurityLimitsTest extends TestCase
{
    private array $paths = [];

    public static function readers(): array
    {
        return ['ZipArchive' => [false], 'PHP fallback' => [true]];
    }

    protected function tearDown(): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK');
        foreach ($this->paths as $path) { @unlink($path); }
    }

    private function archive(array $entries): string
    {
        $path = tempnam(APP_ROOT . '/.phpunit.cache', 'xlsx-security-');
        $this->paths[] = $path;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) { $zip->addFromString($name, $content); }
        $zip->close();
        return $path;
    }

    private function workbook(string $sheet): array
    {
        return ['xl/workbook.xml' => '<workbook><sheets><sheet name="Test"/></sheets></workbook>',
            'xl/worksheets/sheet1.xml' => $sheet];
    }

    #[DataProvider('readers')]
    public function testOrdinaryWorkbooksStillParse(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $path = $this->archive($this->workbook('<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Safe</t></is></c></row></sheetData></worksheet>'));
        $parser = new SimpleXlsxParser($path);
        self::assertSame('Safe', $parser->getSheet()[1][0]);
    }

    #[DataProvider('readers')]
    public function testOversizedExpandedEntryIsRejectedBeforeParsing(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $path = $this->archive(['xl/sharedStrings.xml' => str_repeat('A', SimpleXlsxParser::MAX_ENTRY_BYTES + 1)]);
        self::assertLessThan(100000, filesize($path));
        $this->expectExceptionMessage('16 MiB');
        new SimpleXlsxParser($path);
    }

    #[DataProvider('readers')]
    public function testTooManyArchiveEntriesAreRejected(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $entries = [];
        for ($i = 0; $i <= SimpleXlsxParser::MAX_ENTRIES; $i++) { $entries['entry-' . $i] = 'x'; }
        $path = $this->archive($entries);
        $this->expectExceptionMessage('too many archive entries');
        new SimpleXlsxParser($path);
    }

    #[DataProvider('readers')]
    public function testAggregateExpandedSizeIsBounded(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $block = str_repeat('x', SimpleXlsxParser::MAX_ENTRY_BYTES);
        $path = $this->archive(['a' => $block, 'b' => $block, 'c' => $block, 'd' => $block, 'e' => 'x']);
        unset($block);
        $this->expectExceptionMessage('64 MiB');
        new SimpleXlsxParser($path);
    }

    #[DataProvider('readers')]
    public function testDoctypeDeclarationsAreRejected(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $path = $this->archive(['xl/sharedStrings.xml' => '<!DOCTYPE sst [<!ENTITY example "value">]><sst><si><t>&example;</t></si></sst>']);
        $this->expectExceptionMessage('forbidden declarations');
        new SimpleXlsxParser($path);
    }

    #[DataProvider('readers')]
    public function testSparseOutOfRangeRowsAreRejected(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $path = $this->archive($this->workbook('<worksheet><sheetData><row r="10001"><c r="A10001"><v>1</v></c></row></sheetData></worksheet>'));
        $parser = new SimpleXlsxParser($path);
        $this->expectExceptionMessage('row limit');
        $parser->getSheet();
    }

    #[DataProvider('readers')]
    public function testExcessiveXmlNodeCountsAreRejected(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $path = $this->archive(['xl/sharedStrings.xml' => '<sst>' . str_repeat('<si/>', SimpleXlsxParser::MAX_XML_NODES) . '</sst>']);
        $this->expectExceptionMessage('processing limits');
        new SimpleXlsxParser($path);
    }

    #[DataProvider('readers')]
    public function testBeyondExcelColumnLimitIsRejected(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $path = $this->archive($this->workbook('<worksheet><sheetData><row r="1"><c r="XFE1"><v>1</v></c></row></sheetData></worksheet>'));
        $parser = new SimpleXlsxParser($path);
        $this->expectExceptionMessage('column limit');
        $parser->getSheet();
    }

    #[DataProvider('readers')]
    public function testExcessiveCellsAreRejected(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $cells = str_repeat('<c r="A1"/>', SimpleXlsxParser::MAX_CELLS + 1);
        $path = $this->archive($this->workbook('<worksheet><sheetData><row r="1">' . $cells . '</row></sheetData></worksheet>'));
        $parser = new SimpleXlsxParser($path);
        $this->expectExceptionMessage('cell limits');
        $parser->getSheet();
    }

    #[DataProvider('readers')]
    public function testNulEncodedXmlCannotBypassDeclarationChecks(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        $xml = mb_convert_encoding('<!DOCTYPE sst [<!ENTITY x "value">]><sst/>', 'UTF-16LE', 'UTF-8');
        $path = $this->archive(['xl/sharedStrings.xml' => $xml]);
        $this->expectExceptionMessage('forbidden declarations');
        new SimpleXlsxParser($path);
    }

    public function testCompressedArchiveSizeLimitIsEnforced(): void
    {
        $path = $this->archive(['entry' => 'small']);
        $file = fopen($path, 'r+b');
        ftruncate($file, SimpleXlsxParser::MAX_ARCHIVE_BYTES + 1);
        fclose($file);
        clearstatcache(true, $path);
        $this->expectExceptionMessage('20 MiB');
        new SimpleXlsxParser($path);
    }

    #[DataProvider('readers')]
    public function testOfficialTemplatesRemainReadable(bool $fallback): void
    {
        putenv('APP_FORCE_XLSX_FALLBACK=' . ($fallback ? '1' : '0'));
        foreach (['SF1_Senior_High_School.xlsx', 'SF2_Senior_High_School.xlsx', 'ecr_template.xlsx'] as $name) {
            $parser = new SimpleXlsxParser(APP_ROOT . '/deped/' . $name);
            self::assertNotEmpty($parser->getSheetNames(), $name);
            self::assertNotEmpty($parser->getSheet(), $name);
        }
    }
}
