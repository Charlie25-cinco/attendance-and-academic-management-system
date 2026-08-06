<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Forgot Password Page - Attendance and Academic Management System

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$emailInput = trim((string)($_GET['email'] ?? ''));

function forgotHasTable($db, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

const FORGOT_MAX_ATTEMPTS = 3;
const FORGOT_LOCK_SECONDS = 900;

function forgotIdentifierHash(string $email): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function forgotRateIsLocked($db, string $email, int &$remaining = 0): bool {
    if (!forgotHasTable($db, 'rate_limits')) {
        return false;
    }
    $stmt = $db->prepare("SELECT lock_until FROM rate_limits WHERE context = 'web' AND action_key = 'forgot_password' AND identifier_hash = ? LIMIT 1");
    $stmt->execute([forgotIdentifierHash($email)]);
    $lockUntil = $stmt->fetchColumn();
    if (!$lockUntil) {
        return false;
    }
    $seconds = strtotime((string)$lockUntil) - time();
    if ($seconds > 0) {
        $remaining = $seconds;
        return true;
    }
    return false;
}

function forgotRateRecordAttempt($db, string $email): void {
    if (!forgotHasTable($db, 'rate_limits')) {
        return;
    }
    $stmt = $db->prepare("INSERT INTO rate_limits (context, action_key, identifier_hash, attempts, first_attempt, lock_until, expires_at)
                          VALUES ('web', 'forgot_password', ?, 1, UNIX_TIMESTAMP(), NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))
                          ON DUPLICATE KEY UPDATE
                          attempts = IF(lock_until IS NOT NULL AND lock_until > NOW(), attempts, attempts + 1),
                          first_attempt = IF(attempts = 0, UNIX_TIMESTAMP(), first_attempt),
                          lock_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                          expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                          updated_at = NOW()");
    $stmt->execute([forgotIdentifierHash($email), FORGOT_LOCK_SECONDS, FORGOT_MAX_ATTEMPTS, FORGOT_LOCK_SECONDS, FORGOT_LOCK_SECONDS]);
}

$db = (new Database())->getConnection();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $remaining = 0;
    $emailInput = trim((string)($_POST['email'] ?? ''));
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ''));

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($emailInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$db) {
        $error = 'Database connection failed.';
    } elseif (!forgotHasTable($db, 'auth_password_resets')) {
        $error = 'Password reset table is missing. Please run database schema update.';
    } elseif (forgotRateIsLocked($db, $emailInput, $remaining)) {
        $error = 'Too many reset requests. Please wait ' . max(1, (int)ceil($remaining / 60)) . ' minute(s) and try again.';
    } else {
        try {
            forgotRateRecordAttempt($db, $emailInput);

            $userStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $userStmt->execute([$emailInput]);
            $userId = (int)($userStmt->fetchColumn() ?: 0);

            // Always return generic message to avoid account enumeration.
            $success = 'If your email is registered, a reset code has been sent.';

            if ($userId > 0) {
                $otp = (string)random_int(100000, 999999);
                $tokenHash = hash('sha256', $otp);
                $expiresAt = (string)($db->query("SELECT DATE_ADD(NOW(), INTERVAL 10 MINUTE)")->fetchColumn());
                $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

                // Invalidate old unused tokens first.
                $clearStmt = $db->prepare("UPDATE auth_password_resets
                                           SET used_at = NOW()
                                           WHERE user_id = ? AND used_at IS NULL");
                $clearStmt->execute([$userId]);

                $insertStmt = $db->prepare("INSERT INTO auth_password_resets (user_id, token_hash, expires_at, request_ip, created_at)
                                            VALUES (?, ?, ?, ?, NOW())");
                $insertStmt->execute([$userId, $tokenHash, $expiresAt, $ip]);

                $resetUrl = appPublicWebBaseUrl() . '/auth/reset-password.php?email=' . urlencode($emailInput);
                $subject = 'Your Balingasag SHS password reset code';
                $html = '
                    <p>We received a request to reset your password.</p>
                    <p>Your reset code is:</p>
                    <p style="font-size: 28px; font-weight: 700; letter-spacing: 6px;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>
                    <p>Enter this code on the password reset page: <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset password</a></p>
                    <p>This code expires in 10 minutes. If you did not request this, you can ignore this email.</p>
                ';

                if (!mailerSendHtml($emailInput, $subject, $html)) {
                    $error = 'Unable to send reset email. Please contact the administrator.';
                    $success = '';
                }
            }
        } catch (Throwable $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            $error = 'Unable to process request right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Balingasag Senior High School</title>

    <!-- Bootstrap CSS -->
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/auth.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
<!-- Auth Header -->
            <div class="auth-header">
                <div class="auth-logo">
                    <img src="../assets/images/bshs-logo.jpg" alt="Balingasag SHS Logo" class="auth-logo-img">
                </div>
                <h1 class="auth-title">Forgot Password?</h1>
                <p class="auth-subtitle">Enter your email to receive a password reset code</p>
            </div>

            <?php if ($error !== ''): ?>
            <div class="auth-alert error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
            <div class="auth-alert success">
                <i class="bi bi-check-circle-fill"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <!-- Forgot Password Form -->
            <form class="auth-form" action="" method="POST" id="forgotForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <!-- Email -->
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your registered email" value="<?php echo htmlspecialchars($emailInput); ?>" autocomplete="email" required>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="auth-btn">
                    <i class="bi bi-send"></i>
                    Send Reset Code
                </button>
            </form>

            <!-- Help Text -->
            <div class="auth-footer">
                <p class="auth-footer-text">
                    Remember your password?
                    <a href="login.php" class="auth-footer-link">Login here</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>

</body>
</html>
