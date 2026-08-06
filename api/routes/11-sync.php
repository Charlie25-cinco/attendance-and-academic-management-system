<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

function syncValidAttendanceRecorder(PDO $db, int $recordedBy, int $classId): bool {
    if ($recordedBy <= 0 || $classId <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT id, role FROM users WHERE id = ? AND status = 'active' AND role IN ('admin', 'teacher') LIMIT 1");
    $stmt->execute([$recordedBy]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }
    if ((string)$user['role'] === 'admin') {
        return true;
    }

    return apiTeacherOwnsClass($db, $recordedBy, $classId);
}

if ($route === 'sync-attendance' && $method === 'POST') {
    apiRequireSyncKey();
    $db = apiDb();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $body = apiRequestBody();
    $classId = (int)($body['class_id'] ?? 0);
    $date = trim((string)($body['date'] ?? ''));
    $records = $body['records'] ?? [];
    $recordedBy = (int)($body['recorded_by'] ?? 0);
    if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_array($records)) {
        apiJson(['ok' => false, 'message' => 'Class, date, and records are required'], 422);
    }

    $classCheck = $db->prepare("SELECT 1 FROM classes WHERE id = ? LIMIT 1");
    $classCheck->execute([$classId]);
    if (!$classCheck->fetchColumn()) {
        apiJson(['ok' => false, 'message' => 'Class not found'], 404);
    }
    if (!syncValidAttendanceRecorder($db, $recordedBy, $classId)) {
        apiJson(['ok' => false, 'message' => 'recorded_by must be an active admin or assigned teacher'], 403);
    }

    $students = apiActiveClassStudents($db, $classId);
    $studentIds = array_map('intval', array_column($students, 'id'));

    $academicYear = apiAcademicYearForDate($date);
    $semester = apiSemesterForDate($date);
    $hasTermColumns = apiAttendanceHasTermColumns($db);

    $saved = 0;
    $savedRecords = [];
    foreach ($records as $rec) {
        $studentId = (int)($rec['student_id'] ?? 0);
        $status = strtolower(trim((string)($rec['status'] ?? '')));
        if (!in_array($studentId, $studentIds, true) || !in_array($status, ['present', 'late', 'absent'], true)) {
            continue;
        }

        $existing = $db->prepare(
            "SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? LIMIT 1"
        );
        $existing->execute([$classId, $studentId, $date]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $sql = "UPDATE attendance SET status = ?, recorded_by = ?, updated_at = NOW()" .
                ($hasTermColumns ? ", academic_year = ?, semester = ?" : "") .
                " WHERE id = ?";
            $params = [$status, $recordedBy];
            if ($hasTermColumns) {
                $params[] = $academicYear;
                $params[] = $semester;
            }
            $params[] = (int)$existingId;
            $db->prepare($sql)->execute($params);
        } else {
            $sql = "INSERT INTO attendance (class_id, student_id, date, status, recorded_by, created_at, updated_at" .
                ($hasTermColumns ? ", academic_year, semester" : "") . ")
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW()" .
                ($hasTermColumns ? ", ?, ?" : "") . ")";
            $params = [$classId, $studentId, $date, $status, $recordedBy];
            if ($hasTermColumns) {
                $params[] = $academicYear;
                $params[] = $semester;
            }
            $db->prepare($sql)->execute($params);
        }
        $saved++;
        $savedRecords[] = ['student_id' => $studentId, 'status' => $status];
    }

    appNotifyAttendanceRecords($db, $classId, $date, $savedRecords, $recordedBy);

    apiJson(['ok' => true, 'message' => "Sync complete: $saved records saved", 'saved' => $saved]);
}

if ($route === 'sync-users' && $method === 'POST') {
    apiRequireSyncKey();
    $db = apiDb();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $body = apiRequestBody();
    $users = $body['users'] ?? [];
    if (!is_array($users) || empty($users)) {
        apiJson(['ok' => false, 'message' => 'Users array is required'], 422);
    }

    $saved = 0;
    foreach ($users as $userData) {
        $referenceCode = strtoupper(trim((string)($userData['reference_code'] ?? '')));
        $firstName = trim((string)($userData['first_name'] ?? ''));
        $lastName = trim((string)($userData['last_name'] ?? ''));
        $email = strtolower(trim((string)($userData['email'] ?? '')));
        $role = strtolower(trim((string)($userData['role'] ?? '')));
        $status = strtolower(trim((string)($userData['status'] ?? 'active')));

        if ($referenceCode === '' || $firstName === '' || $lastName === '' || $role === '') {
            continue;
        }
        if (!in_array($role, ['teacher', 'student', 'parent'], true)) {
            continue;
        }
        if (!in_array($status, ['active', 'pending', 'inactive'], true)) {
            $status = 'active';
        }

        $existing = $db->prepare("SELECT id, role FROM users WHERE reference_code = ? LIMIT 1");
        $existing->execute([$referenceCode]);
        $existingUser = $existing->fetch(PDO::FETCH_ASSOC);
        $userId = (int)($existingUser['id'] ?? 0);
        $existingRole = strtolower((string)($existingUser['role'] ?? ''));

        if ($userId > 0) {
            if ($existingRole === 'admin') {
                continue;
            }
            $db->prepare(
                "UPDATE users
                 SET first_name = ?, last_name = ?, email = ?, status = ?, updated_at = NOW()
                 WHERE id = ?"
            )
                ->execute([$firstName, $lastName, $email, $status, $userId]);
        } else {
            $password = password_hash(getDefaultNewUserPassword(), PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO users
                    (reference_code, first_name, last_name, email, role, password, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$referenceCode, $firstName, $lastName, $email, $role, $password, $status]);
        }
        $saved++;
    }

    apiJson(['ok' => true, 'message' => "Sync complete: $saved users processed", 'saved' => $saved]);
}
