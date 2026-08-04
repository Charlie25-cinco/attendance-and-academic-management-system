<?php

namespace BshsAms\Export;

use BshsAms\Xlsx\SimpleXlsxParser;
use Exception;

class EcrParser {
    private array $data = [];
    private array $header = [];
    private array $students = [];
    private array $gradeItems = [];
    private array $errors = [];
    private array $rawData = [];

    public function parse(string $filePath): bool {
        $this->reset();
        try {
            $parser = new SimpleXlsxParser($filePath);
            $sheetNames = $parser->getSheetNames();
            $targetSheet = $this->findTargetSheet($sheetNames);
            $this->rawData = $parser->getSheet($targetSheet);
            if (empty($this->rawData)) { $this->errors[] = 'ECR file appears to be empty'; return false; }
            $this->normalizeData();
            $this->parseHeader();
            $this->parseStudentsAndGrades();
            return empty($this->errors);
        } catch (Exception $e) { $this->errors[] = $e->getMessage(); return false; }
    }

    private function reset(): void { $this->data = []; $this->header = []; $this->students = []; $this->gradeItems = []; $this->errors = []; $this->rawData = []; }

    private function findTargetSheet(array $sheetNames): int {
        if (empty($sheetNames)) return 0;
        $priorityNames = ['TERM 1', 'TERM 2', 'TERM 3', 'INPUT DATA', 'Grade 11', 'Grade 12', '11', '12', 'SHS'];
        foreach ($sheetNames as $index => $name) {
            foreach ($priorityNames as $priority) {
                if (stripos($name, $priority) !== false) { return $index; }
            }
        }
        return 0;
    }

    private function normalizeData(): void {
        foreach ($this->rawData as $rowNum => $row) {
            if (!is_array($row)) continue;
            ksort($row);
            $normalized = []; $colIndex = 0;
            foreach ($row as $col => $value) { $normalized[$colIndex] = $value; $colIndex++; }
            $this->data[$rowNum] = $normalized;
        }
    }

    private function getCellValue(int $row, int $col): string {
        $rowData = $this->rawData[$row] ?? [];
        if (!is_array($rowData)) return '';
        ksort($rowData);
        $values = array_values($rowData);
        return trim((string)($values[$col] ?? ''));
    }

    private function getRowValues(int $row): array {
        if (!isset($this->rawData[$row]) || !is_array($this->rawData[$row])) { return []; }
        $rowData = $this->rawData[$row];
        ksort($rowData);
        return array_values($rowData);
    }

    private function parseHeader(): void {
        $this->header = ['school' => '', 'school_id' => '', 'division' => '', 'region' => '', 'subject' => '', 'subject_type' => 'academic', 'grade_level' => null, 'quarter' => null, 'semester' => null, 'academic_year' => '', 'teacher' => '', 'school_head' => ''];

        for ($row = 1; $row <= 30 && isset($this->data[$row]); $row++) {
            $rowValues = $this->getRowValues($row);
            if (empty($rowValues)) continue;
            foreach ($rowValues as $colIndex => $value) {
                $value = trim((string)$value); if ($value === '') continue;
                $nextValue = $rowValues[$colIndex + 1] ?? '';

                if (stripos($value, 'School Name') !== false || $value === 'School') { if (!empty($nextValue)) $this->header['school'] = $nextValue; }
                if (stripos($value, 'School ID') !== false) { if (!empty($nextValue)) $this->header['school_id'] = $nextValue; }
                if (stripos($value, 'Division') !== false) { if (!empty($nextValue)) $this->header['division'] = $nextValue; }
                if (stripos($value, 'Region') !== false) { if (!empty($nextValue)) $this->header['region'] = $nextValue; }
                if (stripos($value, 'Subject') !== false && stripos($value, 'Type') === false) { if (!empty($nextValue)) $this->header['subject'] = $nextValue; }
                if (stripos($value, 'Grade') !== false && preg_match('/(\d+)/', $value, $m)) { $this->header['grade_level'] = (int)$m[1]; }
                if (stripos($value, 'Quarter') !== false) { $this->header['quarter'] = $this->extractQuarter($value); }
                if (stripos($value, 'Term') !== false && stripos($value, 'Term Assessment') === false) { $detected = $this->extractQuarter($value); if ($detected !== null) $this->header['quarter'] = $detected; }
                if (stripos($value, 'Semester') !== false) { $this->header['semester'] = $this->extractSemester($value); }
                if (stripos($value, 'School Year') !== false || stripos($value, 'SY') !== false) { $this->header['academic_year'] = $this->extractAcademicYear($value); if (empty($this->header['academic_year']) && !empty($nextValue)) $this->header['academic_year'] = $this->extractAcademicYear($nextValue); }
                if (stripos($value, 'Teacher') !== false && stripos($value, 'Name') !== false) { if (!empty($nextValue)) $this->header['teacher'] = $nextValue; }
            }
        }
    }

