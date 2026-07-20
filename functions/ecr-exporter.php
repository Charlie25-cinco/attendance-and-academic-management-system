<?php

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

class EcrExporter {
    private array $header = [];
    private array $students = [];
    private array $gradeItems = [];
    private array $weights = [];
    private string $academicYear = '';
    private string $semester = 'S1';
    private string $quarter = 'Q1';
    private string $gradingSystem = '4_quarter';
    private array $stringCache = [];
    private array $studentGradesCache = [];
    private array $domCellCache = [];

    private const MAX_WW = 10;
    private const MAX_PT = 10;
    private const MAX_QA = 3;

    private const COL_WW_START = 5;
    private const COL_WW_TOTAL = 15;
    private const COL_WW_PS = 16;
    private const COL_WW_WS = 17;
    private const COL_PT_START = 18;
    private const COL_PT_TOTAL = 28;
    private const COL_PT_PS = 29;
    private const COL_PT_WS = 30;
    private const COL_QA_START = 31;
    private const COL_QA_PS = 34;
    private const COL_QA_WS = 35;
    private const COL_INITIAL = 36;
    private const COL_QUARTERLY = 37;
    private const TOTAL_COLS = 38;

    public function __construct() {}

    public function setHeader(array $header): void {
        $this->header = array_merge(['school' => '', 'school_id' => '', 'division' => '', 'region' => '', 'subject' => '', 'subject_type' => 'academic', 'grade_level' => 11, 'teacher' => '', 'school_head' => ''], $header);
    }
    public function setStudents(array $students): void { $this->students = $students; }
    public function setGradeItems(array $gradeItems): void { $this->gradeItems = $gradeItems; $this->studentGradesCache = []; }
    public function setWeights(array $weights): void { $this->weights = array_merge(['ww' => 25, 'pt' => 50, 'assessment' => 25], $weights); }
    public function setAcademicYear(string $ay): void { $this->academicYear = $ay; $this->gradingSystem = SshsGradeCalculator::gradingSystem($ay); }
    public function setSemester(string $semester): void { $this->semester = $semester; }
    public function setQuarter(string $quarter): void { $this->quarter = $quarter; }
    public function setTerm(string $term): void { $this->quarter = $term; }
    public function setGradingSystem(string $gs): void { $this->gradingSystem = $gs; }

    public function getTemplatePath(): string { return $this->resolveTemplatePath(); }

    public function getTemplateInfo(?string $path = null): array {
        $path = $path !== null ? $path : $this->resolveTemplatePath();
        $info = ['path' => $path, 'exists' => $path !== '' && is_file($path), 'readable' => $path !== '' && is_readable($path), 'zip_available' => class_exists('ZipArchive'), 'compatible' => false, 'kind' => 'missing', 'sheets' => [], 'message' => 'No ECR template found.'];
        if (!$info['exists']) { return $info; }
        $sheets = $this->getWorkbookSheetNames($path);
        $info['sheets'] = $sheets;
        if (empty($sheets)) { $info['kind'] = 'unreadable'; $info['message'] = $info['zip_available'] ? 'Template file exists, but PHP could not read its workbook sheets.' : 'Template file exists, but the PHP Zip extension is not enabled.'; return $info; }
        $hasThreeTerm = in_array('INPUT DATA', $sheets, true) && in_array('TERM 1', $sheets, true) && in_array('TERM 2', $sheets, true) && in_array('TERM 3', $sheets, true);
        $hasLegacySemester = in_array('INPUT DATA', $sheets, true) && in_array('1ST', $sheets, true) && in_array('2ND', $sheets, true);
        if ($hasThreeTerm) { $info['compatible'] = true; $info['kind'] = 'three_term'; $info['message'] = 'Compatible Strengthened SHS three-term ECR template.'; }
        elseif ($hasLegacySemester) { $info['kind'] = 'legacy_semester'; $info['message'] = 'Old semester/quarter ECR template. Grade 11 Strengthened SHS needs TERM 1, TERM 2, and TERM 3 sheets.'; }
        else { $info['kind'] = 'unknown'; $info['message'] = 'Template is not recognized. Grade 11 Strengthened SHS needs INPUT DATA, TERM 1, TERM 2, and TERM 3 sheets.'; }
        return $info;
    }

    public function getUploadedTemplateInfo(): array {
        $path = $this->findTemplateInDir($this->depedTemplateDir());
        if ($path !== '') {
            return $this->getTemplateInfo($path);
        }
        return $this->getTemplateInfo('');
    }

    private function depedTemplateDir(): string {
        return __DIR__ . '/../deped';
    }

    private function depedTemplateCandidates(): array {
        return [
            'ecr_template.xlsx',
            'ecr_template.xlsm',
            'SSHS Three-Term ECR (Auto-Hide Cells by www.teachpinas.com).xlsm',
        ];
    }

    private function findTemplateInDir(string $dir): string {
        $realDir = realpath($dir);
        if (!$realDir || !is_dir($realDir)) { return ''; }
        foreach ($this->depedTemplateCandidates() as $templateName) {
            $path = $realDir . DIRECTORY_SEPARATOR . $templateName;
            if (is_file($path)) { return $path; }
        }
        foreach (glob($realDir . DIRECTORY_SEPARATOR . '*.{xlsx,xlsm}', GLOB_BRACE) ?: [] as $path) {
            $name = strtolower(basename((string)$path));
            if (str_contains($name, 'ecr') && $this->isThreeTermTemplate((string)$path)) {
                return (string)$path;
            }
        }
        return '';
    }

    public function getBundledTemplateInfo(): array {
        $path = $this->getBundledTemplatePath();
        return $this->getTemplateInfo($path);
    }

    public function getTemplateDiagnostics(): array {
        return ['active' => $this->getTemplateInfo($this->resolveTemplatePath()), 'uploaded' => $this->getUploadedTemplateInfo(), 'bundled' => $this->getBundledTemplateInfo(), 'zip_available' => class_exists('ZipArchive')];
    }

