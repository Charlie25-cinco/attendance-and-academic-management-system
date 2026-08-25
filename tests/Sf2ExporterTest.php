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
            'school_name' => 'Balingasag Senior High School',
            'district' => 'Balingasag North',
            'division' => 'Misamis Oriental',
            'track' => 'academic',
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
            $this->assertSame('Balingasag Senior High School', $rows[3][5] ?? null);
            $this->assertSame('341227', $rows[3][21] ?? null);
            $this->assertSame('Balingasag North', $rows[3][31] ?? null);
            $this->assertSame('Misamis Oriental', $rows[3][43] ?? null);
            $this->assertSame('N/A (SSHS - Three-Term)', $rows[5][5] ?? null);
            $this->assertSame('2026-2027', $rows[5][21] ?? null);
            $this->assertSame('11', $rows[5][33] ?? null);
            $this->assertSame('Academic Track', $rows[5][47] ?? null);
            $this->assertSame('Amethyst', $rows[7][5] ?? null);
            $this->assertSame('August 2026', $rows[7][47] ?? null);
            $this->assertSame('1', $rows[10][5] ?? null);
            $this->assertSame('S', $rows[11][5] ?? null);
            $this->assertSame('3', $rows[10][7] ?? null);
            $this->assertSame('M', $rows[11][7] ?? null);
            $this->assertSame('Dagohoy, Dave S.', $rows[12][2] ?? null);
            $this->assertSame('', $rows[12][7] ?? '');
            $this->assertSame('X', $rows[12][8] ?? null);
            $this->assertSame(1, (int)($rows[12][43] ?? -1));
            $this->assertSame(0, (int)($rows[12][45] ?? -1));
            $this->assertSame('Cruz, Ana R.', $rows[30][2] ?? null);
            $this->assertSame("\u{2580}", $rows[30][7] ?? null);
            $this->assertSame('', $rows[30][8] ?? '');
            $this->assertSame(0, (int)($rows[30][43] ?? -1));
            $this->assertSame(1, (int)($rows[30][45] ?? -1));
            $this->assertSame(1, (int)($rows[29][7] ?? -1));
            $this->assertSame(1, (int)($rows[57][8] ?? -1));
            $this->assertSame('GUIDELINES:', $rows[60][0] ?? null);

            // Verify Summary Statistics Block
            $this->assertSame('August', $rows[61][43] ?? null);
            $this->assertSame(26, (int)($rows[61][46] ?? 0));
            $this->assertSame(1, (int)($rows[62][48] ?? 0)); // Male Enrolment 1st Friday
            $this->assertSame(1, (int)($rows[62][49] ?? 0)); // Female Enrolment 1st Friday
            $this->assertSame(2, (int)($rows[62][50] ?? 0)); // Total Enrolment 1st Friday
            $this->assertSame(1, (int)($rows[64][48] ?? 0)); // End of month M
            $this->assertSame(1, (int)($rows[64][49] ?? 0)); // End of month F
            $this->assertSame(2, (int)($rows[64][50] ?? 0)); // End of month Total
            $this->assertSame('TEST TEACHER', $rows[80][44] ?? null); // Adviser Sign-Off
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }

    public function testSf2ConsecutiveAbsencesAndRemarksPopulation(): void
    {
        $exporter = new Sf2Exporter();
        $exporter->setClass([
            'class_name' => 'English 1',
            'grade_level' => 11,
            'section' => 'Emerald',
            'track' => 'academic',
        ]);
        $exporter->setStudents([
            [
                'id' => 10,
                'first_name' => 'Juan',
                'last_name' => 'Luna',
                'sex' => 'male',
            ],
            [
                'id' => 20,
                'first_name' => 'Maria',
                'last_name' => 'Clara',
                'sex' => 'female',
                'remarks' => 'CCT Recipient',
            ],
        ]);
        // 5 consecutive absences for Juan Luna (Aug 3, 4, 5, 6, 7)
        $exporter->setAttendance([
            10 => [
                '2026-08-03' => 'absent',
                '2026-08-04' => 'absent',
                '2026-08-05' => 'absent',
                '2026-08-06' => 'absent',
                '2026-08-07' => 'absent',
            ],
            20 => [
                '2026-08-03' => ['status' => 'cutting', 'remarks' => 'Cutting Class (Period 3)'],
                '2026-08-04' => 'present',
            ],
        ]);
        $exporter->setMonth(8);
        $exporter->setYear(2026);
        $exporter->setTeacherName('Mr. Adviser');

        $path = tempnam(sys_get_temp_dir(), 'sf2_remarks_') . '.xlsx';
        putenv('APP_FORCE_XLSX_FALLBACK=1');
        try {
            $this->assertTrue($exporter->export($path));
            $parser = new SimpleXlsxParser($path);
            $rows = $parser->getSheet(0);

            // Learner remarks column AV (index 47)
            $this->assertSame('5 consecutive days absent', $rows[12][47] ?? null);
            $this->assertSame('CCT Recipient; Cutting Class (Period 3)', $rows[30][47] ?? null);

            // Cutting mark for female learner on Aug 3 (row 30, col 7)
            $this->assertSame("\u{2584}", $rows[30][7] ?? null);

            // Row 68 summary: 5 consecutive days absent count (1 male, 0 female, 1 total)
            $this->assertSame(1, (int)($rows[68][48] ?? 0));
            $this->assertSame(0, (int)($rows[68][49] ?? 0));
            $this->assertSame(1, (int)($rows[68][50] ?? 0));
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }

    public function testSf2FallbackPreservesAllLearnersAcrossTemplateSheets(): void
    {
        $exporter = new Sf2Exporter();
        $exporter->setClass(['grade_level' => 11, 'section' => 'Amethyst', 'track' => 'academic']);
        $students = [];
        for ($i = 1; $i <= 25; $i++) {
            $students[] = ['id' => $i, 'first_name' => 'Male' . $i, 'last_name' => sprintf('Learner%02d', $i), 'sex' => 'male'];
        }
        for ($i = 1; $i <= 25; $i++) {
            $students[] = ['id' => 100 + $i, 'first_name' => 'Female' . $i, 'last_name' => sprintf('Student%02d', $i), 'sex' => 'female'];
        }
        $exporter->setStudents($students);
        $exporter->setAttendance([]);
        $exporter->setMonth(8);
        $exporter->setYear(2026);

        $path = tempnam(sys_get_temp_dir(), 'sf2_overflow_') . '.xlsx';
        putenv('APP_FORCE_XLSX_FALLBACK=1');
        try {
            $this->assertTrue($exporter->export($path));
            $parser = new SimpleXlsxParser($path);
            $this->assertSame(['SHSF-2', 'SF2 (2)'], $parser->getSheetNames());

            $exportedNames = [];
            foreach ([0, 1] as $sheetIndex) {
                $rows = $parser->getSheet($sheetIndex);
                foreach (array_merge(range(12, 28), range(30, 56)) as $row) {
                    $name = trim((string)($rows[$row][2] ?? ''));
                    if ($name !== '') { $exportedNames[] = $name; }
                }
                $this->assertSame('GUIDELINES:', $rows[60][0] ?? null);
            }
            $this->assertCount(50, array_unique($exportedNames));
        } finally {
            putenv('APP_FORCE_XLSX_FALLBACK');
            @unlink($path);
        }
    }

    public function testPhpSpreadsheetOverflowClonesOfficialTemplate(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('PHP Zip is required for the PhpSpreadsheet export path.');
        }

        $exporter = new Sf2Exporter();
        $exporter->setClass(['grade_level' => 11, 'section' => 'Amethyst', 'track' => 'academic']);
        $students = [];
        for ($i = 1; $i <= 18; $i++) {
            $students[] = ['id' => $i, 'first_name' => 'Male' . $i, 'last_name' => sprintf('Learner%02d', $i), 'sex' => 'male'];
        }
        $exporter->setStudents($students);
        $exporter->setAttendance([]);
        $exporter->setMonth(8);
        $exporter->setYear(2026);

        $path = tempnam(sys_get_temp_dir(), 'sf2_php_') . '.xlsx';
        try {
            $this->assertTrue($exporter->export($path));
            $parser = new SimpleXlsxParser($path);
            $this->assertSame(['SHSF-2', 'SF2 (2)'], $parser->getSheetNames());
            $secondSheet = $parser->getSheet(1);
            $this->assertSame('Learner18, Male18', $secondSheet[12][2] ?? null);
            $this->assertSame('GUIDELINES:', $secondSheet[60][0] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function testReportDaysDoNotRequireCalendarExtension(): void
    {
        $exporter = new Sf2Exporter();
        $exporter->setMonth(2);
        $exporter->setYear(2028);

        $method = new ReflectionMethod($exporter, 'computeSchoolDays');
        $schoolDays = $method->invoke($exporter);

        $this->assertContains(29, $schoolDays, 'Leap-year February 29 should be exported when it is a report day.');
        $this->assertCount(25, $schoolDays);

        $endpoint = file_get_contents(__DIR__ . '/../teacher/teacher_SF2_Export.php');
        $exporterSource = file_get_contents(__DIR__ . '/../src/Export/Sf2Exporter.php');
        $adminActionSource = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($endpoint);
        $this->assertIsString($exporterSource);
        $this->assertIsString($adminActionSource);
        $this->assertStringNotContainsString('cal_days_in_month', $endpoint);
        $this->assertStringNotContainsString('cal_days_in_month', $exporterSource);
        $this->assertStringNotContainsString('cal_days_in_month', $adminActionSource);
    }

    public function testTeacherSf2ExportPermitsSubjectTeacherAndResilientEnrollments(): void
    {
        $endpoint = (string)file_get_contents(__DIR__ . '/../teacher/teacher_SF2_Export.php');
        $this->assertStringContainsString('class_subjects cs WHERE cs.class_id = c.id AND cs.teacher_id = ?', $endpoint, 'Teacher SF2 export must permit subject teachers assigned via class_subjects.');
        $this->assertStringContainsString('e.academic_year = (SELECT e2.academic_year FROM enrollments e2', $endpoint, 'Teacher SF2 export must fall back to class enrollments if academic year varies.');

        $adminAction = (string)file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertStringContainsString('e.academic_year = (SELECT e2.academic_year FROM enrollments e2', $adminAction, 'Admin SF2 export must fall back to class enrollments if academic year varies.');
    }
}