    private function extractQuarter(string $text): ?string {
        $text = strtoupper($text);
        if (preg_match('/1ST\s*Q|QTR?\s*1|QUARTER\s*1|FIRST\s*Q/i', $text)) return 'Q1';
        if (preg_match('/2ND\s*Q|QTR?\s*2|QUARTER\s*2|SECOND\s*Q/i', $text)) return 'Q2';
        if (preg_match('/3RD\s*Q|QTR?\s*3|QUARTER\s*3|THIRD\s*Q/i', $text)) return 'Q3';
        if (preg_match('/4TH\s*Q|QTR?\s*4|QUARTER\s*4|FOURTH\s*Q/i', $text)) return 'Q4';
        if (preg_match('/1ST\s*TERM|TERM\s*1|FIRST\s*TERM/i', $text)) return 'Term1';
        if (preg_match('/2ND\s*TERM|TERM\s*2|SECOND\s*TERM/i', $text)) return 'Term2';
        if (preg_match('/3RD\s*TERM|TERM\s*3|THIRD\s*TERM/i', $text)) return 'Term3';
        if (preg_match('/Q\s*1|Q1/i', $text)) return 'Q1';
        if (preg_match('/Q\s*2|Q2/i', $text)) return 'Q2';
        return null;
    }

    private function extractSemester(string $text): ?string {
        $text = strtoupper($text);
        if (preg_match('/1ST\s*SEM|SEMESTER\s*1|FIRST\s*SEM/i', $text)) return 'S1';
        if (preg_match('/2ND\s*SEM|SEMESTER\s*2|SECOND\s*SEM/i', $text)) return 'S2';
        return null;
    }

    private function extractAcademicYear(string $text): string {
        if (preg_match('/(\d{4})\s*[-–—]\s*(\d{4})/', $text, $m)) { return $m[1] . '-' . $m[2]; }
        if (preg_match('/SY\s*(\d{4})\s*[-–]?\s*(\d{2,4})?/i', $text, $m)) {
            $start = $m[1]; $end = isset($m[2]) ? (strlen($m[2]) === 2 ? '20' . $m[2] : $m[2]) : (intval($start) + 1);
            return $start . '-' . $end;
        }
        return '';
    }

    private function parseStudentsAndGrades(): void {
        $headerRow = 0;
        $studentLrnMap = [];
        $componentCols = $this->scanComponentCols();
        $hpsValues = $this->scanHpsRow($componentCols);
        $colRanges = $this->getComponentColRanges($componentCols);
        $componentItemNum = ['WW' => 0, 'PT' => 0, 'ASSESSMENT' => 0];

        foreach ($this->data as $rowNum => $row) {
            if (!is_array($row)) continue;
            $rowValues = $this->getRowValues($rowNum);
            if (empty($rowValues)) continue;
            $rowText = implode(' ', array_map('strval', $rowValues));

            if (stripos($rowText, 'No.') !== false && stripos($rowText, 'Name') !== false) { $headerRow = $rowNum; continue; }

            if ($headerRow > 0 && $rowNum > $headerRow) {
                $firstCol = $rowValues[0] ?? ''; $secondCol = $rowValues[1] ?? '';
                if (!is_numeric($firstCol) || empty(trim((string)$secondCol))) { continue; }
                $lrn = trim((string)$firstCol); $name = trim((string)$secondCol);
                if (!is_numeric($lrn) || strlen($lrn) < 9) { continue; }

                if (!isset($studentLrnMap[$lrn])) {
                    $studentIndex = count($this->students);
                    $this->students[] = ['lrn' => $lrn, 'name' => $name, 'row' => $rowNum];
                    $studentLrnMap[$lrn] = $studentIndex;
                }
                $studentIndex = $studentLrnMap[$lrn];

                foreach ($colRanges as $component => $range) {
                    $scores = [];
                    for ($i = $range[0]; $i <= $range[1] && $i < count($rowValues); $i++) {
                        $val = trim((string)($rowValues[$i] ?? ''));
                        if ($val !== '' && is_numeric($val)) {
                            $score = floatval($val);
                            $hps = $hpsValues[$i] ?? null;
                            if ($hps !== null && $hps > 0) { $score = round(($score / $hps) * 100, 2); }
                            $scores[] = $score;
                        }
                    }
                    if (!empty($scores)) {
                        $componentItemNum[$component]++;
                        $this->gradeItems[] = [
                            'student_lrn' => $lrn, 'student_index' => $studentIndex,
                            'student_name' => $this->students[$studentIndex]['name'] ?? '',
                            'component' => $component, 'item_number' => $componentItemNum[$component],
                            'scores' => $scores, 'row' => $rowNum
                        ];
                    }
                }
            }
        }
    }