    public function exportToCsv(string $filePath): bool {
        $handle = fopen($filePath, 'w');
        if ($handle === false) return false;
        $wwWeight = (float)($this->weights['ww'] ?? 25);
        $ptWeight = (float)($this->weights['pt'] ?? 50);
        $qaWeight = (float)($this->weights['assessment'] ?? 25);
        $isTerm = $this->gradingSystem === '3_term';
        $quarterLabel = $isTerm ? strtoupper(str_replace('Term', 'TERM ', $this->quarter)) : (function() { $semesterNum = (int)substr($this->semester, 1); return ($semesterNum === 1) ? 'FIRST QUARTER' : (($this->quarter === 'Q1') ? 'THIRD QUARTER' : 'FOURTH QUARTER'); })();
        $n = self::TOTAL_COLS;

        $row = array_fill(0, $n, ''); $row[0] = 'Senior High School Class Record'; fputcsv($handle, $row);
        fputcsv($handle, array_fill(0, $n, ''));
        $row = array_fill(0, $n, ''); $row[0] = '(Pursuant to Deped Order 8 series of 2015)'; fputcsv($handle, $row);
        $row = array_fill(0, $n, ''); $row[2] = 'REGION'; $row[6] = $this->header['region'] ?? ''; $row[11] = 'DIVISION'; $row[14] = $this->header['division'] ?? ''; fputcsv($handle, $row);
        $row = array_fill(0, $n, ''); $row[0] = $this->header['school'] ?? ''; $row[6] = $this->header['school_id'] ?? ''; $row[19] = 'SCHOOL ID'; $row[23] = $this->header['school_id'] ?? ''; $row[29] = 'SCHOOL YEAR'; $row[32] = $this->academicYear; fputcsv($handle, $row);
        fputcsv($handle, array_fill(0, $n, ''));
        $row = array_fill(0, $n, ''); $row[0] = "LEARNERS' NAMES"; $row[5] = 'GRADE & SECTION:'; $row[10] = $this->header['grade_level'] ?? ''; $row[16] = 'TEACHER:'; $row[18] = $this->header['teacher'] ?? ''; $row[28] = 'SUBJECT:'; $row[30] = $this->header['subject'] ?? ''; fputcsv($handle, $row);
        $row = array_fill(0, $n, ''); $row[16] = 'SEMESTER:'; $row[18] = $this->gradingSystem === '3_term' ? strtoupper(str_replace('Term', 'TERM ', $this->quarter)) : strtoupper($this->semester === 'S1' ? '1ST' : '2ND'); $row[28] = 'TRACK:'; $row[30] = $this->header['subject_type'] ?? 'Core Subject (All Tracks)'; fputcsv($handle, $row);
        fputcsv($handle, array_fill(0, $n, ''));
        $row = array_fill(0, $n, ''); $row[0] = "LEARNERS' NAMES"; $row[5] = "WRITTEN WORK ({$wwWeight}%)"; $row[18] = "PERFORMANCE TASKS ({$ptWeight}%)"; $row[31] = "QUARTERLY ASSESSMENT ({$qaWeight}%)"; $row[34] = 'Initial'; $row[35] = 'Quarterly'; fputcsv($handle, $row);
        $row = array_fill(0, $n, '');
        for ($i = 0; $i < self::MAX_WW; $i++) $row[self::COL_WW_START + $i] = (string)($i + 1);
        $row[self::COL_WW_TOTAL] = 'Total'; $row[self::COL_WW_PS] = 'PS'; $row[self::COL_WW_WS] = 'WS';
        for ($i = 0; $i < self::MAX_PT; $i++) $row[self::COL_PT_START + $i] = (string)($i + 1);
        $row[self::COL_PT_TOTAL] = 'Total'; $row[self::COL_PT_PS] = 'PS'; $row[self::COL_PT_WS] = 'WS';
        for ($i = 0; $i < self::MAX_QA; $i++) $row[self::COL_QA_START + $i] = (string)($i + 1);
        $row[self::COL_QA_PS] = 'PS'; $row[self::COL_QA_WS] = 'WS';
        $row[34] = 'Grade'; $row[35] = 'Grade';
        fputcsv($handle, $row);
        $row = array_fill(0, $n, ''); $row[0] = 'HIGHEST POSSIBLE SCORE'; $row[self::COL_WW_PS] = '100'; $row[self::COL_WW_WS] = (string)($wwWeight / 100); $row[self::COL_PT_PS] = '100'; $row[self::COL_PT_WS] = (string)($ptWeight / 100); $row[self::COL_QA_PS] = '100'; $row[self::COL_QA_WS] = (string)($qaWeight / 100); fputcsv($handle, $row);
        $row = array_fill(0, $n, ''); $row[0] = 'MALE'; fputcsv($handle, $row);
        $studentIndex = 0;
        foreach ($this->students as $student) {
            $studentIndex++;
            $studentGrades = $this->getStudentGrades($student['lrn'] ?? '');
            $row = array_fill(0, $n, '');
            $row[0] = (string)$studentIndex; $row[1] = $student['lrn'] ?? '';
            for ($i = 0; $i < self::MAX_WW; $i++) $row[self::COL_WW_START + $i] = $studentGrades['ww_scores'][$i] !== null ? (string)$studentGrades['ww_scores'][$i] : '';
            $row[self::COL_WW_TOTAL] = $studentGrades['ww_total'] !== null ? (string)$studentGrades['ww_total'] : '';
            $row[self::COL_WW_PS] = $studentGrades['ww_ps'] !== null ? (string)$studentGrades['ww_ps'] : '';
            $row[self::COL_WW_WS] = $studentGrades['ww_ws'] !== null ? (string)$studentGrades['ww_ws'] : '';
            for ($i = 0; $i < self::MAX_PT; $i++) $row[self::COL_PT_START + $i] = $studentGrades['pt_scores'][$i] !== null ? (string)$studentGrades['pt_scores'][$i] : '';
            $row[self::COL_PT_TOTAL] = $studentGrades['pt_total'] !== null ? (string)$studentGrades['pt_total'] : '';
            $row[self::COL_PT_PS] = $studentGrades['pt_ps'] !== null ? (string)$studentGrades['pt_ps'] : '';
            $row[self::COL_PT_WS] = $studentGrades['pt_ws'] !== null ? (string)$studentGrades['pt_ws'] : '';
            for ($i = 0; $i < self::MAX_QA; $i++) $row[self::COL_QA_START + $i] = $studentGrades['qa_scores'][$i] !== null ? (string)$studentGrades['qa_scores'][$i] : '';
            $row[self::COL_QA_PS] = $studentGrades['qa_ps'] !== null ? (string)$studentGrades['qa_ps'] : '';
            $row[self::COL_QA_WS] = $studentGrades['qa_ws'] !== null ? (string)$studentGrades['qa_ws'] : '';
            $row[self::COL_INITIAL] = $studentGrades['initial_grade'] !== null ? (string)$studentGrades['initial_grade'] : '';
            $row[self::COL_QUARTERLY] = $studentGrades['quarterly_grade'] !== null ? (string)$studentGrades['quarterly_grade'] : '';
            fputcsv($handle, $row);
        }
        return fclose($handle);
    }

