<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'parent-progress' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'parent') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $parentId = (int)$user['id'];
    $studentId = (int)($_GET['student_id'] ?? 0);
    $attFrom = apiNormalizeDate((string)($_GET['att_from'] ?? ''));
    $attTo = apiNormalizeDate((string)($_GET['att_to'] ?? ''));
    $actFrom = apiNormalizeDate((string)($_GET['act_from'] ?? ''));
    $actTo = apiNormalizeDate((string)($_GET['act_to'] ?? ''));
    apiNormalizeDateRange($attFrom, $attTo);
    apiNormalizeDateRange($actFrom, $actTo);
    $actClassId = (int)($_GET['act_class_id'] ?? 0);

    $childrenStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                                  FROM parent_students ps
                                  JOIN users u ON u.id = ps.student_id
                                  WHERE ps.parent_id = ? AND u.role = 'student' AND u.status IN ('active', 'pending')
                                  ORDER BY u.last_name, u.first_name");
    $childrenStmt->execute([$parentId]);
    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($children)) {
        apiJson(['ok' => true, 'children' => []]);
    }

    $childIds = array_map('intval', array_column($children, 'id'));
    if ($studentId > 0 && in_array($studentId, $childIds, true)) {
        $childIds = [$studentId];
    }

    $progressData = [];
    foreach ($children as $child) {
        $cid = (int)$child['id'];
        if (!in_array($cid, $childIds, true)) continue;

        $attendanceWhere = "a.student_id = ?";
        $attendanceParams = [$cid];
        if ($attFrom !== '') { $attendanceWhere .= " AND a.date >= ?"; $attendanceParams[] = $attFrom; }
        if ($attTo !== '') { $attendanceWhere .= " AND a.date <= ?"; $attendanceParams[] = $attTo; }

        $attStmt = $db->prepare("SELECT a.status, a.date, c.class_name
                                 FROM attendance a
                                 JOIN classes c ON c.id = a.class_id
                                 WHERE $attendanceWhere
                                 ORDER BY a.date DESC LIMIT 20");
        $attStmt->execute($attendanceParams);
        $attendanceRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);
        $attendanceSummary = buildAttendanceSummary($attendanceRows);

        $activitiesWhere = "e.student_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled'";
        $activitiesParams = [$cid];
        if ($actFrom !== '') { $activitiesWhere .= " AND gi.created_at >= ?"; $activitiesParams[] = $actFrom . ' 00:00:00'; }
        if ($actTo !== '') { $activitiesWhere .= " AND gi.created_at <= ?"; $activitiesParams[] = $actTo . ' 23:59:59'; }
        if ($actClassId > 0) { $activitiesWhere .= " AND gi.class_id = ?"; $activitiesParams[] = $actClassId; }

        $actStmt = $db->prepare("SELECT gi.title, gi.component, gi.total_score, gi.created_at, c.class_name
                                 FROM grade_items gi
                                 JOIN classes c ON c.id = gi.class_id
                                 JOIN enrollments e ON e.class_id = c.id AND $activitiesWhere
                                 ORDER BY gi.created_at DESC LIMIT 15");
        $actStmt->execute($activitiesParams);
        $activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

        $gradesStmt = $db->prepare("SELECT g.grade, g.term, c.class_name, s.subject_name
                                    FROM grades g
                                    JOIN classes c ON c.id = g.class_id
                                    LEFT JOIN subjects s ON s.id = c.subject_id
                                    WHERE g.student_id = ?
                                    ORDER BY c.grade_level, c.section, c.class_name, g.term");
        $gradesStmt->execute([$cid]);
        $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);

        $progressData[] = [
            'student' => $child,
            'attendance_summary' => $attendanceSummary,
            'attendance' => $attendanceRows,
            'recent_activities' => $activities,
            'grades' => $grades,
        ];
    }

    $classListStmt = $db->prepare("SELECT DISTINCT c.id, c.class_name, c.grade_level, c.section
                                   FROM enrollments e
                                   JOIN classes c ON c.id = e.class_id
                                   WHERE e.student_id IN (" . implode(',', $childIds) . ") AND COALESCE(e.status, 'enrolled') = 'enrolled' AND c.status = 'active'
                                   ORDER BY c.grade_level, c.section, c.class_name");
    $classListStmt->execute();
    $classList = $classListStmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson([
        'ok' => true,
        'children' => $children,
        'progress' => $progressData,
        'class_list' => $classList,
    ]);
}

if ($route === 'parent-children' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'parent') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $parentId = (int)$user['id'];

    $childrenStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name, u.grade_level, u.section, u.email
                                  FROM parent_students ps
                                  JOIN users u ON u.id = ps.student_id
                                  WHERE ps.parent_id = ? AND u.role = 'student' AND u.status IN ('active', 'pending')
                                  ORDER BY u.last_name, u.first_name");
    $childrenStmt->execute([$parentId]);
    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson(['ok' => true, 'children' => $children]);
}
