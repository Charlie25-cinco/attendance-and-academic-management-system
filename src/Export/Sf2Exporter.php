<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use BshsAms\Xlsx\SimpleXlsxTemplateEditor;
use RuntimeException;
use Throwable;

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
    private const DAY_COLUMNS = [
        'F', 'H', 'I', 'J', 'K', 'L', 'M', 'O', 'P', 'Q',
        'R', 'S', 'T', 'V', 'W', 'X', 'Z', 'AB', 'AC', 'AE',
        'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AM', 'AN', 'AO', 'AQ',
    ];
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
        $default = realpath(dirname(__DIR__, 2) . '/deped/SF2_Senior_High_School.xlsx');
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
        $daysInMonth = (int)date('t', mktime(0, 0, 0, $this->month, 1, $this->year));
        $schoolDays = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts = mktime(0, 0, 0, $this->month, $d, $this->year);
            if ((int)date('N', $ts) !== 7) { $schoolDays[] = $d; }
        }
        return $schoolDays;
    }

    private function splitIntoChunks(array $students, int $capacity): array {
        if (empty($students)) return [[]];
        return array_chunk($students, $capacity);
    }

    private function fillSheet($ws, array $maleChunk, array $femaleChunk, array $schoolDays, int $sheetNumber): void {
        $monthName = date('F', mktime(0, 0, 0, $this->month, 1, $this->year));
        $schoolYearStart = $this->month >= 6 ? $this->year : $this->year - 1;
        $schoolYear = $schoolYearStart . '-' . ($schoolYearStart + 1);
        $sheetTitle = $sheetNumber === 1 ? 'SHSF-2' : 'SF2 (' . $sheetNumber . ')';
        $ws->setTitle($sheetTitle);
        foreach ($this->headerValues($schoolYear, $monthName) as $cell => $value) {
            $ws->setCellValue($cell, $value);
        }
        $this->writeCalendarHeader($ws, $schoolDays);
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
        if (!class_exists('ZipArchive') || getenv('APP_FORCE_XLSX_FALLBACK') === '1') {
            return $this->exportWithTemplateEditor($filePath, $templatePath);
        }
        try {
            return $this->exportWithSpreadsheet($filePath, $templatePath);
        } catch (Throwable $e) {
            error_log('SF2 PhpSpreadsheet export failed: ' . $e->getMessage());
            return $this->exportWithTemplateEditor($filePath, $templatePath);
        }
    }

    private function exportWithSpreadsheet(string $filePath, string $templatePath): bool {
        $schoolDays = $this->computeSchoolDays();
        $spreadsheet = IOFactory::load($templatePath);
        $ws = $spreadsheet->getActiveSheet();
        $templateSheet = clone $ws;
        $groups = $this->splitStudentsBySex();
        $maleChunks = $this->splitIntoChunks($groups['male'], self::MALE_CAPACITY);
        $femaleChunks = $this->splitIntoChunks($groups['female'], self::FEMALE_CAPACITY);
        $sheetCount = max(count($maleChunks), count($femaleChunks));
        $this->fillSheet($ws, $maleChunks[0] ?? [], $femaleChunks[0] ?? [], $schoolDays, 1);
        for ($i = 1; $i < $sheetCount; $i++) {
            $cloneIndex = $i + 1;
            $newWs = clone $templateSheet;
            $this->fillSheet($newWs, $maleChunks[$i] ?? [], $femaleChunks[$i] ?? [], $schoolDays, $cloneIndex);
            $spreadsheet->addSheet($newWs);
        }
        if ($sheetCount > 1) { $spreadsheet->setActiveSheetIndex(0); }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);
        $spreadsheet->disconnectWorksheets();
        return true;
    }

    private function exportWithTemplateEditor(string $filePath, string $templatePath): bool {
        $schoolDays = $this->computeSchoolDays();
        $groups = $this->splitStudentsBySex();
        $maleChunks = $this->splitIntoChunks($groups['male'], self::MALE_CAPACITY);
        $femaleChunks = $this->splitIntoChunks($groups['female'], self::FEMALE_CAPACITY);
        $sheetCount = max(count($maleChunks), count($femaleChunks));
        $editor = new SimpleXlsxTemplateEditor($templatePath);
        $this->fillTemplateEditor($editor, $maleChunks[0] ?? [], $femaleChunks[0] ?? [], $schoolDays);
        for ($i = 1; $i < $sheetCount; $i++) {
            $editor->duplicateTemplateSheet('SF2 (' . ($i + 1) . ')');
            $this->fillTemplateEditor($editor, $maleChunks[$i] ?? [], $femaleChunks[$i] ?? [], $schoolDays);
        }
        $editor->save($filePath);
        return is_file($filePath) && filesize($filePath) > 0;
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
        } else {
            @unlink($tmp);
            throw new RuntimeException('SF2 XLSX export failed.');
        }
        exit();
    }

    private function fillTemplateEditor(SimpleXlsxTemplateEditor $editor, array $maleChunk, array $femaleChunk, array $schoolDays): void {
        $monthName = date('F', mktime(0, 0, 0, $this->month, 1, $this->year));
        $schoolYearStart = $this->month >= 6 ? $this->year : $this->year - 1;
        $schoolYear = $schoolYearStart . '-' . ($schoolYearStart + 1);
        foreach ($this->headerValues($schoolYear, $monthName) as $cell => $value) {
            $editor->setCell($cell, $value);
        }
        $this->writeTemplateCalendarHeader($editor, $schoolDays);

        $groupTotals = [
            'male' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)],
            'female' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)],
            'combined' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)],
        ];
        $this->clearTemplateLearnerBlock($editor, self::MALE_START_ROW, self::MALE_TOTAL_ROW - 1, $schoolDays);
        $this->clearTemplateLearnerBlock($editor, self::FEMALE_START_ROW, self::FEMALE_TOTAL_ROW - 1, $schoolDays);
        $this->clearTemplateTotalsRow($editor, self::MALE_TOTAL_ROW, $schoolDays);
        $this->clearTemplateTotalsRow($editor, self::FEMALE_TOTAL_ROW, $schoolDays);
        $this->clearTemplateTotalsRow($editor, self::COMBINED_TOTAL_ROW, $schoolDays);
        $this->writeTemplateLearnerGroup($editor, $maleChunk, self::MALE_START_ROW, self::MALE_TOTAL_ROW - 1, 'male', $schoolDays, $groupTotals);
        $this->writeTemplateLearnerGroup($editor, $femaleChunk, self::FEMALE_START_ROW, self::FEMALE_TOTAL_ROW - 1, 'female', $schoolDays, $groupTotals);
        $this->writeTemplateTotalsRow($editor, self::MALE_TOTAL_ROW, 'male', $schoolDays, $groupTotals);
        $this->writeTemplateTotalsRow($editor, self::FEMALE_TOTAL_ROW, 'female', $schoolDays, $groupTotals);
        $this->writeTemplateTotalsRow($editor, self::COMBINED_TOTAL_ROW, 'combined', $schoolDays, $groupTotals);
    }

    private function clearTemplateLearnerBlock(SimpleXlsxTemplateEditor $editor, int $startRow, int $endRow, array $schoolDays): void {
        for ($row = $startRow; $row <= $endRow; $row++) {
            $editor->setCell('A' . $row, '');
            $editor->setCell('B' . $row, '');
            foreach ($schoolDays as $di => $_day) {
                $editor->setCell($this->dayColumn($di) . $row, '');
            }
            $editor->setCell(self::ABSENT_COL . $row, '');
            $editor->setCell(self::TARDY_COL . $row, '');
        }
    }

    private function clearTemplateTotalsRow(SimpleXlsxTemplateEditor $editor, int $row, array $schoolDays): void {
        foreach ($schoolDays as $di => $_day) {
            $editor->setCell($this->dayColumn($di) . $row, '');
        }
        $editor->setCell(self::ABSENT_COL . $row, '');
        $editor->setCell(self::TARDY_COL . $row, '');
    }

    private function writeTemplateLearnerGroup(SimpleXlsxTemplateEditor $editor, array $students, int $startRow, int $endRow, string $group, array $schoolDays, array &$groupTotals): void {
        $capacity = max(0, $endRow - $startRow + 1);
        foreach (array_slice($students, 0, $capacity) as $idx => $student) {
            $row = $startRow + $idx;
            $editor->setCell('A' . $row, $idx + 1);
            $editor->setCell(Coordinate::stringFromColumnIndex(self::NAME_COL) . $row, $this->studentDisplayName($student));
            $absentCount = 0;
            $tardyCount = 0;
            foreach ($schoolDays as $di => $day) {
                $col = $this->dayColumn($di);
                $dateStr = sprintf('%04d-%02d-%02d', $this->year, $this->month, $day);
                $status = strtolower(trim((string)($this->attendance[$student['id']][$dateStr] ?? '')));
                $mark = '';
                if ($status === 'present') {
                    $groupTotals[$group]['present_by_day'][$day]++;
                    $groupTotals['combined']['present_by_day'][$day]++;
                } elseif ($status === 'absent') {
                    $mark = 'X';
                    $absentCount++;
                } elseif ($status === 'late' || $status === 'tardy') {
                    $mark = "\u{2580}";
                    $tardyCount++;
                }
                $editor->setCell($col . $row, $mark);
            }
            $groupTotals[$group]['absent'] += $absentCount;
            $groupTotals[$group]['tardy'] += $tardyCount;
            $groupTotals['combined']['absent'] += $absentCount;
            $groupTotals['combined']['tardy'] += $tardyCount;
            $editor->setCell(self::ABSENT_COL . $row, $absentCount);
            $editor->setCell(self::TARDY_COL . $row, $tardyCount);
        }
    }

    private function writeTemplateTotalsRow(SimpleXlsxTemplateEditor $editor, int $row, string $group, array $schoolDays, array $groupTotals): void {
        foreach ($schoolDays as $di => $day) {
            $editor->setCell($this->dayColumn($di) . $row, $groupTotals[$group]['present_by_day'][$day] ?? 0);
        }
        $editor->setCell(self::ABSENT_COL . $row, $groupTotals[$group]['absent'] ?? 0);
        $editor->setCell(self::TARDY_COL . $row, $groupTotals[$group]['tardy'] ?? 0);
    }

    private function clearLearnerBlock($ws, int $startRow, int $endRow, array $schoolDays): void {
        for ($row = $startRow; $row <= $endRow; $row++) {
            $ws->setCellValue('A' . $row, ''); $ws->setCellValue('B' . $row, '');
            foreach ($schoolDays as $di => $_day) { $ws->setCellValue($this->dayColumn($di) . $row, ''); }
            $ws->setCellValue(self::ABSENT_COL . $row, ''); $ws->setCellValue(self::TARDY_COL . $row, '');
        }
    }

    private function clearTotalsRow($ws, int $row, array $schoolDays): void {
        foreach ($schoolDays as $di => $_day) { $ws->setCellValue($this->dayColumn($di) . $row, ''); }
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
                $col = $this->dayColumn($di);
                $dateStr = sprintf('%04d-%02d-%02d', $this->year, $this->month, $day);
                $status = strtolower(trim((string)($this->attendance[$student['id']][$dateStr] ?? '')));
                $mark = '';
                if ($status === 'present') { $groupTotals[$group]['present_by_day'][$day]++; $groupTotals['combined']['present_by_day'][$day]++; }
                elseif ($status === 'absent') { $mark = 'X'; $absentCount++; }
                elseif ($status === 'late' || $status === 'tardy') { $mark = "\u{2580}"; $tardyCount++; }
                $ws->setCellValue($col . $row, $mark);
            }
            $groupTotals[$group]['absent'] += $absentCount; $groupTotals[$group]['tardy'] += $tardyCount;
            $groupTotals['combined']['absent'] += $absentCount; $groupTotals['combined']['tardy'] += $tardyCount;
            $ws->setCellValue(self::ABSENT_COL . $row, $absentCount); $ws->setCellValue(self::TARDY_COL . $row, $tardyCount);
        }
    }

    private function writeTotalsRow($ws, int $row, string $group, array $schoolDays, array $groupTotals): void {
        foreach ($schoolDays as $di => $day) { $ws->setCellValue($this->dayColumn($di) . $row, $groupTotals[$group]['present_by_day'][$day] ?? 0); }
        $ws->setCellValue(self::ABSENT_COL . $row, $groupTotals[$group]['absent'] ?? 0);
        $ws->setCellValue(self::TARDY_COL . $row, $groupTotals[$group]['tardy'] ?? 0);
    }

    private function headerValues(string $schoolYear, string $monthName): array {
        $track = trim((string)($this->class['track_and_strand'] ?? $this->class['strand'] ?? ''));
        if ($track === '') {
            $trackKey = strtolower(trim((string)($this->class['track'] ?? '')));
            $track = match ($trackKey) {
                'academic' => 'Academic Track',
                'techpro', 'tvl', 'technical-vocational-livelihood' => 'Technical-Vocational-Livelihood Track',
                default => trim((string)($this->class['track'] ?? '')),
            };
        }

        $semester = trim((string)($this->class['semester'] ?? ''));
        if ($semester === '') { $semester = 'N/A (SSHS - Three-Term)'; }

        return [
            'F3' => $this->schoolMetaValue('school_name', 'SCHOOL_NAME', 'Balingasag Senior High School'),
            'V3' => $this->schoolMetaValue('school_id', 'SCHOOL_ID', '341227'),
            'AF3' => $this->schoolMetaValue('district', 'SCHOOL_DISTRICT', 'Balingasag North'),
            'AR3' => $this->schoolMetaValue('division', 'SCHOOL_DIVISION', 'Misamis Oriental'),
            'F5' => $semester,
            'V5' => $schoolYear,
            'AH5' => (string)($this->class['grade_level'] ?? ''),
            'AV5' => $track,
            'F7' => (string)($this->class['section'] ?? ''),
            'W7' => (string)($this->class['course'] ?? ''),
            'AV7' => $monthName . ' ' . $this->year,
        ];
    }

    private function writeCalendarHeader($ws, array $schoolDays): void {
        foreach (self::DAY_COLUMNS as $column) {
            $ws->setCellValue($column . '10', '');
            $ws->setCellValue($column . '11', '');
        }
        foreach ($schoolDays as $index => $day) {
            $column = $this->dayColumn($index);
            $ws->setCellValue($column . '10', $day);
            $ws->setCellValue($column . '11', $this->weekdayLabel($day));
        }
    }

    private function writeTemplateCalendarHeader(SimpleXlsxTemplateEditor $editor, array $schoolDays): void {
        foreach (self::DAY_COLUMNS as $column) {
            $editor->setCell($column . '10', '');
            $editor->setCell($column . '11', '');
        }
        foreach ($schoolDays as $index => $day) {
            $column = $this->dayColumn($index);
            $editor->setCell($column . '10', $day);
            $editor->setCell($column . '11', $this->weekdayLabel($day));
        }
    }

    private function dayColumn(int $index): string {
        if (!isset(self::DAY_COLUMNS[$index])) {
            throw new RuntimeException('SF2 template does not have enough attendance day columns.');
        }
        return self::DAY_COLUMNS[$index];
    }

    private function weekdayLabel(int $day): string {
        return match ((int)date('N', mktime(0, 0, 0, $this->month, $day, $this->year))) {
            1 => 'M',
            2 => 'T',
            3 => 'W',
            4 => 'TH',
            5 => 'F',
            6 => 'S',
            default => '',
        };
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
        $localPath = dirname(__DIR__, 2) . '/config/App.local.php';
        if (file_exists($localPath)) {
            $config = require $localPath;
            if (is_array($config)) { $localValue = trim((string)($config[$localKey] ?? '')); if ($localValue !== '') return $localValue; }
        }
        return $fallback;
    }
}
