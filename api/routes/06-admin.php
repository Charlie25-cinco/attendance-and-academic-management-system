<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
function apiAdminUsersHasColumn(PDO $db, string $column): bool {
    return apiTableHasColumn($db, 'users', $column);
}

if ($route === 'admin-users' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    if (($user['role'] ?? '') !== 'admin') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $search = trim((string)($_GET['search'] ?? ''));
    $roleFilter = trim((string)($_GET['role'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? 'active'));
    $sexFilter = strtolower(trim((string)($_GET['sex'] ?? '')));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    if (!in_array($roleFilter, ['', 'admin', 'teacher', 'student', 'parent'], true)) {
        $roleFilter = '';
    }
    if (!in_array($statusFilter, ['active', 'pending', 'inactive', ''], true)) {
        $statusFilter = 'active';
    }

    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR reference_code LIKE ?)';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($roleFilter !== '') {
        $where[] = 'role = ?';
        $params[] = $roleFilter;
    }
    if ($statusFilter !== '') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($sexFilter !== '' && in_array($sexFilter, ['male', 'female'], true)) {
        $hasSex = apiAdminUsersHasColumn($db, 'sex');
        if ($hasSex) {
            $where[] = "LOWER(TRIM(COALESCE(sex, ''))) = ?";
            $params[] = $sexFilter;
        }
    }

    $whereClause = implode(' AND ', $where);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT id, reference_code, first_name, last_name, email, role, status, created_at
                          FROM users WHERE $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apiJson([
        'ok' => true,
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ]);
}

if ($route === 'admin-users' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    if (($user['role'] ?? '') !== 'admin') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $body = apiRequestBody();
    $firstName = trim((string)($body['first_name'] ?? ''));
    $lastName = trim((string)($body['last_name'] ?? ''));
    $role = strtolower(trim((string)($body['role'] ?? '')));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $gradeLevel = (int)($body['grade_level'] ?? 0);
    $section = trim((string)($body['section'] ?? ''));
    $sex = strtolower(trim((string)($body['sex'] ?? '')));

    if ($firstName === '' || $lastName === '' || $role === '' || $email === '') {
        apiJson(['ok' => false, 'message' => 'First name, last name, role, and email are required'], 422);
    }
    if (!in_array($role, ['admin', 'teacher', 'student', 'parent'], true)) {
        apiJson(['ok' => false, 'message' => 'Invalid role'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiJson(['ok' => false, 'message' => 'Invalid email address'], 422);
    }
    if (!apiValidPersonName($firstName) || !apiHasMinimumLetters($firstName)) {
        apiJson(['ok' => false, 'message' => 'First name is invalid'], 422);
    }
    if (!apiValidPersonName($lastName) || !apiHasMinimumLetters($lastName)) {
        apiJson(['ok' => false, 'message' => 'Last name is invalid'], 422);
    }
    if ($sex !== '' && !in_array($sex, ['male', 'female'], true)) {
        apiJson(['ok' => false, 'message' => 'Invalid sex'], 422);
    }
    if ($role === 'student' && ($gradeLevel < 11 || $gradeLevel > 12)) {
        apiJson(['ok' => false, 'message' => 'Grade level must be 11 or 12 for students'], 422);
    }
    if ($role === 'teacher' && $gradeLevel > 0 && $section !== '') {
        if (\BshsAms\User\UserValidationHelper::isTeacherSectionTaken($db, $gradeLevel, $section)) {
            apiJson(['ok' => false, 'message' => 'A teacher is already assigned to this grade level and section'], 422);
        }
    }

    $existing = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $existing->execute([$email]);
    if ($existing->fetchColumn()) {
        apiJson(['ok' => false, 'message' => 'Email is already in use'], 409);
    }

    $referenceCode = generateReferenceCode($role, $db);
    $defaultPassword = getDefaultNewUserPassword();
    $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

    $middleName = apiAdminUsersHasColumn($db, 'middle_name') ? trim((string)($body['middle_name'] ?? '')) : '';

    $stmt = $db->prepare("INSERT INTO users (reference_code, first_name, last_name, email, role, password, grade_level, section, sex, status, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$referenceCode, $firstName, $lastName, $email, $role, $hashedPassword, $gradeLevel ?: null, $section ?: '', $sex ?: '', ]);

    $newId = (int)$db->lastInsertId();

    recordAdminAuditLog($db, 'create_user', 'users', $newId, ['role' => $role, 'email' => $email]);

    apiJson([
        'ok' => true,
        'message' => 'User created successfully',
        'user' => [
            'id' => $newId,
            'reference_code' => $referenceCode,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'role' => $role,
        ],
    ], 201);
}

if ($route === 'admin-user-status' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    if (($user['role'] ?? '') !== 'admin') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $body = apiRequestBody();
    $userId = (int)($body['user_id'] ?? 0);
    $newStatus = strtolower(trim((string)($body['status'] ?? '')));

    if ($userId <= 0 || !in_array($newStatus, ['active', 'pending', 'inactive'], true)) {
        apiJson(['ok' => false, 'message' => 'Invalid user ID or status'], 422);
    }

    $stmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);

    recordAdminAuditLog($db, 'update_user_status', 'users', $userId, ['new_status' => $newStatus]);

    apiJson(['ok' => true, 'message' => 'User status updated']);
}

if ($route === 'admin-enroll' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    if (($user['role'] ?? '') !== 'admin') {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $body = apiRequestBody();
    $studentId = (int)($body['student_id'] ?? 0);
    $classId = (int)($body['class_id'] ?? 0);

    if ($studentId <= 0 || $classId <= 0) {
        apiJson(['ok' => false, 'message' => 'Student ID and class ID are required'], 422);
    }

    $existing = $db->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND class_id = ? AND COALESCE(status, 'enrolled') = 'enrolled' LIMIT 1");
    $existing->execute([$studentId, $classId]);
    if ($existing->fetchColumn()) {
        apiJson(['ok' => false, 'message' => 'Student is already enrolled in this class'], 409);
    }

    $academicYear = apiCurrentAcademicYear();
    $stmt = $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status, enrolled_at) VALUES (?, ?, ?, 'enrolled', NOW())");
    $stmt->execute([$studentId, $classId, $academicYear]);

    recordAdminAuditLog($db, 'enroll_student', 'enrollments', null, ['student_id' => $studentId, 'class_id' => $classId]);

    apiJson(['ok' => true, 'message' => 'Student enrolled successfully'], 201);
}
