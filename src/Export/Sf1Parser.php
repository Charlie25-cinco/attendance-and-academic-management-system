<?php

namespace BshsAms\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use BshsAms\Xlsx\SimpleXlsxParser;
use DateTime;
use Exception;

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
            if (stripos($name, 'SHSF-1') !== false || stripos($name, 'SF1') !== false || stripos($name, 'SF-1') !== false || stripos($name, 'SF 1') !== false) {
                $sf1Index = $i;
                break;
            }
        }
        if ($sf1Index === null && !empty($sheets)) {
            $sf1Index = 0;
        }
        if ($sf1Index === null) {
            $this->errors[] = 'No sheets found in the workbook.';
            return ['header' => [], 'students' => [], 'errors' => $this->errors, 'warnings' => $this->warnings];
        }

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
            'school_name'  => $this->firstCell($data, 3, range(2, 10)),
            'school_id'    => $this->firstCell($data, 3, range(10, 18)),
            'district'     => $this->firstCell($data, 3, range(18, 24)),
            'division'     => $this->firstCell($data, 3, range(24, 30)),
            'region'       => $this->firstCell($data, 3, range(30, 36)),
            'semester'     => $this->firstCell($data, 5, range(4, 10)),
            'school_year'  => $this->firstCell($data, 5, range(11, 19)),
            'grade_level'  => $this->firstCell($data, 5, range(20, 26)),
            'track_strand' => $this->firstCell($data, 5, range(25, 35)),
            'section'      => $this->firstCell($data, 7, range(1, 10)),
            'course_tvl'   => $this->firstCell($data, 7, range(11, 23)),
        ];
    }

    private function parseStudents(array $data): array {
        $students = [];
        $maxRow = max(250, count($data) + 20);
        for ($row = 8; $row <= $maxRow; $row++) {
            if (!isset($data[$row])) continue;

            $col0 = $this->cell($data, $row, 0);
            $col1 = $this->cell($data, $row, 1);
            $col2 = $this->cell($data, $row, 2);
            $col3 = $this->cell($data, $row, 3);
            $col4 = $this->cell($data, $row, 4);
            $col5 = $this->cell($data, $row, 5);

            $markerText = strtoupper($col0 . ' ' . $col1 . ' ' . $col2 . ' ' . $col3 . ' ' . $col4);
            if (
                str_contains($markerText, '<===') ||
                str_contains($markerText, 'REQUIRED INFORMATION') ||
                str_contains($markerText, 'NAME OF SCHOOL, DATE') ||
                str_contains($markerText, 'TOTAL MALE') ||
                str_contains($markerText, 'TOTAL FEMALE') ||
                str_contains($markerText, 'COMBINED') ||
                str_contains($markerText, 'REGISTERED LEARNERS') ||
                str_contains($markerText, 'END OF SCHOOL YEAR') ||
                str_contains($markerText, 'LIST OF LEARNERS') ||
                str_contains($markerText, 'SUMMARY TABLE') ||
                str_contains($markerText, 'PREPARED BY') ||
                str_contains($markerText, 'ADVISER SIGNATURE') ||
                str_contains($markerText, 'SCHOOL NAME') ||
                str_contains($markerText, 'SCHOOL ID') ||
                str_contains($markerText, 'TRACK AND STRAND') ||
                $markerText === 'MALE' ||
                $markerText === 'FEMALE'
            ) {
                continue;
            }

            // Skip table header rows
            if (
                (str_contains($markerText, 'LRN') && (str_contains($markerText, 'NAME') || str_contains($markerText, 'LAST') || str_contains($markerText, 'SEX') || str_contains($markerText, 'BIRTHDATE'))) ||
                (str_contains($markerText, 'NO.') && str_contains($markerText, 'LRN'))
            ) {
                continue;
            }

            // Find LRN (12 digits) in cols 0, 1, 2
            $lrn = '';
            if (preg_match('/\b\d{12}\b/', preg_replace('/\s+/', '', $col0), $m)) {
                $lrn = $m[0];
            } elseif (preg_match('/\b\d{12}\b/', preg_replace('/\s+/', '', $col1), $m)) {
                $lrn = $m[0];
            } elseif (preg_match('/\b\d{12}\b/', preg_replace('/\s+/', '', $col2), $m)) {
                $lrn = $m[0];
            }

            if ($lrn !== '' && $lrn === preg_replace('/\D/', '', $col0)) {
                $name = $this->firstCell($data, $row, range(1, 5));
            } else {
                $name = $this->firstCell($data, $row, range(2, 5));
            }

            $parsedName = ['last_name' => '', 'first_name' => '', 'middle_name' => '', 'name_extension' => ''];
            if ($col2 !== '' && $col3 !== '' && !str_contains($col2, ',') && !is_numeric($col2)) {
                $ext = '';
                $mid = '';
                if (self::isNameExtension($col4)) { $ext = $col4; $mid = $col5; }
                elseif (self::isNameExtension($col5)) { $ext = $col5; $mid = $col4; }
                else { $mid = $col5 !== '' ? $col5 : $col4; }

                $parsedName = [
                    'last_name' => $col2,
                    'first_name' => $col3,
                    'name_extension' => $ext,
                    'middle_name' => $mid,
                ];
            } elseif ($name !== '') {
                $parsedName = self::parseLearnerName($name);
            }

            if (empty($parsedName['last_name']) && empty($parsedName['first_name'])) {
                continue;
            }

            $lastNameUpper = strtoupper($parsedName['last_name']);
            if (in_array($lastNameUpper, ['SEMESTER', 'SECTION', 'GRADE', 'SCHOOL', 'TRACK', 'COURSE', 'NO.', 'LRN', 'NAME'], true)) {
                continue;
            }

            $rawSex = $this->firstCell($data, $row, [6, 7, 8]);
            $sex = self::normalizeSex($rawSex);
            $birthdate = $this->firstCell($data, $row, [7, 8, 9]);

            $student = [
                'lrn' => $lrn ?: ($col0 !== '' && is_numeric($col0) && strlen($col0) >= 6 ? $col0 : ($col1 !== '' && is_numeric($col1) && strlen($col1) >= 6 ? $col1 : '')),
                'last_name' => $parsedName['last_name'],
                'first_name' => $parsedName['first_name'],
                'name_extension' => $parsedName['name_extension'],
                'middle_name' => $parsedName['middle_name'],
                'sex' => $sex ?: $rawSex,
                'birthdate' => $birthdate,
                'age' => $this->firstCell($data, $row, [9, 10]),
                'religion' => $this->firstCell($data, $row, [11]),
                'house_street' => $this->firstCell($data, $row, [12]),
                'barangay' => $this->firstCell($data, $row, [13, 14, 15, 16]),
                'municipality' => $this->firstCell($data, $row, [17, 18, 19]),
                'province' => $this->firstCell($data, $row, [20, 21]),
                'father_name' => $this->firstCell($data, $row, [22]),
                'mother_name' => $this->firstCell($data, $row, [23, 24]),
                'guardian_name' => $this->firstCell($data, $row, [25, 26, 27]),
                'relationship' => $this->firstCell($data, $row, [28]),
                'contact_number' => $this->firstCell($data, $row, [29]),
                'remarks' => $this->firstCell($data, $row, [30]),
            ];
            $student['address'] = implode(', ', array_filter([$student['house_street'], $student['barangay'], $student['municipality'], $student['province']]));
            $students[] = $student;
        }
        return $students;
    }

    private function sf1Lrn(array $data, int $row): string {
        $columnA = $this->cell($data, $row, 0);
        $columnB = $this->cell($data, $row, 1);
        $columnC = $this->cell($data, $row, 2);
        if (preg_match('/^\d{12}$/', preg_replace('/\D/', '', $columnA))) {
            return $columnA;
        }
        if (preg_match('/^\d{12}$/', preg_replace('/\D/', '', $columnB))) {
            return $columnB;
        }
        if (preg_match('/^\d{12}$/', preg_replace('/\D/', '', $columnC))) {
            return $columnC;
        }
        return $columnA !== '' ? $columnA : $columnB;
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
