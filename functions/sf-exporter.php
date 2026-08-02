<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// SF1 Parser
class Sf1Parser {
    private $parser;
    private $errors = [];
    private $warnings = [];

    public function __construct(string $filePath) {
        $this->parser = new SimpleXlsxParser($filePath);
    }

    public function parse(): array {
        $sheets = $this->parser->getSheetNames();
        $sf1Index = null;
        foreach ($sheets as $i => $name) {
            if (stripos($name, 'SHSF-1') !== false || stripos($name, 'SF1') !== false) { $sf1Index = $i; break; }
        }
        if ($sf1Index === null) { $this->errors[] = 'SHSF-1 sheet not found in the workbook.'; return ['header' => [], 'students' => [], 'errors' => $this->errors, 'warnings' => $this->warnings]; }

        $data = $this->parser->getSheet($sf1Index);
        $header = $this->parseHeader($data);
        $students = $this->parseStudents($data);
        return ['header' => $header, 'students' => $students, 'errors' => $this->errors, 'warnings' => $this->warnings];
    }

    private function cell($data, int $row, int $col): string { return trim((string)($data[$row][$col] ?? '')); }

    private function firstCell(array $data, int $row, array $cols): string {
        foreach ($cols as $col) {
            $value = $this->cell($data, $row, $col);
            if ($value !== '') { return $value; }
        }
        return '';
    }

    private function parseHeader(array $data): array {
        return [
            'school_name' => $this->firstCell($data, 3, range(5, 8)),
            'school_id' => $this->firstCell($data, 3, range(12, 16)),
            'district' => $this->firstCell($data, 3, range(20, 22)),
            'division' => $this->firstCell($data, 3, range(25, 29)),
            'region' => $this->firstCell($data, 3, range(31, 34)),
            'semester' => $this->firstCell($data, 5, range(5, 8)),
            'school_year' => $this->firstCell($data, 5, range(12, 18)),
            'grade_level' => $this->firstCell($data, 5, range(22, 24)),
            'track_strand' => $this->firstCell($data, 5, range(28, 31)),
            'section' => $this->firstCell($data, 7, range(5, 8)),
            'course_tvl' => $this->firstCell($data, 7, range(15, 22)),
        ];
    }

    private function parseStudents(array $data): array {
        $students = [];
        for ($row = 11; $row <= 100; $row++) {
            if (!isset($data[$row])) continue;
            $lrn = $this->sf1Lrn($data, $row);
            $name = $this->cell($data, $row, 2);
            if ($lrn === '' && $name === '') continue;
            $markerText = strtoupper($lrn . ' ' . $name);
            if (str_contains($markerText, '<===') || str_contains($markerText, 'REQUIRED INFORMATION') || str_contains($markerText, 'NAME OF SCHOOL, DATE')) {
                continue;
            }
            if ($name === '' && preg_replace('/\D/', '', $lrn) === '') {
                continue;
            }
            $parsedName = self::parseLearnerName($name);
            $student = [
                'lrn' => $lrn, 'last_name' => $parsedName['last_name'], 'first_name' => $parsedName['first_name'],
                'name_extension' => $parsedName['name_extension'], 'middle_name' => $parsedName['middle_name'],
                'sex' => $this->cell($data, $row, 6), 'birthdate' => $this->cell($data, $row, 7),
                'age' => $this->cell($data, $row, 9), 'religion' => $this->cell($data, $row, 11),
                'house_street' => $this->cell($data, $row, 12), 'barangay' => $this->cell($data, $row, 13),
                'municipality' => $this->cell($data, $row, 17), 'province' => $this->cell($data, $row, 20),
                'father_name' => $this->cell($data, $row, 22), 'mother_name' => $this->cell($data, $row, 23),
                'guardian_name' => $this->cell($data, $row, 25), 'relationship' => $this->cell($data, $row, 28),
                'contact_number' => $this->cell($data, $row, 29), 'remarks' => $this->cell($data, $row, 30),
            ];
            $student['address'] = implode(', ', array_filter([$student['house_street'], $student['barangay'], $student['municipality'], $student['province']]));
            $students[] = $student;
        }
        return $students;
    }

    private function sf1Lrn(array $data, int $row): string {
        $columnA = $this->cell($data, $row, 0);
        $columnB = $this->cell($data, $row, 1);
        if (preg_match('/^\d{12}$/', preg_replace('/\D/', '', $columnA))) {
            return $columnA;
        }
        return $columnB;
    }

    public static function parseLearnerName(string $value): array {
        $value = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $value)));
        $parsed = ['last_name' => '', 'first_name' => '', 'middle_name' => '', 'name_extension' => ''];
        if ($value === '') {
            return $parsed;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        if (count($parts) >= 4) {
            $parsed['last_name'] = $parts[0];
            $parsed['first_name'] = $parts[1];
            $parsed['name_extension'] = $parts[2];
            $parsed['middle_name'] = implode(' ', array_slice($parts, 3));
            return $parsed;
        }
        if (count($parts) === 3) {
            $parsed['last_name'] = $parts[0];
            $parsed['first_name'] = $parts[1];
            $thirdPart = $parts[2];
            if (self::isNameExtension($thirdPart)) {
                $parsed['name_extension'] = $thirdPart;
            } else {
                $parsed['middle_name'] = $thirdPart;
            }
            return $parsed;
        }
        if (count($parts) === 2) {
            $parsed['last_name'] = $parts[0];
            $givenParts = preg_split('/\s+/', $parts[1], -1, PREG_SPLIT_NO_EMPTY);
            $extensionIndex = self::findNameExtensionIndex($givenParts);
            if ($extensionIndex !== null) {
                $parsed['name_extension'] = $givenParts[$extensionIndex];
                array_splice($givenParts, $extensionIndex, 1);
            }
            if (count($givenParts) > 1) {
                $parsed['middle_name'] = array_pop($givenParts);
            }
            $parsed['first_name'] = implode(' ', $givenParts);
            return $parsed;
        }

        $nameParts = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $parsed['last_name'] = array_shift($nameParts) ?? '';
        $parsed['first_name'] = implode(' ', $nameParts);
        return $parsed;
    }

    private static function findNameExtensionIndex(array $parts): ?int {
        foreach ($parts as $index => $part) {
            if (self::isNameExtension($part)) {
                return $index;
            }
        }
        return null;
    }

    private static function isNameExtension(string $value): bool {
        $normalized = strtoupper(trim($value, " ."));
        return in_array($normalized, ['JR', 'SR', 'II', 'III', 'IV', 'V', 'VI'], true);
    }

    public function getErrors(): array { return $this->errors; }
    public function getWarnings(): array { return $this->warnings; }

    public static function normalizeSex(string $value): string {
        $upper = strtoupper(trim($value));
        if ($upper === 'M' || $upper === 'MALE') return 'Male';
        if ($upper === 'F' || $upper === 'FEMALE') return 'Female';
        return $upper;
    }

    public static function parseBirthdate(string $value): ?string {
        $value = trim($value);
        if ($value === '') return null;
        if (is_numeric($value)) {
            $number = (float)$value;
            if ($number > 20000 && $number < 60000) {
                $base = new DateTime('1899-12-30');
                $base->modify('+' . (int)$number . ' days');
                return $base->format('Y-m-d');
            }
        }
        $formats = ['m/d/Y', 'm-d-Y', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'M d, Y', 'F d, Y'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) { return $dt->format('Y-m-d'); }
        }
        if (is_numeric($value) && strlen($value) === 10) { $ts = (int)$value; if ($ts > 0) return date('Y-m-d', $ts); }
        return null;
    }

    public static function normalizeTrack(string $value): string {
        $lower = strtolower(trim($value));
        if (strpos($lower, 'techpro') !== false || strpos($lower, 'technical') !== false || strpos($lower, 'tvl') !== false) return 'techpro';
        return 'academic';
    }
}

