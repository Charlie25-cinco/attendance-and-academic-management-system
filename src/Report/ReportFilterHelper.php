<?php

namespace BshsAms\Report;

class ReportFilterHelper
{
    public const ALLOWED_DATE_COLUMNS = [
        'a.date',
        'sg.recorded_at',
        'g.created_at',
        'e.enrolled_at',
        'e.created_at',
        'u.created_at',
        'c.created_at',
        'created_at',
        'date',
        'attendance_date',
    ];

    public static function appendDateFilter(string $column, array &$where, array &$params, string $dateFrom, string $dateTo): void
    {
        $column = trim($column);
        if (!in_array($column, self::ALLOWED_DATE_COLUMNS, true)) {
            throw new \InvalidArgumentException("Invalid or unregistered date column: '{$column}'");
        }
        if ($dateFrom !== '') {
            $where[] = "DATE({$column}) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE({$column}) <= ?";
            $params[] = $dateTo;
        }
    }

    public static function appendAdvancedFilters(string $type, array &$where, array &$params, array $filters): void
    {
        $classId = (int)($filters['class_id'] ?? 0);
        $gradeLevel = (int)($filters['grade_level'] ?? 0);
        $section = trim((string)($filters['section'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));

        if ($type === 'attendance') {
            if ($classId > 0) {
                $where[] = "a.class_id = ?";
                $params[] = $classId;
            }
            if ($gradeLevel > 0) {
                $where[] = "c.grade_level = ?";
                $params[] = $gradeLevel;
            }
            if ($section !== '') {
                $where[] = "c.section = ?";
                $params[] = $section;
            }
            if ($status !== '') {
                $where[] = "a.status = ?";
                $params[] = $status;
            }
            return;
        }

        if ($type === 'grades') {
            if ($classId > 0) {
                $where[] = "cs.class_id = ?";
                $params[] = $classId;
            }
            if ($gradeLevel > 0) {
                $where[] = "c.grade_level = ?";
                $params[] = $gradeLevel;
            }
            if ($section !== '') {
                $where[] = "c.section = ?";
                $params[] = $section;
            }
            return;
        }

        if ($type === 'enrollment') {
            if ($classId > 0) {
                $where[] = "e.class_id = ?";
                $params[] = $classId;
            }
            if ($gradeLevel > 0) {
                $where[] = "c.grade_level = ?";
                $params[] = $gradeLevel;
            }
            if ($section !== '') {
                $where[] = "c.section = ?";
                $params[] = $section;
            }
            if ($status !== '') {
                $where[] = "e.status = ?";
                $params[] = $status;
            }
            return;
        }

        if ($type === 'teachers') {
            if ($classId > 0) {
                $where[] = "EXISTS (SELECT 1 FROM class_subjects x WHERE x.teacher_id = u.id AND x.class_id = ?)";
                $params[] = $classId;
            }
            if ($gradeLevel > 0) {
                $where[] = "EXISTS (
                            SELECT 1
                            FROM class_subjects x
                            JOIN classes xc ON xc.id = x.class_id
                            WHERE x.teacher_id = u.id AND xc.grade_level = ?
                           )";
                $params[] = $gradeLevel;
            }
            if ($section !== '') {
                $where[] = "EXISTS (
                            SELECT 1
                            FROM class_subjects x
                            JOIN classes xc ON xc.id = x.class_id
                            WHERE x.teacher_id = u.id AND xc.section = ?
                           )";
                $params[] = $section;
            }
            if ($status !== '') {
                $where[] = "u.status = ?";
                $params[] = $status;
            }
            return;
        }

        if ($type === 'classes') {
            if ($classId > 0) {
                $where[] = "c.id = ?";
                $params[] = $classId;
            }
            if ($gradeLevel > 0) {
                $where[] = "c.grade_level = ?";
                $params[] = $gradeLevel;
            }
            if ($section !== '') {
                $where[] = "c.section = ?";
                $params[] = $section;
            }
            if ($status !== '') {
                $where[] = "c.status = ?";
                $params[] = $status;
            }
        }
    }

    public static function buildFilterParams(string $dateFrom, string $dateTo, int $classId, int $gradeLevel, string $section, string $status, int $topN = 10): array
    {
        $params = [];
        if ($dateFrom !== '') {
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $params['date_to'] = $dateTo;
        }
        if ($classId > 0) {
            $params['class_id'] = $classId;
        }
        if ($gradeLevel > 0) {
            $params['grade_level'] = $gradeLevel;
        }
        if ($section !== '') {
            $params['section'] = $section;
        }
        if ($status !== '') {
            $params['status'] = $status;
        }
        if ($topN >= 1 && $topN <= 100) {
            $params['top_n'] = $topN;
        }
        return $params;
    }

    public static function getStatusOptions(string $type): array
    {
        return match ($type) {
            'attendance' => ['present', 'absent', 'late'],
            'enrollment' => ['enrolled', 'dropped', 'completed'],
            'teachers' => ['active', 'inactive', 'pending'],
            'classes' => ['active', 'inactive'],
            default => [],
        };
    }

    public static function formatPercent(float $rate): string
    {
        return number_format($rate, 1) . '%';
    }
}

