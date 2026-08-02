<?php
require_once __DIR__ . '/../functions/bootstrap.php';
header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (ob_get_level() === 0) {
    ob_start();
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $exception) {
    error_log('Admin_Enrollments_Action fatal: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    if (ob_get_length()) {
        ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit();
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }
    error_log('Admin_Enrollments_Action shutdown: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (ob_get_length()) {
        ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit();
});

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}
$action = $_GET['action'] ?? '';

if (in_array($action, ['create', 'update', 'delete', 'toggle_status'], true)) {
    requireCsrfToken();
}

try {
    // Clean buffer before any action output
    if (ob_get_length()) {
        ob_clean();
    }

    switch ($action) {
        case 'list':
            getStudents($db);
            break;
        case 'create':
            ensureStrengthenedShsColumns($db);
            createStudent($db);
            break;
        case 'get':
            getStudent($db);
            break;
        case 'update':
            ensureStrengthenedShsColumns($db);
            updateStudent($db);
            break;
        case 'delete':
            deleteStudent($db);
            break;
        case 'toggle_status':
            toggleStudentStatus($db);
            break;
        case 'export_sf1':
            ensureStrengthenedShsColumns($db);
            exportSf1($db);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Admin_Enrollments_Action error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}

function validateLrn(string $lrn): bool {
    return preg_match('/^\d{12}$/', $lrn) === 1;
}

function checkLrnExists(PDO $db, string $lrn, int $excludeUserId = 0): bool {
    $sql = "SELECT id FROM users WHERE lrn = ? AND role = 'student'";
    $params = [$lrn];
    if ($excludeUserId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeUserId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function getStudents(PDO $db): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 15;
    $offset = ($page - 1) * $per_page;
    $search = trim((string)($_GET['search'] ?? ''));
    $status_filter = trim((string)($_GET['status'] ?? ''));
    $grade_filter = trim((string)($_GET['grade_level'] ?? ''));
    $section_filter = trim((string)($_GET['section'] ?? ''));
    $track_filter = trim((string)($_GET['track'] ?? ''));
    $hasTrackColumn = dbHasColumn($db, 'users', 'track');
    $hasCurriculumColumn = dbHasColumn($db, 'users', 'curriculum');
    $hasProgramColumn = dbHasColumn($db, 'users', 'program');

    $where = ["u.role = 'student'"];
    $params = [];

    if ($status_filter) {
        $where[] = "u.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($grade_filter) {
        $where[] = "u.grade_level = :grade_level";
        $params[':grade_level'] = (int)$grade_filter;
    }
    if ($section_filter) {
        $where[] = "u.section = :section";
        $params[':section'] = $section_filter;
    }
    if ($track_filter && $hasTrackColumn) {
        $where[] = "u.track = :track";
        $params[':track'] = $track_filter;
    }
    if ($search) {
        $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.lrn LIKE :search OR u.reference_code LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM users u $whereClause");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $per_page));

    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }

    $trackSelect = $hasTrackColumn ? 'u.track,' : '';
    $curriculumSelect = $hasCurriculumColumn ? 'u.curriculum,' : "NULL AS curriculum,";
    $programSelect = $hasProgramColumn ? 'u.program,' : "NULL AS program,";

    $query = "SELECT u.id, u.reference_code, u.first_name, u.last_name, u.lrn, u.sex, u.grade_level, u.section, $trackSelect $curriculumSelect $programSelect u.status, u.created_at,
                     '' as enrolled_classes
              FROM users u
              $whereClause
              ORDER BY u.last_name ASC, u.first_name ASC
              LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    attachEnrolledClassNames($db, $students);

    // Stats
    $statsStmt = $db->query("SELECT
        SUM(CASE WHEN role = 'student' AND status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN role = 'student' AND status = 'inactive' THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN role = 'student' AND status = 'pending' THEN 1 ELSE 0 END) as pending,
        COUNT(*) as total
    FROM users WHERE role = 'student'");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'students' => $students,
        'stats' => $stats,
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page
    ]);
}

function attachEnrolledClassNames(PDO $db, array &$students): void {
    if (empty($students)) {
        return;
    }

    $studentIds = array_values(array_filter(array_map('intval', array_column($students, 'id'))));
    if (empty($studentIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $db->prepare("SELECT e.student_id, GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') AS enrolled_classes
                          FROM enrollments e
                          JOIN classes c ON c.id = e.class_id
                          WHERE e.status = 'enrolled' AND e.student_id IN ($placeholders)
                          GROUP BY e.student_id");
    $stmt->execute($studentIds);
    $classNamesByStudent = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $classNamesByStudent[(int)$row['student_id']] = (string)($row['enrolled_classes'] ?? '');
    }

    foreach ($students as &$student) {
        $student['enrolled_classes'] = $classNamesByStudent[(int)$student['id']] ?? '';
    }
    unset($student);
}

function createStudent(PDO $db): void {
    $tempPath = null;
    try {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $sex = strtolower(trim((string)($_POST['sex'] ?? '')));
        $gradeLevel = trim((string)($_POST['grade_level'] ?? ''));
        $section = trim((string)($_POST['section'] ?? ''));
        $track = trim((string)($_POST['track'] ?? ''));
        $lrn = trim((string)($_POST['lrn'] ?? ''));
        $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
        $houseStreet = trim((string)($_POST['house_street'] ?? ''));
        $barangay = trim((string)($_POST['barangay'] ?? ''));
        $municipality = trim((string)($_POST['municipality'] ?? ''));
        $province = trim((string)($_POST['province'] ?? ''));
        $address = implode(', ', array_filter([$houseStreet, $barangay, $municipality, $province]));
        $dateOfBirth = trim((string)($_POST['date_of_birth'] ?? ''));
        $academicYear = currentAcademicYear();

        if (empty($firstName) || empty($lastName)) {
            echo json_encode(['success' => false, 'message' => 'First name and last name are required']);
            return;
        }
        if (!isValidPersonName($firstName, false) || !isValidPersonName($middleName, true) || !isValidPersonName($lastName, false)) {
            echo json_encode(['success' => false, 'message' => 'Names must contain letters only.']);
            return;
        }
        if (!in_array($sex, ['male', 'female'], true)) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid sex']);
            return;
        }
        if (empty($gradeLevel) || empty($section)) {
            echo json_encode(['success' => false, 'message' => 'Grade level and section are required']);
            return;
        }
        if ((int)$gradeLevel < 11 || (int)$gradeLevel > 12) {
            echo json_encode(['success' => false, 'message' => 'Grade level must be 11 or 12']);
            return;
        }
        if (empty($track) || !in_array($track, ['academic', 'techpro'], true)) {
            echo json_encode(['success' => false, 'message' => 'Track is required (academic or techpro)']);
            return;
        }
        if (empty($lrn)) {
            echo json_encode(['success' => false, 'message' => 'LRN is required']);
            return;
        }
        if (!validateLrn($lrn)) {
            echo json_encode(['success' => false, 'message' => 'LRN must be exactly 12 digits']);
            return;
        }
        if (checkLrnExists($db, $lrn)) {
            echo json_encode(['success' => false, 'message' => 'LRN already exists in the system']);
            return;
        }

        $db->beginTransaction();

        $referenceCode = generateReferenceCode('student', $db, currentAcademicYear());
        $email = $referenceCode . '@students.balingasag.edu.ph';
        $hashedPassword = password_hash(getDefaultNewUserPassword(), PASSWORD_DEFAULT);
        $curriculum = strengthenedShsCurriculum((int)$gradeLevel, $academicYear);
        $program = strengthenedShsProgram((int)$gradeLevel, $track, $academicYear);

        $stmt = $db->prepare("INSERT INTO users (reference_code, email, lrn, password, first_name, middle_name, last_name, sex, grade_level, section, track, curriculum, program, role, status, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', NOW(), NOW())");
        $stmt->execute([
            $referenceCode, $email, $lrn, $hashedPassword,
            $firstName, $middleName ?: null, $lastName,
            $sex ?: null, (int)$gradeLevel, $section, $track, $curriculum, $program
        ]);

        $userId = (int)$db->lastInsertId();

        if ($contactNumber !== '') {
            $db->prepare("UPDATE users SET contact_number = ? WHERE id = ?")->execute([$contactNumber, $userId]);
        }
        if ($address !== '') {
            $db->prepare("UPDATE users SET address = ?, house_street = ?, barangay = ?, municipality = ?, province = ? WHERE id = ?")
                ->execute([$address, $houseStreet ?: null, $barangay ?: null, $municipality ?: null, $province ?: null, $userId]);
        }
        if ($dateOfBirth !== '') {
            $db->prepare("UPDATE users SET date_of_birth = ? WHERE id = ?")->execute([$dateOfBirth, $userId]);
        }

        syncStudentEnrollments($db, $userId, (int)$gradeLevel, $section, $track, $academicYear);

        $db->commit();

        recordAdminAuditLog($db, 'student.create', 'student', $userId, [
            'reference_code' => $referenceCode,
            'lrn' => $lrn,
            'grade_level' => (int)$gradeLevel,
            'section' => $section,
            'track' => $track,
            'academic_year' => $academicYear,
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'Student created and enrolled successfully. Default password: ' . getDefaultNewUserPassword(),
            'user_id' => $userId
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Admin_Enrollments_Action createStudent error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function getStudent(PDO $db): void {
    $userId = (int)($_GET['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
        return;
    }

    $stmt = $db->prepare("SELECT id, reference_code, first_name, middle_name, last_name, email, lrn, sex, grade_level, section, track, curriculum, program, status, contact_number, address, date_of_birth, created_at, last_login
                          FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$userId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        return;
    }

    // Enrolled classes
    $classStmt = $db->prepare("SELECT e.id as enrollment_id, e.class_id, e.status as enrollment_status, e.academic_year, e.semester, c.class_name, c.grade_level, c.section, c.track as class_track, c.schedule
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               WHERE e.student_id = ?
                               ORDER BY e.status ASC, e.enrolled_at DESC");
    $classStmt->execute([$userId]);
    $student['enrolled_classes'] = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'student' => $student]);
}

function updateStudent(PDO $db): void {
    try {
        $userId = (int)($_POST['user_id'] ?? 0);
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $sex = strtolower(trim((string)($_POST['sex'] ?? '')));
        $gradeLevel = trim((string)($_POST['grade_level'] ?? ''));
        $section = trim((string)($_POST['section'] ?? ''));
        $track = trim((string)($_POST['track'] ?? ''));
        $lrn = trim((string)($_POST['lrn'] ?? ''));
        $academicYear = currentAcademicYear();

        if ($userId <= 0 || $firstName === '' || $lastName === '') {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            return;
        }
        if (!isValidPersonName($firstName, false) || !isValidPersonName($middleName, true) || !isValidPersonName($lastName, false)) {
            echo json_encode(['success' => false, 'message' => 'Names must contain letters only.']);
            return;
        }
        if (!in_array($sex, ['male', 'female'], true)) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid sex']);
            return;
        }
        if (empty($gradeLevel) || empty($section)) {
            echo json_encode(['success' => false, 'message' => 'Grade level and section are required']);
            return;
        }
        if (empty($track) || !in_array($track, ['academic', 'techpro'], true)) {
            echo json_encode(['success' => false, 'message' => 'Track is required (academic or techpro)']);
            return;
        }

        $stmt = $db->prepare("SELECT role, lrn FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['role'] !== 'student') {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            return;
        }

        if (empty($lrn)) {
            echo json_encode(['success' => false, 'message' => 'LRN is required']);
            return;
        }
        if (!validateLrn($lrn)) {
            echo json_encode(['success' => false, 'message' => 'LRN must be exactly 12 digits']);
            return;
        }
        if (checkLrnExists($db, $lrn, $userId)) {
            echo json_encode(['success' => false, 'message' => 'LRN already exists for another student']);
            return;
        }

        $db->beginTransaction();

        $curriculum = strengthenedShsCurriculum((int)$gradeLevel, $academicYear);
        $program = strengthenedShsProgram((int)$gradeLevel, $track, $academicYear);

        $stmt = $db->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, sex = ?, lrn = ?, grade_level = ?, section = ?, track = ?, curriculum = ?, program = ? WHERE id = ?");
        $stmt->execute([$firstName, $middleName ?: null, $lastName, $sex, $lrn, (int)$gradeLevel, $section, $track, $curriculum, $program, $userId]);

        syncStudentEnrollments($db, $userId, (int)$gradeLevel, $section, $track, $academicYear);

        $db->commit();

        recordAdminAuditLog($db, 'student.update', 'student', $userId, [
            'lrn' => $lrn,
            'grade_level' => (int)$gradeLevel,
            'section' => $section,
            'track' => $track,
            'academic_year' => $academicYear,
        ]);
        echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Admin_Enrollments_Action updateStudent error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteStudent(PDO $db): void {
    $userId = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
        return;
    }

    $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    if ($role !== 'student') {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        return;
    }

    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
    $stmt->execute([$userId]);

    recordAdminAuditLog($db, 'student.archive', 'student', $userId);
    echo json_encode(['success' => true, 'message' => 'Student archived successfully']);
}

function toggleStudentStatus(PDO $db): void {
    $userId = (int)($_POST['user_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));

    if ($userId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        return;
    }

    $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    if ($role !== 'student') {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        return;
    }

    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$status, $userId]);

    $label = $status === 'active' ? 'activated' : 'archived';
    recordAdminAuditLog($db, 'student.set_status', 'student', $userId, [
        'status' => $status,
    ]);
    echo json_encode(['success' => true, 'message' => "Student $label successfully"]);
}

function exportSf1(PDO $db): void {
    $gradeLevel = (int)($_GET['grade_level'] ?? 0);
    $section = trim((string)($_GET['section'] ?? ''));
    $track = trim((string)($_GET['track'] ?? '')) ?: null;
    $academicYear = trim((string)($_GET['academic_year'] ?? currentAcademicYear()));
    $format = trim((string)($_GET['format'] ?? 'xlsx'));
    $semester = trim((string)($_GET['semester'] ?? '')) ?: null;
    if (!in_array($format, ['csv', 'xlsx'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'SF1 export supports CSV and XLSX only.']);
        exit();
    }

    if ($gradeLevel < 11 || $gradeLevel > 12) {
        echo json_encode(['success' => false, 'message' => 'Invalid grade level.']);
        return;
    }
    if ($section === '') {
        echo json_encode(['success' => false, 'message' => 'Section is required.']);
        return;
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../functions/simple-xlsx-writer.php';

    $tempPath = null;
    try {
        $exporter = new Sf1Exporter($db);

        $safeSection = preg_replace('/[^a-zA-Z0-9_-]/', '', $section);
        $filename = "SF1_G{$gradeLevel}_{$safeSection}_{$academicYear}";

        if ($format === 'csv') {
            $spreadsheet = $exporter->exportXlsx($gradeLevel, $section, $track, $academicYear, $semester);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            $out = fopen('php://output', 'w');
            foreach ($spreadsheet->getActiveSheet()->toArray('', true, true, false) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        } else {
            $tempPath = tempnam(sys_get_temp_dir(), 'sf1-export-');
            if ($tempPath === false) {
                throw new RuntimeException('Unable to prepare SF1 XLSX download.');
            }
            $exporter->exportOfficialTemplateXlsx($tempPath, $gradeLevel, $section, $track, $academicYear, $semester);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            header('Cache-Control: max-age=0');
            header('Content-Length: ' . filesize($tempPath));

            readfile($tempPath);
            @unlink($tempPath);
            $tempPath = null;
        }
    } catch (Throwable $e) {
        if (is_string($tempPath) && $tempPath !== '') {
            @unlink($tempPath);
        }
        error_log('SF1 export failed: ' . $e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
    }
    exit();
}
