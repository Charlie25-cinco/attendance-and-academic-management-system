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
    private const REMARKS_COL = 'AV';

    private ?PDO $db = null;

    public function setDb(?PDO $db): void { $this->db = $db; }
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

    private function computeLearnerConsecutiveAbsences(int $studentId, array $schoolDays): int {
        $maxConsecutive = 0;
        $currentStreak = 0;
        foreach ($schoolDays as $day) {
            $dateStr = sprintf('%04d-%02d-%02d', $this->year, $this->month, $day);
            $rec = $this->attendance[$studentId][$dateStr] ?? '';
            $status = is_array($rec) ? strtolower(trim((string)($rec['status'] ?? ''))) : strtolower(trim((string)$rec));
            if ($status === 'absent' || $status === 'cutting') {
                $currentStreak++;
                if ($currentStreak > $maxConsecutive) {
                    $maxConsecutive = $currentStreak;
                }
            } else {
                $currentStreak = 0;
            }
        }
        return $maxConsecutive;
    }

    private function countConsecutiveAbsencesGroup(array $students, array $schoolDays): int {
        $count = 0;
        foreach ($students as $student) {
            $sId = (int)($student['id'] ?? 0);
            if ($sId > 0 && $this->computeLearnerConsecutiveAbsences($sId, $schoolDays) >= 5) {
                $count++;
            }
        }
        return $count;
    }

    private function resolveLearnerRemark(array $student, array $schoolDays): string {
        $studentId = (int)($student['id'] ?? 0);
        $explicitRemarks = [];
        if (!empty($student['remarks'])) {
            $explicitRemarks[] = trim((string)$student['remarks']);
        }
        foreach ($schoolDays as $day) {
            $dateStr = sprintf('%04d-%02d-%02d', $this->year, $this->month, $day);
            $rec = $this->attendance[$studentId][$dateStr] ?? null;
            if (is_array($rec) && !empty($rec['remarks'])) {
                $rem = trim((string)$rec['remarks']);
                if (!in_array($rem, $explicitRemarks, true)) {
                    $explicitRemarks[] = $rem;
                }
            }
        }
        if ($studentId > 0 && $this->computeLearnerConsecutiveAbsences($studentId, $schoolDays) >= 5) {
            if (empty($explicitRemarks)) {
                $explicitRemarks[] = '5 consecutive days absent';
            }
        }
        return implode('; ', $explicitRemarks);
    }

    private function computeSummaryMetrics(array $groups, array $schoolDays, array $groupTotals): array {
        $schoolDaysCount = count($schoolDays);
        $totalMaleReg = count($groups['male']);
        $totalFemaleReg = count($groups['female']);
        $totalCombinedReg = $totalMaleReg + $totalFemaleReg;

        $sumDailyPresentM = array_sum($groupTotals['male']['present_by_day'] ?? []);
        $sumDailyPresentF = array_sum($groupTotals['female']['present_by_day'] ?? []);
        $sumDailyPresentCombined = array_sum($groupTotals['combined']['present_by_day'] ?? []);

        $adaM = $schoolDaysCount > 0 ? round($sumDailyPresentM / $schoolDaysCount, 2) : 0;
        $adaF = $schoolDaysCount > 0 ? round($sumDailyPresentF / $schoolDaysCount, 2) : 0;
        $adaCombined = $schoolDaysCount > 0 ? round($sumDailyPresentCombined / $schoolDaysCount, 2) : 0;

        $paM = $totalMaleReg > 0 ? round(($adaM / $totalMaleReg) * 100, 2) : 0;
        $paF = $totalFemaleReg > 0 ? round(($adaF / $totalFemaleReg) * 100, 2) : 0;
        $paCombined = $totalCombinedReg > 0 ? round(($adaCombined / $totalCombinedReg) * 100, 2) : 0;

        $consecutive5M = $this->countConsecutiveAbsencesGroup($groups['male'], $schoolDays);
        $consecutive5F = $this->countConsecutiveAbsencesGroup($groups['female'], $schoolDays);
        $consecutive5Combined = $consecutive5M + $consecutive5F;

        return [
            'school_days' => $schoolDaysCount,
            'enrolment_1st_friday' => [
                'male' => $totalMaleReg,
                'female' => $totalFemaleReg,
                'total' => $totalCombinedReg,
            ],
            'late_enrolment' => [
                'male' => 0,
                'female' => 0,
                'total' => 0,
            ],
            'registered_end_of_month' => [
                'male' => $totalMaleReg,
                'female' => $totalFemaleReg,
                'total' => $totalCombinedReg,
            ],
            'percentage_of_enrolment' => [
                'male' => $totalMaleReg > 0 ? '100%' : '0%',
                'female' => $totalFemaleReg > 0 ? '100%' : '0%',
                'total' => $totalCombinedReg > 0 ? '100%' : '0%',
            ],
            'average_daily_attendance' => [
                'male' => $adaM,
                'female' => $adaF,
                'total' => $adaCombined,
            ],
            'percentage_of_attendance' => [
                'male' => $paM . '%',
                'female' => $paF . '%',
                'total' => $paCombined . '%',
            ],
            'consecutive_5_absent' => [
                'male' => $consecutive5M,
                'female' => $consecutive5F,
                'total' => $consecutive5Combined,
            ],
            'nls' => ['male' => 0, 'female' => 0, 'total' => 0],
            'transferred_out' => ['male' => 0, 'female' => 0, 'total' => 0],
            'transferred_in' => ['male' => 0, 'female' => 0, 'total' => 0],
            'shifting_out' => ['male' => 0, 'female' => 0, 'total' => 0],
            'shifting_in' => ['male' => 0, 'female' => 0, 'total' => 0],
        ];
    }

    private function fillSheet($ws, array $maleChunk, array $femaleChunk, array $schoolDays, int $sheetNumber, array $groups): void {
        $monthName = date('F', mktime(0, 0, 0, $this->month, 1, $this->year));
        $schoolYearStart = $this->month >= 6 ? $this->year : $this->year - 1;
        $schoolYear = $schoolYearStart . '-' . ($schoolYearStart + 1);
        $sheetTitle = $sheetNumber === 1 ? 'SHSF-2' : 'SF2 (' . $sheetNumber . ')';
        $ws->setTitle($sheetTitle);
        foreach ($this->headerValues($schoolYear, $monthName) as $cell => $value) {
            $ws->setCellValue($cell, $value);
        }
        $this->writeCalendarHeader($ws, $schoolDays);
        $groupTotals = [
            'male' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)],
            'female' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)],
            'combined' => ['absent' => 0, 'tardy' => 0, 'present_by_day' => array_fill_keys($schoolDays, 0)]
        ];
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

        $summaryMetrics = $this->computeSummaryMetrics($groups, $schoolDays, $groupTotals);
        $this->writeSummaryBox($ws, $summaryMetrics, $monthName);
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
        $this->fillSheet($ws, $maleChunks[0] ?? [], $femaleChunks[0] ?? [], $schoolDays, 1, $groups);
        for ($i = 1; $i < $sheetCount; $i++) {
            $cloneIndex = $i + 1;
            $newWs = clone $templateSheet;
            $this->fillSheet($newWs, $maleChunks[$i] ?? [], $femaleChunks[$i] ?? [], $schoolDays, $cloneIndex, $groups);
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
        $this->fillTemplateEditor($editor, $maleChunks[0] ?? [], $femaleChunks[0] ?? [], $schoolDays, $groups);
        for ($i = 1; $i < $sheetCount; $i++) {
            $editor->duplicateTemplateSheet('SF2 (' . ($i + 1) . ')');
            $this->fillTemplateEditor($editor, $maleChunks[$i] ?? [], $femaleChunks[$i] ?? [], $schoolDays, $groups);
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

    private function fillTemplateEditor(SimpleXlsxTemplateEditor $editor, array $maleChunk, array $femaleChunk, array $schoolDays, array $groups): void {
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

        $summaryMetrics = $this->computeSummaryMetrics($groups, $schoolDays, $groupTotals);
        $this->writeTemplateSummaryBox($editor, $summaryMetrics, $monthName);
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
            $editor->setCell(self::REMARKS_COL . $row, '');
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
                $rec = $this->attendance[$student['id']][$dateStr] ?? '';
                $status = is_array($rec) ? strtolower(trim((string)($rec['status'] ?? ''))) : strtolower(trim((string)$rec));
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
                } elseif ($status === 'cutting') {
                    $mark = "\u{2584}";
                    $absentCount++;
                }
                $editor->setCell($col . $row, $mark);
            }
            $groupTotals[$group]['absent'] += $absentCount;
            $groupTotals[$group]['tardy'] += $tardyCount;
            $groupTotals['combined']['absent'] += $absentCount;
            $groupTotals['combined']['tardy'] += $tardyCount;
            $editor->setCell(self::ABSENT_COL . $row, $absentCount);
            $editor->setCell(self::TARDY_COL . $row, $tardyCount);
            $editor->setCell(self::REMARKS_COL . $row, $this->resolveLearnerRemark($student, $schoolDays));
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
            $ws->setCellValue(self::ABSENT_COL . $row, '');
            $ws->setCellValue(self::TARDY_COL . $row, '');
            $ws->setCellValue(self::REMARKS_COL . $row, '');
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
                $rec = $this->attendance[$student['id']][$dateStr] ?? '';
                $status = is_array($rec) ? strtolower(trim((string)($rec['status'] ?? ''))) : strtolower(trim((string)$rec));
                $mark = '';
                if ($status === 'present') { $groupTotals[$group]['present_by_day'][$day]++; $groupTotals['combined']['present_by_day'][$day]++; }
                elseif ($status === 'absent') { $mark = 'X'; $absentCount++; }
                elseif ($status === 'late' || $status === 'tardy') { $mark = "\u{2580}"; $tardyCount++; }
                elseif ($status === 'cutting') { $mark = "\u{2584}"; $absentCount++; }
                $ws->setCellValue($col . $row, $mark);
            }
            $groupTotals[$group]['absent'] += $absentCount; $groupTotals[$group]['tardy'] += $tardyCount;
            $groupTotals['combined']['absent'] += $absentCount; $groupTotals['combined']['tardy'] += $tardyCount;
            $ws->setCellValue(self::ABSENT_COL . $row, $absentCount);
            $ws->setCellValue(self::TARDY_COL . $row, $tardyCount);
            $ws->setCellValue(self::REMARKS_COL . $row, $this->resolveLearnerRemark($student, $schoolDays));
        }
    }

    private function writeTotalsRow($ws, int $row, string $group, array $schoolDays, array $groupTotals): void {
        foreach ($schoolDays as $di => $day) { $ws->setCellValue($this->dayColumn($di) . $row, $groupTotals[$group]['present_by_day'][$day] ?? 0); }
        $ws->setCellValue(self::ABSENT_COL . $row, $groupTotals[$group]['absent'] ?? 0);
        $ws->setCellValue(self::TARDY_COL . $row, $groupTotals[$group]['tardy'] ?? 0);
    }

    private function writeSummaryBox($ws, array $metrics, string $monthName): void {
        $ws->setCellValue('AR61', $monthName);
        $ws->setCellValue('AU61', $metrics['school_days']);

        $ws->setCellValue('AW62', $metrics['enrolment_1st_friday']['male']);
        $ws->setCellValue('AX62', $metrics['enrolment_1st_friday']['female']);
        $ws->setCellValue('AY62', $metrics['enrolment_1st_friday']['total']);

        $ws->setCellValue('AW63', $metrics['late_enrolment']['male']);
        $ws->setCellValue('AX63', $metrics['late_enrolment']['female']);
        $ws->setCellValue('AY63', $metrics['late_enrolment']['total']);

        $ws->setCellValue('AW64', $metrics['registered_end_of_month']['male']);
        $ws->setCellValue('AX64', $metrics['registered_end_of_month']['female']);
        $ws->setCellValue('AY64', $metrics['registered_end_of_month']['total']);

        $ws->setCellValue('AW65', $metrics['percentage_of_enrolment']['male']);
        $ws->setCellValue('AX65', $metrics['percentage_of_enrolment']['female']);
        $ws->setCellValue('AY65', $metrics['percentage_of_enrolment']['total']);

        $ws->setCellValue('AW66', $metrics['average_daily_attendance']['male']);
        $ws->setCellValue('AX66', $metrics['average_daily_attendance']['female']);
        $ws->setCellValue('AY66', $metrics['average_daily_attendance']['total']);

        $ws->setCellValue('AW67', $metrics['percentage_of_attendance']['male']);
        $ws->setCellValue('AX67', $metrics['percentage_of_attendance']['female']);
        $ws->setCellValue('AY67', $metrics['percentage_of_attendance']['total']);

        $ws->setCellValue('AW68', $metrics['consecutive_5_absent']['male']);
        $ws->setCellValue('AX68', $metrics['consecutive_5_absent']['female']);
        $ws->setCellValue('AY68', $metrics['consecutive_5_absent']['total']);

        $ws->setCellValue('AW70', $metrics['nls']['male']);
        $ws->setCellValue('AX70', $metrics['nls']['female']);
        $ws->setCellValue('AY70', $metrics['nls']['total']);

        $ws->setCellValue('AW71', $metrics['transferred_out']['male']);
        $ws->setCellValue('AX71', $metrics['transferred_out']['female']);
        $ws->setCellValue('AY71', $metrics['transferred_out']['total']);

        $ws->setCellValue('AW72', $metrics['transferred_in']['male']);
        $ws->setCellValue('AX72', $metrics['transferred_in']['female']);
        $ws->setCellValue('AY72', $metrics['transferred_in']['total']);

        $ws->setCellValue('AW73', $metrics['shifting_out']['male']);
        $ws->setCellValue('AX73', $metrics['shifting_out']['female']);
        $ws->setCellValue('AY73', $metrics['shifting_out']['total']);

        $ws->setCellValue('AW74', $metrics['shifting_in']['male']);
        $ws->setCellValue('AX74', $metrics['shifting_in']['female']);
        $ws->setCellValue('AY74', $metrics['shifting_in']['total']);

        $adviserName = mb_strtoupper($this->teacherName !== '' ? $this->teacherName : $this->schoolMetaValue('adviser_name', 'ADVISER_NAME', 'CLASS ADVISER'));
        $principalName = mb_strtoupper($this->schoolMetaValue('principal_name', 'SCHOOL_HEAD', 'SCHOOL PRINCIPAL'));

        $ws->setCellValue('AS80', $adviserName);
        $ws->setCellValue('AS85', $principalName);
    }

    private function writeTemplateSummaryBox(SimpleXlsxTemplateEditor $editor, array $metrics, string $monthName): void {
        $editor->setCell('AR61', $monthName);
        $editor->setCell('AU61', $metrics['school_days']);

        $editor->setCell('AW62', $metrics['enrolment_1st_friday']['male']);
        $editor->setCell('AX62', $metrics['enrolment_1st_friday']['female']);
        $editor->setCell('AY62', $metrics['enrolment_1st_friday']['total']);

        $editor->setCell('AW63', $metrics['late_enrolment']['male']);
        $editor->setCell('AX63', $metrics['late_enrolment']['female']);
        $editor->setCell('AY63', $metrics['late_enrolment']['total']);

        $editor->setCell('AW64', $metrics['registered_end_of_month']['male']);
        $editor->setCell('AX64', $metrics['registered_end_of_month']['female']);
        $editor->setCell('AY64', $metrics['registered_end_of_month']['total']);

        $editor->setCell('AW65', $metrics['percentage_of_enrolment']['male']);
        $editor->setCell('AX65', $metrics['percentage_of_enrolment']['female']);
        $editor->setCell('AY65', $metrics['percentage_of_enrolment']['total']);

        $editor->setCell('AW66', $metrics['average_daily_attendance']['male']);
        $editor->setCell('AX66', $metrics['average_daily_attendance']['female']);
        $editor->setCell('AY66', $metrics['average_daily_attendance']['total']);

        $editor->setCell('AW67', $metrics['percentage_of_attendance']['male']);
        $editor->setCell('AX67', $metrics['percentage_of_attendance']['female']);
        $editor->setCell('AY67', $metrics['percentage_of_attendance']['total']);

        $editor->setCell('AW68', $metrics['consecutive_5_absent']['male']);
        $editor->setCell('AX68', $metrics['consecutive_5_absent']['female']);
        $editor->setCell('AY68', $metrics['consecutive_5_absent']['total']);

        $editor->setCell('AW70', $metrics['nls']['male']);
        $editor->setCell('AX70', $metrics['nls']['female']);
        $editor->setCell('AY70', $metrics['nls']['total']);

        $editor->setCell('AW71', $metrics['transferred_out']['male']);
        $editor->setCell('AX71', $metrics['transferred_out']['female']);
        $editor->setCell('AY71', $metrics['transferred_out']['total']);

        $editor->setCell('AW72', $metrics['transferred_in']['male']);
        $editor->setCell('AX72', $metrics['transferred_in']['female']);
        $editor->setCell('AY72', $metrics['transferred_in']['total']);

        $editor->setCell('AW73', $metrics['shifting_out']['male']);
        $editor->setCell('AX73', $metrics['shifting_out']['female']);
        $editor->setCell('AY73', $metrics['shifting_out']['total']);

        $editor->setCell('AW74', $metrics['shifting_in']['male']);
        $editor->setCell('AX74', $metrics['shifting_in']['female']);
        $editor->setCell('AY74', $metrics['shifting_in']['total']);

        $adviserName = mb_strtoupper($this->teacherName !== '' ? $this->teacherName : $this->schoolMetaValue('adviser_name', 'ADVISER_NAME', 'CLASS ADVISER'));
        $principalName = mb_strtoupper($this->schoolMetaValue('principal_name', 'SCHOOL_HEAD', 'SCHOOL PRINCIPAL'));

        $editor->setCell('AS80', $adviserName);
        $editor->setCell('AS85', $principalName);
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
        if ($this->db instanceof PDO && function_exists('getSchoolSetting')) {
            $dbVal = trim(getSchoolSetting($this->db, $localKey, ''));
            if ($dbVal !== '') return $dbVal;
        }
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