// SF1 Exporter
class Sf1Exporter {
    private $db;
    private $schoolSettings;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->schoolSettings = getSchoolSettings($db);
    }

    public function exportXlsx(int $gradeLevel, string $section, ?string $track, string $academicYear, ?string $semester = null): Spreadsheet {
        $students = $this->fetchStudents($gradeLevel, $section, $track, $academicYear);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SHSF-1');
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $this->writeHeaders($sheet, $gradeLevel, $section, $track, $academicYear, $semester);
        $this->writeStudentRows($sheet, $students);
        $this->applyStyles($sheet, count($students));
        return $spreadsheet;
    }

    public function exportOfficialTemplateXlsx(string $filePath, int $gradeLevel, string $section, ?string $track, string $academicYear, ?string $semester = null): void {
        $templatePath = APP_ROOT . '/deped/SF1_Senior_High_School.xlsx';
        if (!is_file($templatePath)) {
            throw new RuntimeException('Official SF1 template is missing.');
        }

        if (!class_exists('SimpleXlsxTemplateEditor')) {
            require_once APP_ROOT . '/functions/simple-xlsx-writer.php';
        }

        $students = $this->fetchStudents($gradeLevel, $section, $track, $academicYear);
        $school = $this->schoolSettings;
        $editor = new SimpleXlsxTemplateEditor($templatePath);
        $trackLabel = $track === 'techpro' ? 'Technical-Vocational-Livelihood Track' : 'Academic Track';

        $editor->setCell('F3', $school['school_name'] ?? 'Balingasag Senior High School');
        $editor->setCell('M3', $school['school_id'] ?? '341227');
        $editor->setCell('U3', $school['district'] ?? 'Balingasag North');
        $editor->setCell('Z3', $school['division'] ?? 'Misamis Oriental');
        $editor->setCell('AF3', $school['region'] ?? 'X');
        $editor->setCell('F5', $semester ?: 'N/A (SSHS - Three-Term)');
        $editor->setCell('M5', $academicYear);
        $editor->setCell('W5', 'Grade ' . $gradeLevel);
        $editor->setCell('AC5', $trackLabel);
        $editor->setCell('F7', $section);
        $editor->setCell('M7', $track === 'techpro' ? 'TVL' : '');
        $editor->clearRange('A', 'AE', 11, 120);

        $row = 11;
        foreach ($students as $student) {
            $editor->setCell('A' . $row, (string)($student['lrn'] ?? ''));
            $editor->setCell('C' . $row, $this->formatSf1LearnerName($student));
            $editor->setCell('G' . $row, strtoupper(substr((string)($student['sex'] ?? ''), 0, 1)));
            $editor->setCell('H' . $row, $this->formatSf1Date($student['date_of_birth'] ?? ''));
            $editor->setCell('J' . $row, $student['age'] ?? '');
            $editor->setCell('L' . $row, $student['religion'] ?? '');
            $editor->setCell('M' . $row, $student['house_street'] ?? $this->extractAddressPart($student, 'house'));
            $editor->setCell('N' . $row, $student['barangay'] ?? $this->extractAddressPart($student, 'barangay'));
            $editor->setCell('R' . $row, $student['municipality'] ?? $this->extractAddressPart($student, 'municipality'));
            $editor->setCell('U' . $row, $student['province'] ?? $this->extractAddressPart($student, 'province'));
            $editor->setCell('W' . $row, $student['father_name'] ?? '');
            $editor->setCell('X' . $row, $student['mother_name'] ?? '');
            $editor->setCell('Z' . $row, $student['guardian_name'] ?? '');
            $editor->setCell('AC' . $row, $student['relationship'] ?? '');
            $editor->setCell('AD' . $row, $student['contact_number'] ?? '');
            $editor->setCell('AE' . $row, $student['remarks'] ?? '');
            $row++;
        }

        $editor->save($filePath);
    }

    private function formatSf1LearnerName(array $student): string {
        $lastName = trim((string)($student['last_name'] ?? ''));
        $firstName = trim((string)($student['first_name'] ?? ''));
        $extension = trim((string)($student['name_extension'] ?? ''));
        $middleName = trim((string)($student['middle_name'] ?? ''));
        $given = trim(implode(' ', array_filter([$firstName, $extension, $middleName])));
        return trim($lastName . ($given !== '' ? ', ' . $given : ''));
    }

    private function formatSf1Date($value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        try {
            return (new DateTime($value))->format('m/d/Y');
        } catch (Throwable $e) {
            return $value;
        }
    }

    private function writeHeaders($sheet, int $gradeLevel, string $section, ?string $track, string $academicYear, ?string $semester): void {
        $school = $this->schoolSettings;
        $schoolName = $school['school_name'] ?? 'Balingasag Senior High School';
        $schoolId = $school['school_id'] ?? '341227';
        $district = $school['district'] ?? 'Balingasag North';
        $division = $school['division'] ?? 'Misamis Oriental';
        $region = $school['region'] ?? 'Region X';
        $trackLabel = $track === 'techpro' ? 'Technical-Vocational-Livelihood' : 'Academic';
        $semLabel = $semester ?: '';
        $strandLabel = $track === 'techpro' ? 'TVL' : 'Academic';

        $sheet->mergeCells('A1:AD1'); $sheet->setCellValue('A1', 'School Form 1'); $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A2:AD2'); $sheet->setCellValue('A2', 'School Register for Senior High School (SF1-SHS)'); $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue('B3', 'School Name'); $sheet->setCellValue('C3', $schoolName); $sheet->mergeCells('C3:J3');
        $sheet->setCellValue('K3', 'School ID'); $sheet->setCellValue('L3', $schoolId);
        $sheet->setCellValue('S3', 'District'); $sheet->setCellValue('T3', $district); $sheet->mergeCells('T3:X3');
        $sheet->setCellValue('Y3', 'Division'); $sheet->setCellValue('Z3', $division); $sheet->mergeCells('Z3:AD3');
        $sheet->setCellValue('D5', 'Semester'); $sheet->setCellValue('E5', $semLabel);
        $sheet->setCellValue('L5', 'School Year'); $sheet->setCellValue('M5', $academicYear);
        $sheet->setCellValue('U5', 'Grade Level'); $sheet->setCellValue('V5', 'Grade ' . $gradeLevel);
        $sheet->setCellValue('Z5', 'Track and Strand'); $sheet->setCellValue('AA5', $trackLabel . ' - ' . $strandLabel);
        $sheet->setCellValue('A7', 'Section'); $sheet->setCellValue('B7', $section);
        $sheet->setCellValue('L7', 'Course (For TVL Only)'); $sheet->setCellValue('M7', $track === 'techpro' ? 'TVL' : '');
        $sheet->setCellValue('A9', 'No.'); $sheet->setCellValue('B9', 'LRN'); $sheet->mergeCells('C9:F9'); $sheet->setCellValue('C9', 'NAME');
        $sheet->setCellValue('G9', 'Sex'); $sheet->setCellValue('H9', 'Birthdate'); $sheet->setCellValue('J9', 'Age');
        $sheet->setCellValue('L9', 'Religious Affiliation'); $sheet->mergeCells('M9:V9'); $sheet->setCellValue('M9', 'COMPLETE ADDRESS');
        $sheet->mergeCells('W9:Y9'); $sheet->setCellValue('W9', "PARENTS"); $sheet->mergeCells('Z9:AC9'); $sheet->setCellValue('Z9', 'GUARDIAN');
        $sheet->setCellValue('AD9', 'Contact Number'); $sheet->setCellValue('AE9', 'Remarks');
        $sheet->setCellValue('C10', 'Last Name'); $sheet->setCellValue('D10', 'First Name'); $sheet->setCellValue('E10', 'Ext.');
        $sheet->setCellValue('F10', 'Middle Name'); $sheet->setCellValue('M10', 'House No./Street/Sitio/Purok');
        $sheet->setCellValue('N10', 'Barangay'); $sheet->setCellValue('R10', 'Municipality/City');
        $sheet->setCellValue('U10', 'Province'); $sheet->setCellValue('W10', "Father's Name");
        $sheet->setCellValue('X10', "Mother's Maiden Name"); $sheet->setCellValue('Z10', "Guardian Name");
        $sheet->setCellValue('AC10', 'Relationship');
    }

    private function writeStudentRows($sheet, array $students): void {
        $startRow = 11;
        $maleStudents = array_filter($students, fn($s) => strtolower($s['sex'] ?? '') === 'male');
        $femaleStudents = array_filter($students, fn($s) => strtolower($s['sex'] ?? '') === 'female');
        $row = $startRow; $num = 1;

        if (!empty($maleStudents)) {
            $sheet->setCellValue('A' . $row, 'MALE'); $sheet->getStyle('A' . $row)->getFont()->setBold(true); $row++;
            foreach ($maleStudents as $student) { $this->writeStudentRow($sheet, $row, $num, $student); $row++; $num++; }
        }
        if (!empty($femaleStudents)) {
            $sheet->setCellValue('A' . $row, 'FEMALE'); $sheet->getStyle('A' . $row)->getFont()->setBold(true); $row++;
            foreach ($femaleStudents as $student) { $this->writeStudentRow($sheet, $row, $num, $student); $row++; $num++; }
        }
        $sheet->setCellValue('A' . ($row + 1), 'Prepared by:'); $sheet->getStyle('A' . ($row + 1))->getFont()->setBold(true);
        $sheet->setCellValue('A' . ($row + 2), 'Adviser Signature: ___________________________');
        $sheet->setCellValue('A' . ($row + 3), 'Date: ___________________________');
    }

    private function writeStudentRow($sheet, int $row, int $num, array $student): void {
        $sheet->setCellValue('A' . $row, $num);
        $sheet->setCellValueExplicit('B' . $row, (string)($student['lrn'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('C' . $row, $student['last_name'] ?? ''); $sheet->setCellValue('D' . $row, $student['first_name'] ?? '');
        $sheet->setCellValue('E' . $row, $student['name_extension'] ?? ''); $sheet->setCellValue('F' . $row, $student['middle_name'] ?? '');
        $sheet->setCellValue('G' . $row, strtoupper(substr($student['sex'] ?? '', 0, 1)));
        $sheet->setCellValue('H' . $row, $student['date_of_birth'] ?? ''); $sheet->setCellValue('J' . $row, $student['age'] ?? '');
        $sheet->setCellValue('L' . $row, $student['religion'] ?? '');
        $sheet->setCellValue('M' . $row, $student['house_street'] ?? $this->extractAddressPart($student, 'house'));
        $sheet->setCellValue('N' . $row, $student['barangay'] ?? $this->extractAddressPart($student, 'barangay'));
        $sheet->setCellValue('R' . $row, $student['municipality'] ?? $this->extractAddressPart($student, 'municipality'));
        $sheet->setCellValue('U' . $row, $student['province'] ?? $this->extractAddressPart($student, 'province'));
        $sheet->setCellValue('W' . $row, $student['father_name'] ?? ''); $sheet->setCellValue('X' . $row, $student['mother_name'] ?? '');
        $sheet->setCellValue('Z' . $row, $student['guardian_name'] ?? ''); $sheet->setCellValue('AC' . $row, $student['relationship'] ?? '');
        $sheet->setCellValue('AD' . $row, $student['contact_number'] ?? ''); $sheet->setCellValue('AE' . $row, $student['remarks'] ?? '');
    }

    private function extractAddressPart(array $student, string $part): string {
        $fullAddress = $student['address'] ?? '';
        if ($fullAddress === '') return '';
        $parts = array_map('trim', explode(',', $fullAddress));
        return $parts[0] ?? '';
    }

    private function fetchStudents(int $gradeLevel, string $section, ?string $track, string $academicYear): array {
        $addressCols = dbHasColumn($this->db, 'users', 'house_street')
            ? "COALESCE(u.house_street, '') as house_street, COALESCE(u.barangay, '') as barangay, COALESCE(u.municipality, '') as municipality, COALESCE(u.province, '') as province,"
            : "'' as house_street, '' as barangay, '' as municipality, '' as province,";
        $sql = "SELECT u.id, u.lrn, u.first_name, u.middle_name, u.last_name, u.sex, u.date_of_birth, u.contact_number, u.address, {$addressCols} COALESCE(u.religion, '') as religion, COALESCE(u.name_extension, '') as name_extension, COALESCE(u.father_name, '') as father_name, COALESCE(u.mother_name, '') as mother_name, COALESCE(u.guardian_name, '') as guardian_name, COALESCE(u.guardian_relationship, '') as relationship FROM users u WHERE u.role = 'student' AND u.status = 'active' AND u.grade_level = ? AND LOWER(TRIM(COALESCE(u.section, ''))) = LOWER(TRIM(?))";
        $params = [$gradeLevel, $section];
        if ($track) { $sql .= " AND (? IS NULL OR u.track = ?)"; $params[] = $track; $params[] = $track; }
        $sql .= " ORDER BY CASE WHEN LOWER(u.sex) = 'male' THEN 0 ELSE 1 END, u.last_name, u.first_name";
        try {
            $stmt = $this->db->prepare($sql); $stmt->execute($params); $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('SF1 export query failed: ' . $e->getMessage()); $students = []; }
        foreach ($students as &$student) {
            if (!empty($student['date_of_birth'])) {
                try { $dob = new DateTime($student['date_of_birth']); $now = new DateTime(); $student['age'] = $dob->diff($now)->y; }
                catch (Throwable $e) { $student['age'] = ''; }
            } else { $student['age'] = ''; }
        }
        unset($student);
        return $students;
    }

    private function applyStyles($sheet, int $studentCount): void {
        $headerRows = [1, 2, 3, 5, 7, 9, 10];
        foreach ($headerRows as $row) {
            for ($col = 1; $col <= 30; $col++) {
                $coordinate = Coordinate::stringFromColumnIndex($col) . $row;
                $cell = $sheet->getCell($coordinate);
                if ($cell->getValue()) { $sheet->getStyle($coordinate)->getFont()->setBold(true); }
            }
        }
        $borderStyle = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];
        $startRow = 11; $endRow = $startRow + $studentCount + 20;
        for ($row = $startRow; $row <= $endRow; $row++) {
            for ($col = 1; $col <= 30; $col++) {
                $coordinate = Coordinate::stringFromColumnIndex($col) . $row;
                $sheet->getStyle($coordinate)->applyFromArray($borderStyle);
            }
        }
        $sheet->getDefaultColumnDimension()->setWidth(12);
        $sheet->getColumnDimension('A')->setWidth(5); $sheet->getColumnDimension('B')->setWidth(14); $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15); $sheet->getColumnDimension('E')->setWidth(6); $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(5); $sheet->getColumnDimension('I')->setWidth(12); $sheet->getColumnDimension('J')->setWidth(5);
        $sheet->getColumnDimension('M')->setWidth(18); $sheet->getColumnDimension('N')->setWidth(12); $sheet->getColumnDimension('R')->setWidth(15);
        $sheet->getColumnDimension('U')->setWidth(12); $sheet->getColumnDimension('W')->setWidth(20); $sheet->getColumnDimension('X')->setWidth(20);
        $sheet->getColumnDimension('Z')->setWidth(20); $sheet->getColumnDimension('AA')->setWidth(14); $sheet->getColumnDimension('AC')->setWidth(14);
        $sheet->getColumnDimension('AD')->setWidth(16);
        $sheet->getHeaderFooter()->setOddFooter('&CPage &P of &N');
    }
}