    private function getStudentGrades(string $studentKey): array {
        if (empty($this->studentGradesCache)) { $this->studentGradesCache = $this->buildGradesByStudentKey(); }
        return $this->studentGradesCache[$studentKey] ?? $this->emptyStudentGrades();
    }

    private function buildGradesByStudentKey(): array {
        $wwWeight = (float)($this->weights['ww'] ?? 25);
        $ptWeight = (float)($this->weights['pt'] ?? 50);
        $qaWeight = (float)($this->weights['assessment'] ?? 25);
        $acc = [];
        foreach ($this->gradeItems as $item) {
            $studentKey = $this->studentKey($item);
            if ($studentKey === '') { continue; }
            if (!isset($acc[$studentKey])) {
                $acc[$studentKey] = ['ww_scores' => [], 'pt_scores' => [], 'qa_scores' => [], 'ww_hps' => 0.0, 'pt_hps' => 0.0, 'qa_hps' => 0.0, 'saved_final_grade' => null, 'saved_initial_grade' => null, 'saved_quarterly_grade' => null];
            }
            $component = strtolower(trim((string)($item['component'] ?? '')));
            $scores = $item['scores'] ?? [];
            $total = $item['total_score'] ?? null;
            if ($component === 'final_grade' || $component === 'saved_grade') {
                $finalGrade = $item['final_grade'] ?? ($item['quarterly_grade'] ?? ($item['score'] ?? null));
                $initialGrade = $item['initial_grade'] ?? $finalGrade;
                $quarterlyGrade = $item['quarterly_grade'] ?? $finalGrade;
                if ($finalGrade !== null && $finalGrade !== '') $acc[$studentKey]['saved_final_grade'] = (float)$finalGrade;
                if ($initialGrade !== null && $initialGrade !== '') $acc[$studentKey]['saved_initial_grade'] = (float)$initialGrade;
                if ($quarterlyGrade !== null && $quarterlyGrade !== '') $acc[$studentKey]['saved_quarterly_grade'] = (float)$quarterlyGrade;
            } elseif ($component === 'ww' || $component === 'written work') {
                foreach ($scores as $score) { $acc[$studentKey]['ww_scores'][] = $score; }
                if ($total !== null) $acc[$studentKey]['ww_hps'] += (float)$total;
            } elseif ($component === 'pt' || $component === 'performance task') {
                foreach ($scores as $score) { $acc[$studentKey]['pt_scores'][] = $score; }
                if ($total !== null) $acc[$studentKey]['pt_hps'] += (float)$total;
            } elseif ($component === 'assessment' || $component === 'quarterly assessment') {
                foreach ($scores as $score) { $acc[$studentKey]['qa_scores'][] = $score; }
                if ($total !== null) $acc[$studentKey]['qa_hps'] += (float)$total;
            }
        }

        $resultByStudentKey = [];
        foreach ($acc as $studentKey => $data) {
            $result = $this->emptyStudentGrades();
            $wwScores = $data['ww_scores']; $ptScores = $data['pt_scores']; $qaScores = $data['qa_scores'];
            $wwHps = (float)$data['ww_hps']; $ptHps = (float)$data['pt_hps']; $qaHps = (float)$data['qa_hps'];

            $result['ww_scores'] = array_pad(array_slice($wwScores, 0, self::MAX_WW), self::MAX_WW, null);
            $result['pt_scores'] = array_pad(array_slice($ptScores, 0, self::MAX_PT), self::MAX_PT, null);
            $result['qa_scores'] = array_pad(array_slice($qaScores, 0, self::MAX_QA), self::MAX_QA, null);

            if (!empty($wwScores)) {
                $wwTotal = array_sum($wwScores); $wwHps = $wwHps > 0 ? $wwHps : count($wwScores) * 10;
                $result['ww_total'] = $wwTotal;
                $wwPs = round(($wwTotal / $wwHps) * 100, 2);
                $result['ww_ps'] = $wwPs; $result['ww_ws'] = round($wwPs * $wwWeight / 100, 2);
            }
            if (!empty($ptScores)) {
                $ptTotal = array_sum($ptScores); $ptHps = $ptHps > 0 ? $ptHps : count($ptScores) * 10;
                $result['pt_total'] = $ptTotal;
                $ptPs = round(($ptTotal / $ptHps) * 100, 2);
                $result['pt_ps'] = $ptPs; $result['pt_ws'] = round($ptPs * $ptWeight / 100, 2);
            }
            if (!empty($qaScores)) {
                $qaTotal = array_sum($qaScores); $qaHps = $qaHps > 0 ? $qaHps : count($qaScores) * 50;
                $qaPs = round(($qaTotal / $qaHps) * 100, 2);
                $result['qa_ps'] = $qaPs; $result['qa_ws'] = round($qaPs * $qaWeight / 100, 2);
            }
            if ($result['ww_ws'] !== null && $result['pt_ws'] !== null && $result['qa_ws'] !== null) {
                $result['initial_grade'] = round($result['ww_ws'] + $result['pt_ws'] + $result['qa_ws'], 2);
                $result['quarterly_grade'] = $this->transmuteQuarterlyGrade($result['initial_grade']);
            }
            if ($data['saved_final_grade'] !== null) {
                $result['initial_grade'] = $data['saved_initial_grade'] !== null ? (float)$data['saved_initial_grade'] : (float)$data['saved_final_grade'];
                $result['quarterly_grade'] = $data['saved_quarterly_grade'] !== null ? (float)$data['saved_quarterly_grade'] : (float)$data['saved_final_grade'];
            }
            $resultByStudentKey[$studentKey] = $result;
        }
        return $resultByStudentKey;
    }

    private function emptyStudentGrades(): array {
        return ['ww_scores' => array_fill(0, self::MAX_WW, null), 'pt_scores' => array_fill(0, self::MAX_PT, null), 'qa_scores' => array_fill(0, self::MAX_QA, null), 'ww_total' => null, 'pt_total' => null, 'ww_ps' => null, 'pt_ps' => null, 'qa_ps' => null, 'ww_ws' => null, 'pt_ws' => null, 'qa_ws' => null, 'initial_grade' => null, 'quarterly_grade' => null];
    }

