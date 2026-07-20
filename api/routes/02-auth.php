<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'login' && $method === 'POST') {
    $db = apiDb();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $body = apiRequestBody();
    $referenceCode = strtoupper(trim((string)($body['reference_code'] ?? '')));
    $password = (string)($body['password'] ?? '');

    if ($referenceCode === '' || $password === '') {
        apiJson(['ok' => false, 'message' => 'Reference code and password are required'], 422);
    }

    apiEnsureRateLimitsTable($db);

    $identifierHash = hash('sha256', $referenceCode . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $now = time();
    $maxAttempts = 5;
    $lockSeconds = 300;

    $attempts = 0;
    $lockedUntil = 0;
    $firstAttempt = $now;

    $rateStmt = $db->prepare("SELECT attempts, lock_until, first_attempt
                                FROM rate_limits
                                WHERE context = 'api' AND action_key = 'login' AND identifier_hash = ? AND expires_at > NOW()
                                LIMIT 1");
    $rateStmt->execute([$identifierHash]);
    $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if ($rateRow) {
        $attempts = (int)$rateRow['attempts'];
        $lockedUntil = (int)strtotime($rateRow['lock_until']);
        $firstAttempt = (int)$rateRow['first_attempt'];
        if ($lockedUntil > $now) {
            $remaining = $lockedUntil - $now;
            apiJson(['ok' => false, 'message' => "Too many attempts. Try again in {$remaining} seconds."], 429);
        }
        if ($now - $firstAttempt > $lockSeconds) {
            $db->prepare("DELETE FROM rate_limits WHERE context = 'api' AND action_key = 'login' AND identifier_hash = ?")->execute([$identifierHash]);
            $attempts = 0;
        }
    }

    $db->prepare("INSERT INTO rate_limits (context, action_key, identifier_hash, attempts, first_attempt, lock_until, expires_at)
                  VALUES ('api', 'login', ?, 1, UNIX_TIMESTAMP(), NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))
                  ON DUPLICATE KEY UPDATE
                    attempts = attempts + 1,
                    first_attempt = IF(attempts = 0, UNIX_TIMESTAMP(), first_attempt),
                    lock_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                    expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    updated_at = NOW()")
        ->execute([$identifierHash, $lockSeconds, $maxAttempts, $lockSeconds]);

    $stmt = $db->prepare("SELECT id, reference_code, email, password, first_name, last_name, role, status
                          FROM users
                          WHERE reference_code = ? AND status = 'active'
                          LIMIT 1");
    $stmt->execute([$referenceCode]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        apiJson(['ok' => false, 'message' => 'Invalid reference code or password'], 401);
    }

    if (password_verify(getDefaultNewUserPassword(), $user['password'])) {
        $token = bin2hex(random_bytes(32));
        $db->prepare("DELETE FROM rate_limits WHERE context = 'api' AND action_key = 'login' AND identifier_hash = ?")
            ->execute([$identifierHash]);
        apiEnsurePasswordChangeTokensTable($db);
        $db->prepare("UPDATE auth_password_change_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([(int)$user['id']]);
        $db->prepare("INSERT INTO auth_password_change_tokens (user_id, token_hash, expires_at, request_ip, created_at)
                      VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, NOW())")
            ->execute([(int)$user['id'], hash('sha256', $token), (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
        $_SESSION['pending_password_change'] = true;
        $_SESSION['pending_user_id'] = (int)$user['id'];
        $_SESSION['pending_reference_code'] = (string)$user['reference_code'];
        apiJson([
            'ok' => false,
            'message' => 'You must change your password before proceeding.',
            'must_change_password' => true,
            'temp_token' => $token,
        ], 403);
    }

    $db->prepare("DELETE FROM rate_limits WHERE context = 'api' AND action_key = 'login' AND identifier_hash = ?")->execute([$identifierHash]);

    $token = apiIssueToken($user);
    apiJson([
        'ok' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'reference_code' => (string)$user['reference_code'],
            'name' => apiFullName($user),
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
        ],
    ]);
}

if ($route === 'authenticate' && $method === 'GET') {
    $user = apiRequireUser();
    apiJson([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'reference_code' => (string)$user['reference_code'],
            'name' => apiFullName($user),
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
            'grade_level' => $user['grade_level'] !== null ? (int)$user['grade_level'] : null,
            'section' => (string)($user['section'] ?? ''),
        ],
    ]);
}

if ($route === 'change-password' && $method === 'POST') {
    $db = apiDb();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $body = apiRequestBody();
    $referenceCode = strtoupper(trim((string)($body['reference_code'] ?? '')));
    $tempToken = trim((string)($body['temp_token'] ?? ''));
    $newPassword = (string)($body['new_password'] ?? '');
    $confirmPassword = (string)($body['confirm_password'] ?? '');

    if ($referenceCode === '' || $tempToken === '' || $newPassword === '' || $confirmPassword === '') {
        apiJson(['ok' => false, 'message' => 'Reference code, temporary token, new password, and confirm password are required'], 422);
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $tempToken)) {
        apiJson(['ok' => false, 'message' => 'Invalid temporary token format'], 422);
    }
    if ($newPassword !== $confirmPassword) {
        apiJson(['ok' => false, 'message' => 'Passwords do not match'], 422);
    }
    if (!validateStrongPassword($newPassword, $errorMsg)) {
        apiJson(['ok' => false, 'message' => $errorMsg], 422);
    }

    apiEnsurePasswordChangeTokensTable($db);

    $stmt = $db->prepare("SELECT id, reference_code, email, first_name, last_name, role FROM users WHERE reference_code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$referenceCode]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        apiJson(['ok' => false, 'message' => 'User not found'], 404);
    }

    $userId = (int)$user['id'];
    $tokenHash = hash('sha256', $tempToken);
    $tokenStmt = $db->prepare("SELECT id FROM auth_password_change_tokens
                               WHERE user_id = ?
                               AND token_hash = ?
                               AND used_at IS NULL
                               AND expires_at > NOW()
                               LIMIT 1");
    $tokenStmt->execute([$userId, $tokenHash]);
    $tokenId = (int)($tokenStmt->fetchColumn() ?: 0);
    if ($tokenId <= 0) {
        apiJson(['ok' => false, 'message' => 'Temporary token is invalid or expired'], 400);
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    try {
        $db->beginTransaction();

        $updateStmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$hashed, $userId]);

        $markToken = $db->prepare("UPDATE auth_password_change_tokens SET used_at = NOW() WHERE id = ?");
        $markToken->execute([$tokenId]);

        if (apiTableExists($db, 'auth_remember_tokens')) {
            $db->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$userId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        apiJson(['ok' => false, 'message' => 'Unable to change password. Please try again.'], 500);
    }

    $token = apiIssueToken($user);
    apiJson([
        'ok' => true,
        'message' => 'Password changed successfully. You are now logged in.',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'reference_code' => (string)$user['reference_code'],
            'name' => apiFullName($user),
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
        ],
    ]);
}

if ($route === 'forgot-password' && $method === 'POST') {
    $db = apiDb();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $body = apiRequestBody();
    $email = strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiJson(['ok' => false, 'message' => 'Valid email is required'], 422);
    }

    apiEnsureRateLimitsTable($db);
    apiEnsurePasswordResetsTable($db);

    $identifierHash = hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $maxAttempts = 3;
    $lockSeconds = 900;

    $rateStmt = $db->prepare("SELECT lock_until FROM rate_limits WHERE context = 'api' AND action_key = 'forgot-password' AND identifier_hash = ? AND expires_at > NOW() LIMIT 1");
    $rateStmt->execute([$identifierHash]);
    $lockUntil = $rateStmt->fetchColumn();
    if ($lockUntil) {
        $remaining = strtotime((string)$lockUntil) - time();
        if ($remaining > 0) {
            apiJson(['ok' => false, 'message' => "Too many requests. Try again in " . ceil($remaining / 60) . " minutes."], 429);
        }
    }

    $db->prepare("INSERT INTO rate_limits (context, action_key, identifier_hash, attempts, first_attempt, lock_until, expires_at)
                  VALUES ('api', 'forgot-password', ?, 1, UNIX_TIMESTAMP(), NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))
                  ON DUPLICATE KEY UPDATE
                    attempts = attempts + 1,
                    first_attempt = IF(attempts = 0, UNIX_TIMESTAMP(), first_attempt),
                    lock_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                    expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    updated_at = NOW()")
        ->execute([$identifierHash, $lockSeconds, $maxAttempts, $lockSeconds]);

    $userStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $userStmt->execute([$email]);
    $userId = (int)($userStmt->fetchColumn() ?: 0);

    $message = 'If the email exists, a reset code has been sent.';
    if ($userId > 0) {
        $resetCode = (string)random_int(100000, 999999);
        $tokenHash = hash('sha256', $resetCode);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $db->prepare("UPDATE auth_password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);

        $insertStmt = $db->prepare("INSERT INTO auth_password_resets (user_id, token_hash, expires_at, request_ip, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?, NOW())");
        $insertStmt->execute([$userId, $tokenHash, $ip]);

        $resetUrl = (function_exists('appPublicWebBaseUrl') ? appPublicWebBaseUrl() : '') . '/auth/reset-password.php?email=' . urlencode($email);
        $subject = 'Your Balingasag SHS password reset code';
        $html = '<p>We received a request to reset your password.</p><p>Your reset code is:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px;">' . htmlspecialchars($resetCode, ENT_QUOTES, 'UTF-8') . '</p><p>Enter this code on the password reset page: <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset password</a></p><p>This code expires in 10 minutes.</p>';

        if (!mailerSendHtml($email, $subject, $html)) {
            apiJson(['ok' => false, 'message' => 'Unable to send reset email. Please contact the administrator.'], 500);
        }
    }

    apiJson(['ok' => true, 'message' => $message]);
}

if ($route === 'reset-password' && $method === 'POST') {
    $db = apiDb();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $body = apiRequestBody();
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $otp = trim((string)($body['otp'] ?? ($body['code'] ?? '')));
    $newPassword = (string)($body['new_password'] ?? '');
    $confirmPassword = (string)($body['confirm_password'] ?? '');

    if ($email === '' || $otp === '' || $newPassword === '' || $confirmPassword === '') {
        apiJson(['ok' => false, 'message' => 'Email, reset code, new password, and confirm password are required'], 422);
    }
    if ($newPassword !== $confirmPassword) {
        apiJson(['ok' => false, 'message' => 'Passwords do not match'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiJson(['ok' => false, 'message' => 'Invalid email address'], 422);
    }
    if (!preg_match('/^\d{6}$/', $otp)) {
        apiJson(['ok' => false, 'message' => 'Invalid reset code format'], 422);
    }
    if (!validateStrongPassword($newPassword, $errorMsg)) {
        apiJson(['ok' => false, 'message' => $errorMsg], 422);
    }

    apiEnsureRateLimitsTable($db);
    apiEnsurePasswordResetsTable($db);

    $identifierHash = hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $maxAttempts = 5;
    $lockSeconds = 900;

    $rateStmt = $db->prepare("SELECT lock_until FROM rate_limits WHERE context = 'api' AND action_key = 'reset-password' AND identifier_hash = ? AND expires_at > NOW() LIMIT 1");
    $rateStmt->execute([$identifierHash]);
    $lockUntil = $rateStmt->fetchColumn();
    if ($lockUntil) {
        $remaining = strtotime((string)$lockUntil) - time();
        if ($remaining > 0) {
            apiJson(['ok' => false, 'message' => "Too many requests. Try again in " . ceil($remaining / 60) . " minutes."], 429);
        }
    }

    $db->prepare("INSERT INTO rate_limits (context, action_key, identifier_hash, attempts, first_attempt, lock_until, expires_at)
                  VALUES ('api', 'reset-password', ?, 1, UNIX_TIMESTAMP(), NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))
                  ON DUPLICATE KEY UPDATE
                    attempts = attempts + 1,
                    first_attempt = IF(attempts = 0, UNIX_TIMESTAMP(), first_attempt),
                    lock_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                    expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    updated_at = NOW()")
        ->execute([$identifierHash, $lockSeconds, $maxAttempts, $lockSeconds]);

    $tokenHash = hash('sha256', $otp);
    $tokenStmt = $db->prepare("SELECT r.id, r.user_id
                               FROM auth_password_resets r
                               JOIN users u ON u.id = r.user_id
                               WHERE u.email = ?
                                 AND u.status = 'active'
                                 AND r.token_hash = ?
                                 AND r.used_at IS NULL
                                 AND r.expires_at > NOW()
                               ORDER BY r.id DESC
                               LIMIT 1");
    $tokenStmt->execute([$email, $tokenHash]);
    $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        apiJson(['ok' => false, 'message' => 'Reset code is invalid or expired'], 400);
    }

    $resetUserId = (int)$tokenRow['user_id'];
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $db->beginTransaction();

        $updUser = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $updUser->execute([$hashed, $resetUserId]);

        $markToken = $db->prepare("UPDATE auth_password_resets SET used_at = NOW() WHERE id = ?");
        $markToken->execute([(int)$tokenRow['id']]);

        if (apiTableExists($db, 'auth_remember_tokens')) {
            $db->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$resetUserId]);
        }

        $db->commit();
        apiJson(['ok' => true, 'message' => 'Password reset successful. You can now login.']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        apiJson(['ok' => false, 'message' => 'Unable to reset password. Please try again.'], 500);
    }
}
