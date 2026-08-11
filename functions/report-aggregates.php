<?php

if (!function_exists('aggregateAttendanceReportTypes')) {
    function aggregateAttendanceReportTypes(): array
    {
        return ['top_attendance', 'class_summary', 'at_risk'];
    }
}

if (!function_exists('aggregateAttendanceReportDefinition')) {
    function aggregateAttendanceReportDefinition(string $type): array
    {
        $definitions = [
            'top_attendance' => [
                'headers' => ['Rank', 'Student', 'Reference', 'Present', 'Late', 'Absent', 'Total', 'Rate'],
                'group' => 'student',
                'limit' => true,
                'order' => 'attendance_rate DESC, total_records DESC, student_name ASC',
            ],
            'class_summary' => [
                'headers' => ['Class', 'Present', 'Late', 'Absent', 'Total', 'Rate'],
                'group' => 'class',
                'limit' => false,
                'order' => 'c.class_name ASC',
            ],
            'at_risk' => [
                'headers' => ['Student', 'Reference', 'Absent', 'Total', 'Rate'],
                'group' => 'student',
                'limit' => true,
                'order' => 'absent_count DESC, attendance_rate ASC, student_name ASC',
            ],
        ];

        if (!isset($definitions[$type])) {
            throw new InvalidArgumentException('Invalid attendance aggregate report type.');
        }

        return $definitions[$type];
    }
}

if (!function_exists('aggregateAttendanceAdminScope')) {
    function aggregateAttendanceAdminScope(PDO $db, array $filters): array
    {
        $where = [];
        $params = [];
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));
        $classId = (int)($filters['class_id'] ?? 0);
        $gradeLevel = (int)($filters['grade_level'] ?? 0);
        $section = trim((string)($filters['section'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));

        if ($dateFrom !== '') {
            $where[] = 'DATE(a.date) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'DATE(a.date) <= ?';
            $params[] = $dateTo;
        }
        if ($classId > 0) {
            $where[] = 'a.class_id = ?';
            $params[] = $classId;
        }
        if ($gradeLevel > 0) {
            $where[] = 'c.grade_level = ?';
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = 'c.section = ?';
            $params[] = $section;
        }
        if (in_array($status, ['present', 'absent', 'late'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }

        return [$where, $params];
    }
}

if (!function_exists('aggregateAttendanceReportRows')) {
    function aggregateAttendanceReportRows(PDO $db, string $type, array $where, array $params, int $limit = 10): array
    {
        $definition = aggregateAttendanceReportDefinition($type);
        $limit = max(1, min(100, $limit));
        $whereSql = implode(' AND ', $where ?: ['1 = 1']);
        $late = "SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END)";
        $present = "SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END)";
        $absent = "SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END)";
        $effectiveLate = "MOD({$late}, 3)";
        $effectiveAbsent = "({$absent} + FLOOR({$late} / 3))";
        $rate = "ROUND((({$present} + {$effectiveLate}) / COUNT(*)) * 100, 2)";

        if ($definition['group'] === 'class') {
            $sql = "SELECT c.class_name,
                           {$present} AS present_count,
                           {$late} AS late_count,
                           {$effectiveAbsent} AS absent_count,
                           COUNT(*) AS total_records,
                           {$effectiveLate} AS effective_late_count,
                           {$rate} AS attendance_rate
                    FROM attendance a
                    JOIN classes c ON c.id = a.class_id
                    JOIN users u ON u.id = a.student_id
                    WHERE {$whereSql}
                    GROUP BY c.id, c.class_name
                    HAVING total_records > 0
                    ORDER BY {$definition['order']}";
        } else {
            $sql = "SELECT CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                           u.reference_code,
                           {$present} AS present_count,
                           {$late} AS late_count,
                           {$effectiveAbsent} AS absent_count,
                           COUNT(*) AS total_records,
                           {$effectiveLate} AS effective_late_count,
                           {$rate} AS attendance_rate
                    FROM attendance a
                    JOIN users u ON u.id = a.student_id
                    JOIN classes c ON c.id = a.class_id
                    WHERE {$whereSql}
                    GROUP BY u.id, u.first_name, u.last_name, u.reference_code
                    HAVING total_records > 0
                    ORDER BY {$definition['order']}";
            if ($definition['limit']) {
                $sql .= " LIMIT {$limit}";
            }
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('aggregateAttendanceReportTable')) {
    function aggregateAttendanceReportTable(string $type, array $rows): array
    {
        $definition = aggregateAttendanceReportDefinition($type);
        $output = [];
        foreach ($rows as $index => $row) {
            if ($type === 'top_attendance') {
                $output[] = [$index + 1, $row['student_name'], $row['reference_code'], $row['present_count'], $row['effective_late_count'], $row['absent_count'], $row['total_records'], $row['attendance_rate'] . '%'];
            } elseif ($type === 'class_summary') {
                $output[] = [$row['class_name'], $row['present_count'], $row['effective_late_count'], $row['absent_count'], $row['total_records'], $row['attendance_rate'] . '%'];
            } else {
                $output[] = [$row['student_name'], $row['reference_code'], $row['absent_count'], $row['total_records'], $row['attendance_rate'] . '%'];
            }
        }
        return ['headers' => $definition['headers'], 'rows' => $output];
    }
}
