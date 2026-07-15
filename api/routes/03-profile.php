<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'profile' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $hasMiddleName = apiHasColumn($db, 'users', 'middle_name');
    $hasSex = apiHasColumn($db, 'users', 'sex');

    $columns = ['first_name', 'last_name', 'email', 'reference_code', 'role'];
    if ($hasMiddleName) {
        $columns[] = 'middle_name';
    }
    if ($hasSex) {
        $columns[] = 'sex';
    }

    $stmt = $db->prepare("SELECT " . implode(', ', $columns) . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiJson(['ok' => false, 'message' => 'User not found'], 404);
    }

    if ($hasMiddleName && !isset($row['middle_name'])) {
        $row['middle_name'] = '';
    }
    if ($hasSex && !isset($row['sex'])) {
        $row['sex'] = '';
    }

    apiJson(['ok' => true, 'profile' => $row]);
}

if ($route === 'profile' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $body = apiRequestBody();
    $updates = [];
    $allowedFields = ['first_name', 'last_name', 'middle_name', 'sex', 'email'];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $body)) {
            $value = trim((string)$body[$field]);
            if ($field === 'first_name' || $field === 'last_name') {
                if (!apiValidPersonName($value)) {
                    apiJson(['ok' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' contains invalid characters'], 422);
                }
                if (!apiHasMinimumLetters($value, 2)) {
                    apiJson(['ok' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' must have at least 2 letters'], 422);
                }
            }
            if ($field === 'middle_name' && $value !== '') {
                if (!apiValidMiddleName($value)) {
                    apiJson(['ok' => false, 'message' => 'Middle name is invalid'], 422);
                }
            }
            if ($field === 'email') {
                $existing = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
                $existing->execute([$value, (int)$user['id']]);
                if ($existing->fetchColumn()) {
                    apiJson(['ok' => false, 'message' => 'Email is already in use'], 409);
                }
            }
            $updates[$field] = $value;
        }
    }

    if (apiHasColumn($db, 'users', 'middle_name')) {
        $allowedFields[] = 'middle_name';
    }
    if (apiHasColumn($db, 'users', 'sex')) {
        $allowedFields[] = 'sex';
    }

    if (empty($updates)) {
        apiJson(['ok' => false, 'message' => 'No valid fields to update'], 422);
    }

    $setClauses = [];
    $params = [];
    foreach ($updates as $field => $value) {
        $setClauses[] = "$field = ?";
        $params[] = $value;
    }
    $params[] = (int)$user['id'];
    $setStr = implode(', ', $setClauses);
    $updateStmt = $db->prepare("UPDATE users SET $setStr, updated_at = NOW() WHERE id = ?");
    $executed = $updateStmt->execute($params);

    if (!$executed) {
        apiJson(['ok' => false, 'message' => 'Failed to update profile'], 500);
    }

    apiJson(['ok' => true, 'message' => 'Profile updated successfully', 'updates' => $updates]);
}
