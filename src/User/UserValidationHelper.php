<?php

namespace BshsAms\User;

use PDO;

class UserValidationHelper
{
    public static function isTeacherSectionTaken(PDO $db, int|string $gradeLevel, string $section, int $excludeUserId = 0): bool
    {
        $sql = "SELECT id
                FROM users
                WHERE role = 'teacher'
                AND status IN ('active', 'pending')
                AND grade_level = ?
                AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))";
        $params = [(int)$gradeLevel, (string)$section];
        if ($excludeUserId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excludeUserId;
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getTeacherSubjectConflicts(PDO $db, array $classIds, int $excludeTeacherId = 0): array
    {
        $classIds = array_values(array_filter(array_map('intval', $classIds), fn($id) => $id > 0));
        if (empty($classIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $params = $classIds;
        $sql = "SELECT cs.class_id,
                       c.class_name,
                       c.grade_level,
                       c.section,
                       u.first_name,
                       u.last_name
                FROM class_subjects cs
                JOIN classes c ON c.id = cs.class_id
                JOIN users u ON u.id = cs.teacher_id
                WHERE cs.class_id IN ($placeholders)
                  AND c.status = 'active'
                  AND u.role = 'teacher'
                  AND u.status IN ('active', 'pending')";

        if ($excludeTeacherId > 0) {
            $sql .= " AND cs.teacher_id <> ?";
            $params[] = $excludeTeacherId;
        }

        $sql .= " ORDER BY c.class_name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        $conflicts = [];
        foreach ($rows as $row) {
            $classId = (int)($row['class_id'] ?? 0);
            if ($classId <= 0 || isset($conflicts[$classId])) {
                continue;
            }
            $subject = trim((string)($row['class_name'] ?? 'Subject'));
            $grade = trim((string)($row['grade_level'] ?? ''));
            $section = trim((string)($row['section'] ?? ''));
            $teacherName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            $suffix = ($grade !== '' && $section !== '') ? " (G{$grade} - {$section})" : '';
            $owner = $teacherName !== '' ? " - {$teacherName}" : '';
            $conflicts[$classId] = $subject . $suffix . $owner;
        }
        return array_values($conflicts);
    }

    public static function areValidStudents(PDO $db, array $studentIds): bool
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($id) => $id > 0));
        if (empty($studentIds)) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id IN ($placeholders) AND role = 'student' AND status IN ('active', 'pending')");
        $stmt->execute($studentIds);
        return (int)$stmt->fetchColumn() === count($studentIds);
    }

    public static function getStudentParentConflicts(PDO $db, array $studentIds, int $excludeParentId = 0): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($id) => $id > 0));
        if (empty($studentIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $params = $studentIds;
        $sql = "SELECT u.first_name AS student_first, u.last_name AS student_last,
                       p.first_name AS parent_first, p.last_name AS parent_last
                FROM parent_students ps
                JOIN users u ON u.id = ps.student_id
                JOIN users p ON p.id = ps.parent_id
                WHERE ps.student_id IN ($placeholders) AND p.status <> 'inactive'";
        if ($excludeParentId > 0) {
            $sql .= " AND ps.parent_id <> ?";
            $params[] = $excludeParentId;
        }
        $sql .= " GROUP BY ps.student_id, u.first_name, u.last_name, p.first_name, p.last_name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conflicts = [];
        foreach ($rows as $row) {
            $studentName = trim((string)($row['student_first'] ?? '') . ' ' . (string)($row['student_last'] ?? ''));
            if ($studentName !== '') {
                $conflicts[] = $studentName;
            }
        }
        return array_values(array_unique(array_filter($conflicts)));
    }
}

