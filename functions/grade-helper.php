<?php

class SshsGradeCalculator {
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

    public static function defaultWeights(string $subjectCategory): array {
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

    public static function transmute(?float $initial): ?float {
        if ($initial === null || $initial < 0) return null;
        $result = null;
        foreach (self::TRANSMUTATION_TABLE as $row) {
            if ($initial >= (float)$row[0]) { $result = (float)$row[1]; } else { break; }
        }
        return $result;
    }

    public static function proficiencyLevel(?float $grade): string {
        if ($grade === null) return 'N/A';
        if ($grade >= 90) return 'Outstanding';
        if ($grade >= 85) return 'Very Satisfactory';
        if ($grade >= 80) return 'Satisfactory';
        if ($grade >= 75) return 'Fairly Satisfactory';
        return 'Did Not Meet Expectations';
    }

    public static function sf9Level(?float $g): string {
        if ($g === null) return '';
        if ($g >= 90) return 'O';
        if ($g >= 85) return 'VS';
        if ($g >= 80) return 'S';
        if ($g >= 75) return 'FS';
        return 'DNME';
    }

    public static function promotionStatus(?float $grade): string {
        if ($grade === null) return 'No Grade';
        return $grade >= 75 ? 'Promoted' : 'Retained/For Remediation';
    }

    public static function subjectCategoryLabel(string $category): string {
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

    public static function trackLabel(string $track): string {
        return match ($track) {
            'academic' => 'Academic Track',
            'techpro' => 'TechPro Track',
            default => 'Academic Track',
        };
    }

    public static function gradingSystem(?string $academicYear): string {
        if ($academicYear === null || $academicYear === '') { return '3_term'; }
        preg_match('/^(\d{4})-\d{4}$/', $academicYear, $m);
        $startYear = (int)($m[1] ?? 0);
        return $startYear >= 2026 ? '3_term' : '4_quarter';
    }

    public static function validTerms(string $gradingSystem): array {
        return $gradingSystem === '4_quarter' ? ['Q1', 'Q2', 'Q3', 'Q4'] : ['Term1', 'Term2', 'Term3'];
    }

    public static function termLabel(string $term): string {
        return match ($term) {
            'Term1' => '1st Term', 'Term2' => '2nd Term', 'Term3' => '3rd Term',
            'Q1' => '1st Quarter', 'Q2' => '2nd Quarter', 'Q3' => '3rd Quarter', 'Q4' => '4th Quarter',
            default => $term,
        };
    }

    public static function subjectTermCount(string $subjectCategory, string $gradingSystem): int {
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

    public static function finalGrade(array $termGrades): ?float {
        $valid = array_filter($termGrades, fn($v) => $v !== null);
        if (empty($valid)) return null;
        return round(array_sum($valid) / count($valid));
    }

    public static function isCombinedSubject(string $subjectName): bool {
        $name = strtolower(trim($subjectName));
        return str_contains($name, 'effective communication')
            || str_contains($name, 'mabisang komunikasyon')
            || str_contains($name, 'effective comm');
    }

    public static function combinedSubjectKey(string $subjectName): string {
        $name = strtolower(trim($subjectName));
        if (str_contains($name, 'effective')) return 'ec';
        if (str_contains($name, 'mabisang')) return 'mk';
        return '';
    }

    public static function combinedDisplayName(): string {
        return 'Effective Communication / Mabisang Komunikasyon';
    }

    public static function combineGrades(?float $a, ?float $b): ?float {
        if ($a === null && $b === null) return null;
        if ($a === null) return $b;
        if ($b === null) return $a;
        return round(($a + $b) / 2);
    }

    public static function normalizeTerm(string $value): string {
        $v = strtoupper(trim($value));
        if (in_array($v, ['Term1', 'Term2', 'Term3', 'Q1', 'Q2', 'Q3', 'Q4'], true)) { return $v; }
        return 'Term1';
    }

    public static function sectionHeaderLabel(string $subjectCategory): string {
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

class GradeImporter {
    private PDO $db;
    private int $teacherId;
    private array $importLog = [];
    private array $errors = [];
    private string $tc = 'term';

    public function __construct(PDO $db, int $teacherId) {
        $this->db = $db;
        $this->teacherId = $teacherId;
        $hasQuarter = dbHasColumn($db, 'grades', 'quarter');
        if ($hasQuarter) {
            $this->tc = dbHasColumn($db, 'grades', 'term') ? 'term' : 'quarter';
        }
    }

    public function import(array $ecrData, array $options = []): array {
        $this->reset();

        $mode = $options['mode'] ?? 'merge';
        $academicYear = $options['academic_year'] ?? $this->getCurrentAcademicYear();
        $semester = $options['semester'] ?? 'S1';
        $quarter = $this->mapQuarter($options['quarter'] ?? ($options['term'] ?? 'Q1'));

        $gs = SshsGradeCalculator::gradingSystem($academicYear);
        if ($gs === '3_term') { $semester = null; }

        $this->importLog[] = "Starting import for AY $academicYear" . ($semester !== null ? ", $semester" : "") . ", $quarter";

        $header = $ecrData['header'] ?? [];
        $students = $ecrData['students'] ?? [];
        $gradeItems = $ecrData['grade_items'] ?? [];

        if (empty($students) || empty($gradeItems)) {
            $this->errors[] = 'No students or grade data found in the file';
            return $this->getResult();
        }

        $classId = $options['class_id'] ?? $this->findOrCreateClass($header);
        if (!$classId) {
            $this->errors[] = 'Could not find or create matching class';
            return $this->getResult();
        }

        $classSubjectId = $this->getClassSubjectId($classId);
        if (!$classSubjectId) {
            $this->errors[] = 'No class subject assignment found for teacher';
            return $this->getResult();
        }

        $this->importLog[] = "Found class ID: $classId, subject ID: $classSubjectId";

        $processedStudents = 0;
        $importedScores = 0;

        $studentGrades = $this->groupGradesByStudent($gradeItems);

        foreach ($studentGrades as $lrn => $studentData) {
            $student = $studentData['info'];
            $dbStudent = $this->findStudent($lrn);

            if (!$dbStudent) {
                $this->importLog[] = "Student not found: $lrn ({$student['name']})";
                continue;
            }

            $processedStudents++;

            $wwScore = $this->computeComponentScore($studentData['WW'] ?? [], 'WW');
            $ptScore = $this->computeComponentScore($studentData['PT'] ?? [], 'PT');
            $assessmentScore = $this->computeComponentScore($studentData['ASSESSMENT'] ?? [], 'ASSESSMENT');

            $weights = $this->getClassWeights($classId);
            $finalGrade = $this->computeFinalGrade($wwScore, $ptScore, $assessmentScore, $weights);

            $this->upsertGrade($dbStudent['id'], $classSubjectId, $wwScore, $ptScore, $assessmentScore, $finalGrade, $academicYear, $semester, $quarter, $mode);

            $importedScores++;
            $this->importLog[] = "Imported grades for {$student['name']} (LRN: $lrn) - Final: $finalGrade";
        }

        return $this->getResult($processedStudents, $importedScores);
    }

    private function reset(): void { $this->importLog = []; $this->errors = []; }

    private function mapQuarter(string $ecrQuarter): string {
        $map = ['Q1' => 'Q1', 'Q2' => 'Q2', 'Q3' => 'Q3', 'Q4' => 'Q4', 'Term1' => 'Term1', 'Term2' => 'Term2', 'Term3' => 'Term3'];
        return $map[$ecrQuarter] ?? ($this->tc === 'term' ? 'Term1' : 'Q1');
    }

    private function findOrCreateClass(array $header): ?int {
        $teacherId = $this->teacherId;
        $gradeLevel = $header['grade_level'] ?? 11;
        $stmt = $this->db->prepare("SELECT c.id FROM classes c JOIN class_subjects cs ON cs.class_id = c.id WHERE cs.teacher_id = ? AND c.grade_level = ? AND c.status = 'active' LIMIT 1");
        $stmt->execute([$teacherId, $gradeLevel]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    private function getClassSubjectId(int $classId): ?int {
        $stmt = $this->db->prepare("SELECT id FROM class_subjects WHERE class_id = ? AND teacher_id = ? LIMIT 1");
        $stmt->execute([$classId, $this->teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    private function findStudent(string $lrn): ?array {
        $stmt = $this->db->prepare("SELECT id, reference_code, lrn, first_name, last_name FROM users WHERE role = 'student' AND (lrn = ? OR reference_code = ?) LIMIT 1");
        $stmt->execute([$lrn, $lrn]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function groupGradesByStudent(array $gradeItems): array {
        $grouped = [];
        foreach ($gradeItems as $item) {
            $lrn = trim((string)($item['student_lrn'] ?? ''));
            if ($lrn === '') { continue; }
            $student = [
                'index' => (int)($item['student_index'] ?? 0),
                'name' => trim((string)($item['student_name'] ?? '')),
                'lrn' => $lrn,
            ];
            $component = (string)($item['component'] ?? '');
            if (!in_array($component, ['WW', 'PT', 'ASSESSMENT'], true)) { continue; }
            $scores = is_array($item['scores'] ?? null) ? $item['scores'] : [];
            if (!isset($grouped[$lrn])) {
                $grouped[$lrn] = ['info' => $student, 'WW' => [], 'PT' => [], 'ASSESSMENT' => []];
            }
            $grouped[$lrn][$component] = array_merge($grouped[$lrn][$component] ?? [], $scores);
        }
        return $grouped;
    }

    private function computeComponentScore(array $scores, string $component): ?float {
        if (empty($scores)) { return null; }
        return round(array_sum($scores) / count($scores), 2);
    }

    private function getClassWeights(int $classId): array {
        $stmt = $this->db->prepare("SELECT ww_weight, pt_weight, assessment_weight FROM classes WHERE id = ?");
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['ww' => (float)($row['ww_weight'] ?? 25), 'pt' => (float)($row['pt_weight'] ?? 50), 'assessment' => (float)($row['assessment_weight'] ?? 25)];
    }

    private function computeFinalGrade(?float $ww, ?float $pt, ?float $assessment, array $weights): ?float {
        $totalWeight = 0; $weightedSum = 0;
        if ($ww !== null) { $weightedSum += $ww * $weights['ww']; $totalWeight += $weights['ww']; }
        if ($pt !== null) { $weightedSum += $pt * $weights['pt']; $totalWeight += $weights['pt']; }
        if ($assessment !== null) { $weightedSum += $assessment * $weights['assessment']; $totalWeight += $weights['assessment']; }
        if ($totalWeight === 0) { return null; }
        return round($weightedSum / $totalWeight, 2);
    }

    private function upsertGrade(int $studentId, int $classSubjectId, ?float $ww, ?float $pt, ?float $assessment, ?float $final, string $academicYear, ?string $semester, string $quarter, string $mode): void {
        $semCondition = $semester !== null ? "AND semester = ?" : "AND semester IS NULL";
        $semParams = $semester !== null ? [$semester] : [];
        $tc = $this->tc;

        $stmt = $this->db->prepare("SELECT id, ww_raw_score, pt_raw_score, assessment_raw_score, final_grade FROM grades WHERE student_id = ? AND class_subject_id = ? AND academic_year = ? {$semCondition} AND {$tc} = ? LIMIT 1");
        $stmt->execute(array_merge([$studentId, $classSubjectId, $academicYear], $semParams, [$quarter]));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($mode === 'skip') { $this->importLog[] = "Skipped existing grade for student ID $studentId"; return; }
            $updateFields = []; $params = [];
            if ($ww !== null) { $updateFields[] = 'ww_raw_score = ?'; $params[] = $ww; }
            if ($pt !== null) { $updateFields[] = 'pt_raw_score = ?'; $params[] = $pt; }
            if ($assessment !== null) { $updateFields[] = 'assessment_raw_score = ?'; $params[] = $assessment; }
            if ($final !== null) { $updateFields[] = 'final_grade = ?'; $params[] = $final; }
            $updateFields[] = 'recorded_by = ?'; $params[] = $this->teacherId;
            $params[] = $existing['id'];
            $sql = "UPDATE grades SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
            $this->db->prepare($sql)->execute($params);
            $this->importLog[] = "Updated grade for student ID $studentId";
        } else {
            $stmt = $this->db->prepare("INSERT INTO grades (student_id, class_subject_id, ww_raw_score, pt_raw_score, assessment_raw_score, final_grade, academic_year, semester, {$tc}, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$studentId, $classSubjectId, $ww, $pt, $assessment, $final, $academicYear, $semester, $quarter, $this->teacherId]);
            $this->importLog[] = "Created new grade for student ID $studentId";
        }
    }

    private function getCurrentAcademicYear(): string {
        $year = (int)date('Y'); $month = (int)date('n');
        $start = $month >= 6 ? $year : $year - 1;
        return $start . '-' . ($start + 1);
    }

    private function getResult(int $students = 0, int $scores = 0): array {
        return ['success' => empty($this->errors), 'students_processed' => $students, 'scores_imported' => $scores, 'logs' => $this->importLog, 'errors' => $this->errors];
    }
}