// SF2 Exporter
class Sf2Exporter {
    private array $class = [];
    private array $students = [];
    private array $attendance = [];
    private int $month;
    private int $year;
    private string $teacherName = '';

    private const MALE_CAPACITY = 17;
    private const FEMALE_CAPACITY = 27;
    private const MALE_START_ROW = 12;
    private const MALE_TOTAL_ROW = 29;
    private const FEMALE_START_ROW = 30;
    private const FEMALE_TOTAL_ROW = 57;
    private const COMBINED_TOTAL_ROW = 58;
    private const DAY_COL_START = 6;
    private const NAME_COL = 3;
    private const ABSENT_COL = 'AR';
    private const TARDY_COL = 'AT';

    public function setClass(array $class): void { $this->class = $class; }
    public function setStudents(array $students): void { $this->students = $students; }
    public function setAttendance(array $attendance): void { $this->attendance = $attendance; }
    public function setMonth(int $month): void { $this->month = $month; }
    public function setYear(int $year): void { $this->year = $year; }
    public function setTeacherName(string $name): void { $this->teacherName = $name; }

    public function getTemplatePath(): string {
        $envPath = trim((string)getenv('SF2_TEMPLATE_PATH'));
        if ($envPath !== '' && is_file($envPath)) return $envPath;
        $default = realpath(__DIR__ . '/../deped/SF2_Senior_High_School.xlsx');
        return $default ?: '';
    }