    private function transmuteQuarterlyGrade($initial): ?float {
        if ($initial === null || $initial === '') { return null; }
        $initial = (float)$initial;
        if ($initial < 0) { return null; }
        $table = [[0, 60], [4, 61], [8, 62], [12, 63], [16, 64], [20, 65], [24, 66], [28, 67], [32, 68], [36, 69], [40, 70], [44, 71], [48, 72], [52, 73], [56, 74], [60, 75], [61.6, 76], [63.2, 77], [64.8, 78], [66.4, 79], [68, 80], [69.6, 81], [71.2, 82], [72.8, 83], [74.4, 84], [76, 85], [77.6, 86], [79.2, 87], [80.8, 88], [82.4, 89], [84, 90], [85.6, 91], [87.2, 92], [88.8, 93], [90.4, 94], [92, 95], [93.6, 96], [95.2, 97], [96.8, 98], [98.4, 99], [100, 100]];
        $result = null;
        foreach ($table as $row) { if ($initial >= (float)$row[0]) { $result = (float)$row[1]; } else { break; } }
        return $result;
    }

    public function exportToXlsx(string $filePath): bool {
        $templatePath = $this->resolveTemplatePath();
        if ($templatePath === '') { return $this->exportToGeneratedXlsx($filePath); }
        if (!in_array(strtolower(pathinfo($templatePath, PATHINFO_EXTENSION)), ['xlsx', 'xlsm'], true)) {
            return $this->exportToGeneratedXlsx($filePath);
        }
        return $this->exportToXlsxUsingTemplate($filePath, $templatePath);
    }

    private function exportToGeneratedXlsx(string $filePath): bool {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return false;
        }

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('ECR Export');
            $lastCol = $this->columnLetter(self::TOTAL_COLS - 1);
            $wwWeight = (float)($this->weights['ww'] ?? 25);
            $ptWeight = (float)($this->weights['pt'] ?? 50);
            $qaWeight = (float)($this->weights['assessment'] ?? 25);
            $termLabel = $this->gradingSystem === '3_term'
                ? strtoupper(str_replace('Term', 'TERM ', $this->quarter))
                : strtoupper($this->semester . ' ' . $this->quarter);

            $sheet->mergeCells("A1:{$lastCol}1");
            $sheet->setCellValue('A1', 'Senior High School E-Class Record');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $metaRows = [
                ['School', $this->header['school'] ?? '', 'School ID', $this->header['school_id'] ?? '', 'School Year', $this->academicYear],
                ['Region', $this->header['region'] ?? '', 'Division', $this->header['division'] ?? '', 'Term', $termLabel],
                ['Grade & Section', $this->header['grade_section'] ?? ($this->header['grade_level'] ?? ''), 'Teacher', $this->header['teacher'] ?? '', 'Subject', $this->header['subject'] ?? ''],
                ['Track/Type', $this->header['subject_type'] ?? '', '', '', '', ''],
            ];
            $row = 3;
            foreach ($metaRows as $meta) {
                $cols = ['A', 'B', 'G', 'H', 'M', 'N'];
                foreach ($meta as $idx => $value) {
                    $sheet->setCellValue($cols[$idx] . $row, $value);
                    if ($idx % 2 === 0) {
                        $sheet->getStyle($cols[$idx] . $row)->getFont()->setBold(true);
                    }
                }
                $row++;
            }

            $headerRow = 9;
            $sheet->setCellValue('A' . $headerRow, 'No.');
            $sheet->setCellValue('B' . $headerRow, 'LRN');
            $sheet->setCellValue('C' . $headerRow, "Learner's Name");
            for ($i = 0; $i < self::MAX_WW; $i++) {
                $sheet->setCellValue($this->columnLetter(self::COL_WW_START + $i) . $headerRow, 'WW ' . ($i + 1));
            }
            $sheet->setCellValue($this->columnLetter(self::COL_WW_TOTAL) . $headerRow, 'WW Total');
            $sheet->setCellValue($this->columnLetter(self::COL_WW_PS) . $headerRow, 'WW PS');
            $sheet->setCellValue($this->columnLetter(self::COL_WW_WS) . $headerRow, 'WW WS');
            for ($i = 0; $i < self::MAX_PT; $i++) {
                $sheet->setCellValue($this->columnLetter(self::COL_PT_START + $i) . $headerRow, 'PT ' . ($i + 1));
            }
            $sheet->setCellValue($this->columnLetter(self::COL_PT_TOTAL) . $headerRow, 'PT Total');
            $sheet->setCellValue($this->columnLetter(self::COL_PT_PS) . $headerRow, 'PT PS');
            $sheet->setCellValue($this->columnLetter(self::COL_PT_WS) . $headerRow, 'PT WS');
            for ($i = 0; $i < self::MAX_QA; $i++) {
                $sheet->setCellValue($this->columnLetter(self::COL_QA_START + $i) . $headerRow, 'TA ' . ($i + 1));
            }
            $sheet->setCellValue($this->columnLetter(self::COL_QA_PS) . $headerRow, 'TA PS');
            $sheet->setCellValue($this->columnLetter(self::COL_QA_WS) . $headerRow, 'TA WS');
            $sheet->setCellValue($this->columnLetter(self::COL_INITIAL) . $headerRow, 'Initial Grade');
            $sheet->setCellValue($this->columnLetter(self::COL_QUARTERLY) . $headerRow, 'Quarterly Grade');

            $hpsRow = $headerRow + 1;
            $sheet->setCellValue('A' . $hpsRow, 'HPS');
            $hps = $this->buildComponentHps();
            for ($i = 0; $i < self::MAX_WW; $i++) {
                $value = $hps['ww'][$i] ?? null;
                if ($value !== null) { $sheet->setCellValue($this->columnLetter(self::COL_WW_START + $i) . $hpsRow, $value); }
            }
            for ($i = 0; $i < self::MAX_PT; $i++) {
                $value = $hps['pt'][$i] ?? null;
                if ($value !== null) { $sheet->setCellValue($this->columnLetter(self::COL_PT_START + $i) . $hpsRow, $value); }
            }
            for ($i = 0; $i < self::MAX_QA; $i++) {
                $value = $hps['qa'][$i] ?? null;
                if ($value !== null) { $sheet->setCellValue($this->columnLetter(self::COL_QA_START + $i) . $hpsRow, $value); }
            }
            $sheet->setCellValue($this->columnLetter(self::COL_WW_WS) . $hpsRow, $wwWeight . '%');
            $sheet->setCellValue($this->columnLetter(self::COL_PT_WS) . $hpsRow, $ptWeight . '%');
            $sheet->setCellValue($this->columnLetter(self::COL_QA_WS) . $hpsRow, $qaWeight . '%');

