<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'student-attendance' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'student') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $studentId = (int)$user['id'];
    $classId = (int)($_GET['class_id'] ?? 0);
    $fromDate = trim((string)($_GET['from'] ?? ''));
    $toDate = trim((string)($_GET['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $fromDate = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $toDate = date('Y-m-d');
    }

    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$studentId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($classes)) {
        apiJson(['ok' => true, 'classes' => [], 'attendance' => []]);
    }

    $attendanceQuery = "SELECT a.id, a.class_id, a.status, a.date, c.class_name, c.grade_level, c.section
                        FROM attendance a
                        JOIN classes c ON c.id = a.class_id
                        WHERE a.student_id = ? AND a.date BETWEEN ? AND ?";
    $attendanceParams = [$studentId, $fromDate, $toDate];

    if ($classId > 0) {
        $attendanceQuery .= " AND a.class_id = ?";
        $attendanceParams[] = $classId;
    }
    $attendanceQuery .= " ORDER BY a.date DESC, c.class_name";

    $attStmt = $db->prepare($attendanceQuery);
    $attStmt->execute($attendanceParams);
    $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = buildAttendanceSummary($attendance);

    apiJson([
        'ok' => true,
        'classes' => $classes,
        'attendance' => $attendance,
        'summary' => $summary,
    ]);
}

if ($route === 'student-classes' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'student') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $studentId = (int)$user['id'];
    $stmt = $db->prepare("SELECT c.id, c.class_name, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                                 c.grade_level, c.section, c.schedule, s.subject_name
                          FROM enrollments e
                          JOIN classes c ON c.id = e.class_id
                          JOIN users u ON u.id = c.teacher_id
                          LEFT JOIN class_subjects cs ON cs.class_id = c.id
                          LEFT JOIN subjects s ON s.id = cs.subject_id
                          WHERE e.student_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled' AND c.status = 'active'
                          ORDER BY c.grade_level, c.section, c.class_name");
    $stmt->execute([$studentId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $teacherRoles = [];
    foreach ($classes as $cls) {
        $tid = (int)$cls['teacher_id'] ?? 0;
        if ($tid > 0 && !isset($teacherRoles[$tid])) {
            $teacherRoles[$tid] = apiGetTeacherRoles($db, $tid);
        }
    }

    apiJson(['ok' => true, 'classes' => $classes]);
}

if ($route === 'student-qr' && $method === 'GET') {
    $user = apiRequireUser();
    if ($user['role'] !== 'student') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    apiJson([
        'ok' => true,
        'qr_data' => json_encode([
            'type' => 'student_attendance',
            'reference_code' => $user['reference_code'],
            'user_id' => (int)$user['id'],
        ]),
    ]);
}

if ($route === 'student-grades' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'student') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $studentId = (int)$user['id'];

    $period = trim((string)($_GET['period'] ?? ''));
    $academicYear = apiCurrentAcademicYear();
    $gradingSystem = apiGradingSystemForAcademicYear($academicYear);

    $stmt = $db->prepare("SELECT g.id, g.student_id, g.class_id, g.grade, g.term, g.quarter, g.academic_year,
                                 c.class_name, c.grade_level, c.section, s.subject_name
                          FROM grades g
                          JOIN classes c ON c.id = g.class_id
                          LEFT JOIN subjects s ON s.id = c.subject_id
                          WHERE g.student_id = ? AND g.academic_year = ?
                          ORDER BY c.grade_level, c.section, c.class_name");
    $stmt->execute([$studentId, $academicYear]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $enrolledStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, s.subject_name
                                  FROM enrollments e
                                  JOIN classes c ON c.id = e.class_id
                                  LEFT JOIN class_subjects cs ON cs.class_id = c.id
                                  LEFT JOIN subjects s ON s.id = cs.subject_id
                                  WHERE e.student_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled' AND c.status = 'active'
                                  ORDER BY c.grade_level, c.section, c.class_name");
    $enrolledStmt->execute([$studentId]);
    $classes = $enrolledStmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson(['ok' => true, 'grades' => $grades, 'classes' => $classes, 'academic_year' => $academicYear, 'grading_system' => $gradingSystem]);
}

if ($route === 'student-report-card' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'student') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $studentId = (int)$user['id'];
    $academicYear = trim((string)($_GET['academic_year'] ?? apiCurrentAcademicYear()));

    $gradesStmt = $db->prepare("SELECT g.*, c.class_name, c.grade_level, c.section, s.subject_name
                                FROM grades g
                                JOIN classes c ON c.id = g.class_id
                                LEFT JOIN subjects s ON s.id = c.subject_id
                                WHERE g.student_id = ? AND g.academic_year = ?
                                ORDER BY c.grade_level, c.section, c.class_name, g.term");
    $gradesStmt->execute([$studentId, $academicYear]);
    $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);

    $attStmt = $db->prepare("SELECT a.status, a.date, c.class_name, c.grade_level, c.section
                             FROM attendance a
                             JOIN classes c ON c.id = a.class_id
                             WHERE a.student_id = ? AND a.academic_year = ?
                             ORDER BY a.date DESC");
    $attStmt->execute([$studentId, $academicYear]);
    $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson(['ok' => true, 'grades' => $grades, 'attendance' => $attendance, 'academic_year' => $academicYear]);
}
