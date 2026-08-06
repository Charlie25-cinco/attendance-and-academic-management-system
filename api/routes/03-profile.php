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
                if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    apiJson(['ok' => false, 'message' => 'Invalid email address'], 422);
                }
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

    $currentPassword = (string)($body['current_password'] ?? '');
    $newPassword = (string)($body['new_password'] ?? '');
    $confirmPassword = (string)($body['confirm_password'] ?? '');
    $changingPassword = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';

    if ($changingPassword) {
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            apiJson(['ok' => false, 'message' => 'Current password, new password, and confirmation are required'], 422);
        }
        if ($newPassword !== $confirmPassword) {
            apiJson(['ok' => false, 'message' => 'New passwords do not match'], 422);
        }
        if (!validateStrongPassword($newPassword, $passwordError)) {
            apiJson(['ok' => false, 'message' => $passwordError], 422);
        }
        $passStmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $passStmt->execute([(int)$user['id']]);
        $currentHash = (string)($passStmt->fetchColumn() ?: '');
        if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
            apiJson(['ok' => false, 'message' => 'Current password is incorrect'], 403);
        }
        $updates['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    $passwordChanged = array_key_exists('password', $updates);

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

    if ($passwordChanged && apiHasTable($db, 'auth_remember_tokens')) {
        $revokeStmt = $db->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $revokeStmt->execute([(int)$user['id']]);
    }

    foreach (['first_name', 'middle_name', 'last_name', 'email', 'sex'] as $sessionField) {
        if (array_key_exists($sessionField, $updates)) {
            $_SESSION[$sessionField] = $updates[$sessionField];
        }
    }
    unset($_SESSION['app_header_profile']);
    unset($updates['password']);

    $message = 'Profile updated successfully';
    if ($passwordChanged && empty($updates)) {
        $message = 'Password updated successfully';
    } elseif ($passwordChanged) {
        $message = 'Profile and password updated successfully';
    }

    apiJson(['ok' => true, 'message' => $message, 'updates' => $updates]);
}
