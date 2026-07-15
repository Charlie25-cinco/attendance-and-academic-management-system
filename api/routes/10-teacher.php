<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'teacher-attendance' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $classId = (int)($_GET['class_id'] ?? 0);
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    $sex = strtolower(trim((string)($_GET['sex'] ?? '')));
    if (!in_array($sex, ['male', 'female'], true)) {
        $sex = '';
    }
    if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        apiJson(['ok' => false, 'message' => 'Class and date are required'], 422);
    }
    if (!apiTeacherOwnsClass($db, (int)$user['id'], $classId)) {
        apiJson(['ok' => false, 'message' => 'You are not assigned to this class'], 403);
    }
    $canEdit = apiClassHasScheduleOnDate($db, $classId, $date);
    $editReason = $canEdit ? '' : 'This class has no schedule on the selected date.';

    $classStmt = $db->prepare("SELECT class_name, grade_level, section
                               FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
    $classStmt->execute([$classId]);
    $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);

    if (!$classInfo) {
        apiJson(['ok' => false, 'message' => 'Class not found'], 404);
    }

    $students = apiActiveClassStudents($db, $classId, $sex);
    $studentIds = array_map('intval', array_column($students, 'id'));

    $records = [];
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $attStmt = $db->prepare("SELECT student_id, status, time_in, time_out, remarks
                                 FROM attendance WHERE class_id = ? AND date = ? AND student_id IN ($placeholders)");
        $attStmt->execute(array_merge([$classId, $date], $studentIds));
        $existing = $attStmt->fetchAll(PDO::FETCH_ASSOC);
        $recordsByStudent = [];
        foreach ($existing as $r) {
            $recordsByStudent[(int)$r['student_id']] = $r;
        }

        foreach ($students as $s) {
            $sid = (int)$s['id'];
            $records[] = [
                'student_id' => $sid,
                'reference_code' => $s['reference_code'],
                'first_name' => $s['first_name'],
                'last_name' => $s['last_name'],
                'status' => isset($recordsByStudent[$sid]) ? $recordsByStudent[$sid]['status'] : null,
                'time_in' => isset($recordsByStudent[$sid]) ? $recordsByStudent[$sid]['time_in'] : null,
                'time_out' => isset($recordsByStudent[$sid]) ? $recordsByStudent[$sid]['time_out'] : null,
                'remarks' => isset($recordsByStudent[$sid]) ? $recordsByStudent[$sid]['remarks'] : null,
            ];
        }
    }

    apiJson([
        'ok' => true,
        'class' => $classInfo,
        'students' => $records,
        'can_edit' => $canEdit,
        'edit_reason' => $editReason,
    ]);
}

