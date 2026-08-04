<?php

namespace BshsAms\Grade;

class SshsGradeCalculator
{
    private const TRANSMUTATION_TABLE = [
        [0, 60], [4, 61], [8, 62], [12, 63], [16, 64], [20, 65], [24, 66], [28, 67], [32, 68], [36, 69],
        [40, 70], [44, 71], [48, 72], [52, 73], [56, 74], [60, 75],
        [61.6, 76], [63.2, 77], [64.8, 78], [66.4, 79],
        [68, 80], [69.6, 81], [71.2, 82], [72.8, 83], [74.4, 84],
        [76, 85], [77.6, 86], [79.2, 87], [80.8, 88], [82.4, 89],
        [84, 90], [85.6, 91], [87.2, 92], [88.8, 93], [90.4, 94],
        [92, 95], [93.6, 96], [95.2, 97], [96.8, 98], [98.4, 99],
        [100, 100],
    ];

    public static function defaultWeights(string $subjectCategory): array
    {
        return match ($subjectCategory) {
            'core' => ['ww' => 25, 'pt' => 50, 'assessment' => 25],
            'academic_elective' => ['ww' => 25, 'pt' => 45, 'assessment' => 30],
            'techpro_elective' => ['ww' => 20, 'pt' => 60, 'assessment' => 20],
            'field_experience_elective' => ['ww' => 15, 'pt' => 65, 'assessment' => 20],
            'other_elective' => ['ww' => 20, 'pt' => 50, 'assessment' => 30],
            'work_immersion' => ['ww' => 20, 'pt' => 80, 'assessment' => 0],
            default => ['ww' => 25, 'pt' => 50, 'assessment' => 25],
        };
    }

    public static function transmute(?float $initial): ?float
    {
        if ($initial === null || $initial < 0) return null;
        $result = null;
        foreach (self::TRANSMUTATION_TABLE as $row) {
            if ($initial >= (float)$row[0]) { $result = (float)$row[1]; } else { break; }
        }
        return $result;
    }

    public static function proficiencyLevel(?float $grade): string
    {
        if ($grade === null) return 'N/A';
        if ($grade >= 90) return 'Outstanding';
        if ($grade >= 85) return 'Very Satisfactory';
        if ($grade >= 80) return 'Satisfactory';
        if ($grade >= 75) return 'Fairly Satisfactory';
        return 'Did Not Meet Expectations';
    }

    public static function sf9Level(?float $g): string
    {
        if ($g === null) return '';
        if ($g >= 90) return 'O';
        if ($g >= 85) return 'VS';
        if ($g >= 80) return 'S';
        if ($g >= 75) return 'FS';
        return 'DNME';
    }

    public static function promotionStatus(?float $grade): string
    {
        if ($grade === null) return 'No Grade';
        return $grade >= 75 ? 'Promoted' : 'Retained/For Remediation';
    }

    public static function subjectCategoryLabel(string $category): string
    {
        return match ($category) {
            'core' => 'Core Subject',
            'academic_elective' => 'Academic Elective',
            'techpro_elective' => 'TechPro Elective',
            'work_immersion' => 'Work Immersion',
            'field_experience_elective' => 'Field Experience / Sports & Arts Elective',
            'other_elective' => 'Other Elective',
            default => 'Core Subject',
        };
    }

    public static function trackLabel(string $track): string
    {
        return match ($track) {
            'academic' => 'Academic Track',
            'techpro' => 'TechPro Track',
            default => 'Academic Track',
        };
    }

    public static function gradingSystem(?string $academicYear): string
    {
        if ($academicYear === null || $academicYear === '') { return '3_term'; }
        preg_match('/^(\d{4})-\d{4}$/', $academicYear, $m);
        $startYear = (int)($m[1] ?? 0);
        return $startYear >= 2026 ? '3_term' : '4_quarter';
    }

    public static function validTerms(string $gradingSystem): array
    {
        return $gradingSystem === '4_quarter' ? ['Q1', 'Q2', 'Q3', 'Q4'] : ['Term1', 'Term2', 'Term3'];
    }

    public static function termLabel(string $term): string
    {
        return match ($term) {
            'Term1' => '1st Term', 'Term2' => '2nd Term', 'Term3' => '3rd Term',
            'Q1' => '1st Quarter', 'Q2' => '2nd Quarter', 'Q3' => '3rd Quarter', 'Q4' => '4th Quarter',
            default => $term,
        };
    }

    public static function subjectTermCount(string $subjectCategory, string $gradingSystem): int
    {
        if ($gradingSystem === '4_quarter') {
            return match ($subjectCategory) {
                'core', 'techpro_elective' => 4,
                'academic_elective' => 2,
                'work_immersion', 'field_experience_elective', 'other_elective' => 1,
                default => 4,
            };
        }
        return match ($subjectCategory) {
            'core', 'techpro_elective' => 3,
            'academic_elective', 'work_immersion', 'field_experience_elective', 'other_elective' => 1,
            default => 3,
        };
    }

    public static function finalGrade(array $termGrades): ?float
    {
        $valid = array_filter($termGrades, fn($v) => $v !== null);
        if (empty($valid)) return null;
        return round(array_sum($valid) / count($valid));
    }

    public static function isCombinedSubject(string $subjectName): bool
    {
        $name = strtolower(trim($subjectName));
        return str_contains($name, 'effective communication')
            || str_contains($name, 'mabisang komunikasyon')
            || str_contains($name, 'effective comm');
    }

    public static function combinedSubjectKey(string $subjectName): string
    {
        $name = strtolower(trim($subjectName));
        if (str_contains($name, 'effective')) return 'ec';
        if (str_contains($name, 'mabisang')) return 'mk';
        return '';
    }

    public static function combinedDisplayName(): string
    {
        return 'Effective Communication / Mabisang Komunikasyon';
    }

    public static function combineGrades(?float $a, ?float $b): ?float
    {
        if ($a === null && $b === null) return null;
        if ($a === null) return $b;
        if ($b === null) return $a;
        return round(($a + $b) / 2);
    }

    public static function normalizeTerm(string $value): string
    {
        $upper = strtoupper(trim($value));
        $map = [
            'TERM1' => 'Term1', 'TERM 1' => 'Term1', '1ST TERM' => 'Term1',
            'TERM2' => 'Term2', 'TERM 2' => 'Term2', '2ND TERM' => 'Term2',
            'TERM3' => 'Term3', 'TERM 3' => 'Term3', '3RD TERM' => 'Term3',
            'Q1' => 'Q1', 'Q2' => 'Q2', 'Q3' => 'Q3', 'Q4' => 'Q4',
        ];
        return $map[$upper] ?? 'Term1';
    }

    public static function sectionHeaderLabel(string $subjectCategory): string
    {
        return match ($subjectCategory) {
            'core' => 'CORE SUBJECTS',
            'academic_elective' => 'ACADEMIC ELECTIVES',
            'techpro_elective' => 'TECHNICAL PROFESSIONAL (TECHPRO) ELECTIVES',
            'work_immersion' => 'WORK IMMERSION',
            'field_experience_elective' => 'FIELD EXPERIENCE / EXPOSURE / SPORTS & ARTS ELECTIVES',
            default => 'OTHER SUBJECTS',
        };
    }
}
