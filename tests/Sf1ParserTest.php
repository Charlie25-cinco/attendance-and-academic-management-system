<?php

use PHPUnit\Framework\TestCase;

final class Sf1ParserTest extends TestCase
{
    public function testSf1ExporterFormatsBirthdateInsideNamespace(): void
    {
        $reflection = new ReflectionClass(\BshsAms\Export\Sf1Exporter::class);
        $exporter = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('formatSf1Date');
        $method->setAccessible(true);

        $this->assertSame('05/08/2008', $method->invoke($exporter, '2008-05-08'));
    }

    public function testSf1OfficialExporterWritesFirstLearnerOnTemplateRowEleven(): void
    {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('SQLite PDO driver is not available.');
        }

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE school_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)");
        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            lrn TEXT,
            first_name TEXT,
            middle_name TEXT,
            last_name TEXT,
            sex TEXT,
            date_of_birth TEXT,
            contact_number TEXT,
            address TEXT,
            house_street TEXT,
            barangay TEXT,
            municipality TEXT,
            province TEXT,
            religion TEXT,
            name_extension TEXT,
            father_name TEXT,
            mother_name TEXT,
            guardian_name TEXT,
            guardian_relationship TEXT,
            role TEXT,
            status TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT
        )");
        $stmt = $db->prepare("INSERT INTO users (
            lrn, first_name, middle_name, last_name, sex, date_of_birth, contact_number,
            address, house_street, barangay, municipality, province, religion, name_extension,
            father_name, mother_name, guardian_name, guardian_relationship, role, status,
            grade_level, section, track
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            '123456789012',
            'Dave',
            'Santos',
            'Dagohoy',
            'male',
            '2008-05-08',
            '09123456789',
            '',
            '36',
            'Mambayaan',
            'Balingasag',
            'Misamis Oriental',
            '',
            '',
            'Dagohoy, Father',
            'Santos, Mother',
            '',
            '',
            'student',
            'active',
            11,
            'Amethyst',
            'academic',
        ]);
        $cacheReflection = new ReflectionClass(\BshsAms\Database\SchemaCache::class);
        $columnsProperty = $cacheReflection->getProperty('columns');
        $columnsProperty->setAccessible(true);
        $columnsProperty->setValue(null, [
            'users' => [
                'id',
                'lrn',
                'first_name',
                'middle_name',
                'last_name',
                'sex',
                'date_of_birth',
                'contact_number',
                'address',
                'house_street',
                'barangay',
                'municipality',
                'province',
                'religion',
                'name_extension',
                'father_name',
                'mother_name',
                'guardian_name',
                'guardian_relationship',
                'role',
                'status',
                'grade_level',
                'section',
                'track',
            ],
        ]);
        $loadedAtProperty = $cacheReflection->getProperty('loadedAt');
        $loadedAtProperty->setAccessible(true);
        $loadedAtProperty->setValue(null, time());

        $path = tempnam(sys_get_temp_dir(), 'sf1-export-test-');
        $this->assertIsString($path);

        try {
            $exporter = new \BshsAms\Export\Sf1Exporter($db);
            $exporter->exportOfficialTemplateXlsx($path, 11, 'Amethyst', 'academic', '2026-2027');

            putenv('APP_FORCE_XLSX_FALLBACK=1');
            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);
            putenv('APP_FORCE_XLSX_FALLBACK');

            $this->assertSame('123456789012', $rows[11][0] ?? null);
            $this->assertSame('Dagohoy, Dave Santos', $rows[11][2] ?? null);
            $this->assertSame('M', $rows[11][6] ?? null);
            $this->assertSame('05/08/2008', $rows[11][7] ?? null);
            $this->assertSame('', $rows[12][0] ?? '');
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            \BshsAms\Database\SchemaCache::clearCache();
            @unlink($path);
        }
    }

    public function testParsesExcelSerialBirthdate(): void
    {
        $this->assertSame('2008-01-15', Sf1Parser::parseBirthdate('39462'));
    }

    public function testSharedBootstrapLoadsSf1ParserAlias(): void
    {
        require_once __DIR__ . '/../functions/bootstrap.php';

        $this->assertTrue(class_exists('Sf1Parser'));
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
            $editor->clearRange('A', 'AE', 11, 120);
            $editor->setCell('A11', '123456789012');
            $editor->setCell('C11', 'Dagohoy, Dave Santos');
            $editor->setCell('G11', 'M');
            $editor->setCell('H11', '05/08/2008');
            $editor->setCell('M11', '36');
            $editor->setCell('N11', 'Mambayaan');
            $editor->save($path);

            putenv('APP_FORCE_XLSX_FALLBACK=1');
            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);
            putenv('APP_FORCE_XLSX_FALLBACK');

            $this->assertSame(['SHSF-1'], $parser->getSheetNames());
            $this->assertSame('NAME' . "\n" . '(Last Name, First Name, Name Extension, Middle Name)', $rows[9][2] ?? null);
            $this->assertSame('House No./ Street/ Sitio/ Purok', $rows[10][12] ?? null);
            $this->assertSame('123456789012', $rows[11][0] ?? null);
            $this->assertSame('Dagohoy, Dave Santos', $rows[11][2] ?? null);
            $this->assertSame('Mambayaan', $rows[11][13] ?? null);
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }
}
