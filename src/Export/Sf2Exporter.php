<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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
        $localPath = dirname(__DIR__, 2) . '/config/App.local.php';
        if (file_exists($localPath)) {
            $config = require $localPath;
            if (is_array($config)) { $localValue = trim((string)($config[$localKey] ?? '')); if ($localValue !== '') return $localValue; }
        }
        return $fallback;
    }
}