            $dataRow = $hpsRow + 1;
            $counter = 1;
            foreach ($this->students as $student) {
                $studentKey = $this->studentKey($student);
                $grades = $this->getStudentGrades($studentKey);
                $studentName = trim((string)($student['name'] ?? ''));
                if ($studentName === '') {
                    $studentName = trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? ''));
                }
                $sheet->setCellValue('A' . $dataRow, $counter++);
                $sheet->setCellValueExplicit('B' . $dataRow, (string)($student['lrn'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('C' . $dataRow, $studentName);
                for ($i = 0; $i < self::MAX_WW; $i++) {
                    if (($grades['ww_scores'][$i] ?? null) !== null) { $sheet->setCellValue($this->columnLetter(self::COL_WW_START + $i) . $dataRow, $grades['ww_scores'][$i]); }
                }
                for ($i = 0; $i < self::MAX_PT; $i++) {
                    if (($grades['pt_scores'][$i] ?? null) !== null) { $sheet->setCellValue($this->columnLetter(self::COL_PT_START + $i) . $dataRow, $grades['pt_scores'][$i]); }
                }
                for ($i = 0; $i < self::MAX_QA; $i++) {
                    if (($grades['qa_scores'][$i] ?? null) !== null) { $sheet->setCellValue($this->columnLetter(self::COL_QA_START + $i) . $dataRow, $grades['qa_scores'][$i]); }
                }
                foreach ([
                    self::COL_WW_TOTAL => 'ww_total',
                    self::COL_WW_PS => 'ww_ps',
                    self::COL_WW_WS => 'ww_ws',
                    self::COL_PT_TOTAL => 'pt_total',
                    self::COL_PT_PS => 'pt_ps',
                    self::COL_PT_WS => 'pt_ws',
                    self::COL_QA_PS => 'qa_ps',
                    self::COL_QA_WS => 'qa_ws',
                    self::COL_INITIAL => 'initial_grade',
                    self::COL_QUARTERLY => 'quarterly_grade',
                ] as $col => $key) {
                    if (($grades[$key] ?? null) !== null) {
                        $sheet->setCellValue($this->columnLetter($col) . $dataRow, $grades[$key]);
                    }
                }
                $dataRow++;
            }

            $sheet->getStyle("A{$headerRow}:{$lastCol}{$hpsRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$headerRow}:{$lastCol}" . max($hpsRow, $dataRow - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->getStyle("A{$headerRow}:{$lastCol}{$hpsRow}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
            $sheet->freezePane('D11');
            foreach (range('A', 'C') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            for ($col = self::COL_WW_START; $col < self::TOTAL_COLS; $col++) {
                $sheet->getColumnDimension($this->columnLetter($col))->setWidth(10);
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filePath);
            $spreadsheet->disconnectWorksheets();
            return is_file($filePath) && filesize($filePath) > 0;
        } catch (Throwable $e) {
            error_log('Generated ECR XLSX export failed: ' . $e->getMessage());
            return false;
        }
    }

    private function resolveTemplatePath(): string {
        $envPath = trim((string)getenv('ECR_TEMPLATE_PATH'));
        if ($envPath !== '' && is_file($envPath) && $this->isThreeTermTemplate($envPath)) { return $envPath; }
        $depedTemplate = $this->findTemplateInDir($this->depedTemplateDir());
        if ($depedTemplate !== '' && $this->isThreeTermTemplate($depedTemplate)) { return $depedTemplate; }
        $officialTemplate = $this->getBundledTemplatePath();
        if ($officialTemplate !== '' && is_file($officialTemplate) && $this->isThreeTermTemplate($officialTemplate)) { return $officialTemplate; }
        return '';
    }

    private function getBundledTemplatePath(): string {
        $depedTemplate = $this->findTemplateInDir($this->depedTemplateDir());
        if ($depedTemplate !== '') { return $depedTemplate; }
        return '';
    }

    private function isThreeTermTemplate(string $path): bool {
        $sheets = $this->getWorkbookSheetNames($path);
        return in_array('INPUT DATA', $sheets, true) && in_array('TERM 1', $sheets, true) && in_array('TERM 2', $sheets, true) && in_array('TERM 3', $sheets, true);
    }

    private function getWorkbookSheetNames(string $path): array {
        if ($path === '' || !is_file($path) || !class_exists('ZipArchive')) { return []; }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { return []; }
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if ($workbookXml === false) { return []; }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($workbookXml)) { return []; }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = [];
        foreach ($xp->query('//x:sheets/x:sheet') as $sheetNode) {
            $name = trim((string)$sheetNode->getAttribute('name'));
            if ($name !== '') { $sheets[] = $name; }
        }
        return $sheets;
    }

    private function exportToXlsxUsingTemplate(string $filePath, string $templatePath): bool {
        $this->domCellCache = [];
        if (!@copy($templatePath, $filePath)) { return false; }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) { return false; }
        $sheetPaths = $this->getSheetPathsByName($zip);
        $inputPath = $sheetPaths['INPUT DATA'] ?? '';
        $termSheetPaths = ['Term1' => $sheetPaths['TERM 1'] ?? $sheetPaths['1ST'] ?? '', 'Term2' => $sheetPaths['TERM 2'] ?? $sheetPaths['2ND'] ?? '', 'Term3' => $sheetPaths['TERM 3'] ?? $sheetPaths['3RD'] ?? ''];
        if ($inputPath === '' || $termSheetPaths['Term1'] === '') { $zip->close(); return false; }
        $inputDom = $this->loadSheetDom($zip, $inputPath);
        $termDoms = [];
        foreach ($termSheetPaths as $term => $path) {
            if ($path === '') { continue; }
            $dom = $this->loadSheetDom($zip, $path);
            if ($dom) { $termDoms[$term] = $dom; }
        }
        if (!$inputDom || empty($termDoms)) { $zip->close(); return false; }
        $wwWeight = (float)($this->weights['ww'] ?? 25);
        $ptWeight = (float)($this->weights['pt'] ?? 50);
        $qaWeight = (float)($this->weights['assessment'] ?? 25);
        $track = trim((string)($this->header['subject_type'] ?? 'Core Subject (All Tracks)'));
        if ($track === '') { $track = 'Core Subject (All Tracks)'; }
        $gradeSection = trim((string)($this->header['grade_section'] ?? ''));
        if ($gradeSection === '') {
            $grade = (string)($this->header['grade_level'] ?? '');
            $section = trim((string)($this->header['section'] ?? ''));
            $gradeSection = $grade !== '' ? ('Grade ' . $grade . ($section !== '' ? (' - ' . $section) : '')) : $section;
        }
        $isTermMode = $this->gradingSystem === '3_term';
        $semesterLabel = $isTermMode ? strtoupper(str_replace('Term', 'TERM ', $this->quarter)) : strtoupper($this->semester === 'S2' ? '2ND' : '1ST');
        $headerCells = ['G4' => (string)($this->header['region'] ?? ''), 'O4' => (string)($this->header['division'] ?? ''), 'G5' => (string)($this->header['school'] ?? ''), 'X5' => (string)($this->header['school_id'] ?? ''), 'AG5' => $this->academicYear, 'K7' => $gradeSection, 'S7' => (string)($this->header['teacher'] ?? ''), 'AE7' => (string)($this->header['subject'] ?? ''), 'S8' => $semesterLabel, 'AE8' => $track];
        $targetTerm = $this->gradingSystem === '3_term' ? (in_array($this->quarter, ['Term1', 'Term2', 'Term3'], true) ? $this->quarter : 'Term1') : (($this->quarter === 'Q2' || $this->quarter === 'Q4') ? 'Term2' : 'Term1');
        $targetQuarterDom = $termDoms[$targetTerm] ?? reset($termDoms);
        foreach ($termDoms as $term => $termDom) {
            if ($term === $targetTerm) { continue; }
            foreach (array_keys($headerCells) as $cell) { $this->setCellBlank($termDom, $cell); }
            for ($row = 13; $row <= 213; $row++) {
                $this->setCellBlank($termDom, 'A' . $row); $this->setCellBlank($termDom, 'B' . $row); $this->setCellBlank($termDom, 'C' . $row);
                $this->clearScoreCells($termDom, $row);
            }
        }
        $this->setCellString($inputDom, 'A1', 'Input Data Sheet for SHS E-Class Record');
        foreach ($headerCells as $cell => $value) { $this->setCellString($inputDom, $cell, $value); $this->setCellString($targetQuarterDom, $cell, $value); }
        $this->setCellString($targetQuarterDom, 'F9', 'WRITTEN WORK (' . rtrim(rtrim(number_format($wwWeight, 2, '.', ''), '0'), '.') . '%)');
        $this->setCellString($targetQuarterDom, 'S9', 'PERFORMANCE TASKS (' . rtrim(rtrim(number_format($ptWeight, 2, '.', ''), '0'), '.') . '%)');
        $this->setCellString($targetQuarterDom, 'AF9', 'TERM ASSESSMENT (' . rtrim(rtrim(number_format($qaWeight, 2, '.', ''), '0'), '.') . '%)');
        $this->setCellNumber($targetQuarterDom, 'R11', round($wwWeight / 100, 4));
        $this->setCellNumber($targetQuarterDom, 'AE11', round($ptWeight / 100, 4));
        $this->setCellNumber($targetQuarterDom, 'AI11', round($qaWeight / 100, 4));

        $rowMap = $this->buildStudentRowMap($this->students);
        for ($row = 13; $row <= 213; $row++) {
            $this->setCellBlank($inputDom, 'A' . $row); $this->setCellBlank($inputDom, 'B' . $row); $this->setCellBlank($inputDom, 'C' . $row);
            $this->setCellBlank($targetQuarterDom, 'A' . $row); $this->setCellBlank($targetQuarterDom, 'B' . $row); $this->setCellBlank($targetQuarterDom, 'C' . $row);
            $this->clearScoreCells($targetQuarterDom, $row);
        }
        $hpsByComponent = $this->buildComponentHps();
        $this->applyHpsRow($targetQuarterDom, 11, $hpsByComponent);
        $gradesByStudentKey = $this->buildGradesByStudentKey();
        $lastRowNumber = 0;
        foreach ($this->students as $student) {
            $studentKey = $this->studentKey($student);
            $lrn = (string)($student['lrn'] ?? '');
            if ($studentKey === '' || !isset($rowMap[$studentKey])) { continue; }
            $row = (int)$rowMap[$studentKey];
            $rowNumber = isset($lastRowNumber) ? $lastRowNumber + 1 : 1;
            $lastRowNumber = $rowNumber;
            $studentName = trim((string)($student['name'] ?? ''));
            if ($studentName === '') { $studentName = trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? '')); }
            if ($studentName === '') { $studentName = $lrn !== '' ? $lrn : $studentKey; }

            $this->setCellNumber($inputDom, 'A' . $row, (float)$rowNumber);
            $this->setCellString($inputDom, 'B' . $row, $studentName);
            $this->setCellString($inputDom, 'C' . $row, $lrn);
            $this->setCellNumber($targetQuarterDom, 'A' . $row, (float)$rowNumber);
            $this->setCellString($targetQuarterDom, 'B' . $row, $studentName);
            $this->setCellString($targetQuarterDom, 'C' . $row, $lrn);

            $grades = $gradesByStudentKey[$studentKey] ?? $this->emptyStudentGrades();
            for ($i = 0; $i < self::MAX_WW; $i++) {
                $score = $grades['ww_scores'][$i] ?? null;
                $cell = $this->columnLetter(self::COL_WW_START + $i) . $row;
                if ($score === null || $score === '') { $this->setCellBlank($targetQuarterDom, $cell); } else { $this->setCellNumber($targetQuarterDom, $cell, (float)$score); }
            }
            for ($i = 0; $i < self::MAX_PT; $i++) {
                $score = $grades['pt_scores'][$i] ?? null;
                $cell = $this->columnLetter(self::COL_PT_START + $i) . $row;
                if ($score === null || $score === '') { $this->setCellBlank($targetQuarterDom, $cell); } else { $this->setCellNumber($targetQuarterDom, $cell, (float)$score); }
            }
            for ($i = 0; $i < self::MAX_QA; $i++) {
                $qaScore = $grades['qa_scores'][$i] ?? null;
                $qaCell = $this->columnLetter(self::COL_QA_START + $i) . $row;
                if ($qaScore === null || $qaScore === '') { $this->setCellBlank($targetQuarterDom, $qaCell); } else { $this->setCellNumber($targetQuarterDom, $qaCell, (float)$qaScore); }
            }
            $initialCell = $this->columnLetter(self::COL_INITIAL) . $row;
            $quarterCell = $this->columnLetter(self::COL_QUARTERLY) . $row;
            $initialGrade = $grades['initial_grade'] ?? null;
            $quarterGrade = $grades['quarterly_grade'] ?? null;
            if ($initialGrade === null || $initialGrade === '') { $this->setCellBlank($targetQuarterDom, $initialCell); } else { $this->setCellNumber($targetQuarterDom, $initialCell, (float)$initialGrade); }
            if ($quarterGrade === null || $quarterGrade === '') { $this->setCellBlank($targetQuarterDom, $quarterCell); } else { $this->setCellNumber($targetQuarterDom, $quarterCell, (float)$quarterGrade); }
        }

        $zip->addFromString($inputPath, $inputDom->saveXML());
        foreach ($termDoms as $term => $termDom) {
            $path = $termSheetPaths[$term] ?? '';
            if ($path !== '') {
                if ($term === $targetTerm) { $this->unhideLearnerRows($termDom); }
                $zip->addFromString($path, $termDom->saveXML());
            }
        }
        if (strtolower(pathinfo($templatePath, PATHINFO_EXTENSION)) === 'xlsm') {
            $this->stripMacroPartsForXlsx($zip);
        }
        $this->setWorkbookActiveSheet($zip, ['Term1' => 'TERM 1', 'Term2' => 'TERM 2', 'Term3' => 'TERM 3'][$targetTerm]);
        $ok = $zip->close();
        $this->domCellCache = [];
        return (bool)$ok;
    }

    private function stripMacroPartsForXlsx(ZipArchive $zip): void {
        $zip->deleteName('xl/vbaProject.bin');

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes !== false) {
            $dom = new DOMDocument();
            if (@$dom->loadXML($contentTypes)) {
                $xp = new DOMXPath($dom);
                $xp->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');
                foreach ($xp->query('//ct:Override[@PartName="/xl/vbaProject.bin"]') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                foreach ($xp->query('//ct:Default[@ContentType="application/vnd.ms-office.vbaProject"]') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $zip->addFromString('[Content_Types].xml', $dom->saveXML());
            }
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels !== false) {
            $dom = new DOMDocument();
            if (@$dom->loadXML($rels)) {
                $xp = new DOMXPath($dom);
                $xp->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
                foreach ($xp->query('//rel:Relationship[contains(@Type, "/vbaProject")]') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $zip->addFromString('xl/_rels/workbook.xml.rels', $dom->saveXML());
            }
        }
    }

    private function unhideLearnerRows(DOMDocument $dom): void {
        $cache = &$this->getDomCache($dom);
        for ($row = 12; $row <= 113; $row++) {
            $rowNode = $cache['rows'][$row] ?? null;
            if (!$rowNode instanceof DOMElement) { continue; }
            $rowNode->removeAttribute('hidden'); $rowNode->removeAttribute('outlineLevel');
            if ($rowNode->getAttribute('ht') === '') { $rowNode->setAttribute('ht', '18'); }
            $rowNode->setAttribute('customHeight', '1');
        }
    }

    private function setWorkbookActiveSheet(ZipArchive $zip, string $sheetName): void {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) { return; }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($workbookXml)) { return; }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetIndex = null; $idx = 0;
        foreach ($xp->query('//x:sheets/x:sheet') as $sheetNode) {
            if (trim((string)$sheetNode->getAttribute('name')) === $sheetName) { $sheetIndex = $idx; break; }
            $idx++;
        }
        if ($sheetIndex === null) { return; }
        $views = $xp->query('//x:bookViews/x:workbookView');
        if ($views->length === 0) { return; }
        $view = $views->item(0);
        $view->setAttribute('activeTab', (string)$sheetIndex); $view->setAttribute('firstSheet', (string)$sheetIndex);
        $zip->addFromString('xl/workbook.xml', $dom->saveXML());
    }

    private function buildComponentHps(): array {
        $seen = []; $hps = ['ww' => [], 'pt' => [], 'qa' => []];
        foreach ($this->gradeItems as $item) {
            $itemId = (string)($item['item_id'] ?? '');
            if ($itemId === '' || isset($seen[$itemId])) { continue; }
            $seen[$itemId] = true;
            $component = strtolower(trim((string)($item['component'] ?? '')));
            $total = $item['total_score'] ?? null;
            $score = $total !== null ? (float)$total : null;
            if ($component === 'ww' || $component === 'written work') { $hps['ww'][] = $score; }
            elseif ($component === 'pt' || $component === 'performance task') { $hps['pt'][] = $score; }
            elseif ($component === 'assessment' || $component === 'quarterly assessment') { $hps['qa'][] = $score; }
        }
        return $hps;
    }

    private function applyHpsRow(DOMDocument $dom, int $row, array $hps): void {
        for ($i = 0; $i < self::MAX_WW; $i++) {
            $cell = $this->columnLetter(self::COL_WW_START + $i) . $row;
            $value = $hps['ww'][$i] ?? null;
            if ($value === null) { $this->setCellBlank($dom, $cell); } else { $this->setCellNumber($dom, $cell, (float)$value); }
        }
        for ($i = 0; $i < self::MAX_PT; $i++) {
            $cell = $this->columnLetter(self::COL_PT_START + $i) . $row;
            $value = $hps['pt'][$i] ?? null;
            if ($value === null) { $this->setCellBlank($dom, $cell); } else { $this->setCellNumber($dom, $cell, (float)$value); }
        }
        for ($i = 0; $i < self::MAX_QA; $i++) {
            $qaCell = $this->columnLetter(self::COL_QA_START + $i) . $row;
            $qaValue = $hps['qa'][$i] ?? null;
            if ($qaValue === null) { $this->setCellBlank($dom, $qaCell); } else { $this->setCellNumber($dom, $qaCell, (float)$qaValue); }
        }
    }

    private function buildStudentRowMap(array $students): array {
        $male = []; $female = []; $unknown = [];
        foreach ($students as $student) {
            $studentKey = $this->studentKey($student);
            if ($studentKey === '') { continue; }
            $sex = strtolower(trim((string)($student['sex'] ?? '')));
            if ($sex === 'male' || $sex === 'm') { $male[] = $studentKey; }
            elseif ($sex === 'female' || $sex === 'f') { $female[] = $studentKey; }
            else { $unknown[] = $studentKey; }
        }
        $rows = []; $maleRow = 14; $maleCount = 0;
        foreach ($male as $lrn) { $rows[$lrn] = $maleRow++; $maleCount++; }
        foreach ($unknown as $lrn) { if (!isset($rows[$lrn])) { $rows[$lrn] = $maleRow++; $maleCount++; } }
        $femaleRow = 64;
        foreach ($female as $lrn) { $rows[$lrn] = $femaleRow++; }
        foreach ($unknown as $lrn) { if (!isset($rows[$lrn])) { $rows[$lrn] = $femaleRow++; } }
        return $rows;
    }

    private function studentKey(array $row): string {
        $key = trim((string)($row['export_key'] ?? ''));
        if ($key !== '') { return $key; }
        $studentId = (int)($row['student_id'] ?? $row['id'] ?? 0);
        if ($studentId > 0) { return 'student:' . $studentId; }
        $lrn = trim((string)($row['lrn'] ?? ''));
        return $lrn !== '' ? 'lrn:' . $lrn : '';
    }

    private function clearScoreCells(DOMDocument $dom, int $row): void {
        for ($i = 0; $i < self::MAX_WW; $i++) $this->setCellBlank($dom, $this->columnLetter(self::COL_WW_START + $i) . $row);
        for ($i = 0; $i < self::MAX_PT; $i++) $this->setCellBlank($dom, $this->columnLetter(self::COL_PT_START + $i) . $row);
        for ($i = 0; $i < self::MAX_QA; $i++) $this->setCellBlank($dom, $this->columnLetter(self::COL_QA_START + $i) . $row);
    }

    private function loadSheetDom(ZipArchive $zip, string $sheetPath): ?DOMDocument {
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) { return null; }
        $dom = new DOMDocument(); $dom->preserveWhiteSpace = false; $dom->formatOutput = false;
        return @$dom->loadXML($xml) ? $dom : null;
    }

    private function getSheetPathsByName(ZipArchive $zip): array {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) { return []; }
        $wb = new DOMDocument(); $rel = new DOMDocument();
        if (!@$wb->loadXML($workbookXml) || !@$rel->loadXML($relsXml)) { return []; }
        $wbXp = new DOMXPath($wb); $wbXp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'); $wbXp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relXp = new DOMXPath($rel); $relXp->registerNamespace('x', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $ridToTarget = [];
        foreach ($relXp->query('//x:Relationship') as $node) {
            $id = (string)$node->getAttribute('Id'); $target = (string)$node->getAttribute('Target');
            if ($id !== '' && strpos($target, 'worksheets/') === 0) { $ridToTarget[$id] = 'xl/' . $target; }
        }
        $result = [];
        foreach ($wbXp->query('//x:sheets/x:sheet') as $sheetNode) {
            $name = (string)$sheetNode->getAttribute('name');
            $rid = (string)$sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($name !== '' && $rid !== '' && isset($ridToTarget[$rid])) { $result[$name] = $ridToTarget[$rid]; }
        }
        return $result;
    }

    private function setCellBlank(DOMDocument $dom, string $ref): void {
        $cell = $this->getOrCreateCell($dom, $ref);
        if (!$cell) return;
        while ($cell->firstChild) { $cell->removeChild($cell->firstChild); }
        $cell->removeAttribute('t');
    }

    private function setCellNumber(DOMDocument $dom, string $ref, float $value): void {
        $cell = $this->getOrCreateCell($dom, $ref);
        if (!$cell) return;
        while ($cell->firstChild) { $cell->removeChild($cell->firstChild); }
        $cell->removeAttribute('t');
        $v = $dom->createElement('v');
        $v->nodeValue = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        if ($v->nodeValue === '') { $v->nodeValue = '0'; }
        $cell->appendChild($v);
    }

    private function setCellString(DOMDocument $dom, string $ref, string $value): void {
        $cell = $this->getOrCreateCell($dom, $ref);
        if (!$cell) return;
        while ($cell->firstChild) { $cell->removeChild($cell->firstChild); }
        $cell->setAttribute('t', 'inlineStr');
        $is = $dom->createElement('is');
        $t = $dom->createElement('t');
        if ($value !== trim($value)) { $t->setAttribute('xml:space', 'preserve'); }
        $t->appendChild($dom->createTextNode($value));
        $is->appendChild($t);
        $cell->appendChild($is);
    }

    private function getOrCreateCell(DOMDocument $dom, string $ref): ?DOMElement {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) { return null; }
        $rowNum = (int)$m[2];
        $cache = &$this->getDomCache($dom);
        if (isset($cache['cells'][$ref]) && $cache['cells'][$ref] instanceof DOMElement) { return $cache['cells'][$ref]; }
        $sheetData = $cache['sheetData'] ?? null;
        if (!$sheetData instanceof DOMElement) { return null; }
        $rowNode = $cache['rows'][$rowNum] ?? null;
        if (!$rowNode instanceof DOMElement) {
            $rowNode = $dom->createElement('row'); $rowNode->setAttribute('r', (string)$rowNum);
            $sheetData->appendChild($rowNode); $cache['rows'][$rowNum] = $rowNode;
        }
        $cellNode = $dom->createElement('c'); $cellNode->setAttribute('r', $ref);
        $rowNode->appendChild($cellNode); $cache['cells'][$ref] = $cellNode;
        return $cellNode;
    }

    private function &getDomCache(DOMDocument $dom): array {
        $domId = spl_object_id($dom);
        if (!isset($this->domCellCache[$domId])) {
            $this->domCellCache[$domId] = ['sheetData' => null, 'rows' => [], 'cells' => []];
            $xp = new DOMXPath($dom); $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheetData = $xp->query('//x:sheetData')->item(0);
            if ($sheetData instanceof DOMElement) {
                $this->domCellCache[$domId]['sheetData'] = $sheetData;
                foreach ($xp->query('//x:sheetData/x:row') as $rowNode) {
                    if (!($rowNode instanceof DOMElement)) { continue; }
                    $rowNum = (int)$rowNode->getAttribute('r');
                    if ($rowNum > 0) { $this->domCellCache[$domId]['rows'][$rowNum] = $rowNode; }
                    foreach ($rowNode->getElementsByTagName('c') as $cellNode) {
                        if (!($cellNode instanceof DOMElement)) { continue; }
                        $cellRef = (string)$cellNode->getAttribute('r');
                        if ($cellRef !== '') { $this->domCellCache[$domId]['cells'][$cellRef] = $cellNode; }
                    }
                }
            }
        }
        return $this->domCellCache[$domId];
    }

    private function columnLetter(int $index): string {
        $letter = ''; $index++;
        while ($index > 0) { $index--; $letter = chr(65 + ($index % 26)) . $letter; $index = (int)($index / 26); }
        return $letter;
    }
}