if ($route === 'teacher-attendance' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $body = apiRequestBody();
    $classId = (int)($body['class_id'] ?? 0);
    $date = trim((string)($body['date'] ?? date('Y-m-d')));
    $records = $body['records'] ?? [];

    if ($classId <= 0 || empty($records) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        apiJson(['ok' => false, 'message' => 'Class, date, and records are required'], 422);
    }
    if (!apiTeacherOwnsClass($db, (int)$user['id'], $classId)) {
        apiJson(['ok' => false, 'message' => 'You are not assigned to this class'], 403);
    }

    $academicYear = apiAcademicYearForDate($date);
    $semester = apiSemesterForDate($date);
    $hasTermColumns = apiAttendanceHasTermColumns($db);

    $teacherId = (int)$user['id'];
    $updated = 0;
    $inserted = 0;

    foreach ($records as $rec) {
        $studentId = (int)($rec['student_id'] ?? 0);
        $status = strtolower(trim((string)($rec['status'] ?? '')));
        if ($studentId <= 0 || !in_array($status, ['present', 'late', 'absent'], true)) continue;

        if (!apiTeacherOwnsClass($db, $teacherId, $classId)) continue;

        $existing = $db->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? LIMIT 1");
        $existing->execute([$classId, $studentId, $date]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $updateSql = "UPDATE attendance SET status = ?, recorded_by = ?, updated_at = NOW()" .
                ($hasTermColumns ? ", academic_year = ?, semester = ?" : "") .
                " WHERE id = ?";
            $updateParams = [$status, $teacherId];
            if ($hasTermColumns) { $updateParams[] = $academicYear; $updateParams[] = $semester; }
            $updateParams[] = (int)$existingId;
            $db->prepare($updateSql)->execute($updateParams);
            $updated++;
        } else {
            $insertSql = "INSERT INTO attendance (class_id, student_id, date, status, recorded_by, created_at, updated_at" .
                ($hasTermColumns ? ", academic_year, semester" : "") . ")
                          VALUES (?, ?, ?, ?, ?, NOW(), NOW()" .
                ($hasTermColumns ? ", ?, ?" : "") . ")";
            $insertParams = [$classId, $studentId, $date, $status, $teacherId];
            if ($hasTermColumns) { $insertParams[] = $academicYear; $insertParams[] = $semester; }
            $db->prepare($insertSql)->execute($insertParams);
            $inserted++;
        }
    }

    apiJson(['ok' => true, 'message' => "Attendance saved: $inserted new, $updated updated"]);
}

if ($route === 'teacher-grades' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $teacherId = (int)$user['id'];
    $classId = (int)($_GET['class_id'] ?? 0);
    $academicYear = apiCurrentAcademicYear();

    $teacherRoles = apiGetTeacherRoles($db, $teacherId);
    $sections = $teacherRoles['sections'];
    $subjectClasses = $teacherRoles['classes'];

    if ($classId <= 0) {
        apiJson(['ok' => true, 'sections' => $sections, 'subject_classes' => $subjectClasses, 'grades' => []]);
    }

    $period = apiNormalizePeriodLabel((string)($_GET['period'] ?? ''));
    $gradingSystem = apiGradingSystemForAcademicYear($academicYear);
    if ($period === '') {
        $period = apiDefaultPeriodLabel($academicYear);
    }

    $periodColumn = $gradingSystem === '4_quarter' ? 'quarter' : 'term';
    $params = [$classId, $academicYear, $period];

    if (!apiTeacherOwnsClass($db, $teacherId, $classId)) {
        apiJson(['ok' => false, 'message' => 'You are not assigned to this class'], 403);
    }

    $students = apiActiveClassStudents($db, $classId);

    $gradeStmt = $db->prepare("SELECT g.id, g.student_id, g.grade, g.$periodColumn AS period, g.remarks
                               FROM grades g
                               WHERE g.class_id = ? AND g.academic_year = ? AND g.$periodColumn = ?
                               ORDER BY g.student_id");
    $gradeStmt->execute($params);
    $existingGrades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    $gradesByStudent = [];
    foreach ($existingGrades as $g) {
        $gradesByStudent[(int)$g['student_id']] = $g;
    }

    $studentGrades = [];
    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $studentGrades[] = [
            'student_id' => $sid,
            'reference_code' => $s['reference_code'],
            'first_name' => $s['first_name'],
            'last_name' => $s['last_name'],
            'grade_id' => isset($gradesByStudent[$sid]) ? (int)$gradesByStudent[$sid]['id'] : null,
            'grade' => isset($gradesByStudent[$sid]) ? (float)$gradesByStudent[$sid]['grade'] : null,
            'remarks' => isset($gradesByStudent[$sid]) ? $gradesByStudent[$sid]['remarks'] : null,
        ];
    }

    apiJson([
        'ok' => true,
        'students' => $studentGrades,
        'period' => $period,
        'grading_system' => $gradingSystem,
        'academic_year' => $academicYear,
        'sections' => $sections,
        'subject_classes' => $subjectClasses,
    ]);
}

if ($route === 'teacher-grades' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $body = apiRequestBody();
    $classId = (int)($body['class_id'] ?? 0);
    $records = $body['records'] ?? [];

    if ($classId <= 0 || empty($records)) {
        apiJson(['ok' => false, 'message' => 'Class and grade records are required'], 422);
    }
    if (!apiTeacherOwnsClass($db, (int)$user['id'], $classId)) {
        apiJson(['ok' => false, 'message' => 'You are not assigned to this class'], 403);
    }

    $academicYear = apiCurrentAcademicYear();
    $period = apiNormalizePeriodLabel((string)($body['period'] ?? ''));
    $gradingSystem = apiGradingSystemForAcademicYear($academicYear);
    if ($period === '') {
        $period = apiDefaultPeriodLabel($academicYear);
    }
    $periodColumn = $gradingSystem === '4_quarter' ? 'quarter' : 'term';

    $teacherId = (int)$user['id'];
    $saved = 0;

    foreach ($records as $rec) {
        $studentId = (int)($rec['student_id'] ?? 0);
        $grade = normalizeGradeNumber($rec['grade'] ?? null);
        if ($studentId <= 0) continue;

        $existing = $db->prepare("SELECT id FROM grades WHERE class_id = ? AND student_id = ? AND academic_year = ? AND $periodColumn = ? LIMIT 1");
        $existing->execute([$classId, $studentId, $academicYear, $period]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $db->prepare("UPDATE grades SET grade = ?, updated_at = NOW() WHERE id = ?")->execute([$grade, (int)$existingId]);
            if ($grade !== null) {
                apiMarkGradePendingApproval($db, (int)$existingId, $teacherId);
            }
        } else {
            if ($grade === null) continue;
            $db->prepare("INSERT INTO grades (class_id, student_id, grade, $periodColumn, academic_year, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, NOW(), NOW())")->execute([$classId, $studentId, $grade, $period, $academicYear]);
            $newGradeId = (int)$db->lastInsertId();
            if ($newGradeId > 0) {
                apiMarkGradePendingApproval($db, $newGradeId, $teacherId);
            }
        }
        $saved++;
    }

    apiJson(['ok' => true, 'message' => "$saved grade(s) saved"]);
}

if ($route === 'teacher-classes' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $teacherId = (int)$user['id'];

    $teacherRoles = apiGetTeacherRoles($db, $teacherId);

    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                                      (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND COALESCE(status, 'enrolled') = 'enrolled') AS student_count
                               FROM classes c
                               JOIN users u ON u.id = c.teacher_id
                               WHERE c.status = 'active' AND (
                                 c.teacher_id = ?
                                 OR EXISTS (SELECT 1 FROM class_subjects WHERE class_id = c.id AND teacher_id = ?)
                               )
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$teacherId, $teacherId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson(['ok' => true, 'classes' => $classes, 'teacher_roles' => $teacherRoles]);
}

if ($route === 'teacher-class-students' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $teacherId = (int)$user['id'];
    $classId = (int)($_GET['class_id'] ?? 0);

    if ($classId <= 0) {
        apiJson(['ok' => false, 'message' => 'Class ID is required'], 422);
    }
    if (!apiTeacherOwnsClass($db, $teacherId, $classId)) {
        apiJson(['ok' => false, 'message' => 'You are not assigned to this class'], 403);
    }

    $students = apiActiveClassStudents($db, $classId);
    $classStmt = $db->prepare("SELECT class_name, grade_level, section FROM classes WHERE id = ? LIMIT 1");
    $classStmt->execute([$classId]);
    $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);

    apiJson(['ok' => true, 'class' => $classInfo, 'students' => $students]);
}

