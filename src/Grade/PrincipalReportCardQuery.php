<?php

namespace BshsAms\Grade;

use PDO;

final class PrincipalReportCardQuery
{
    private const ALLOWED_STATUSES = ['submitted_admin', 'approved', 'rejected'];

    public function __construct(private PDO $db)
    {
    }

    /** @return array{submitted_admin: int, approved: int, rejected: int} */
    public function statusCounts(): array
    {
        $counts = ['submitted_admin' => 0, 'approved' => 0, 'rejected' => 0];
        $statement = $this->db->query(
            'SELECT status, COUNT(*) total FROM report_card_approvals GROUP BY status'
        );

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string)($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)$row['total'];
            }
        }

        return $counts;
    }

    /** @return list<string> */
    public function academicYears(): array
    {
        return $this->db->query(
            'SELECT DISTINCT academic_year FROM report_card_approvals ORDER BY academic_year DESC'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    public function sections(): array
    {
        return $this->db->query(
            "SELECT DISTINCT s.section
             FROM report_card_approvals rc
             JOIN users s ON s.id = rc.student_id
             WHERE COALESCE(s.section, '') <> ''
             ORDER BY s.section"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<string> $statuses
     * @param array{academic_year?: string, semester?: string, grade_level?: int, section?: string, search?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function find(array $statuses, array $filters = [], int $limit = 250): array
    {
        $statuses = array_values(array_intersect(self::ALLOWED_STATUSES, $statuses));
        if ($statuses === []) {
            return [];
        }

        $where = ['rc.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')'];
        $params = $statuses;
        $academicYear = trim((string)($filters['academic_year'] ?? ''));
        $semester = trim((string)($filters['semester'] ?? ''));
        $gradeLevel = (int)($filters['grade_level'] ?? 0);
        $section = trim((string)($filters['section'] ?? ''));
        $search = trim((string)($filters['search'] ?? ''));

        if ($academicYear !== '') {
            $where[] = 'rc.academic_year = ?';
            $params[] = $academicYear;
        }
        if ($semester !== '') {
            $where[] = 'rc.semester = ?';
            $params[] = $semester;
        }
        if (in_array($gradeLevel, [11, 12], true)) {
            $where[] = 's.grade_level = ?';
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = 'LOWER(TRIM(s.section)) = LOWER(TRIM(?))';
            $params[] = $section;
        }
        if ($search !== '') {
            $where[] = '(s.reference_code LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.section LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle, $needle);
        }

        $limit = max(1, min(250, $limit));
        $sql = "SELECT rc.id, rc.student_id, rc.academic_year, rc.semester, rc.status,
                       rc.submitted_at, rc.reviewed_at, rc.remarks,
                       s.reference_code, s.first_name, s.last_name, s.grade_level, s.section,
                       CONCAT(a.first_name, ' ', a.last_name) adviser_name,
                       CONCAT(r.first_name, ' ', r.last_name) reviewer_name,
                       (SELECT COUNT(*) FROM grades g
                        JOIN class_subjects cs ON cs.id = g.class_subject_id
                        JOIN classes c ON c.id = cs.class_id
                        WHERE g.student_id = rc.student_id AND g.academic_year = rc.academic_year
                          AND (COALESCE(rc.semester, '') = '' OR g.semester = rc.semester)
                          AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory') subject_count,
                       (SELECT COUNT(*) FROM grades g
                        JOIN class_subjects cs ON cs.id = g.class_subject_id
                        JOIN classes c ON c.id = cs.class_id
                        JOIN grade_approvals ga ON ga.grade_id = g.id AND ga.status = 'admin_verified'
                        WHERE g.student_id = rc.student_id AND g.academic_year = rc.academic_year
                          AND (COALESCE(rc.semester, '') = '' OR g.semester = rc.semester)
                          AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory') verified_count
                FROM report_card_approvals rc
                JOIN users s ON s.id = rc.student_id
                JOIN users a ON a.id = rc.advisory_teacher_id
                LEFT JOIN users r ON r.id = rc.reviewed_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY COALESCE(rc.reviewed_at, rc.submitted_at) DESC, rc.id DESC
                LIMIT $limit";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{academic_year?: string, grade_level?: int, section?: string, search?: string, decision?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function decisionHistory(array $filters = [], int $limit = 250): array
    {
        $actions = ['report_card.approve', 'report_card.reject', 'report_card.withdraw'];
        $where = [
            "al.actor_role = 'principal'",
            "al.target_type = 'report_card'",
            'al.action_name IN (?, ?, ?)',
        ];
        $params = $actions;
        $academicYear = trim((string)($filters['academic_year'] ?? ''));
        $gradeLevel = (int)($filters['grade_level'] ?? 0);
        $section = trim((string)($filters['section'] ?? ''));
        $search = trim((string)($filters['search'] ?? ''));
        $decision = trim((string)($filters['decision'] ?? ''));

        if (in_array($decision, $actions, true)) {
            $where[] = 'al.action_name = ?';
            $params[] = $decision;
        }
        if ($academicYear !== '') {
            $where[] = 'rc.academic_year = ?';
            $params[] = $academicYear;
        }
        if (in_array($gradeLevel, [11, 12], true)) {
            $where[] = 's.grade_level = ?';
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = 'LOWER(TRIM(s.section)) = LOWER(TRIM(?))';
            $params[] = $section;
        }
        if ($search !== '') {
            $where[] = '(s.reference_code LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.section LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle, $needle);
        }

        $limit = max(1, min(250, $limit));
        $reviewerNameSql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "TRIM(COALESCE(actor.first_name, '') || ' ' || COALESCE(actor.last_name, ''))"
            : "CONCAT(actor.first_name, ' ', actor.last_name)";
        $sql = "SELECT al.id event_id, al.action_name, al.created_at decision_at,
                       rc.id report_card_id, rc.academic_year, rc.semester,
                       CASE WHEN rc.reviewed_at = al.created_at THEN rc.remarks ELSE NULL END decision_remarks,
                       s.reference_code, s.first_name, s.last_name, s.grade_level, s.section,
                       $reviewerNameSql reviewer_name
                FROM activity_logs al
                JOIN report_card_approvals rc ON rc.id = al.target_id
                JOIN users s ON s.id = rc.student_id
                LEFT JOIN users actor ON actor.id = al.actor_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT $limit";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
