<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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
        $default = realpath(dirname(__DIR__, 2) . '/resources/deped_templates/SF 5 Report on Promotion and Learning Progress _ Achievement_0 (1).xlsx');
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
