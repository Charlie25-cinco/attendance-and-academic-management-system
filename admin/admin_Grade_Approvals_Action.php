<?php
require_once __DIR__ . '/../functions/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db = (new Database())->getConnection();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}



function notifySectionAdviserAndTeachers(PDO $db, int $gradeLevel, string $section, string $academicYear, string $title, string $subtitle, string $icon, string $color, string $teacherTarget = 'teacher_Advisory.php', ?int $onlyTeacherId = null): void {
    try {
        $teacherIds = [];
        if ($onlyTeacherId !== null && $onlyTeacherId > 0) {
            $teacherIds = [$onlyTeacherId];
        } else {
            $advStmt = $db->prepare("SELECT adviser_id FROM sections WHERE grade_level = ? AND " . sectionMatchSql('name') . " LIMIT 1");
            $advStmt->execute([$gradeLevel, $section, $section]);
            $adviserId = (int)($advStmt->fetchColumn() ?: 0);
            if ($adviserId > 0) {
                $teacherIds[] = $adviserId;
            }
            $subStmt = $db->prepare("SELECT DISTINCT cs.teacher_id 
                                     FROM class_subjects cs 
                                     JOIN classes c ON c.id = cs.class_id 
                                     WHERE c.grade_level = ? AND " . sectionMatchSql('c.section'));
            $subStmt->execute([$gradeLevel, $section, $section]);
            foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) {
                    $teacherIds[] = $tid;
                }
            }
        }
        $teacherIds = array_values(array_unique(array_filter($teacherIds)));
        if (!empty($teacherIds) && function_exists('appDispatchNotification')) {
            appDispatchNotification(
                $db,
                $teacherIds,
                'grade_workflow_' . $gradeLevel . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $section) . '_' . time(),
                $title,
                $subtitle,
                $icon,
                $color,
                ['teacher' => $teacherTarget],
                ['type' => 'grade_workflow', 'grade_level' => $gradeLevel, 'section' => $section, 'academic_year' => $academicYear]
            );
        }
    } catch (Throwable $e) {
        error_log('notifySectionAdviserAndTeachers error: ' . $e->getMessage());
    }
}

