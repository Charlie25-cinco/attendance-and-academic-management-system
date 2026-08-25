<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = (new Database())->getConnection();
$error = '';
$success = '';
$emailInput = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
$otpInput = trim((string)($_POST['otp'] ?? ''));
$resetUserId = 0;
$resetTokenRowId = 0;

function resetHasTable($db, $table) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!$db) {
        $error = 'Database connection failed.';
    } elseif (!resetHasTable($db, 'auth_password_resets')) {
        $error = 'Password reset table is missing. Please run database schema update.';
    } elseif ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($emailInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{6}$/', $otpInput)) {
        $error = 'Please enter the 6-digit reset code.';
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $passwordError = '';
        if (!validateStrongPassword($newPassword, $passwordError)) {
            $error = $passwordError;
        }
    }

    if ($error === '') {
        try {
            $tokenHash = hash('sha256', $otpInput);
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
            $tokenStmt->execute([$emailInput, $tokenHash]);
            $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tokenRow) {
                $error = 'Reset code is invalid or expired.';
                throw new RuntimeException('Invalid reset OTP.');
            }
            $resetUserId = (int)$tokenRow['user_id'];
            $resetTokenRowId = (int)$tokenRow['id'];

            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->beginTransaction();

            $updUser = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $updUser->execute([$hashed, $resetUserId]);
            bumpUserApiTokenVersion($db, $resetUserId);

            $markToken = $db->prepare("UPDATE auth_password_resets
                                       SET used_at = NOW()
                                       WHERE id = ? AND used_at IS NULL");
            $markToken->execute([$resetTokenRowId]);

            if (resetHasTable($db, 'auth_remember_tokens')) {
                $revokeRemember = $db->prepare("UPDATE auth_remember_tokens
                                                SET revoked_at = NOW()
                                                WHERE user_id = ? AND revoked_at IS NULL");
                $revokeRemember->execute([$resetUserId]);
            }

            $db->commit();
            $success = 'Password reset successful. You can now login with your new password.';
            $emailInput = '';
            $otpInput = '';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error === '') {
                error_log('Reset password save error: ' . $e->getMessage());
                $error = 'Unable to reset password. Please try again.';
            }
        }
    }
} elseif (!$db) {
    $error = 'Database connection failed.';
} elseif (!resetHasTable($db, 'auth_password_resets')) {
    $error = 'Password reset table is missing. Please run database schema update.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/auth.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <img src="../assets/images/bshs-logo.jpg" alt="Balingasag SHS Logo" class="auth-logo-img">
                </div>
                <h1 class="auth-title">Reset Password</h1>
                <p class="auth-subtitle">Enter the reset code from your email and set a new password</p>
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
            <div class="text-center">
                <a href="login.php" class="auth-footer-link">Go to Login</a>
            </div>
            <?php endif; ?>

            <?php if ($success === ''): ?>
            <form class="auth-form" action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your registered email" value="<?php echo htmlspecialchars($emailInput, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="otp">Reset Code</label>
                    <div class="input-group">
                        <i class="bi bi-shield-lock input-icon"></i>
                        <input type="text" class="form-control" id="otp" name="otp" placeholder="Enter 6-digit code" value="<?php echo htmlspecialchars($otpInput, ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-group">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Toggle password visibility">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="password-guidance" data-password-guidance data-target="new_password">
                        <p class="password-guidance-title">Recommended password</p>
                        <ul class="password-guidance-list">
                            <li data-rule="length">12 to 72 characters</li>
                            <li data-rule="uppercase">At least one uppercase letter</li>
                            <li data-rule="lowercase">At least one lowercase letter</li>
                            <li data-rule="number">At least one number</li>
                            <li data-rule="special">At least one special character</li>
                        </ul>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-group">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Toggle password visibility">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div id="passwordMatchIndicator" class="password-match-indicator" aria-live="polite"></div>
                </div>

                <button type="submit" class="auth-btn">
                    <i class="bi bi-shield-lock"></i>
                    Reset Password
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
    document.querySelectorAll('.password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (!input) return;
            const isPassword = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPassword ? 'text' : 'password');
            const icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !isPassword);
                icon.classList.toggle('bi-eye-slash', isPassword);
            }
        });
    });

    const newPassInput = document.getElementById('new_password');
    const confirmPassInput = document.getElementById('confirm_password');
    const matchIndicator = document.getElementById('passwordMatchIndicator');

    function updatePasswordMatch() {
        if (!matchIndicator || !newPassInput || !confirmPassInput) return;
        const newV = newPassInput.value || '';
        const confirmV = confirmPassInput.value || '';
        if (!confirmV && !newV) {
            matchIndicator.textContent = '';
            matchIndicator.className = 'password-match-indicator';
            return;
        }
        if (confirmV && newV && confirmV === newV) {
            matchIndicator.textContent = '✓ Passwords match';
            matchIndicator.className = 'password-match-indicator is-match';
        } else if (confirmV) {
            matchIndicator.textContent = '✗ Passwords do not match';
            matchIndicator.className = 'password-match-indicator is-mismatch';
        } else {
            matchIndicator.textContent = '';
            matchIndicator.className = 'password-match-indicator';
        }
    }

    if (newPassInput) newPassInput.addEventListener('input', updatePasswordMatch);
    if (confirmPassInput) confirmPassInput.addEventListener('input', updatePasswordMatch);

    document.querySelectorAll('[data-password-guidance]').forEach(function (guide) {
        const input = document.getElementById(guide.getAttribute('data-target'));
        if (!input) return;
        const rules = {
            length: function (value) { return value.length >= 12 && value.length <= 72; },
            uppercase: function (value) { return /[A-Z]/.test(value); },
            lowercase: function (value) { return /[a-z]/.test(value); },
            number: function (value) { return /[0-9]/.test(value); },
            special: function (value) { return /[^A-Za-z0-9\s]/.test(value); }
        };
        const items = guide.querySelectorAll('[data-rule]');
        const updateGuide = function () {
            const value = input.value || '';
            items.forEach(function (item) {
                const ruleName = item.getAttribute('data-rule');
                const passed = rules[ruleName] ? rules[ruleName](value) : false;
                item.classList.toggle('is-valid', passed);
            });
        };
        input.addEventListener('input', updateGuide);
        input.addEventListener('blur', updateGuide);
        updateGuide();
    });
</script>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
</body>
</html>
