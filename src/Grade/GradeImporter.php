<?php

namespace BshsAms\Grade;

use PDO;

class GradeImporter
{
    private PDO $db;
    private int $teacherId;
    private array $importLog = [];
    private array $errors = [];
    private string $tc = 'term';

    public function __construct(PDO $db, int $teacherId)
    {
        $this->db = $db;
        $this->teacherId = $teacherId;
        $hasQuarter = dbHasColumn($db, 'grades', 'quarter');
        if ($hasQuarter) {
            $this->tc = dbHasColumn($db, 'grades', 'term') ? 'term' : 'quarter';
        }
    }

    public function import(array $ecrData, array $options = []): array
    {
        $this->reset();

        $mode = $options['mode'] ?? 'merge';
        $academicYear = $options['academic_year'] ?? $this->getCurrentAcademicYear();
        $semester = $options['semester'] ?? 'S1';
        $quarter = $this->mapQuarter($options['quarter'] ?? ($options['term'] ?? 'Q1'));

        $gs = \BshsAms\Grade\SshsGradeCalculator::gradingSystem($academicYear);
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

    private function mapQuarter(string $ecrQuarter): string
    {
        $map = ['Q1' => 'Q1', 'Q2' => 'Q2', 'Q3' => 'Q3', 'Q4' => 'Q4', 'Term1' => 'Term1', 'Term2' => 'Term2', 'Term3' => 'Term3'];
        return $map[$ecrQuarter] ?? ($this->tc === 'term' ? 'Term1' : 'Q1');
    }

    private function findOrCreateClass(array $header): ?int
    {
        $teacherId = $this->teacherId;
        $gradeLevel = $header['grade_level'] ?? 11;
        $stmt = $this->db->prepare("SELECT c.id FROM classes c JOIN class_subjects cs ON cs.class_id = c.id WHERE cs.teacher_id = ? AND c.grade_level = ? AND c.status = 'active' LIMIT 1");
        $stmt->execute([$teacherId, $gradeLevel]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    private function getClassSubjectId(int $classId): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM class_subjects WHERE class_id = ? AND teacher_id = ? LIMIT 1");
        $stmt->execute([$classId, $this->teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    private function findStudent(string $lrn): ?array
    {
        $stmt = $this->db->prepare("SELECT id, reference_code, lrn, first_name, last_name FROM users WHERE role = 'student' AND (lrn = ? OR reference_code = ?) LIMIT 1");
        $stmt->execute([$lrn, $lrn]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function groupGradesByStudent(array $gradeItems): array
    {
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

    private function computeComponentScore(array $scores, string $component): ?float
    {
        if (empty($scores)) { return null; }
        return round(array_sum($scores) / count($scores), 2);
    }

    private function getClassWeights(int $classId): array
    {
        $stmt = $this->db->prepare("SELECT ww_weight, pt_weight, assessment_weight FROM classes WHERE id = ?");
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['ww' => (float)($row['ww_weight'] ?? 25), 'pt' => (float)($row['pt_weight'] ?? 50), 'assessment' => (float)($row['assessment_weight'] ?? 25)];
    }

    private function computeFinalGrade(?float $ww, ?float $pt, ?float $assessment, array $weights): ?float
    {
        $totalWeight = 0; $weightedSum = 0;
        if ($ww !== null) { $weightedSum += $ww * $weights['ww']; $totalWeight += $weights['ww']; }
        if ($pt !== null) { $weightedSum += $pt * $weights['pt']; $totalWeight += $weights['pt']; }
        if ($assessment !== null) { $weightedSum += $assessment * $weights['assessment']; $totalWeight += $weights['assessment']; }
        if ($totalWeight === 0) { return null; }
        return round($weightedSum / $totalWeight, 2);
    }

    private function upsertGrade(int $studentId, int $classSubjectId, ?float $ww, ?float $pt, ?float $assessment, ?float $final, string $academicYear, ?string $semester, string $quarter, string $mode): void
    {
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

    private function getCurrentAcademicYear(): string
    {
        $year = (int)date('Y'); $month = (int)date('n');
        $start = $month >= 6 ? $year : $year - 1;
        return $start . '-' . ($start + 1);
    }

    private function getResult(int $students = 0, int $scores = 0): array
    {
        return ['success' => empty($this->errors), 'students_processed' => $students, 'scores_imported' => $scores, 'logs' => $this->importLog, 'errors' => $this->errors];
    }
}