$action = $_GET['action'] ?? '';
if ($action === 'review') {
    requireCsrfToken();
    $approvalId = (int)($_POST['approval_id'] ?? 0);
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($approvalId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid approval id']);
        exit();
    }
    if (!in_array($status, ['admin_verified', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    $stmt = $db->prepare("UPDATE grade_approvals
                          SET status = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = ?
                          WHERE id = ? AND status = 'submitted'");
    $stmt->execute([$status, $adminId, $remarks !== '' ? $remarks : null, $approvalId]);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'Submitted approval record not found or already reviewed']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Grade status updated to ' . ucfirst($status)]);
    exit();
}

if ($action === 'return_grade') {
    requireCsrfToken();
    $approvalId = (int)($_POST['approval_id'] ?? 0);
    $remarks = trim((string)($_POST['remarks'] ?? 'Returned to teacher for correction.'));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($approvalId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid approval id']);
        exit();
    }

    $stmt = $db->prepare("UPDATE grade_approvals
                          SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), remarks = ?
                          WHERE id = ? AND status = 'admin_verified'");
    $stmt->execute([$adminId, $remarks !== '' ? $remarks : 'Returned to teacher for correction.', $approvalId]);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'Verified approval record not found or already returned']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Grade returned to teacher as rejected for correction']);
    exit();
}

if ($action === 'review_grade_batch') {
    requireCsrfToken();
    $gradeLevel = (int)($_POST['grade_level'] ?? 0);
    $section = trim((string)($_POST['section'] ?? ''));
    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($gradeLevel <= 0 || $section === '' || $academicYear === '') {
        echo json_encode(['success' => false, 'message' => 'Grade level, section, and academic year are required']);
        exit();
    }
    if (!in_array($status, ['admin_verified', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    $semCondition = $semester !== ''
        ? "AND g.semester = ?" : "AND (g.semester IS NULL OR g.semester = '')";
    $params = [$status, $adminId, $remarks !== '' ? $remarks : null, $academicYear];
    if ($semester !== '') {
        $params[] = $semester;
    }
    $params = array_merge($params, [$gradeLevel, $section, $section]);

    $stmt = $db->prepare("UPDATE grade_approvals ga
                          JOIN grades g ON g.id = ga.grade_id
                          JOIN class_subjects cs ON cs.id = g.class_subject_id
                          JOIN classes c ON c.id = cs.class_id
                          SET ga.status = ?, ga.reviewed_by = ?, ga.reviewed_at = NOW(), ga.remarks = ?
                          WHERE g.academic_year = ?
                          {$semCondition}
                          AND c.grade_level = ?
                          AND " . sectionMatchSql('c.section') . "
                          AND ga.status = 'submitted'");
    $stmt->execute($params);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'No submitted grade records found for the selected section']);
        exit();
    }

    if ($status === 'admin_verified') {
        notifySectionAdviserAndTeachers(
            $db,
            $gradeLevel,
            $section,
            $academicYear,
            'Subject Grades Verified',
            "Admin verified subject grades for Grade {$gradeLevel} - {$section}. Adviser report cards can now be compiled.",
            'bi-check2-circle',
            'info',
            'teacher_Advisory.php'
        );
    } elseif ($status === 'rejected') {
        $reasonText = $remarks !== '' ? ": {$remarks}" : '.';
        notifySectionAdviserAndTeachers(
            $db,
            $gradeLevel,
            $section,
            $academicYear,
            'Subject Grades Returned for Correction',
            "Admin returned subject grades for Grade {$gradeLevel} - {$section}{$reasonText}",
            'bi-exclamation-triangle',
            'warning',
            'teacher_Grades.php'
        );
    }

    echo json_encode(['success' => true, 'message' => 'Section grades updated to ' . str_replace('_', ' ', $status)]);
    exit();
}

if ($action === 'return_grade_batch') {
    requireCsrfToken();
    $gradeLevel = (int)($_POST['grade_level'] ?? 0);
    $section = trim((string)($_POST['section'] ?? ''));
    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? 'Returned to teacher for correction.'));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($gradeLevel <= 0 || $section === '' || $academicYear === '') {
        echo json_encode(['success' => false, 'message' => 'Grade level, section, and academic year are required']);
        exit();
    }

    $semCondition = $semester !== ''
        ? "AND g.semester = ?" : "AND (g.semester IS NULL OR g.semester = '')";
    $params = [$adminId, $remarks !== '' ? $remarks : 'Returned to teacher for correction.', $academicYear];
    if ($semester !== '') {
        $params[] = $semester;
    }
    $params = array_merge($params, [$gradeLevel, $section, $section]);

    $stmt = $db->prepare("UPDATE grade_approvals ga
                          JOIN grades g ON g.id = ga.grade_id
                          JOIN class_subjects cs ON cs.id = g.class_subject_id
                          JOIN classes c ON c.id = cs.class_id
                          SET ga.status = 'rejected', ga.reviewed_by = ?, ga.reviewed_at = NOW(), ga.remarks = ?
                          WHERE g.academic_year = ?
                          {$semCondition}
                          AND c.grade_level = ?
                          AND " . sectionMatchSql('c.section') . "
                          AND ga.status = 'admin_verified'");
    $stmt->execute($params);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'No verified grade records found for the selected section']);
        exit();
    }

    $reasonText = $remarks !== '' ? ": {$remarks}" : '.';
    notifySectionAdviserAndTeachers(
        $db,
        $gradeLevel,
        $section,
        $academicYear,
        'Verified Grades Returned for Teacher Edit',
        "Admin returned verified grades for Grade {$gradeLevel} - {$section} as editable{$reasonText}",
        'bi-exclamation-triangle',
        'warning',
        'teacher_Grades.php'
    );

    echo json_encode(['success' => true, 'message' => 'Verified grades returned as rejected. Teachers can edit and submit again.']);
    exit();
}