    private function splitStudentsBySex(): array {
        $groups = ['male' => [], 'female' => []];
        foreach ($this->students as $student) {
            $sex = strtolower(trim((string)($student['sex'] ?? '')));
            if ($sex === 'female' || $sex === 'f') { $groups['female'][] = $student; }
            else { $groups['male'][] = $student; }
        }
        foreach ($groups as &$students) {
            usort($students, function (array $a, array $b): int {
                return strcasecmp(trim((string)($a['last_name'] ?? '') . ', ' . (string)($a['first_name'] ?? '')), trim((string)($b['last_name'] ?? '') . ', ' . (string)($b['first_name'] ?? '')));
            });
        }
        unset($students);
        return $groups;
    }

    private function computeSchoolDays(): array {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year);
        $schoolDays = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts = mktime(0, 0, 0, $this->month, $d, $this->year);
            if ((int)date('N', $ts) <= 5) { $schoolDays[] = $d; }
        }
        return $schoolDays;
    }

    private function splitIntoChunks(array $students, int $capacity): array {
        if (empty($students)) return [[]];
        return array_chunk($students, $capacity);
    }

    private function fillSheet($ws, array $maleChunk, array $femaleChunk, array $schoolDays, int $sheetNumber, int $totalSheets): void {
        $monthName = date('F', mktime(0, 0, 0, $this->month, 1, $this->year));
        $schoolYearStart = $this->month >= 6 ? $this->year : $this->year - 1;
        $schoolYear = $schoolYearStart . '-' . ($schoolYearStart + 1);
        $schoolId = $this->schoolMetaValue('school_id', 'SCHOOL_ID', $this->class['school_id'] ?? '');
        $schoolName = $this->schoolMetaValue('school_name', 'SCHOOL_NAME', 'Balingasag Senior High School');
        $sheetTitle = $totalSheets > 1 ? 'SF2' . ($sheetNumber > 1 ? ' (' . $sheetNumber . ')' : '') : 'SF2';
        $ws->setTitle($sheetTitle);
        $ws->setCellValue('C6', $schoolId); $ws->setCellValue('K6', $schoolYear); $ws->setCellValue('X6', $monthName . ' ' . $this->year);
        $ws->setCellValue('C8', $schoolName); $ws->setCellValue('X8', $this->class['grade_level'] ?? ''); $ws->setCellValue('AC8', $this->class['section'] ?? '');
        $groupTotals = ['male' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)], 'female' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)], 'combined' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)]];
        $this->clearLearnerBlock($ws, self::MALE_START_ROW, self::MALE_TOTAL_ROW - 1, $schoolDays);
        $this->clearLearnerBlock($ws, self::FEMALE_START_ROW, self::FEMALE_TOTAL_ROW - 1, $schoolDays);
        $this->clearTotalsRow($ws, self::MALE_TOTAL_ROW, $schoolDays);
        $this->clearTotalsRow($ws, self::FEMALE_TOTAL_ROW, $schoolDays);
        $this->clearTotalsRow($ws, self::COMBINED_TOTAL_ROW, $schoolDays);
        $this->writeLearnerGroup($ws, $maleChunk, self::MALE_START_ROW, self::MALE_TOTAL_ROW - 1, 'male', $schoolDays, $groupTotals);
        $this->writeLearnerGroup($ws, $femaleChunk, self::FEMALE_START_ROW, self::FEMALE_TOTAL_ROW - 1, 'female', $schoolDays, $groupTotals);
        $this->writeTotalsRow($ws, self::MALE_TOTAL_ROW, 'male', $schoolDays, $groupTotals);
        $this->writeTotalsRow($ws, self::FEMALE_TOTAL_ROW, 'female', $schoolDays, $groupTotals);
        $this->writeTotalsRow($ws, self::COMBINED_TOTAL_ROW, 'combined', $schoolDays, $groupTotals);
    }

    public function export(string $filePath): bool {
        $templatePath = $this->getTemplatePath();
        if ($templatePath === '') return false;
        $schoolDays = $this->computeSchoolDays();
        $spreadsheet = IOFactory::load($templatePath);
        $ws = $spreadsheet->getActiveSheet();
        $groups = $this->splitStudentsBySex();
        $maleChunks = $this->splitIntoChunks($groups['male'], self::MALE_CAPACITY);
        $femaleChunks = $this->splitIntoChunks($groups['female'], self::FEMALE_CAPACITY);
        $sheetCount = max(count($maleChunks), count($femaleChunks));
        $this->fillSheet($ws, $maleChunks[0] ?? [], $femaleChunks[0] ?? [], $schoolDays, 1, $sheetCount);
        for ($i = 1; $i < $sheetCount; $i++) {
            $cloneIndex = $i + 1;
            $newWs = $spreadsheet->createSheet();
            $newWs->fromArray($ws->toArray());
            $this->fillSheet($newWs, $maleChunks[$i] ?? [], $femaleChunks[$i] ?? [], $schoolDays, $cloneIndex, $sheetCount);
        }
        if ($sheetCount > 1) { $spreadsheet->setActiveSheetIndex(0); }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);
        $spreadsheet->disconnectWorksheets();
        return true;
    }

    public function outputToBrowser(string $filename): void {
        $tmp = tempnam(sys_get_temp_dir(), 'sf2_') . '.xlsx';
        if ($this->export($tmp)) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'SF2.xlsx';
            $filename = trim($filename, " .\t\n\r\0\x0B") ?: 'SF2.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: public');
            readfile($tmp); unlink($tmp);
        }
        exit();
    }

    private function clearLearnerBlock($ws, int $startRow, int $endRow, array $schoolDays): void {
        for ($row = $startRow; $row <= $endRow; $row++) {
            $ws->setCellValue('A' . $row, ''); $ws->setCellValue('B' . $row, '');
            foreach ($schoolDays as $di => $_day) { $ws->setCellValue(Coordinate::stringFromColumnIndex(self::DAY_COL_START + $di) . $row, ''); }
            $ws->setCellValue(self::ABSENT_COL . $row, ''); $ws->setCellValue(self::TARDY_COL . $row, '');
        }
    }

    private function clearTotalsRow($ws, int $row, array $schoolDays): void {
        foreach ($schoolDays as $di => $_day) { $ws->setCellValue(Coordinate::stringFromColumnIndex(self::DAY_COL_START + $di) . $row, ''); }
        $ws->setCellValue(self::ABSENT_COL . $row, ''); $ws->setCellValue(self::TARDY_COL . $row, '');
    }

    private function writeLearnerGroup($ws, array $students, int $startRow, int $endRow, string $group, array $schoolDays, array &$groupTotals): void {
        $capacity = max(0, $endRow - $startRow + 1);
        foreach (array_slice($students, 0, $capacity) as $idx => $student) {
            $row = $startRow + $idx;
            $ws->setCellValue('A' . $row, $idx + 1);
            $ws->setCellValue(Coordinate::stringFromColumnIndex(self::NAME_COL) . $row, $this->studentDisplayName($student));
            $absentCount = 0; $tardyCount = 0;
            foreach ($schoolDays as $di => $day) {
                $col = Coordinate::stringFromColumnIndex(self::DAY_COL_START + $di);
                $dateStr = sprintf('%04d-%02d-%02d', $this->year, $this->month, $day);
                $status = strtolower(trim((string)($this->attendance[$student['id']][$dateStr] ?? '')));
                $mark = '';
                if ($status === 'present') { $mark = '/'; $groupTotals[$group]['present_by_day'][$day]++; $groupTotals['combined']['present_by_day'][$day]++; }
                elseif ($status === 'absent') { $mark = 'A'; $absentCount++; }
                elseif ($status === 'late' || $status === 'tardy') { $mark = 'L'; $tardyCount++; }
                $ws->setCellValue($col . $row, $mark);
            }
            $groupTotals[$group]['absent'] += $absentCount; $groupTotals[$group]['tardy'] += $tardyCount;
            $groupTotals['combined']['absent'] += $absentCount; $groupTotals['combined']['tardy'] += $tardyCount;
            $ws->setCellValue(self::ABSENT_COL . $row, $absentCount); $ws->setCellValue(self::TARDY_COL . $row, $tardyCount);
        }
    }

    private function writeTotalsRow($ws, int $row, string $group, array $schoolDays, array $groupTotals): void {
        foreach ($schoolDays as $di => $day) { $ws->setCellValue(Coordinate::stringFromColumnIndex(self::DAY_COL_START + $di) . $row, $groupTotals[$group]['present_by_day'][$day] ?? 0); }
        $ws->setCellValue(self::ABSENT_COL . $row, $groupTotals[$group]['absent'] ?? 0);
        $ws->setCellValue(self::TARDY_COL . $row, $groupTotals[$group]['tardy'] ?? 0);
    }

    private function studentDisplayName(array $student): string {
        $middle = trim((string)($student['middle_name'] ?? ''));
        return trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? '') . ($middle !== '' ? ' ' . substr($middle, 0, 1) . '.' : ''));
    }

    private function schoolMetaValue(string $localKey, string $envKey, string $fallback = ''): string {
        $value = trim((string)($this->class[$localKey] ?? ''));
        if ($value !== '') return $value;
        $envValue = trim((string)appEnvValue($envKey, ''));
        if ($envValue !== '') return $envValue;
        $localPath = __DIR__ . '/../config/App.local.php';
        if (file_exists($localPath)) {
            $config = require $localPath;
            if (is_array($config)) { $localValue = trim((string)($config[$localKey] ?? '')); if ($localValue !== '') return $localValue; }
        }
        return $fallback;
    }
}