if ($route === 'teacher-advisory' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $teacherId = (int)$user['id'];

    $advisory = apiTeacherAdvisoryInfo($db, $teacherId);
    if (!$advisory) {
        $teacherRoles = apiGetTeacherRoles($db, $teacherId);
        apiJson(['ok' => true, 'advisory' => null, 'sections' => $teacherRoles['sections']]);
    }

    $students = apiTeacherAdvisoryStudents($db, $advisory);
    $teacherRoles = apiGetTeacherRoles($db, $teacherId);

    apiJson([
        'ok' => true,
        'advisory' => $advisory,
        'students' => $students,
        'sections' => $teacherRoles['sections'],
    ]);
}

if ($route === 'teacher-announcements' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if ($user['role'] !== 'teacher') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    $teacherId = (int)$user['id'];

    $annStmt = $db->query("SELECT a.id, a.title, a.content, a.category, a.created_at, a.views,
                           CONCAT(u.first_name, ' ', u.last_name) AS posted_by_name
                           FROM announcements a
                           LEFT JOIN users u ON u.id = a.posted_by
                           WHERE a.status = 'active'
                           ORDER BY a.created_at DESC LIMIT 10");
    $announcements = $annStmt->fetchAll(PDO::FETCH_ASSOC);

    $classAnnStmt = $db->prepare("SELECT ca.id, ca.title, ca.content, ca.status, ca.created_at, c.class_name
                                  FROM class_announcements ca
                                  JOIN class_subjects cs ON cs.class_id = ca.class_id
                                  JOIN classes c ON c.id = ca.class_id
                                  WHERE cs.teacher_id = ? AND ca.status = 'active'
                                  ORDER BY ca.created_at DESC LIMIT 10");
    $classAnnStmt->execute([$teacherId]);
    $classAnnouncements = $classAnnStmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson(['ok' => true, 'announcements' => $announcements, 'class_announcements' => $classAnnouncements]);
}