if ($action === 'review_report_card') {
    requireCsrfToken();
    $approvalId = (int)($_POST['approval_id'] ?? 0);
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($approvalId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid report card approval id']);
        exit();
    }
    if (!in_array($status, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    $stmt = $db->prepare("UPDATE report_card_approvals
                          SET status = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = ?
                          WHERE id = ? AND status = 'submitted_admin'");
    $stmt->execute([$status, $adminId, $remarks !== '' ? $remarks : null, $approvalId]);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'Submitted report card record not found or already reviewed']);
        exit();
    }

    if ($status === 'approved') {
        $rcStmt = $db->prepare("SELECT student_id, academic_year, semester FROM report_card_approvals WHERE id = ?");
        $rcStmt->execute([$approvalId]);
        $rcRow = $rcStmt->fetch(PDO::FETCH_ASSOC);
        if ($rcRow) {
            $studentId = (int)$rcRow['student_id'];
            $enStmt = $db->prepare("SELECT class_id FROM enrollments WHERE student_id = ? AND COALESCE(status, 'enrolled') = 'enrolled' LIMIT 1");
            $enStmt->execute([$studentId]);
            $classId = (int)($enStmt->fetchColumn() ?: 0);
            if ($classId > 0 && function_exists('pushNotifyGradePublication')) {
                pushNotifyGradePublication($db, $classId, (string)($rcRow['semester'] ?? 'Final'), (string)($rcRow['academic_year'] ?? ''));
            }
        }
    } elseif ($status === 'rejected') {
        $rcStmt = $db->prepare("SELECT student_id, academic_year, semester FROM report_card_approvals WHERE id = ?");
        $rcStmt->execute([$approvalId]);
        $rcRow = $rcStmt->fetch(PDO::FETCH_ASSOC);
        if ($rcRow) {
            $studentId = (int)$rcRow['student_id'];
            $enStmt = $db->prepare("SELECT class_id FROM enrollments WHERE student_id = ? AND COALESCE(status, 'enrolled') = 'enrolled' LIMIT 1");
            $enStmt->execute([$studentId]);
            $classId = (int)($enStmt->fetchColumn() ?: 0);
            if ($classId > 0 && function_exists('pushNotifyGradeRecall')) {
                pushNotifyGradeRecall($db, $classId, (string)($rcRow['semester'] ?? 'Final'), (string)($rcRow['academic_year'] ?? ''), $remarks);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Report card status updated to ' . ucfirst($status)]);
    exit();
}

if ($action === 'review_report_card_batch') {
    requireCsrfToken();
    $gradeLevel = (int)($_POST['grade_level'] ?? 0);
    $section = trim((string)($_POST['section'] ?? ''));
    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($gradeLevel <= 0 || $section === '' || $academicYear === '') {
        echo json_encode(['success' => false, 'message' => 'Grade level, section, and academic year are required']);
        exit();
    }
    if (!in_array($status, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    $semCondition = $semester !== ''
        ? "AND rc.semester = ?" : "AND rc.semester IS NULL";
    $params = [$status, $adminId, $remarks !== '' ? $remarks : null, $academicYear];
    if ($semester !== '') $params[] = $semester;
    $params = array_merge($params, [$gradeLevel, $section, $section]);

    $stmt = $db->prepare("UPDATE report_card_approvals rc
                          JOIN users s ON s.id = rc.student_id
                          SET rc.status = ?, rc.reviewed_by = ?, rc.reviewed_at = NOW(), rc.remarks = ?
                          WHERE rc.academic_year = ?
                          {$semCondition}
                          AND s.role = 'student'
                          AND s.grade_level = ?
                          AND " . sectionMatchSql('s.section') . "
                          AND rc.status = 'submitted_admin'");
    $stmt->execute($params);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'No pending report card records found for the selected section']);
        exit();
    }

    if ($status === 'approved') {
        $cStmt = $db->prepare("SELECT id FROM classes WHERE grade_level = ? AND " . sectionMatchSql('section') . " LIMIT 1");
        $cStmt->execute([$gradeLevel, $section, $section]);
        $classId = (int)($cStmt->fetchColumn() ?: 0);
        if ($classId > 0 && function_exists('pushNotifyGradePublication')) {
            pushNotifyGradePublication($db, $classId, $semester !== '' ? $semester : 'Final', $academicYear);
        }

        notifySectionAdviserAndTeachers(
            $db,
            $gradeLevel,
            $section,
            $academicYear,
            'Report Cards Officially Approved & Released',
            "Admin officially approved and released the report cards for Grade {$gradeLevel} - {$section}.",
            'bi-award',
            'success',
            'teacher_Advisory.php'
        );
    } elseif ($status === 'rejected') {
        $reasonText = $remarks !== '' ? ": {$remarks}" : '.';
        notifySectionAdviserAndTeachers(
            $db,
            $gradeLevel,
            $section,
            $academicYear,
            'Report Cards Returned by Admin',
            "Admin returned the report cards for Grade {$gradeLevel} - {$section}{$reasonText}",
            'bi-x-circle',
            'danger',
            'teacher_Advisory.php'
        );
    }

    echo json_encode(['success' => true, 'message' => 'Section report cards updated to ' . ucfirst($status)]);
    exit();
}

if ($action === 'return_released_report_card_batch') {
    requireCsrfToken();
    $gradeLevel = (int)($_POST['grade_level'] ?? 0);
    $section = trim((string)($_POST['section'] ?? ''));
    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? 'Returned after final release for correction.'));
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($gradeLevel <= 0 || $section === '' || $academicYear === '') {
        echo json_encode(['success' => false, 'message' => 'Grade level, section, and academic year are required']);
        exit();
    }

    $semReportCondition = $semester !== ''
        ? "AND rc.semester = ?" : "AND (rc.semester IS NULL OR rc.semester = '')";
    $semGradeCondition = $semester !== ''
        ? "AND g.semester = ?" : "AND (g.semester IS NULL OR g.semester = '')";
    $reason = $remarks !== '' ? $remarks : 'Returned after final release for correction.';

    try {
        $db->beginTransaction();

        $reportParams = [$adminId, $reason, $academicYear];
        if ($semester !== '') {
            $reportParams[] = $semester;
        }
        $reportParams = array_merge($reportParams, [$gradeLevel, $section, $section]);
        $reportStmt = $db->prepare("UPDATE report_card_approvals rc
                                    JOIN users s ON s.id = rc.student_id
                                    SET rc.status = 'rejected',
                                        rc.reviewed_by = ?,
                                        rc.reviewed_at = NOW(),
                                        rc.remarks = ?
                                    WHERE rc.academic_year = ?
                                    {$semReportCondition}
                                    AND s.role = 'student'
                                    AND s.grade_level = ?
                                    AND " . sectionMatchSql('s.section') . "
                                    AND rc.status = 'approved'");
        $reportStmt->execute($reportParams);
        $reportCount = $reportStmt->rowCount();

        $gradeParams = [$adminId, $reason, $academicYear];
        if ($semester !== '') {
            $gradeParams[] = $semester;
        }
        $gradeParams = array_merge($gradeParams, [$gradeLevel, $section, $section]);
        $gradeStmt = $db->prepare("UPDATE grade_approvals ga
                                   JOIN grades g ON g.id = ga.grade_id
                                   JOIN class_subjects cs ON cs.id = g.class_subject_id
                                   JOIN classes c ON c.id = cs.class_id
                                   SET ga.status = 'rejected',
                                       ga.reviewed_by = ?,
                                       ga.reviewed_at = NOW(),
                                       ga.remarks = ?
                                   WHERE g.academic_year = ?
                                   {$semGradeCondition}
                                   AND c.grade_level = ?
                                   AND " . sectionMatchSql('c.section') . "
                                   AND ga.status = 'admin_verified'");
        $gradeStmt->execute($gradeParams);
        $gradeCount = $gradeStmt->rowCount();

        if ($reportCount <= 0 && $gradeCount <= 0) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'No released report cards or verified grades found for the selected section']);
            exit();
        }

        $db->commit();

        $cStmt = $db->prepare("SELECT id FROM classes WHERE grade_level = ? AND " . sectionMatchSql('section') . " LIMIT 1");
        $cStmt->execute([$gradeLevel, $section, $section]);
        $classId = (int)($cStmt->fetchColumn() ?: 0);
        if ($classId > 0 && function_exists('pushNotifyGradeRecall')) {
            pushNotifyGradeRecall($db, $classId, $semester !== '' ? $semester : 'Final', $academicYear, $reason);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Released grades returned for correction. Teachers can edit and submit again.'
        ]);
        exit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('returnReleasedReportCards error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        exit();
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);