// SF5 Exporter
class Sf5Exporter {
    private array $class = [];
    private array $students = [];
    private string $term = 'Term1';
    private string $academicYear = '';
    private string $teacherName = '';
    private string $gradingSystem = '3_term';

    public function setClass(array $class): void { $this->class = $class; }
    public function setStudents(array $students): void { $this->students = $students; }
    public function setTerm(string $term): void { $this->term = $term; $this->gradingSystem = SshsGradeCalculator::gradingSystem($this->academicYear); }
    public function setAcademicYear(string $ay): void { $this->academicYear = $ay; $this->gradingSystem = SshsGradeCalculator::gradingSystem($ay); }
    public function setTeacherName(string $name): void { $this->teacherName = $name; }

    public function getTemplatePath(): string {
        $envPath = trim((string)getenv('SF5_TEMPLATE_PATH'));
        if ($envPath !== '' && is_file($envPath)) return $envPath;
        $default = realpath(__DIR__ . '/../resources/deped_templates/SF 5 Report on Promotion and Learning Progress _ Achievement_0 (1).xlsx');
        return $default ?: '';
    }

    public function export(string $filePath): bool {
        $templatePath = $this->getTemplatePath();
        if ($templatePath === '') return false;
        $spreadsheet = IOFactory::load($templatePath);
        $ws = $spreadsheet->getSheetByName('School Form 5 (SF5)') ?: $spreadsheet->getActiveSheet();
        $teacher = $this->teacherName ?: trim(($this->class['t_first'] ?? '') . ' (' . ($this->class['t_last'] ?? ''));
        $gradeSection = 'Grade ' . ($this->class['grade_level'] ?? '') . ' - ' . ($this->class['section'] ?? '');
        $quarterLabel = SshsGradeCalculator::termLabel($this->term);
        $ws->setCellValue('B3', 'Region: ' . ($this->class['region'] ?? 'Region X'));
        $ws->setCellValue('D3', 'Division: ' . ($this->class['division'] ?? 'Misamis Oriental'));
        $ws->setCellValue('A5', 'School ID: ' . ($this->class['school_id'] ?? ''));
        $ws->setCellValue('E5', 'School Year: ' . $this->academicYear);
        $ws->setCellValue('A7', 'School: Balingasag Senior High School'); $ws->setCellValue('C7', 'Balingasag, Misamis Oriental');
        $dataStartRow = 11; $promoted = 0; $retained = 0; $noGrade = 0;
        foreach ($this->students as $idx => $s) {
            $row = $dataStartRow + $idx;
            $name = $s['last_name'] . ', ' . $s['first_name'] . ($s['middle_name'] ? ' ' . substr($s['middle_name'], 0, 1) . '.' : '');
            $fg = $s['final_grade'] !== null ? (float)$s['final_grade'] : null;
            $level = SshsGradeCalculator::proficiencyLevel($fg);
            $status = SshsGradeCalculator::promotionStatus($fg);
            if ($fg === null) $noGrade++; elseif ($fg >= 75) $promoted++; else $retained++;
            $ws->setCellValue('A' . $row, $s['lrn'] ?? ''); $ws->setCellValue('B' . $row, $name);
            $ws->setCellValue('C' . $row, $s['sex'] === 'male' ? 'M' : ($s['sex'] === 'female' ? 'F' : ''));
            $ws->setCellValue('F' . $row, $fg !== null ? number_format($fg, 0) : '');
            $ws->setCellValue('G' . $row, $status); $ws->setCellValue('H' . $row, $level);
        }
        $totalRow = $dataStartRow + count($this->students) + 1;
        $ws->setCellValue('A' . $totalRow, 'Total Enrolled: ' . count($this->students));
        $ws->setCellValue('C' . $totalRow, 'Promoted: ' . $promoted);
        $ws->setCellValue('E' . $totalRow, 'For Remediation: ' . $retained);
        $ws->setCellValue('G' . $totalRow, 'No Grade: ' . $noGrade);
        $rate = count($this->students) > 0 ? number_format($promoted / count($this->students) * 100, 1) : '0';
        $ws->setCellValue('A' . ($totalRow + 1), 'Promotion Rate: ' . $rate . '%');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);
        $spreadsheet->disconnectWorksheets();
        return true;
    }

    public function outputToBrowser(string $filename): void {
        $tmp = tempnam(sys_get_temp_dir(), 'sf5_') . '.xlsx';
        if ($this->export($tmp)) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp); unlink($tmp);
        }
        exit();
    }
}

