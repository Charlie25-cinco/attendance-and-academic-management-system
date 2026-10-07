<?php
require_once __DIR__ . '/../functions/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Legacy final-review URLs must not permit an Admin to bypass the Principal.
if (in_array($_GET['action'] ?? '', ['review_report_card', 'review_report_card_batch', 'return_released_report_card_batch'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Final report card decisions are handled in the Principal portal.']);
    exit();
}
$db = (new Database())->getConnection();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}



function notifySectionAdviserAndTeachers(
    PDO $db,
    int $gradeLevel,
    string $section,
    string $academicYear,
    string $title,
    string $subtitle,
    string $icon,
    string $color,
    string $teacherTarget = 'teacher_Advisory.php',
    ?int $onlyTeacherId = null,
    ?string $customSourceKey = null
): void {
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
            $cleanSec = preg_replace('/[^a-zA-Z0-9]/', '', $section);
            $cleanYear = preg_replace('/[^a-zA-Z0-9]/', '', $academicYear);
            $sourceKey = ($customSourceKey !== null && $customSourceKey !== '')
                ? $customSourceKey
                : ('grade_workflow_' . $gradeLevel . '_' . $cleanSec . '_' . $cleanYear);

            appDispatchNotification(
                $db,
                $teacherIds,
                $sourceKey,
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

    $infoStmt = $db->prepare("SELECT ga.submitted_by, ga.reviewed_at, g.academic_year, c.grade_level, c.section, c.class_name, cs.teacher_id
                              FROM grade_approvals ga
                              JOIN grades g ON g.id = ga.grade_id
                              JOIN class_subjects cs ON cs.id = g.class_subject_id
                              JOIN classes c ON c.id = cs.class_id
                              WHERE ga.id = ? LIMIT 1");
    $infoStmt->execute([$approvalId]);
    $gInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);
    if ($gInfo) {
        $targetTeacherId = (int)($gInfo['submitted_by'] ?: $gInfo['teacher_id']);
        $gLevel = (int)$gInfo['grade_level'];
        $sec = (string)$gInfo['section'];
        $ay = (string)$gInfo['academic_year'];
        $cName = (string)$gInfo['class_name'];
        $reviewedAtTs = strtotime((string)($gInfo['reviewed_at'] ?? 'now')) ?: time();
        if ($status === 'admin_verified') {
            notifySectionAdviserAndTeachers(
                $db,
                $gLevel,
                $sec,
                $ay,
                'Subject Grades Verified',
                "Admin verified grades for {$cName} (Grade {$gLevel} - {$sec}).",
                'bi-check2-circle',
                'info',
                'teacher_Advisory.php',
                $targetTeacherId,
                'grade_verify_single_' . $approvalId . '_status_admin_verified_' . $reviewedAtTs
            );
        } else {
            $reasonText = $remarks !== '' ? ": {$remarks}" : '.';
            notifySectionAdviserAndTeachers(
                $db,
                $gLevel,
                $sec,
                $ay,
                'Subject Grades Returned for Correction',
                "Admin returned grades for {$cName} (Grade {$gLevel} - {$sec}) for correction{$reasonText}",
                'bi-exclamation-triangle',
                'warning',
                'teacher_Grades.php',
                $targetTeacherId,
                'grade_reject_single_' . $approvalId . '_status_rejected_' . $reviewedAtTs
            );
        }
    }

    recordAdminAuditLog($db, 'grade_approval.' . $status, 'grade_approval', $approvalId, [
        'new_status' => $status,
        'remarks_provided' => $remarks !== '',
    ], $adminId);

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

    $infoStmt = $db->prepare("SELECT ga.submitted_by, ga.reviewed_at, g.academic_year, c.grade_level, c.section, c.class_name, cs.teacher_id
                              FROM grade_approvals ga
                              JOIN grades g ON g.id = ga.grade_id
                              JOIN class_subjects cs ON cs.id = g.class_subject_id
                              JOIN classes c ON c.id = cs.class_id
                              WHERE ga.id = ? LIMIT 1");
    $infoStmt->execute([$approvalId]);
    $gInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);
    if ($gInfo) {
        $targetTeacherId = (int)($gInfo['submitted_by'] ?: $gInfo['teacher_id']);
        $gLevel = (int)$gInfo['grade_level'];
        $sec = (string)$gInfo['section'];
        $ay = (string)$gInfo['academic_year'];
        $cName = (string)$gInfo['class_name'];
        $reviewedAtTs = strtotime((string)($gInfo['reviewed_at'] ?? 'now')) ?: time();
        $reasonText = $remarks !== '' ? ": {$remarks}" : '.';
        notifySectionAdviserAndTeachers(
            $db,
            $gLevel,
            $sec,
            $ay,
            'Verified Grades Returned for Teacher Edit',
            "Admin returned verified grades for {$cName} (Grade {$gLevel} - {$sec}) as editable{$reasonText}",
            'bi-exclamation-triangle',
            'warning',
            'teacher_Grades.php',
            $targetTeacherId,
            'grade_return_single_' . $approvalId . '_status_rejected_' . $reviewedAtTs
        );
    }

    recordAdminAuditLog($db, 'grade_approval.return', 'grade_approval', $approvalId, [
        'new_status' => 'rejected',
        'remarks_provided' => $remarks !== '',
    ], $adminId);

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
    $updatedCount = $stmt->rowCount();
    if ($updatedCount <= 0) {
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

    recordAdminAuditLog($db, 'grade_approval.batch_' . $status, 'section_grades', null, [
        'grade_level' => $gradeLevel,
        'section' => $section,
        'academic_year' => $academicYear,
        'semester' => $semester,
        'record_count' => $updatedCount,
        'remarks_provided' => $remarks !== '',
    ], $adminId);

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
    $updatedCount = $stmt->rowCount();
    if ($updatedCount <= 0) {
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

    recordAdminAuditLog($db, 'grade_approval.batch_return', 'section_grades', null, [
        'grade_level' => $gradeLevel,
        'section' => $section,
        'academic_year' => $academicYear,
        'semester' => $semester,
        'record_count' => $updatedCount,
        'remarks_provided' => $remarks !== '',
    ], $adminId);

    echo json_encode(['success' => true, 'message' => 'Verified grades returned as rejected. Teachers can edit and submit again.']);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);






