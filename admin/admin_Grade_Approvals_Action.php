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

function adminGradeApprovalRequireCsrf() {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
}

function ensureGradeApprovalsTableForAdminAction($db) {
    static $ready = false; if ($ready) return; $ready = true;
    $db->exec("CREATE TABLE IF NOT EXISTS grade_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade_id INT NOT NULL,
        status ENUM('pending','submitted','admin_verified','rejected','approved') DEFAULT 'pending',
        submitted_by INT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        remarks VARCHAR(255) NULL,
        UNIQUE KEY uq_grade_approval_grade (grade_id),
        KEY idx_grade_approval_status (status),
        FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

function ensureReportCardApprovalsTableForAdminAction($db) {
    static $ready = false; if ($ready) return; $ready = true;
    $db->exec("CREATE TABLE IF NOT EXISTS report_card_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        semester VARCHAR(5) NULL,
        advisory_teacher_id INT NOT NULL,
        status ENUM('pending','rejected','submitted_admin','approved') DEFAULT 'pending',
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        remarks VARCHAR(255) NULL,
        UNIQUE KEY uq_report_card_term (student_id, academic_year, semester),
        KEY idx_report_card_status (status),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (advisory_teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

$action = $_GET['action'] ?? '';
if ($action === 'review') {
    adminGradeApprovalRequireCsrf();
    ensureGradeApprovalsTableForAdminAction($db);
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
                          WHERE id = ?");
    $stmt->execute([$status, $adminId, $remarks !== '' ? $remarks : null, $approvalId]);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'Approval record not found']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Grade status updated to ' . ucfirst($status)]);
    exit();
}

if ($action === 'review_grade_batch') {
    adminGradeApprovalRequireCsrf();
    ensureGradeApprovalsTableForAdminAction($db);
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
        ? "AND g.semester = ?" : "AND g.semester IS NULL";
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
                          AND (
                            LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                            OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(c.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                          )
                          AND ga.status = 'submitted'");
    $stmt->execute($params);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'No submitted grade records found for the selected section']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Section grades updated to ' . str_replace('_', ' ', $status)]);
    exit();
}

if ($action === 'review_report_card') {
    adminGradeApprovalRequireCsrf();
    ensureReportCardApprovalsTableForAdminAction($db);
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
                          WHERE id = ?");
    $stmt->execute([$status, $adminId, $remarks !== '' ? $remarks : null, $approvalId]);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'Report card approval record not found']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Report card status updated to ' . ucfirst($status)]);
    exit();
}

if ($action === 'review_report_card_batch') {
    adminGradeApprovalRequireCsrf();
    ensureReportCardApprovalsTableForAdminAction($db);
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
                          AND (
                            LOWER(TRIM(COALESCE(s.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                            OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(s.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                          )
                          AND rc.status = 'submitted_admin'");
    $stmt->execute($params);
    if ($stmt->rowCount() <= 0) {
        echo json_encode(['success' => false, 'message' => 'No pending report card records found for the selected section']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Section report cards updated to ' . ucfirst($status)]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);