// SF9 Exporter
class Sf9Exporter {
    private array $student = [];
    private array $enrolledClasses = [];
    private array $gradesByClass = [];
    private string $academicYear = '';
    private array $attendance = [];
    private string $gradingSystem = '3_term';
    private array $combinedSubjects = [];

    public function setStudent(array $student): void { $this->student = $student; }
    public function setEnrolledClasses(array $classes): void { $this->enrolledClasses = $classes; }
    public function setGradesByClass(array $grades): void { $this->gradesByClass = $grades; }
    public function setAcademicYear(string $ay): void { $this->academicYear = $ay; $this->gradingSystem = SshsGradeCalculator::gradingSystem($ay); }
    public function setAttendance(array $attendance): void { $this->attendance = $attendance; }
    public function setCombinedSubjects(array $combined): void { $this->combinedSubjects = $combined; }

    public function export(string $filePath): bool {
        $sp = new Spreadsheet();
        $ws = $sp->getActiveSheet(); $ws->setTitle('SF9'); $ws->setShowGridlines(false);
        $bold = ['bold' => true];
        $borderThin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];
        $center = ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER];
        $ar = 'Arial';
        $termLabels = $this->gradingSystem === '4_quarter' ? ['Q1', 'Q2', 'Q3', 'Q4'] : ['Term1', 'Term2', 'Term3'];
        $r = 1;
        $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'Republic of the Philippines'); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'name' => $ar], 'alignment' => $center]);
        $r = 2; $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'Department of Education'); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 11, 'name' => $ar], 'alignment' => $center]);
        $r = 3; $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'BALINGASAG SENIOR HIGH SCHOOL'); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 14, 'name' => $ar], 'alignment' => $center]);
        $r = 4; $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'Balingasag, Misamis Oriental'); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['size' => 10, 'name' => $ar], 'alignment' => $center]);
        $r = 5; $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'School Form 9 (SF9) — Learner\'s Progress Report Card'); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'name' => $ar], 'alignment' => $center]); $ws->getStyle("A{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
        $r = 7; $ws->mergeCells("A{$r}:D{$r}"); $ws->setCellValue("A{$r}", 'Name: ' . ($this->student['last_name'] ?? '') . ', ' . ($this->student['first_name'] ?? '')); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['size' => 10, 'name' => $ar]]); $ws->mergeCells("E{$r}:H{$r}"); $ws->setCellValue("E{$r}", 'LRN: ' . ($this->student['lrn'] ?? ''));
        $r = 8; $ws->mergeCells("A{$r}:D{$r}"); $ws->setCellValue("A{$r}", 'Grade Level: ' . ($this->student['grade_level'] ?? '')); $ws->mergeCells("E{$r}:H{$r}"); $ws->setCellValue("E{$r}", 'Section: ' . ($this->student['section'] ?? ''));
        $r = 9; $ws->mergeCells("A{$r}:D{$r}"); $ws->setCellValue("A{$r}", 'Sex: ' . ucfirst($this->student['sex'] ?? '')); $ws->mergeCells("E{$r}:H{$r}"); $ws->setCellValue("E{$r}", 'Academic Year: ' . $this->academicYear);
        $r = 10; $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'School: Balingasag Senior High School');
        $r = 12; $headerLabels = ['Subject']; foreach ($termLabels as $tl) $headerLabels[] = SshsGradeCalculator::termLabel($tl); $headerLabels[] = 'Final Grade'; $headerLabels[] = 'Proficiency'; $headerLabels[] = 'Remarks';
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($headerLabels as $i => $h) { $ws->setCellValue($cols[$i] . $r, $h); $ws->getStyle($cols[$i] . $r)->applyFromArray(array_merge($borderThin, ['font' => array_merge($bold, ['size' => 9, 'name' => $ar]), 'alignment' => $center])); $ws->getStyle($cols[$i] . $r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2'); }
        $ws->getColumnDimension('A')->setWidth(40); foreach (['B', 'C', 'D'] as $c) $ws->getColumnDimension($c)->setWidth(9); if (isset($cols[4])) $ws->getColumnDimension($cols[4])->setWidth(9); $ws->getColumnDimension('F')->setWidth(10); $ws->getColumnDimension('G')->setWidth(14); $ws->getColumnDimension('H')->setWidth(10);
        $r = 13; $overallSum = 0; $overallCount = 0;
        usort($this->enrolledClasses, function ($a, $b) {
            $catOrder = ['core' => 1, 'academic_elective' => 2, 'techpro_elective' => 3, 'work_immersion' => 4, 'field_experience_elective' => 5];
            $ca = $catOrder[$a['subject_category'] ?? 'core'] ?? 99; $cb = $catOrder[$b['subject_category'] ?? 'core'] ?? 99;
            if ($ca !== $cb) return $ca <=> $cb;
            return strcasecmp($a['class_name'] ?? '', $b['class_name'] ?? '');
        });
        $lastCategory = null; $processedSubjects = [];
        foreach ($this->enrolledClasses as $cls) {
            $cat = $cls['subject_category'] ?? 'core';
            $subjectKey = strtolower(trim($cls['class_name'] ?? ''));
            $combinedKey = SshsGradeCalculator::combinedSubjectKey($subjectKey);
            $isCombined = $combinedKey !== '';
            if ($isCombined) {
                $key = 'ec_mk';
                if (isset($processedSubjects[$key])) continue;
                $processedSubjects[$key] = true;
                $ecGrade = $this->gradesByClass[$cls['class_id']] ?? []; $mkClassId = $this->findCombinedPartner($cls['class_id']); $mkGrade = $mkClassId ? ($this->gradesByClass[$mkClassId] ?? []) : [];
                $combinedGrades = $this->mergeCombinedGrades($ecGrade, $mkGrade);
                $displayName = SshsGradeCalculator::combinedDisplayName();
            } else {
                if (isset($processedSubjects[$subjectKey])) continue;
                $processedSubjects[$subjectKey] = true;
                $combinedGrades = $this->gradesByClass[$cls['class_id']] ?? []; $displayName = $cls['class_name'] ?? '';
            }
            if ($cat !== $lastCategory) {
                $catLabel = SshsGradeCalculator::sectionHeaderLabel($cat);
                $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", $catLabel);
                $ws->getStyle("A{$r}")->applyFromArray(['font' => array_merge($bold, ['size' => 9, 'name' => $ar]), 'alignment' => $center]); $ws->getStyle("A{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA'); $ws->getStyle("A{$r}")->applyFromArray($borderThin); $r++; $lastCategory = $cat;
            }
            $termGrades = []; foreach ($termLabels as $tl) $termGrades[] = $combinedGrades[$tl] ?? null;
            $termCount = SshsGradeCalculator::subjectTermCount($cat, $this->gradingSystem);
            $effectiveTermGrades = array_slice($termGrades, 0, $termCount);
            $avg = SshsGradeCalculator::finalGrade($effectiveTermGrades); if ($avg !== null) { $overallSum += $avg; $overallCount++; }
            $level = SshsGradeCalculator::sf9Level($avg); $passed = $avg !== null && $avg >= 75;
            $ws->setCellValue("A{$r}", $displayName); $colIdx = 1;
            foreach ($termLabels as $i => $tl) { $g = $termGrades[$i] ?? null; $ws->setCellValue($cols[$colIdx] . $r, ($i < $termCount && $g !== null) ? (string)(int)$g : ($i < $termCount ? '' : '—')); $colIdx++; }
            $ws->setCellValue("F{$r}", $avg !== null ? (string)(int)$avg : ''); $ws->setCellValue("G{$r}", $level); $ws->setCellValue("H{$r}", $avg !== null ? ($passed ? 'Passed' : 'Failed') : '');
            foreach ($cols as $c) $ws->getStyle($c . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 9, 'name' => $ar], 'alignment' => $center])); $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT); $r++;
        }
        $gpa = $overallCount > 0 ? round($overallSum / $overallCount, 2) : null;
        $ws->mergeCells("A{$r}:E{$r}"); $ws->setCellValue("A{$r}", 'GENERAL AVERAGE'); $ws->getStyle("A{$r}")->applyFromArray(['font' => array_merge($bold, ['size' => 10, 'name' => $ar])]); $ws->getStyle("A{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $ws->setCellValue("F{$r}", $gpa !== null ? number_format($gpa, 2) : ''); $ws->setCellValue("G{$r}", SshsGradeCalculator::sf9Level($gpa)); $ws->setCellValue("H{$r}", $gpa !== null ? ($gpa >= 75 ? 'PROMOTED' : 'RETAINED') : '');
        foreach ($cols as $c) $ws->getStyle($c . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 10, 'name' => $ar], 'alignment' => $center])); $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT); $r += 2;
        $ws->mergeCells("A{$r}:H{$r}"); $ws->setCellValue("A{$r}", 'Level of Proficiency: O = Outstanding (90-100) | VS = Very Satisfactory (85-89) | S = Satisfactory (80-84) | FS = Fairly Satisfactory (75-79) | DNME = Did Not Meet Expectations (Below 75)'); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['size' => 8, 'name' => $ar], 'alignment' => $center]); $r++;
        $daysPresent = ($this->attendance['present'] ?? 0) + ($this->attendance['late'] ?? 0); $daysAbsent = $this->attendance['absent'] ?? 0; $daysLate = $this->attendance['late'] ?? 0;
        $ws->mergeCells("A{$r}:B{$r}"); $ws->setCellValue("A{$r}", "Days Present: {$daysPresent}"); $ws->mergeCells("C{$r}:D{$r}"); $ws->setCellValue("C{$r}", "Days Absent: {$daysAbsent}"); $ws->mergeCells("E{$r}:F{$r}"); $ws->setCellValue("E{$r}", "Days Tardy: {$daysLate}"); $ws->getStyle("A{$r}:H{$r}")->applyFromArray($borderThin); $ws->getStyle("A{$r}:H{$r}")->applyFromArray(['font' => ['size' => 9, 'name' => $ar], 'alignment' => $center]); $r += 2;
        $ws->mergeCells("A{$r}:B{$r}"); $ws->setCellValue("A{$r}", 'Adviser\'s Signature over Printed Name'); $ws->mergeCells("D{$r}:E{$r}"); $ws->setCellValue("D{$r}", 'Parent/Guardian Signature & Date Received'); $ws->mergeCells("G{$r}:H{$r}"); $ws->setCellValue("G{$r}", 'Principal\'s Signature over Printed Name'); $ws->getStyle("A{$r}:H{$r}")->applyFromArray(['font' => ['size' => 8, 'name' => $ar], 'alignment' => $center]); $r++;
        for ($i = 0; $i < 3; $i++, $r++) { foreach (['A', 'B', 'D', 'E', 'G', 'H'] as $c) $ws->getStyle($c . $r)->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]]]); }
        $ws->mergeCells("A{$r}:H{$r}"); $footerText = $this->gradingSystem === '4_quarter' ? 'Per DepEd Order No. 8, s. 2015 | DM 74, s. 2025 (SSHS) | BSHS-AMS Generated on ' : 'Per DepEd Order No. 8, s. 2015 | DO 009, s. 2026 (Three-Term Calendar) | DM 12, s. 2026 (SSHS) | BSHS-AMS Generated on ';
        $ws->setCellValue("A{$r}", $footerText . date('F j, Y \a\t g:i A')); $ws->getStyle("A{$r}")->applyFromArray(['font' => ['size' => 7, 'name' => $ar, 'italic' => true], 'alignment' => $center]);
        $ws->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4); $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT); $ws->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
        $writer = new Xlsx($sp); $writer->save($filePath); $sp->disconnectWorksheets();
        return true;
    }

    public function outputToBrowser(string $filename): void {
        $tmp = tempnam(sys_get_temp_dir(), 'sf9_') . '.xlsx';
        if ($this->export($tmp)) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"'); header('Content-Length: ' . filesize($tmp));
            readfile($tmp); unlink($tmp);
        }
        exit();
    }

    private function findCombinedPartner(int $classId): ?int {
        $subjectKey = '';
        foreach ($this->enrolledClasses as $cls) {
            if ((int)$cls['class_id'] === $classId) { $subjectKey = strtolower(trim($cls['class_name'] ?? '')); break; }
        }
        if ($subjectKey === '') return null;
        $isEc = str_contains($subjectKey, 'effective'); $isMk = str_contains($subjectKey, 'mabisang');
        if (!$isEc && !$isMk) return null;
        foreach ($this->enrolledClasses as $cls) {
            $name = strtolower(trim($cls['class_name'] ?? ''));
            if ((int)$cls['class_id'] === $classId) continue;
            if ($isEc && str_contains($name, 'mabisang')) return (int)$cls['class_id'];
            if ($isMk && str_contains($name, 'effective')) return (int)$cls['class_id'];
        }
        return null;
    }

    private function mergeCombinedGrades(array $ecGrades, array $mkGrades): array {
        $result = [];
        $allKeys = array_unique(array_merge(array_keys($ecGrades), array_keys($mkGrades)));
        foreach ($allKeys as $key) {
            $ec = $ecGrades[$key] ?? null; $mk = $mkGrades[$key] ?? null;
            if (in_array($key, ['Q1', 'Q2', 'Q3', 'Q4', 'Term1', 'Term2', 'Term3'], true)) { $result[$key] = SshsGradeCalculator::combineGrades($ec, $mk); }
            else { $result[$key] = $ec ?? $mk; }
        }
        return $result;
    }
}
