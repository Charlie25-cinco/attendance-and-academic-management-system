<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin Users Action Handler
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
    error_log('Admin_Users_Action fatal: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

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

    error_log('Admin_Users_Action shutdown: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

    if (ob_get_length()) {
        ob_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
});


// Check if user is logged in and is admin
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

function adminUsersRequireCsrf() {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
}

if (in_array($action, ['create', 'update', 'delete', 'reset_password', 'set_status'], true)) {
    adminUsersRequireCsrf();
}

switch ($action) {
    case 'create':
        createUser($db);
        break;
    case 'get':
        getUser($db);
        break;
    case 'update':
        updateUser($db);
        break;
    case 'delete':
        deleteUser($db);
        break;
    case 'reset_password':
        resetUserPassword($db);
        break;
    case 'set_status':
        setUserStatus($db);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function usersHasColumn($db, $columnName) {
    return dbHasColumn($db, 'users', $columnName);
}

function teacherSectionTakenByAnother($db, $gradeLevel, $section, $excludeUserId = 0) {
    $sql = "SELECT id
            FROM users
            WHERE role = 'teacher'
            AND status IN ('active', 'pending')
            AND grade_level = ?
            AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))";
    $params = [(int)$gradeLevel, (string)$section];
    if ((int)$excludeUserId > 0) {
        $sql .= " AND id <> ?";
        $params[] = (int)$excludeUserId;
    }
    $sql .= " LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function getTeacherSubjectConflicts($db, $classIds, $excludeTeacherId = 0) {
    $classIds = normalizeIdArray($classIds);
    if (empty($classIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $params = $classIds;
    $sql = "SELECT cs.class_id,
                   c.class_name,
                   c.grade_level,
                   c.section,
                   u.first_name,
                   u.last_name
            FROM class_subjects cs
            JOIN classes c ON c.id = cs.class_id
            JOIN users u ON u.id = cs.teacher_id
            WHERE cs.class_id IN ($placeholders)
              AND c.status = 'active'
              AND u.role = 'teacher'
              AND u.status IN ('active', 'pending')";

    if ((int)$excludeTeacherId > 0) {
        $sql .= " AND cs.teacher_id <> ?";
        $params[] = (int)$excludeTeacherId;
    }

    $sql .= " ORDER BY c.class_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [];
    }

    $conflicts = [];
    foreach ($rows as $row) {
        $classId = (int)($row['class_id'] ?? 0);
        if ($classId <= 0 || isset($conflicts[$classId])) {
            continue;
        }
        $subject = trim((string)($row['class_name'] ?? 'Subject'));
        $grade = trim((string)($row['grade_level'] ?? ''));
        $section = trim((string)($row['section'] ?? ''));
        $teacherName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $suffix = ($grade !== '' && $section !== '') ? " (G{$grade} - {$section})" : '';
        $owner = $teacherName !== '' ? " - {$teacherName}" : '';
        $conflicts[$classId] = $subject . $suffix . $owner;
    }
    return array_values($conflicts);
}

function createUser($db) {
    try {
        $role = trim((string)($_POST['role'] ?? ''));
        $referenceCode = strtoupper(trim((string)($_POST['reference_code'] ?? '')));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $sex = strtolower(trim((string)($_POST['sex'] ?? '')));
        $gradeLevel = trim((string)($_POST['grade_level'] ?? ''));
        $section = trim((string)($_POST['section'] ?? ''));
        $track = trim((string)($_POST['track'] ?? ''));
        $classIds = normalizeIdArray($_POST['class_ids'] ?? []);
        $linkedStudentIds = normalizeIdArray($_POST['linked_student_ids'] ?? []);

        if (empty($role) || empty($firstName) || empty($lastName)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            return;
        }
        if (!isValidPersonName($firstName, false) || !isValidPersonName($middleName, true) || !isValidPersonName($lastName, false)) {
            echo json_encode(['success' => false, 'message' => 'Names must contain letters only (no numbers).']);
            return;
        }
        if (!hasMinimumLetters($firstName, 2) || !hasMinimumLetters($lastName, 2)) {
            echo json_encode(['success' => false, 'message' => 'First name and last name must have at least 2 letters.']);
            return;
        }
        if (!isValidMiddleName($middleName)) {
            echo json_encode(['success' => false, 'message' => 'Middle name must have at least 2 letters.']);
            return;
        }
        if (!in_array($sex, ['male', 'female'], true)) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid sex']);
            return;
        }
        if ($role === 'student') {
            echo json_encode(['success' => false, 'message' => 'Students must be managed through the Enrollments page']);
            return;
        }

        if ($role === 'teacher' && (($gradeLevel === '' && $section !== '') || ($gradeLevel !== '' && $section === ''))) {
            echo json_encode(['success' => false, 'message' => 'For teacher advisory assignment, select both grade level and section']);
            return;
        }
        if ($role === 'teacher' && $gradeLevel !== '' && $section !== '') {
            if (teacherSectionTakenByAnother($db, $gradeLevel, $section, 0)) {
                echo json_encode(['success' => false, 'message' => 'A teacher is already assigned to this grade level and section']);
                return;
            }
        }

        if ($referenceCode === '') {
            $referenceCode = generateReferenceCode($role, $db, currentAcademicYear());
        }

        if ($role === 'parent' && count($linkedStudentIds) < 1) {
            echo json_encode(['success' => false, 'message' => 'Parent must be linked to at least 1 student']);
            return;
        }
        if ($role === 'parent' && !empty($linkedStudentIds)) {
            if (!areValidStudents($db, $linkedStudentIds)) {
                echo json_encode(['success' => false, 'message' => 'One or more selected students are invalid']);
                return;
            }
            $conflicts = getStudentParentConflicts($db, $linkedStudentIds, 0);
            if (!empty($conflicts)) {
                echo json_encode(['success' => false, 'message' => 'These students are already linked to another parent: ' . implode(', ', $conflicts)]);
                return;
            }
        }

        $checkStmt = $db->prepare("SELECT id FROM users WHERE reference_code = ?");
        $checkStmt->execute([$referenceCode]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Reference code already exists']);
            return;
        }

        if ($role === 'teacher' && !empty($classIds)) {
            $subjectConflicts = getTeacherSubjectConflicts($db, $classIds, 0);
            if (!empty($subjectConflicts)) {
                echo json_encode(['success' => false, 'message' => 'These subjects are already assigned to another teacher: ' . implode(', ', $subjectConflicts)]);
                return;
            }

            $conflict = checkScheduleConflictsForNewTeacher($db, 0, $classIds);
            if ($conflict) {
                echo json_encode(['success' => false, 'message' => $conflict]);
                return;
            }
        }

        $hashedPassword = password_hash(getDefaultNewUserPassword(), PASSWORD_DEFAULT);
        $email = $referenceCode . '@balingasag.edu.ph';

        $db->beginTransaction();

        $hasUserGradeSection = usersHasColumn($db, 'grade_level') && usersHasColumn($db, 'section');
        $hasUserSex = usersHasColumn($db, 'sex');
        $hasUserTrack = usersHasColumn($db, 'track');
        $userGradeLevel = null;
        $userSection = null;
        $userTrack = null;
        if ($role === 'teacher') {
            $userGradeLevel = ($gradeLevel !== '') ? (int)$gradeLevel : null;
            $userSection = ($section !== '') ? $section : null;
            $userTrack = ($hasUserTrack && $track !== '') ? $track : null;
        }

        if ($hasUserGradeSection && $hasUserSex && $hasUserTrack) {
            $stmt = $db->prepare("INSERT INTO users (reference_code, first_name, middle_name, last_name, sex, email, password, role, status, grade_level, section, track, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW())");
            $stmt->execute([$referenceCode, $firstName, ($middleName !== '' ? $middleName : null), $lastName, $sex, $email, $hashedPassword, $role, $userGradeLevel, $userSection, $userTrack]);
        } elseif ($hasUserGradeSection && $hasUserSex) {
            $stmt = $db->prepare("INSERT INTO users (reference_code, first_name, middle_name, last_name, sex, email, password, role, status, grade_level, section, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW())");
            $stmt->execute([$referenceCode, $firstName, ($middleName !== '' ? $middleName : null), $lastName, $sex, $email, $hashedPassword, $role, $userGradeLevel, $userSection]);
        } elseif ($hasUserSex) {
            $stmt = $db->prepare("INSERT INTO users (reference_code, first_name, middle_name, last_name, sex, email, password, role, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->execute([$referenceCode, $firstName, ($middleName !== '' ? $middleName : null), $lastName, $sex, $email, $hashedPassword, $role]);
        } else {
            $stmt = $db->prepare("INSERT INTO users (reference_code, first_name, middle_name, last_name, email, password, role, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->execute([$referenceCode, $firstName, ($middleName !== '' ? $middleName : null), $lastName, $email, $hashedPassword, $role]);
        }

        $userId = $db->lastInsertId();

        if ($role === 'teacher' && !empty($classIds)) {
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $deleteStmt = $db->prepare("DELETE FROM class_subjects WHERE class_id IN ($placeholders)");
            $deleteStmt->execute($classIds);

            $subjectStmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, teacher_id, created_at) VALUES (?, NULL, ?, NOW())");
            foreach ($classIds as $classId) {
                $subjectStmt->execute([$classId, $userId]);
            }
        }
        if ($role === 'teacher' && $gradeLevel !== '' && $section !== '') {
            $adviserAssigned = assignTeacherAdvisoryClass($db, (int)$userId, (int)$gradeLevel, $section);
            if (!$adviserAssigned['ok']) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                echo json_encode(['success' => false, 'message' => $adviserAssigned['message']]);
                return;
            }
        }

        if ($role === 'parent' && !empty($linkedStudentIds)) {
            $linkStmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)");
            foreach ($linkedStudentIds as $studentId) {
                $linkStmt->execute([$userId, $studentId, 'Parent']);
            }
        }

        $db->commit();

        recordAdminAuditLog($db, 'user.create', 'user', (int)$userId, [
            'role' => $role,
            'reference_code' => $referenceCode,
            'name' => trim($firstName . ' ' . $lastName),
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'User created successfully. User should change their default password in Profile after login.',
            'user_id' => $userId
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Admin_Users_Action createUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteUser($db) {
    try {
        $userId = $_POST['id'] ?? ($_GET['id'] ?? '');
        $userId = (int)$userId;
        
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            return;
        }
        
        $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$userId]);
        $role = $roleStmt->fetchColumn();
        if ($role === 'student') {
            echo json_encode(['success' => false, 'message' => 'Students must be managed through the Enrollments page']);
            return;
        }
        
        $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$userId]);
        
        if ($stmt->rowCount() > 0) {
            recordAdminAuditLog($db, 'user.archive', 'user', $userId, [
                'role' => (string)$role,
            ]);
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            // If user exists but already inactive, treat as successful idempotent archive.
            $checkStmt = $db->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
            $checkStmt->execute([$userId]);
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['status']) && strtolower((string)$row['status']) === 'inactive') {
                echo json_encode(['success' => true, 'message' => 'User is already archived']);
                return;
            }
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
        
    } catch (PDOException $e) {
        error_log("Admin_Users_Action deleteUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function resetUserPassword($db) {
    try {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            return;
        }

        $existsStmt = $db->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $existsStmt->execute([$userId]);
        $existingUser = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingUser) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        if ($existingUser['role'] === 'student') {
            echo json_encode(['success' => false, 'message' => 'Students must be managed through the Enrollments page']);
            return;
        }
        $hashedPassword = password_hash(getDefaultNewUserPassword(), PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);

        // Revoke active remember-me tokens for security after admin reset.
        $hasRememberTable = dbHasTable($db, 'auth_remember_tokens');
        if ($hasRememberTable) {
            $revokeStmt = $db->prepare("UPDATE auth_remember_tokens
                                        SET revoked_at = NOW()
                                        WHERE user_id = ? AND revoked_at IS NULL");
            $revokeStmt->execute([$userId]);
        }

        recordAdminAuditLog($db, 'user.reset_password', 'user', $userId, [
            'role' => (string)$existingUser['role'],
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'Password has been reset to the default. User should change it on next login.'
        ]);
    } catch (PDOException $e) {
        error_log("Admin_Users_Action resetUserPassword error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function getUser($db) {
    try {
        $userId = $_GET['id'] ?? '';
        
        if (empty($userId)) {
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            return;
        }
        
        $hasUserGrade = usersHasColumn($db, 'grade_level');
        $hasUserSection = usersHasColumn($db, 'section');
        $hasUserSex = usersHasColumn($db, 'sex');
        $hasUserTrack = usersHasColumn($db, 'track');
        $extraCols = ($hasUserSex ? ", sex" : "") . ($hasUserGrade ? ", grade_level" : "") . ($hasUserSection ? ", section" : "") . ($hasUserTrack ? ", track" : "");
        $stmt = $db->prepare("SELECT id, reference_code, first_name, middle_name, last_name, email, role, status, created_at, last_login{$extraCols} FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            if ($user['role'] === 'student') {
                echo json_encode(['success' => false, 'message' => 'Student details are not available in Manage Users']);
                return;
            }

            $assignedClassIds = [];
            $assignedClasses = '';
            
            if ($user['role'] === 'teacher') {
                $classStmt = $db->prepare("SELECT class_id FROM class_subjects WHERE teacher_id = ?");
                $classStmt->execute([$userId]);
                $assignedClassIds = $classStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $nameStmt = $db->prepare("SELECT GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ') 
                                         FROM class_subjects cs 
                                         JOIN classes c ON cs.class_id = c.id 
                                         WHERE cs.teacher_id = ? AND c.status = 'active'");
                $nameStmt->execute([$userId]);
                $assignedClasses = $nameStmt->fetchColumn();
            } else if ($user['role'] === 'parent') {
                $nameStmt = $db->prepare("SELECT GROUP_CONCAT(CONCAT(s.first_name, ' ', s.last_name) SEPARATOR ', ')
                                         FROM parent_students ps
                                         JOIN users s ON s.id = ps.student_id
                                         WHERE ps.parent_id = ?");
                $nameStmt->execute([$userId]);
                $assignedClasses = $nameStmt->fetchColumn();

                $linkedStmt = $db->prepare("SELECT student_id FROM parent_students WHERE parent_id = ?");
                $linkedStmt->execute([$userId]);
                $user['linked_student_ids'] = array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN));
            }
            
            $user['assigned_class_ids'] = $assignedClassIds;
            $user['assigned_classes'] = $assignedClasses;
            
            if ($user['role'] === 'teacher') {
                $hasStoredGrade = isset($user['grade_level']) && $user['grade_level'] !== null && $user['grade_level'] !== '';
                $hasStoredSection = isset($user['section']) && trim((string)$user['section']) !== '';
                if (!$hasStoredGrade || !$hasStoredSection) {
                    $classInfoStmt = $db->prepare("SELECT grade_level, section
                                                   FROM classes
                                                   WHERE teacher_id = ? AND status = 'active'
                                                   ORDER BY id ASC
                                                   LIMIT 1");
                    $classInfoStmt->execute([$userId]);
                    $classInfo = $classInfoStmt->fetch(PDO::FETCH_ASSOC);
                    if ($classInfo) {
                        $user['grade_level'] = $classInfo['grade_level'];
                        $user['section'] = $classInfo['section'];
                    }
                }
            }

            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
        
    } catch (PDOException $e) {
        error_log("Admin_Users_Action getUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function updateUser($db) {
    try {
        $userId = (int)($_POST['user_id'] ?? 0);
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $sex = strtolower(trim((string)($_POST['sex'] ?? '')));
        $gradeLevel = trim((string)($_POST['grade_level'] ?? ''));
        $section = trim((string)($_POST['section'] ?? ''));
        $track = trim((string)($_POST['track'] ?? ''));
        $classIds = normalizeIdArray($_POST['class_ids'] ?? []);
        $linkedStudentIds = normalizeIdArray($_POST['linked_student_ids'] ?? []);
        
        if ($userId <= 0 || $firstName === '' || $lastName === '') {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            return;
        }
        if (!isValidPersonName($firstName, false) || !isValidPersonName($middleName, true) || !isValidPersonName($lastName, false)) {
            echo json_encode(['success' => false, 'message' => 'Names must contain letters only (no numbers).']);
            return;
        }
        if (!hasMinimumLetters($firstName, 2) || !hasMinimumLetters($lastName, 2)) {
            echo json_encode(['success' => false, 'message' => 'First name and last name must have at least 2 letters.']);
            return;
        }
        if (!isValidMiddleName($middleName)) {
            echo json_encode(['success' => false, 'message' => 'Middle name must have at least 2 letters.']);
            return;
        }
        if (!in_array($sex, ['male', 'female'], true)) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid sex']);
            return;
        }
        
        $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$userId]);
        $userRole = $roleStmt->fetchColumn();
        if (!$userRole) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        if ($userRole === 'student') {
            echo json_encode(['success' => false, 'message' => 'Students must be managed through the Enrollments page']);
            return;
        }
        
        if ($userRole === 'teacher' && !empty($classIds)) {
            $subjectConflicts = getTeacherSubjectConflicts($db, $classIds, $userId);
            if (!empty($subjectConflicts)) {
                echo json_encode(['success' => false, 'message' => 'These subjects are already assigned to another teacher: ' . implode(', ', $subjectConflicts)]);
                return;
            }

            $conflict = checkScheduleConflicts($db, $userId, $classIds);
            if ($conflict) {
                echo json_encode(['success' => false, 'message' => $conflict]);
                return;
            }
        }

        if ($userRole === 'teacher' && (($gradeLevel === '' && $section !== '') || ($gradeLevel !== '' && $section === ''))) {
            echo json_encode(['success' => false, 'message' => 'For teacher advisory assignment, select both grade level and section']);
            return;
        }
        if ($userRole === 'teacher' && $gradeLevel !== '' && $section !== '') {
            if (teacherSectionTakenByAnother($db, $gradeLevel, $section, $userId)) {
                echo json_encode(['success' => false, 'message' => 'A teacher is already assigned to this grade level and section']);
                return;
            }
        }
        if ($userRole === 'parent' && count($linkedStudentIds) < 1) {
            echo json_encode(['success' => false, 'message' => 'Parent must be linked to at least 1 student']);
            return;
        }
        if ($userRole === 'parent' && !empty($linkedStudentIds)) {
            if (!areValidStudents($db, $linkedStudentIds)) {
                echo json_encode(['success' => false, 'message' => 'One or more selected students are invalid']);
                return;
            }
            $conflicts = getStudentParentConflicts($db, $linkedStudentIds, $userId);
            if (!empty($conflicts)) {
                echo json_encode(['success' => false, 'message' => 'These students are already linked to another parent: ' . implode(', ', $conflicts)]);
                return;
            }
        }
        
        $db->beginTransaction();

        $hasUserGradeSection = usersHasColumn($db, 'grade_level') && usersHasColumn($db, 'section');
        $hasUserSex = usersHasColumn($db, 'sex');
        $hasUserTrack = usersHasColumn($db, 'track');

        if ($hasUserGradeSection && $hasUserSex && $hasUserTrack) {
            $userGradeLevel = null;
            $userSection = null;
            $userTrack = null;
            if ($userRole === 'teacher') {
                $userGradeLevel = ($gradeLevel !== '') ? (int)$gradeLevel : null;
                $userSection = ($section !== '') ? $section : null;
                $userTrack = ($track !== '') ? $track : null;
            }
            $stmt = $db->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, sex = ?, grade_level = ?, section = ?, track = ? WHERE id = ?");
            $stmt->execute([$firstName, ($middleName !== '' ? $middleName : null), $lastName, $sex, $userGradeLevel, $userSection, $userTrack, $userId]);
        } elseif ($hasUserGradeSection && $hasUserSex) {
            $userGradeLevel = null;
            $userSection = null;
            if ($userRole === 'teacher') {
                $userGradeLevel = ($gradeLevel !== '') ? (int)$gradeLevel : null;
                $userSection = ($section !== '') ? $section : null;
            }
            $stmt = $db->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, sex = ?, grade_level = ?, section = ? WHERE id = ?");
            $stmt->execute([$firstName, ($middleName !== '' ? $middleName : null), $lastName, $sex, $userGradeLevel, $userSection, $userId]);
        } elseif ($hasUserSex) {
            $stmt = $db->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, sex = ? WHERE id = ?");
            $stmt->execute([$firstName, ($middleName !== '' ? $middleName : null), $lastName, $sex, $userId]);
        } else {
            $stmt = $db->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ?");
            $stmt->execute([$firstName, ($middleName !== '' ? $middleName : null), $lastName, $userId]);
        }
        
        if ($userRole === 'teacher') {
            $deleteStmt = $db->prepare("DELETE FROM class_subjects WHERE teacher_id = ?");
            $deleteStmt->execute([$userId]);

            if (!empty($classIds)) {
                $placeholders = implode(',', array_fill(0, count($classIds), '?'));
                $transferStmt = $db->prepare("DELETE FROM class_subjects WHERE class_id IN ($placeholders)");
                $transferStmt->execute($classIds);

                $insertStmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, teacher_id, created_at) VALUES (?, NULL, ?, NOW())");
                foreach ($classIds as $classId) {
                    $insertStmt->execute([$classId, $userId]);
                }
            }
        }

        if ($userRole === 'teacher' && $gradeLevel !== '' && $section !== '') {
            $adviserAssigned = assignTeacherAdvisoryClass($db, $userId, (int)$gradeLevel, $section);
            if (!$adviserAssigned['ok']) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                echo json_encode(['success' => false, 'message' => $adviserAssigned['message']]);
                return;
            }
        }
        if ($userRole === 'parent') {
            $deleteLinkStmt = $db->prepare("DELETE FROM parent_students WHERE parent_id = ?");
            $deleteLinkStmt->execute([$userId]);

            if (!empty($linkedStudentIds)) {
                $insertLinkStmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)");
                foreach ($linkedStudentIds as $studentId) {
                    $insertLinkStmt->execute([$userId, $studentId, 'Parent']);
                }
            }
        }

        $db->commit();
        
        recordAdminAuditLog($db, 'user.update', 'user', $userId, [
            'role' => $userRole,
            'name' => trim($firstName . ' ' . $lastName),
        ]);
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Admin_Users_Action updateUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function assignTeacherAdvisoryClass($db, $teacherId, $gradeLevel, $section) {
    $classStmt = $db->prepare("SELECT id, teacher_id
                               FROM classes
                               WHERE grade_level = ? AND section = ? AND status = 'active'
                               ORDER BY id ASC
                               LIMIT 1");
    $classStmt->execute([(int)$gradeLevel, $section]);
    $targetClass = $classStmt->fetch(PDO::FETCH_ASSOC);

    // Advisory assignment is optional. If no matching active class exists yet,
    // do not block teacher creation/update.
    if (!$targetClass) {
        return ['ok' => true, 'skipped' => true];
    }

    $targetTeacherId = isset($targetClass['teacher_id']) ? (int)$targetClass['teacher_id'] : 0;
    if ($targetTeacherId > 0 && $targetTeacherId !== (int)$teacherId) {
        return ['ok' => false, 'message' => 'Selected grade/section already has an assigned adviser'];
    }

    $clearStmt = $db->prepare("UPDATE classes SET teacher_id = NULL WHERE teacher_id = ?");
    $clearStmt->execute([(int)$teacherId]);

    $setStmt = $db->prepare("UPDATE classes SET teacher_id = ? WHERE id = ?");
    $setStmt->execute([(int)$teacherId, (int)$targetClass['id']]);

    return ['ok' => true];
}

function areValidStudents($db, $studentIds) {
    if (empty($studentIds)) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id IN ($placeholders) AND role = 'student' AND status IN ('active', 'pending')");
    $stmt->execute($studentIds);
    return (int)$stmt->fetchColumn() === count($studentIds);
}

function getStudentParentConflicts($db, $studentIds, $excludeParentId = 0) {
    if (empty($studentIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $params = $studentIds;
    $sql = "SELECT CONCAT(u.first_name, ' ', u.last_name) AS student_name
            FROM parent_students ps
            JOIN users u ON u.id = ps.student_id
            JOIN users p ON p.id = ps.parent_id
            WHERE ps.student_id IN ($placeholders) AND p.status <> 'inactive'";
    if ($excludeParentId > 0) {
        $sql .= " AND ps.parent_id <> ?";
        $params[] = $excludeParentId;
    }
    $sql .= " GROUP BY ps.student_id, u.first_name, u.last_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
}


function checkScheduleConflicts($db, $teacherId, $newClassIds) {
    // Get schedules for new classes
    $placeholders = implode(',', array_fill(0, count($newClassIds), '?'));
    $stmt = $db->prepare("SELECT id, class_name, schedule FROM classes WHERE id IN ($placeholders) AND status = 'active'");
    $stmt->execute($newClassIds);
    $newClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse new class schedules
    $newSchedules = [];
    foreach ($newClasses as $class) {
        $segments = parseSchedule($class['schedule']);
        if (!empty($segments)) {
            $newSchedules[$class['id']] = ['class_name' => $class['class_name'], 'segments' => $segments];
        }
    }
    
    // Check conflicts among new classes
    foreach ($newSchedules as $id1 => $sched1) {
        foreach ($newSchedules as $id2 => $sched2) {
            if ($id1 >= $id2) continue;
            if (hasTimeConflict($sched1, $sched2)) {
                return "Schedule conflict: {$sched1['class_name']} overlaps with {$sched2['class_name']}";
            }
        }
    }
    
    // Get teacher's existing classes (excluding the ones being updated)
    $existingStmt = $db->prepare("SELECT c.id, c.class_name, c.schedule FROM class_subjects cs 
                                  JOIN classes c ON cs.class_id = c.id 
                                  WHERE cs.teacher_id = ? AND c.status = 'active'");
    $existingStmt->execute([$teacherId]);
    $existingClasses = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check conflicts with existing classes
    foreach ($existingClasses as $existing) {
        // Skip if this class is being re-assigned (in the new list)
        if (in_array($existing['id'], $newClassIds)) continue;
        
        $existingSchedule = parseSchedule($existing['schedule']);
        if (empty($existingSchedule)) continue;
        
        foreach ($newSchedules as $newId => $newSchedule) {
            if (hasTimeConflict(['segments' => $existingSchedule], $newSchedule)) {
                return "Schedule conflict: {$existing['class_name']} (existing) overlaps with {$newSchedule['class_name']} (new)";
            }
        }
    }
    
    return null;
}

function checkScheduleConflictsForNewTeacher($db, $teacherId, $newClassIds) {
    // For new teachers, just check conflicts among the new classes being assigned
    $placeholders = implode(',', array_fill(0, count($newClassIds), '?'));
    $stmt = $db->prepare("SELECT id, class_name, schedule FROM classes WHERE id IN ($placeholders) AND status = 'active'");
    $stmt->execute($newClassIds);
    $newClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse new class schedules
    $newSchedules = [];
    foreach ($newClasses as $class) {
        $segments = parseSchedule($class['schedule']);
        if (!empty($segments)) {
            $newSchedules[$class['id']] = ['class_name' => $class['class_name'], 'segments' => $segments];
        }
    }
    
    // Check conflicts among new classes
    foreach ($newSchedules as $id1 => $sched1) {
        foreach ($newSchedules as $id2 => $sched2) {
            if ($id1 >= $id2) continue;
            if (hasTimeConflict($sched1, $sched2)) {
                return "Schedule conflict: {$sched1['class_name']} overlaps with {$sched2['class_name']}";
            }
        }
    }
    
    return null;
}

function setUserStatus(PDO $db): void {
    $userId = (int)($_POST['user_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    if ($userId <= 0 || !in_array($status, ['active', 'inactive', 'pending'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        return;
    }
    $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
    $roleStmt->execute([$userId]);
    $role = $roleStmt->fetchColumn();
    if ($role === 'student') {
        echo json_encode(['success' => false, 'message' => 'Students must be managed through the Enrollments page']);
        return;
    }
    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$status, $userId]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        return;
    }
    $label = $status === 'active' ? 'activated' : ($status === 'inactive' ? 'archived' : 'set to pending');
    recordAdminAuditLog($db, 'user.set_status', 'user', $userId, [
        'role' => (string)$role,
        'status' => $status,
    ]);
    echo json_encode(['success' => true, 'message' => "User $label successfully"]);
}

