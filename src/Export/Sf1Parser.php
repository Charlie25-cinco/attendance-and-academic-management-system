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
            if (stripos($name, 'SHSF-1') !== false || stripos($name, 'SF1') !== false) { $sf1Index = $i; break; }
        }
        if ($sf1Index === null) { $this->errors[] = 'SHSF-1 sheet not found in the workbook.'; return ['header' => [], 'students' => [], 'errors' => $this->errors, 'warnings' => $this->warnings]; }

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
            'school_name' => $this->firstCell($data, 3, range(5, 8)),
            'school_id' => $this->firstCell($data, 3, range(12, 16)),
            'district' => $this->firstCell($data, 3, range(20, 22)),
            'division' => $this->firstCell($data, 3, range(25, 29)),
            'region' => $this->firstCell($data, 3, range(31, 34)),
            'semester' => $this->firstCell($data, 5, range(5, 8)),
            'school_year' => $this->firstCell($data, 5, range(12, 18)),
            'grade_level' => $this->firstCell($data, 5, range(22, 24)),
            'track_strand' => $this->firstCell($data, 5, range(28, 31)),
            'section' => $this->firstCell($data, 7, range(5, 8)),
            'course_tvl' => $this->firstCell($data, 7, range(15, 22)),
        ];
    }

    private function parseStudents(array $data): array {
        $students = [];
        for ($row = 11; $row <= 100; $row++) {
            if (!isset($data[$row])) continue;
            $lrn = $this->sf1Lrn($data, $row);
            $name = $this->cell($data, $row, 2);
            if ($lrn === '' && $name === '') continue;
            $markerText = strtoupper($lrn . ' ' . $name);
            if (str_contains($markerText, '<===') || str_contains($markerText, 'REQUIRED INFORMATION') || str_contains($markerText, 'NAME OF SCHOOL, DATE')) {
                continue;
            }
            if ($name === '' && preg_replace('/\D/', '', $lrn) === '') {
                continue;
            }
            $parsedName = self::parseLearnerName($name);
            $student = [
                'lrn' => $lrn, 'last_name' => $parsedName['last_name'], 'first_name' => $parsedName['first_name'],
                'name_extension' => $parsedName['name_extension'], 'middle_name' => $parsedName['middle_name'],
                'sex' => $this->cell($data, $row, 6), 'birthdate' => $this->cell($data, $row, 7),
                'age' => $this->cell($data, $row, 9), 'religion' => $this->cell($data, $row, 11),
                'house_street' => $this->cell($data, $row, 12), 'barangay' => $this->cell($data, $row, 13),
                'municipality' => $this->cell($data, $row, 17), 'province' => $this->cell($data, $row, 20),
                'father_name' => $this->cell($data, $row, 22), 'mother_name' => $this->cell($data, $row, 23),
                'guardian_name' => $this->cell($data, $row, 25), 'relationship' => $this->cell($data, $row, 28),
                'contact_number' => $this->cell($data, $row, 29), 'remarks' => $this->cell($data, $row, 30),
            ];
            $student['address'] = implode(', ', array_filter([$student['house_street'], $student['barangay'], $student['municipality'], $student['province']]));
            $students[] = $student;
        }
        return $students;
    }

    private function sf1Lrn(array $data, int $row): string {
        $columnA = $this->cell($data, $row, 0);
        $columnB = $this->cell($data, $row, 1);
        if (preg_match('/^\d{12}$/', preg_replace('/\D/', '', $columnA))) {
            return $columnA;
        }
        return $columnB;
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
