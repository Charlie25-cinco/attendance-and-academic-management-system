<?php

if (!function_exists('tEnrollActiveStudentsSubquerySql')) {
    function tEnrollActiveStudentsSubquerySql() {
        return "SELECT e.student_id
                FROM enrollments e
                JOIN (
                  SELECT student_id, class_id, MAX(id) AS max_id
                  FROM enrollments
                  WHERE class_id = ?
                  GROUP BY student_id, class_id
                ) latest ON latest.max_id = e.id
                WHERE e.class_id = ?
                AND COALESCE(e.status, 'enrolled') = 'enrolled'";
    }
}

if (!function_exists('tEnrollActiveUsersJoinSql')) {
    function tEnrollActiveUsersJoinSql() {
        return "JOIN (" . tEnrollActiveStudentsSubquerySql() . ") ae ON ae.student_id = u.id";
    }
}

if (!function_exists('tEnrollFetchActiveStudentIds')) {
    function tEnrollFetchActiveStudentIds($db, $classId) {
        if (function_exists('syncClassEnrollmentsForClass')) {
            syncClassEnrollmentsForClass($db, (int)$classId);
        }
        $stmt = $db->prepare(tEnrollActiveStudentsSubquerySql());
        $stmt->execute([(int)$classId, (int)$classId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('tEnrollFetchActiveTermMap')) {
    function tEnrollFetchActiveTermMap($db, $classId) {
        if (function_exists('syncClassEnrollmentsForClass')) {
            syncClassEnrollmentsForClass($db, (int)$classId);
        }
        $sql = "SELECT e.student_id, e.academic_year, e.semester
                FROM enrollments e
                JOIN (
                  SELECT student_id, class_id, MAX(id) AS max_id
                  FROM enrollments
                  WHERE class_id = ?
                  GROUP BY student_id, class_id
                ) latest ON latest.max_id = e.id
                WHERE e.class_id = ?
                AND COALESCE(e.status, 'enrolled') = 'enrolled'";
        $stmt = $db->prepare($sql);
        $stmt->execute([(int)$classId, (int)$classId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int)$row['student_id'];
            $map[$sid] = [
                'academic_year' => trim((string)($row['academic_year'] ?? '')),
                'semester' => (int)($row['semester'] ?? 1)
            ];
        }
        return $map;
    }
}

if (!function_exists('tEnrollFetchStudentsWithAttendance')) {
    function tEnrollFetchStudentsWithAttendance($db, $classId, $date, $sex = '') {
        if (function_exists('syncClassEnrollmentsForClass')) {
            syncClassEnrollmentsForClass($db, (int)$classId);
        }
        $sex = strtolower(trim((string)$sex));
        if (!in_array($sex, ['male', 'female'], true)) {
            $sex = '';
        }

        $query = "SELECT DISTINCT u.id, u.reference_code, u.first_name, u.last_name,
                  CASE
                    WHEN a.status = 'cutting' OR (a.status = 'absent' AND a.remarks LIKE '%Cutting%') THEN 'cutting'
                    ELSE COALESCE(a.status, 'present')
                  END AS attendance_status,
                  COALESCE(a.remarks, '') AS remarks
                  FROM users u
                  JOIN classes c ON c.id = ?
                  LEFT JOIN (" . tEnrollActiveStudentsSubquerySql() . ") ae ON ae.student_id = u.id
                  LEFT JOIN attendance a ON a.student_id = u.id AND a.class_id = ? AND a.date = ?
                  WHERE u.role = 'student'
                  AND u.status IN ('active', 'pending')
                  AND (? = '' OR LOWER(COALESCE(u.sex, '')) = ?)
                  AND (
                    ae.student_id IS NOT NULL
                    OR (
                        u.grade_level = c.grade_level
                        AND " . sectionMatchSql('u.section', 'c.section') . "
                    )
                  )
                  ORDER BY u.last_name, u.first_name";
        $stmt = $db->prepare($query);
        $stmt->execute([(int)$classId, (int)$classId, (int)$classId, (int)$classId, (string)$date, $sex, $sex]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('tEnrollFetchAdvisoryStudentsWithAttendance')) {
    function tEnrollFetchAdvisoryStudentsWithAttendance($db, $classId, $date) {
        if (function_exists('syncClassEnrollmentsForClass')) {
            syncClassEnrollmentsForClass($db, (int)$classId);
        }
        $query = "SELECT DISTINCT u.id, u.reference_code, u.first_name, u.last_name,
                  CASE
                    WHEN COALESCE(ar.absent_count, 0) > 0 THEN 'absent'
                    WHEN COALESCE(ar.late_count, 0) > 0 THEN 'late'
                    WHEN COALESCE(ar.present_count, 0) > 0 THEN 'present'
                    ELSE 'present'
                  END AS attendance_status,
                  COALESCE(ar.subject_statuses, '') AS remarks
                  FROM users u
                  JOIN classes cb ON cb.id = ?
                  LEFT JOIN (" . tEnrollActiveStudentsSubquerySql() . ") ae ON ae.student_id = u.id
                  LEFT JOIN (
                    SELECT a.student_id,
                           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                           SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                           GROUP_CONCAT(CONCAT(c.class_name, ': ', UPPER(a.status)) ORDER BY c.class_name SEPARATOR ' | ') AS subject_statuses
                    FROM attendance a
                    JOIN classes c ON c.id = a.class_id
                    JOIN classes cbase ON cbase.id = ?
                    WHERE a.date = ?
                    AND c.status = 'active'
                    AND c.grade_level = cbase.grade_level
                    AND " . sectionMatchSql('c.section', 'cbase.section') . "
                    GROUP BY a.student_id
                  ) ar ON ar.student_id = u.id
                  WHERE u.role = 'student'
                  AND u.status IN ('active', 'pending')
                  AND (
                    ae.student_id IS NOT NULL
                    OR (
                        u.grade_level = cb.grade_level
                        AND " . sectionMatchSql('u.section', 'cb.section') . "
                    )
                  )
                  ORDER BY u.last_name, u.first_name";
        $stmt = $db->prepare($query);
        $stmt->execute([(int)$classId, (int)$classId, (int)$classId, (int)$classId, (string)$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
