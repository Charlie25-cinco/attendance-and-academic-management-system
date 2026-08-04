<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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
