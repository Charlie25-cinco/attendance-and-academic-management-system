<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use BshsAms\Xlsx\SimpleXlsxTemplateEditor;
use PDO;
use RuntimeException;

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
        $editor->clearRange('A', 'AE', 12, 120);

        $row = 12;
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
