<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use BshsAms\Grade\SshsGradeCalculator;

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
    public function setAcademicYear(string $ay): void { 
        $this->academicYear = $ay; 
        $this->gradingSystem = SshsGradeCalculator::gradingSystem($ay); 
    }
    public function setAttendance(array $attendance): void { $this->attendance = $attendance; }
    public function setCombinedSubjects(array $combined): void { $this->combinedSubjects = $combined; }

    public function export(string $filePath): bool {
        $sp = new Spreadsheet();

        $bold = ['bold' => true];
        $borderThin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];
        $borderBottom = ['borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]]];
        $center = ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER];
        $left = ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER];
        $right = ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER];
        $fontName = 'Arial';

        $is3Term = ($this->gradingSystem === '3_term');

        // ==========================================
        // PAGE 1: Front / Cover Page & Attendance
        // ==========================================
        $ws1 = $sp->getActiveSheet();
        $ws1->setTitle('Page 1 (Cover & Attendance)');
        $ws1->setShowGridlines(true);

        $ws1->getColumnDimension('A')->setWidth(18);
        foreach (['B','C','D','E','F','G','H','I','J','K','L'] as $c) {
            $ws1->getColumnDimension($c)->setWidth(4);
        }
        $ws1->getColumnDimension('M')->setWidth(6);
        $ws1->getColumnDimension('N')->setWidth(3); // Gap

        $ws1->getColumnDimension('O')->setWidth(12);
        $ws1->getColumnDimension('P')->setWidth(4);
        $ws1->getColumnDimension('Q')->setWidth(3);
        $lrnCols = ['R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC'];
        foreach ($lrnCols as $c) {
            $ws1->getColumnDimension($c)->setWidth(2.5);
        }

        // --- LEFT PANEL: REPORT ON ATTENDANCE ---
        $ws1->getRowDimension(1)->setRowHeight(20);
        $ws1->mergeCells('A1:M1');
        $ws1->setCellValue('A1', 'REPORT ON ATTENDANCE');
        $ws1->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

        $months = ['Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'Total'];
        $cols = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'];
        $ws1->setCellValue('A2', '');
        $ws1->getRowDimension(2)->setRowHeight(18);
        foreach ($months as $idx => $m) {
            $ws1->setCellValue($cols[$idx] . '2', $m);
            $ws1->getStyle($cols[$idx] . '2')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 7, 'name' => $fontName], 'alignment' => $center]));
        }

        $ws1->getRowDimension(3)->setRowHeight(22);
        $ws1->setCellValue('A3', "No. of\nSchool Days");
        $ws1->getStyle('A3')->getAlignment()->setWrapText(true);
        $ws1->getStyle('A3')->applyFromArray(array_merge($borderThin, ['font' => ['size' => 7, 'name' => $fontName], 'alignment' => $left]));

        $ws1->getRowDimension(4)->setRowHeight(22);
        $ws1->setCellValue('A4', "No. of Days\nPresent");
        $ws1->getStyle('A4')->getAlignment()->setWrapText(true);
        $ws1->getStyle('A4')->applyFromArray(array_merge($borderThin, ['font' => ['size' => 7, 'name' => $fontName], 'alignment' => $left]));

        $ws1->getRowDimension(5)->setRowHeight(22);
        $ws1->setCellValue('A5', "No. of Days\nAbsent");
        $ws1->getStyle('A5')->getAlignment()->setWrapText(true);
        $ws1->getStyle('A5')->applyFromArray(array_merge($borderThin, ['font' => ['size' => 7, 'name' => $fontName], 'alignment' => $left]));

        $totalPresent = ($this->attendance['present'] ?? 0) + ($this->attendance['late'] ?? 0);
        $totalAbsent = $this->attendance['absent'] ?? 0;
        $totalDays = $totalPresent + $totalAbsent;

        foreach ($cols as $c) {
            $isTotal = $c === 'M';
            $ws1->setCellValue($c . '3', $isTotal ? ($totalDays > 0 ? (string)$totalDays : '200') : '');
            $ws1->setCellValue($c . '4', $isTotal ? ($totalPresent > 0 ? (string)$totalPresent : '') : '');
            $ws1->setCellValue($c . '5', $isTotal ? ($totalAbsent > 0 ? (string)$totalAbsent : '') : '');
            $ws1->getStyle($c . '3')->applyFromArray($borderThin);
            $ws1->getStyle($c . '4')->applyFromArray($borderThin);
            $ws1->getStyle($c . '5')->applyFromArray($borderThin);
        }

        // Parent / Guardian Signature Block
        $ws1->getRowDimension(7)->setRowHeight(20);
        $ws1->mergeCells('A7:M7');
        $ws1->setCellValue('A7', 'PARENT / GUARDIAN\'S SIGNATURE');
        $ws1->getStyle('A7')->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $left]);

        $termLabels = $is3Term ? ['1st Term', '2nd Term', '3rd Term'] : ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];
        $row = 8;
        foreach ($termLabels as $lbl) {
            $ws1->getRowDimension($row)->setRowHeight(20);
            $ws1->setCellValue("A{$row}", $lbl);
            $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
            $ws1->mergeCells("B{$row}:M{$row}");
            $ws1->getStyle("B{$row}:M{$row}")->applyFromArray($borderBottom);
            $row++;
        }

        // Certificate of Transfer Block
        $row += 1;
        $ws1->getRowDimension($row)->setRowHeight(20);
        $ws1->mergeCells("A{$row}:M{$row}");
        $ws1->setCellValue("A{$row}", 'Certificate of Transfer');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(20);
        $ws1->setCellValue("A{$row}", 'Admitted to Grade:');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
        $ws1->mergeCells("B{$row}:D{$row}");
        $ws1->getStyle("B{$row}:D{$row}")->applyFromArray($borderBottom);
        $ws1->mergeCells("E{$row}:G{$row}");
        $ws1->setCellValue("E{$row}", 'Section:');
        $ws1->getStyle("E{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $right]);
        $ws1->mergeCells("H{$row}:M{$row}");
        $ws1->getStyle("H{$row}:M{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(20);
        $ws1->mergeCells("A{$row}:E{$row}");
        $ws1->setCellValue("A{$row}", 'Eligibility for Admission to Grade:');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
        $ws1->mergeCells("F{$row}:M{$row}");
        $ws1->getStyle("F{$row}:M{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("A{$row}:M{$row}");
        $ws1->setCellValue("A{$row}", 'Approved:');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);

        $row += 2;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("A{$row}:F{$row}");
        $ws1->getStyle("A{$row}:F{$row}")->applyFromArray($borderBottom);
        $ws1->mergeCells("H{$row}:M{$row}");
        $ws1->getStyle("H{$row}:M{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("A{$row}:F{$row}");
        $ws1->setCellValue("A{$row}", 'School Head');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'italic' => true, 'name' => $fontName], 'alignment' => $center]);
        $ws1->mergeCells("H{$row}:M{$row}");
        $ws1->setCellValue("H{$row}", 'Adviser');
        $ws1->getStyle("H{$row}")->applyFromArray(['font' => ['size' => 8, 'italic' => true, 'name' => $fontName], 'alignment' => $center]);

        // Cancellation of Eligibility to Transfer
        $row += 2;
        $ws1->getRowDimension($row)->setRowHeight(20);
        $ws1->mergeCells("A{$row}:M{$row}");
        $ws1->setCellValue("A{$row}", 'Cancellation of Eligibility to Transfer');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $center]);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(20);
        $ws1->setCellValue("A{$row}", 'Admitted in:');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
        $ws1->mergeCells("B{$row}:M{$row}");
        $ws1->getStyle("B{$row}:M{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(20);
        $ws1->setCellValue("A{$row}", 'Date:');
        $ws1->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
        $ws1->mergeCells("B{$row}:F{$row}");
        $ws1->getStyle("B{$row}:F{$row}")->applyFromArray($borderBottom);
        $ws1->mergeCells("H{$row}:M{$row}");
        $ws1->getStyle("H{$row}:M{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("H{$row}:M{$row}");
        $ws1->setCellValue("H{$row}", 'School Head');
        $ws1->getStyle("H{$row}")->applyFromArray(['font' => ['size' => 8, 'italic' => true, 'name' => $fontName], 'alignment' => $center]);

        // --- RIGHT PANEL: COVER PAGE (SF9-SHS MATATAG) ---
        $ws1->setCellValue('O1', 'SF9-SHS');
        $ws1->getStyle('O1')->applyFromArray(['font' => ['size' => 8, 'italic' => true, 'name' => $fontName]]);

        $ws1->setCellValue('Q1', 'LRN');
        $ws1->getStyle('Q1')->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $right]);

        // 12-box LRN Grid (R1 to AC1)
        $lrnStr = str_pad((string)($this->student['lrn'] ?? ''), 12, ' ', STR_PAD_RIGHT);
        foreach ($lrnCols as $i => $lc) {
            $ws1->setCellValue($lc . '1', substr($lrnStr, $i, 1));
            $ws1->getStyle($lc . '1')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $center]));
        }

        $ws1->getRowDimension(3)->setRowHeight(16);
        $ws1->mergeCells('O3:AC3');
        $ws1->setCellValue('O3', 'Republic of the Philippines');
        $ws1->getStyle('O3')->applyFromArray(['font' => ['size' => 9, 'name' => $fontName], 'alignment' => $center]);

        $ws1->getRowDimension(4)->setRowHeight(18);
        $ws1->mergeCells('O4:AC4');
        $ws1->setCellValue('O4', 'DEPARTMENT OF EDUCATION');
        $ws1->getStyle('O4')->applyFromArray(['font' => ['bold' => true, 'size' => 11, 'name' => $fontName], 'alignment' => $center]);

        $ws1->getRowDimension(5)->setRowHeight(16);
        $ws1->mergeCells('O5:AC5');
        $ws1->setCellValue('O5', 'Region X');
        $ws1->getStyle('O5')->applyFromArray(['font' => ['size' => 9, 'name' => $fontName], 'alignment' => $center]);

        $ws1->getRowDimension(6)->setRowHeight(16);
        $ws1->mergeCells('O6:AC6');
        $ws1->setCellValue('O6', 'DIVISION OF MISAMIS ORIENTAL');
        $ws1->getStyle('O6')->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

        $ws1->getRowDimension(7)->setRowHeight(18);
        $ws1->mergeCells('O7:AC7');
        $ws1->setCellValue('O7', 'BALINGASAG SENIOR HIGH SCHOOL');
        $ws1->getStyle('O7')->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'name' => $fontName], 'alignment' => $center]);

        $ws1->getRowDimension(8)->setRowHeight(14);
        $ws1->mergeCells('O8:AC8');
        $ws1->setCellValue('O8', 'School');
        $ws1->getStyle('O8')->applyFromArray(['font' => ['size' => 8, 'italic' => true, 'name' => $fontName], 'alignment' => $center]);

        // Student Metadata Block (Clean Cell Underlines & Shrink-to-Fit)
        $row = 10;
        $lastName = $this->student['last_name'] ?? '';
        $firstName = $this->student['first_name'] ?? '';
        $middleName = $this->student['middle_name'] ?? '';

        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->setCellValue("O{$row}", 'Name :');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName]]);
        $ws1->mergeCells("P{$row}:AC{$row}");
        $ws1->setCellValue("P{$row}", $lastName . ', ' . $firstName . ' ' . $middleName);
        $ws1->getStyle("P{$row}:AC{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 9, 'name' => $fontName]]));

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->setCellValue("O{$row}", 'Age :');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName]]);
        $ws1->mergeCells("P{$row}:Q{$row}");
        $ws1->setCellValue("P{$row}", (string)($this->student['age'] ?? '16'));
        $ws1->getStyle("P{$row}:Q{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 9, 'name' => $fontName], 'alignment' => $center]));

        $ws1->setCellValue("S{$row}", 'Sex :');
        $ws1->getStyle("S{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $right]);
        $ws1->mergeCells("T{$row}:AC{$row}");
        $ws1->setCellValue("T{$row}", ucfirst($this->student['sex'] ?? 'Male'));
        $ws1->getStyle("T{$row}:AC{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 9, 'name' => $fontName]]));

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->setCellValue("O{$row}", 'Grade :');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName]]);
        $ws1->mergeCells("P{$row}:Q{$row}");
        $ws1->setCellValue("P{$row}", (string)($this->student['grade_level'] ?? '11'));
        $ws1->getStyle("P{$row}:Q{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 9, 'name' => $fontName], 'alignment' => $center]));

        $ws1->mergeCells("R{$row}:U{$row}");
        $ws1->setCellValue("R{$row}", 'Section :');
        $ws1->getStyle("R{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $right]);
        $ws1->mergeCells("V{$row}:AC{$row}");
        $ws1->setCellValue("V{$row}", (string)($this->student['section'] ?? 'Amethyst'));
        $ws1->getStyle("V{$row}:AC{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 9, 'name' => $fontName]]));

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->setCellValue("O{$row}", 'Curriculum :');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName]]);
        $ws1->mergeCells("Q{$row}:AC{$row}");
        $ws1->setCellValue("Q{$row}", $is3Term ? 'MATATAG / Strengthened SHS Curriculum' : 'K to 12 Basic Education Curriculum');
        $ws1->getStyle("Q{$row}:AC{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 8, 'name' => $fontName]]));
        $ws1->getStyle("Q{$row}:AC{$row}")->getAlignment()->setShrinkToFit(true);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->setCellValue("O{$row}", 'School Year :');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName]]);
        $ws1->mergeCells("Q{$row}:AC{$row}");
        $ws1->setCellValue("Q{$row}", $this->academicYear);
        $ws1->getStyle("Q{$row}:AC{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 9, 'name' => $fontName]]));

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->setCellValue("O{$row}", 'Track / Strand :');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]);
        $ws1->mergeCells("Q{$row}:AC{$row}");
        $ws1->setCellValue("Q{$row}", 'Academic / ' . ($this->student['track'] ?? 'General Academic Strand'));
        $ws1->getStyle("Q{$row}:AC{$row}")->applyFromArray(array_merge($borderBottom, ['font' => ['bold' => false, 'size' => 8, 'name' => $fontName]]));

        $row += 2;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("O{$row}:AC{$row}");
        $ws1->setCellValue("O{$row}", 'Dear Parent/Guardian,');
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['italic' => true, 'size' => 9, 'name' => $fontName]]);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(40);
        $ws1->mergeCells("O{$row}:AC{$row}");
        $ws1->setCellValue("O{$row}", '    This report card shows the ability and progress your child has made in the different learning areas as well as his/her core values.');
        $ws1->getStyle("O{$row}")->getAlignment()->setWrapText(true);
        $ws1->getStyle("O{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'name' => $fontName]]);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(28);
        $ws1->mergeCells("O{$row}:AC{$row}");
        $ws1->setCellValue("O{$row}", '    The school welcomes you should you desire to know more about your child\'s progress.');
        $ws1->getStyle("O{$row}")->getAlignment()->setWrapText(true);
        $ws1->getStyle("O{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $ws1->getStyle("O{$row}")->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'name' => $fontName]]);

        $row += 3;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("W{$row}:AC{$row}");
        $ws1->getStyle("W{$row}:AC{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("W{$row}:AC{$row}");
        $ws1->setCellValue("W{$row}", 'Adviser');
        $ws1->getStyle("W{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]);

        $row += 2;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("S{$row}:Y{$row}");
        $ws1->getStyle("S{$row}:Y{$row}")->applyFromArray($borderBottom);

        $row++;
        $ws1->getRowDimension($row)->setRowHeight(18);
        $ws1->mergeCells("S{$row}:Y{$row}");
        $ws1->setCellValue("S{$row}", 'Principal IV');
        $ws1->getStyle("S{$row}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]);

        $ws1->getPageSetup()->setFitToPage(true);
        $ws1->getPageSetup()->setFitToWidth(1);
        $ws1->getPageSetup()->setFitToHeight(1);
        $ws1->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $ws1->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $ws1->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.3)->setRight(0.3);

        // ==========================================
        // PAGE 2: Learner's Progress & Observed Values (Inside Booklet)
        // ==========================================
        $ws2 = $sp->createSheet();
        $ws2->setTitle('Page 2 (Grades & Values)');
        $ws2->setShowGridlines(true);

        if ($is3Term) {
            // DepEd MATATAG 3-TERM LAYOUT
            $ws2->getColumnDimension('A')->setWidth(32);
            $ws2->getColumnDimension('B')->setWidth(5);
            $ws2->getColumnDimension('C')->setWidth(5);
            $ws2->getColumnDimension('D')->setWidth(5);
            $ws2->getColumnDimension('E')->setWidth(11);
            $ws2->getColumnDimension('F')->setWidth(11);
            $ws2->getColumnDimension('G')->setWidth(3); // Gap
            $ws2->getColumnDimension('H')->setWidth(16);
            $ws2->getColumnDimension('I')->setWidth(26);
            $ws2->getColumnDimension('J')->setWidth(6);
            $ws2->getColumnDimension('K')->setWidth(7);
            $ws2->getColumnDimension('L')->setWidth(9);

            // Left Panel Header
            $ws2->getRowDimension(1)->setRowHeight(20);
            $ws2->mergeCells('A1:F1');
            $ws2->setCellValue('A1', 'LEARNER\'S PROGRESS REPORT CARD');
            $ws2->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

            $ws2->getRowDimension(2)->setRowHeight(16);
            $ws2->getRowDimension(3)->setRowHeight(16);

            $ws2->mergeCells('A2:A3');
            $ws2->setCellValue('A2', 'Subjects');
            $ws2->getStyle('A2:A3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('B2:D2');
            $ws2->setCellValue('B2', 'Term');
            $ws2->getStyle('B2:D2')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->setCellValue('B3', '1');
            $ws2->setCellValue('C3', '2');
            $ws2->setCellValue('D3', '3');
            foreach (['B', 'C', 'D'] as $c) {
                $ws2->getStyle($c . '3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            $ws2->mergeCells('E2:E3');
            $ws2->setCellValue('E2', "Final Grade");
            $ws2->getStyle('E2:E3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('F2:F3');
            $ws2->setCellValue('F2', "Remarks");
            $ws2->getStyle('F2:F3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $r = 4;
            $ws2->getRowDimension($r)->setRowHeight(18);
            $ws2->mergeCells("A{$r}:F{$r}");
            $ws2->setCellValue("A{$r}", 'Core Subjects');
            $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]));
            $r++;

            $coreClasses = array_filter($this->enrolledClasses, fn($c) => ($c['subject_category'] ?? 'core') === 'core');
            $appliedClasses = array_filter($this->enrolledClasses, fn($c) => ($c['subject_category'] ?? 'core') !== 'core');

            $allFinalGrades = [];
            foreach ($coreClasses as $cls) {
                $ws2->getRowDimension($r)->setRowHeight(18);
                $name = $cls['class_name'] ?? '';
                $t1 = $this->gradesByClass[$cls['class_id']]['Term1'] ?? null;
                $t2 = $this->gradesByClass[$cls['class_id']]['Term2'] ?? null;
                $t3 = $this->gradesByClass[$cls['class_id']]['Term3'] ?? null;
                $fg = SshsGradeCalculator::finalGrade([$t1, $t2, $t3]);
                if ($fg !== null) { $allFinalGrades[] = $fg; }

                $ws2->setCellValue("A{$r}", $name);
                $ws2->setCellValue("B{$r}", $t1 !== null ? (string)(int)$t1 : '');
                $ws2->setCellValue("C{$r}", $t2 !== null ? (string)(int)$t2 : '');
                $ws2->setCellValue("D{$r}", $t3 !== null ? (string)(int)$t3 : '');
                $ws2->setCellValue("E{$r}", $fg !== null ? (string)(int)$fg : '');
                $ws2->setCellValue("F{$r}", $fg !== null ? ($fg >= 75 ? 'Passed' : 'Failed') : '');

                $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName]]));
                foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
                    $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                }
                $r++;
            }

            if (!empty($appliedClasses)) {
                $ws2->getRowDimension($r)->setRowHeight(18);
                $ws2->mergeCells("A{$r}:F{$r}");
                $ws2->setCellValue("A{$r}", 'Applied and Specialized Subjects');
                $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]));
                $r++;

                foreach ($appliedClasses as $cls) {
                    $ws2->getRowDimension($r)->setRowHeight(18);
                    $name = $cls['class_name'] ?? '';
                    $t1 = $this->gradesByClass[$cls['class_id']]['Term1'] ?? null;
                    $t2 = $this->gradesByClass[$cls['class_id']]['Term2'] ?? null;
                    $t3 = $this->gradesByClass[$cls['class_id']]['Term3'] ?? null;
                    $fg = SshsGradeCalculator::finalGrade([$t1, $t2, $t3]);
                    if ($fg !== null) { $allFinalGrades[] = $fg; }

                    $ws2->setCellValue("A{$r}", $name);
                    $ws2->setCellValue("B{$r}", $t1 !== null ? (string)(int)$t1 : '');
                    $ws2->setCellValue("C{$r}", $t2 !== null ? (string)(int)$t2 : '');
                    $ws2->setCellValue("D{$r}", $t3 !== null ? (string)(int)$t3 : '');
                    $ws2->setCellValue("E{$r}", $fg !== null ? (string)(int)$fg : '');
                    $ws2->setCellValue("F{$r}", $fg !== null ? ($fg >= 75 ? 'Passed' : 'Failed') : '');

                    $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName]]));
                    foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
                        $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                    }
                    $r++;
                }
            }

            $ws2->getRowDimension($r)->setRowHeight(20);
            $overallGpa = !empty($allFinalGrades) ? round(array_sum($allFinalGrades) / count($allFinalGrades), 2) : null;
            $ws2->setCellValue("A{$r}", 'General Average for the School Year');
            $ws2->mergeCells("B{$r}:D{$r}");
            $ws2->setCellValue("E{$r}", $overallGpa !== null ? number_format($overallGpa, 2) : '');
            $ws2->setCellValue("F{$r}", $overallGpa !== null ? ($overallGpa >= 75 ? 'Passed' : 'Failed') : '');
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            // --- RIGHT PANEL: REPORT ON LEARNER'S OBSERVED VALUES ---
            $ws2->getRowDimension(1)->setRowHeight(20);
            $ws2->mergeCells('H1:L1');
            $ws2->setCellValue('H1', 'REPORT ON LEARNER\'S OBSERVED VALUES');
            $ws2->getStyle('H1')->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

            $ws2->mergeCells('H2:H3');
            $ws2->setCellValue('H2', 'Core Values');
            $ws2->getStyle('H2:H3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('I2:I3');
            $ws2->setCellValue('I2', 'Behavior Statements');
            $ws2->getStyle('I2:I3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('J2:L2');
            $ws2->setCellValue('J2', 'Term');
            $ws2->getStyle('J2:L2')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->setCellValue('J3', '1');
            $ws2->setCellValue('K3', '2');
            $ws2->setCellValue('L3', '3');
            foreach (['J', 'K', 'L'] as $col) {
                $ws2->getStyle($col . '3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            // Core Values Rows
            $ws2->mergeCells('H4:H5');
            $ws2->setCellValue('H4', "1. Maka-Diyos");
            $ws2->getStyle('H4:H5')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('I4', "Expresses one's spiritual beliefs while respecting spiritual beliefs of others");
            $ws2->setCellValue('I5', "Shows adherence to ethical principles by upholding truth in all undertakings");

            $ws2->mergeCells('H6:H7');
            $ws2->setCellValue('H6', "2. Makatao");
            $ws2->getStyle('H6:H7')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('I6', "Is sensitive to individual, social, and cultural differences");
            $ws2->setCellValue('I7', "Demonstrates contributions toward solidarity");

            $ws2->setCellValue('H8', "3. Makakalikasan");
            $ws2->getStyle('H8')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('I8', "Cares for the environment and utilizes resources wisely");

            $ws2->mergeCells('H9:H10');
            $ws2->setCellValue('H9', "4. Makabansa");
            $ws2->getStyle('H9:H10')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('I9', "Demonstrates pride in being a Filipino");
            $ws2->setCellValue('I10', "Demonstrates appropriate behavior in community and country");

            for ($vrRow = 4; $vrRow <= 10; $vrRow++) {
                $ws2->getRowDimension($vrRow)->setRowHeight(22);
                $ws2->getStyle('I' . $vrRow)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 7, 'name' => $fontName]]));
                $ws2->getStyle('I' . $vrRow)->getAlignment()->setWrapText(true);
                foreach (['J', 'K', 'L'] as $col) {
                    $ws2->setCellValue($col . $vrRow, 'SO');
                    $ws2->getStyle($col . $vrRow)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                }
            }

            // Observed Values Marking Legend
            $vr = 12;
            $ws2->getRowDimension($vr)->setRowHeight(18);
            $ws2->mergeCells("H{$vr}:I{$vr}");
            $ws2->setCellValue("H{$vr}", "Observed Values Marking");
            $ws2->getStyle("H{$vr}:I{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]);

            $ws2->mergeCells("J{$vr}:L{$vr}");
            $ws2->setCellValue("J{$vr}", "Non-numerical Rating");
            $ws2->getStyle("J{$vr}:L{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]);
            $ws2->getStyle("J{$vr}:L{$vr}")->getAlignment()->setShrinkToFit(true);

            $markings = [
                ['AO', 'Always Observed'],
                ['SO', 'Sometimes Observed'],
                ['RO', 'Rarely Observed'],
                ['NO', 'Not Observed']
            ];

            foreach ($markings as $m) {
                $vr++;
                $ws2->getRowDimension($vr)->setRowHeight(16);
                $ws2->setCellValue("H{$vr}", $m[0]);
                $ws2->mergeCells("I{$vr}:L{$vr}");
                $ws2->setCellValue("I{$vr}", $m[1]);
                $ws2->getStyle("H{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]);
                $ws2->getStyle("I{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
            }

            // Learner Progress and Achievement Grading Scale Legend
            $vr += 2;
            $ws2->getRowDimension($vr)->setRowHeight(18);
            $ws2->mergeCells("H{$vr}:L{$vr}");
            $ws2->setCellValue("H{$vr}", 'Learner Progress and Achievement');
            $ws2->getStyle("H{$vr}:L{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $center]);

            $scaleHeaderRow = $vr + 1;
            $ws2->getRowDimension($scaleHeaderRow)->setRowHeight(18);
            $ws2->mergeCells("H{$scaleHeaderRow}:I{$scaleHeaderRow}");
            $ws2->setCellValue("H{$scaleHeaderRow}", 'Descriptors');
            $ws2->mergeCells("J{$scaleHeaderRow}:K{$scaleHeaderRow}");
            $ws2->setCellValue("J{$scaleHeaderRow}", 'Grading Scale');
            $ws2->setCellValue("L{$scaleHeaderRow}", 'Remarks');
            $ws2->getStyle("H{$scaleHeaderRow}:L{$scaleHeaderRow}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->getStyle("H{$scaleHeaderRow}:L{$scaleHeaderRow}")->getAlignment()->setShrinkToFit(true);

            $scale = [
                ['Outstanding', '90 - 100', 'Passed'],
                ['Very Satisfactory', '85 - 89', 'Passed'],
                ['Satisfactory', '80 - 84', 'Passed'],
                ['Fairly Satisfactory', '75 - 79', 'Passed'],
                ['Did Not Meet Expectations', 'Below 75', 'Failed']
            ];

            $vr = $scaleHeaderRow + 1;
            foreach ($scale as $s) {
                $ws2->getRowDimension($vr)->setRowHeight(16);
                $ws2->mergeCells("H{$vr}:I{$vr}");
                $ws2->setCellValue("H{$vr}", $s[0]);
                $ws2->mergeCells("J{$vr}:K{$vr}");
                $ws2->setCellValue("J{$vr}", $s[1]);
                $ws2->setCellValue("L{$vr}", $s[2]);

                $ws2->getStyle("H{$vr}:L{$vr}")->applyFromArray($borderThin);
                $ws2->getStyle("H{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
                $ws2->getStyle("J{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]);
                $ws2->getStyle("L{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]);
                $vr++;
            }

        } else {
            // LEGACY 4-QUARTER 2-SEMESTER LAYOUT
            $ws2->getColumnDimension('A')->setWidth(30);
            $ws2->getColumnDimension('B')->setWidth(7);
            $ws2->getColumnDimension('C')->setWidth(7);
            $ws2->getColumnDimension('D')->setWidth(14);
            $ws2->getColumnDimension('E')->setWidth(2);
            $ws2->getColumnDimension('F')->setWidth(14);
            $ws2->getColumnDimension('G')->setWidth(24);
            $ws2->getColumnDimension('H')->setWidth(6);
            $ws2->getColumnDimension('I')->setWidth(7);
            $ws2->getColumnDimension('J')->setWidth(6);
            $ws2->getColumnDimension('K')->setWidth(7);

            $ws2->mergeCells('A1:D1');
            $ws2->setCellValue('A1', 'LEARNER\'S PROGRESS REPORT CARD');
            $ws2->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

            $ws2->mergeCells('A2:D2');
            $ws2->setCellValue('A2', 'First Semester');
            $ws2->getStyle('A2')->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $left]);

            $ws2->mergeCells('A3:A4');
            $ws2->setCellValue('A3', 'Subjects');
            $ws2->getStyle('A3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('B3:C3');
            $ws2->setCellValue('B3', 'Quarter');
            $ws2->getStyle('B3:C3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->setCellValue('B4', '1');
            $ws2->setCellValue('C4', '2');
            $ws2->getStyle('B4')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->getStyle('C4')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('D3:D4');
            $ws2->setCellValue('D3', "Semester\nFinal Grade");
            $ws2->getStyle('D3')->getAlignment()->setWrapText(true);
            $ws2->getStyle('D3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $r = 5;
            $ws2->mergeCells("A{$r}:D{$r}");
            $ws2->setCellValue("A{$r}", 'Core Subjects');
            $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]));
            $r++;

            $coreClasses = array_filter($this->enrolledClasses, fn($c) => ($c['subject_category'] ?? 'core') === 'core');
            $appliedClasses = array_filter($this->enrolledClasses, fn($c) => ($c['subject_category'] ?? 'core') !== 'core');

            $sem1Sum = 0; $sem1Count = 0;
            foreach ($coreClasses as $cls) {
                $name = $cls['class_name'] ?? '';
                $g1 = $this->gradesByClass[$cls['class_id']]['Term1'] ?? null;
                $g2 = $this->gradesByClass[$cls['class_id']]['Term2'] ?? null;
                $fg = SshsGradeCalculator::finalGrade([$g1, $g2]);
                if ($fg !== null) { $sem1Sum += $fg; $sem1Count++; }

                $ws2->setCellValue("A{$r}", $name);
                $ws2->setCellValue("B{$r}", $g1 !== null ? (string)(int)$g1 : '');
                $ws2->setCellValue("C{$r}", $g2 !== null ? (string)(int)$g2 : '');
                $ws2->setCellValue("D{$r}", $fg !== null ? (string)(int)$fg : '');

                $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName]]));
                foreach (['B', 'C', 'D'] as $col) {
                    $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                }
                $r++;
            }

            if (!empty($appliedClasses)) {
                $ws2->mergeCells("A{$r}:D{$r}");
                $ws2->setCellValue("A{$r}", 'Applied and Specialized Subjects');
                $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]));
                $r++;

                foreach ($appliedClasses as $cls) {
                    $name = $cls['class_name'] ?? '';
                    $g1 = $this->gradesByClass[$cls['class_id']]['Term1'] ?? null;
                    $g2 = $this->gradesByClass[$cls['class_id']]['Term2'] ?? null;
                    $fg = SshsGradeCalculator::finalGrade([$g1, $g2]);
                    if ($fg !== null) { $sem1Sum += $fg; $sem1Count++; }

                    $ws2->setCellValue("A{$r}", $name);
                    $ws2->setCellValue("B{$r}", $g1 !== null ? (string)(int)$g1 : '');
                    $ws2->setCellValue("C{$r}", $g2 !== null ? (string)(int)$g2 : '');
                    $ws2->setCellValue("D{$r}", $fg !== null ? (string)(int)$fg : '');

                    $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName]]));
                    foreach (['B', 'C', 'D'] as $col) {
                        $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                    }
                    $r++;
                }
            }

            $gpa1 = $sem1Count > 0 ? round($sem1Sum / $sem1Count, 2) : null;
            $ws2->setCellValue("A{$r}", 'General Average for the Semester');
            $ws2->mergeCells("B{$r}:C{$r}");
            $ws2->setCellValue("D{$r}", $gpa1 !== null ? number_format($gpa1, 2) : '');
            foreach (['A', 'B', 'C', 'D'] as $col) {
                $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            // Second Semester Table
            $r += 2;
            $ws2->mergeCells("A{$r}:D{$r}");
            $ws2->setCellValue("A{$r}", 'Second Semester');
            $ws2->getStyle("A{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $left]);
            $r++;

            $startSem2Header = $r;
            $ws2->mergeCells("A{$startSem2Header}:A" . ($startSem2Header + 1));
            $ws2->setCellValue("A{$startSem2Header}", 'Subjects');
            $ws2->getStyle("A{$startSem2Header}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells("B{$startSem2Header}:C{$startSem2Header}");
            $ws2->setCellValue("B{$startSem2Header}", 'Quarter');
            $ws2->getStyle("B{$startSem2Header}:C{$startSem2Header}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $subSem2 = $startSem2Header + 1;
            $ws2->setCellValue("B{$subSem2}", '3');
            $ws2->setCellValue("C{$subSem2}", '4');
            $ws2->getStyle("B{$subSem2}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->getStyle("C{$subSem2}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells("D{$startSem2Header}:D{$subSem2}");
            $ws2->setCellValue("D{$startSem2Header}", "Semester\nFinal Grade");
            $ws2->getStyle("D{$startSem2Header}")->getAlignment()->setWrapText(true);
            $ws2->getStyle("D{$startSem2Header}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $r = $subSem2 + 1;
            $ws2->mergeCells("A{$r}:D{$r}");
            $ws2->setCellValue("A{$r}", 'Core Subjects');
            $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]));
            $r++;

            $sem2Sum = 0; $sem2Count = 0;
            foreach ($coreClasses as $cls) {
                $name = $cls['class_name'] ?? '';
                $g3 = $this->gradesByClass[$cls['class_id']]['Term3'] ?? null;
                $g4 = $this->gradesByClass[$cls['class_id']]['Term4'] ?? null;
                $fg = SshsGradeCalculator::finalGrade([$g3, $g4]);
                if ($fg !== null) { $sem2Sum += $fg; $sem2Count++; }

                $ws2->setCellValue("A{$r}", $name);
                $ws2->setCellValue("B{$r}", $g3 !== null ? (string)(int)$g3 : '');
                $ws2->setCellValue("C{$r}", $g4 !== null ? (string)(int)$g4 : '');
                $ws2->setCellValue("D{$r}", $fg !== null ? (string)(int)$fg : '');

                $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName]]));
                foreach (['B', 'C', 'D'] as $col) {
                    $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                }
                $r++;
            }

            if (!empty($appliedClasses)) {
                $ws2->mergeCells("A{$r}:D{$r}");
                $ws2->setCellValue("A{$r}", 'Applied and Specialized Subjects');
                $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]));
                $r++;

                foreach ($appliedClasses as $cls) {
                    $name = $cls['class_name'] ?? '';
                    $g3 = $this->gradesByClass[$cls['class_id']]['Term3'] ?? null;
                    $g4 = $this->gradesByClass[$cls['class_id']]['Term4'] ?? null;
                    $fg = SshsGradeCalculator::finalGrade([$g3, $g4]);
                    if ($fg !== null) { $sem2Sum += $fg; $sem2Count++; }

                    $ws2->setCellValue("A{$r}", $name);
                    $ws2->setCellValue("B{$r}", $g3 !== null ? (string)(int)$g3 : '');
                    $ws2->setCellValue("C{$r}", $g4 !== null ? (string)(int)$g4 : '');
                    $ws2->setCellValue("D{$r}", $fg !== null ? (string)(int)$fg : '');

                    $ws2->getStyle("A{$r}")->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName]]));
                    foreach (['B', 'C', 'D'] as $col) {
                        $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                    }
                    $r++;
                }
            }

            $gpa2 = $sem2Count > 0 ? round($sem2Sum / $sem2Count, 2) : null;
            $ws2->setCellValue("A{$r}", 'General Average for the Semester');
            $ws2->mergeCells("B{$r}:C{$r}");
            $ws2->setCellValue("D{$r}", $gpa2 !== null ? number_format($gpa2, 2) : '');
            foreach (['A', 'B', 'C', 'D'] as $col) {
                $ws2->getStyle($col . $r)->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            // Right Panel (Observed Values)
            $ws2->mergeCells('F1:K1');
            $ws2->setCellValue('F1', 'REPORT ON LEARNER\'S OBSERVED VALUES');
            $ws2->getStyle('F1')->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'name' => $fontName], 'alignment' => $center]);

            $ws2->mergeCells('F2:F3');
            $ws2->setCellValue('F2', 'Core Values');
            $ws2->getStyle('F2')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('G2:G3');
            $ws2->setCellValue('G2', 'Behavior Statements');
            $ws2->getStyle('G2')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->mergeCells('H2:K2');
            $ws2->setCellValue('H2', 'Quarter');
            $ws2->getStyle('H2:K2')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));

            $ws2->setCellValue('H3', '1');
            $ws2->setCellValue('I3', '2');
            $ws2->setCellValue('J3', '3');
            $ws2->setCellValue('K3', '4');
            foreach (['H', 'I', 'J', 'K'] as $col) {
                $ws2->getStyle($col . '3')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            $ws2->mergeCells('F4:F5');
            $ws2->setCellValue('F4', "1. Maka-Diyos");
            $ws2->getStyle('F4:F5')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('G4', "Expresses one's spiritual beliefs while respecting the spiritual beliefs of others");
            $ws2->setCellValue('G5', "Shows adherence to ethical principles by upholding truth in all undertakings");

            $ws2->mergeCells('F6:F7');
            $ws2->setCellValue('F6', "2. Makatao");
            $ws2->getStyle('F6:F7')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('G6', "Is sensitive to individual, social, and cultural differences");
            $ws2->setCellValue('G7', "Demonstrates contributions toward solidarity");

            $ws2->setCellValue('F8', "3. Makakalikasan");
            $ws2->getStyle('F8')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('G8', "Cares for the environment and utilizes resources wisely");

            $ws2->mergeCells('F9:F10');
            $ws2->setCellValue('F9', "4. Makabansa");
            $ws2->getStyle('F9:F10')->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            $ws2->setCellValue('G9', "Demonstrates pride in being a Filipino");
            $ws2->setCellValue('G10', "Demonstrates appropriate behavior in community and country");

            for ($vrRow = 4; $vrRow <= 10; $vrRow++) {
                $ws2->getStyle('G' . $vrRow)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 7, 'name' => $fontName]]));
                $ws2->getStyle('G' . $vrRow)->getAlignment()->setWrapText(true);
                foreach (['H', 'I', 'J', 'K'] as $col) {
                    $ws2->setCellValue($col . $vrRow, 'SO');
                    $ws2->getStyle($col . $vrRow)->applyFromArray(array_merge($borderThin, ['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]));
                }
            }

            // Observed Values Marking Legend
            $vr = 12;
            $ws2->mergeCells("F{$vr}:G{$vr}");
            $ws2->setCellValue("F{$vr}", "Observed Values Marking");
            $ws2->getStyle("F{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName]]);

            $ws2->mergeCells("H{$vr}:K{$vr}");
            $ws2->setCellValue("H{$vr}", "Non-numerical Rating");
            $ws2->getStyle("H{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]);

            $markings = [
                ['AO', 'Always Observed'],
                ['SO', 'Sometimes Observed'],
                ['RO', 'Rarely Observed'],
                ['NO', 'Not Observed']
            ];

            foreach ($markings as $m) {
                $vr++;
                $ws2->setCellValue("F{$vr}", $m[0]);
                $ws2->mergeCells("G{$vr}:K{$vr}");
                $ws2->setCellValue("G{$vr}", $m[1]);
                $ws2->getStyle("F{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]);
                $ws2->getStyle("G{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
            }

            // Grading Scale Legend
            $vr += 2;
            $ws2->mergeCells("F{$vr}:K{$vr}");
            $ws2->setCellValue("F{$vr}", 'Learner Progress and Achievement');
            $ws2->getStyle("F{$vr}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => $fontName], 'alignment' => $center]);

            $scaleHeaderRow = $vr + 1;
            $ws2->mergeCells("F{$scaleHeaderRow}:G{$scaleHeaderRow}");
            $ws2->setCellValue("F{$scaleHeaderRow}", 'Descriptors');
            $ws2->mergeCells("H{$scaleHeaderRow}:I{$scaleHeaderRow}");
            $ws2->setCellValue("H{$scaleHeaderRow}", 'Grading Scale');
            $ws2->mergeCells("J{$scaleHeaderRow}:K{$scaleHeaderRow}");
            $ws2->setCellValue("J{$scaleHeaderRow}", 'Remarks');
            foreach (['F', 'H', 'J'] as $col) {
                $ws2->getStyle($col . $scaleHeaderRow)->applyFromArray(array_merge($borderThin, ['font' => ['bold' => true, 'size' => 8, 'name' => $fontName], 'alignment' => $center]));
            }

            $scale = [
                ['Outstanding', '90 - 100', 'Passed'],
                ['Very Satisfactory', '85 - 89', 'Passed'],
                ['Satisfactory', '80 - 84', 'Passed'],
                ['Fairly Satisfactory', '75 - 79', 'Passed'],
                ['Did Not Meet Expectations', 'Below 75', 'Failed']
            ];

            $vr = $scaleHeaderRow + 1;
            foreach ($scale as $s) {
                $ws2->mergeCells("F{$vr}:G{$vr}");
                $ws2->setCellValue("F{$vr}", $s[0]);
                $ws2->mergeCells("H{$vr}:I{$vr}");
                $ws2->setCellValue("H{$vr}", $s[1]);
                $ws2->mergeCells("J{$vr}:K{$vr}");
                $ws2->setCellValue("J{$vr}", $s[2]);

                $ws2->getStyle("F{$vr}:K{$vr}")->applyFromArray($borderThin);
                $ws2->getStyle("F{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName]]);
                $ws2->getStyle("H{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]);
                $ws2->getStyle("J{$vr}")->applyFromArray(['font' => ['size' => 8, 'name' => $fontName], 'alignment' => $center]);
                $vr++;
            }
        }

        $ws2->getPageSetup()->setFitToPage(true);
        $ws2->getPageSetup()->setFitToWidth(1);
        $ws2->getPageSetup()->setFitToHeight(1);
        $ws2->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $ws2->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $ws2->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.3)->setRight(0.3);

        $writer = new Xlsx($sp);
        $writer->save($filePath);
        $sp->disconnectWorksheets();
        return true;
    }

    public function outputToBrowser(string $filename): void {
        $tmp = tempnam(sys_get_temp_dir(), 'sf9_') . '.xlsx';
        if ($this->export($tmp)) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
        }
        exit();
    }
}