    private function scanComponentCols(): array {
        $cols = [];
        for ($row = 1; $row <= 20 && isset($this->data[$row]); $row++) {
            $rowValues = $this->getRowValues($row);
            if (empty($rowValues)) continue;
            foreach ($rowValues as $colIndex => $value) {
                $val = trim((string)$value); if ($val === '') continue;
                $lower = strtolower($val);
                if (!isset($cols['WW']) && stripos($lower, 'written work') !== false) $cols['WW'] = $colIndex;
                if (!isset($cols['PT']) && stripos($lower, 'performance task') !== false) $cols['PT'] = $colIndex;
                if (!isset($cols['ASSESSMENT']) && (stripos($lower, 'quarterly assessment') !== false || stripos($lower, 'term assessment') !== false || stripos($lower, 'summative assessment') !== false)) $cols['ASSESSMENT'] = $colIndex;
            }
        }
        return $cols;
    }

    private function getComponentColRanges(array $componentCols): array {
        if (isset($componentCols['WW'], $componentCols['PT'], $componentCols['ASSESSMENT'])) {
            $wwEnd = min($componentCols['WW'] + 9, $componentCols['PT'] - 1);
            $ptEnd = min($componentCols['PT'] + 9, $componentCols['ASSESSMENT'] - 1);
            return ['WW' => [$componentCols['WW'], $wwEnd], 'PT' => [$componentCols['PT'], $ptEnd], 'ASSESSMENT' => [$componentCols['ASSESSMENT'], $componentCols['ASSESSMENT'] + 2]];
        }
        return ['WW' => [5, 14], 'PT' => [18, 27], 'ASSESSMENT' => [31, 33]];
    }

    private function scanHpsRow(array $componentCols): array {
        $hps = [];
        for ($row = 8; $row <= 12 && isset($this->data[$row]); $row++) {
            $rowValues = $this->getRowValues($row);
            if (empty($rowValues)) continue;
            $cols = $this->getComponentColRanges($componentCols);
            $candidate = []; $numericCount = 0;
            foreach ($cols as $range) {
                for ($c = $range[0]; $c <= $range[1] && $c < count($rowValues); $c++) {
                    $val = $rowValues[$c] ?? '';
                    if (is_numeric($val) && (float)$val > 0) { $candidate[$c] = (float)$val; $numericCount++; }
                }
            }
            if ($numericCount > count($hps)) { $hps = $candidate; }
        }
        return $hps;
    }

    public function getHeader(): array { return $this->header; }
    public function getStudents(): array { return $this->students; }
    public function getGradeItems(): array { return $this->gradeItems; }
    public function getErrors(): array { return $this->errors; }
    public function getRawData(): array { return $this->rawData; }
    public function getWeights(): array { return ['ww' => 25, 'pt' => 50, 'assessment' => 25]; }

    public function getPreview(): array {
        return ['header' => $this->header, 'weights' => $this->getWeights(), 'student_count' => count($this->students), 'grade_items_count' => count($this->gradeItems), 'students' => array_slice($this->students, 0, 10), 'sample_grades' => array_slice($this->gradeItems, 0, 10), 'validation' => ['has_school' => !empty($this->header['school']), 'has_subject' => !empty($this->header['subject']), 'has_grade_level' => $this->header['grade_level'] !== null, 'has_quarter' => $this->header['quarter'] !== null, 'has_academic_year' => !empty($this->header['academic_year'])]];
    }

    public function getFullData(): array {
        return ['header' => $this->header, 'weights' => $this->getWeights(), 'students' => $this->students, 'grade_items' => $this->gradeItems];
    }
}
