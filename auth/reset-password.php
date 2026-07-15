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
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenValid = false;
$resetUserId = 0;

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

if (!$db) {
    $error = 'Database connection failed.';
} elseif (!resetHasTable($db, 'auth_password_resets')) {
    $error = 'Password reset table is missing. Please run database schema update.';
} elseif ($token === '' || !preg_match('/^[a-fA-F0-9]{64}$/', $token)) {
    $error = 'Invalid or missing reset token.';
} else {
    try {
        $tokenHash = hash('sha256', $token);
        $tokenStmt = $db->prepare("SELECT id, user_id
                                   FROM auth_password_resets
                                   WHERE token_hash = ?
                                     AND used_at IS NULL
                                     AND expires_at > NOW()
                                   LIMIT 1");
        $tokenStmt->execute([$tokenHash]);
        $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        if ($tokenRow) {
            $tokenValid = true;
            $resetUserId = (int)$tokenRow['user_id'];
        } else {
            $error = 'Reset link is invalid or expired.';
        }
    } catch (Throwable $e) {
        error_log('Reset password token check error: ' . $e->getMessage());
        $error = 'Unable to validate reset token.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!validateStrongPassword($newPassword, $error)) {
        // Error message populated by shared validator.
    } else {
        try {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->beginTransaction();

            $updUser = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $updUser->execute([$hashed, $resetUserId]);

            $tokenHash = hash('sha256', $token);
            $markToken = $db->prepare("UPDATE auth_password_resets
                                       SET used_at = NOW()
                                       WHERE token_hash = ? AND used_at IS NULL");
            $markToken->execute([$tokenHash]);

            if (resetHasTable($db, 'auth_remember_tokens')) {
                $revokeRemember = $db->prepare("UPDATE auth_remember_tokens
                                                SET revoked_at = NOW()
                                                WHERE user_id = ? AND revoked_at IS NULL");
                $revokeRemember->execute([$resetUserId]);
            }

            $db->commit();
            $success = 'Password reset successful. You can now login with your new password.';
            $tokenValid = false;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Reset password save error: ' . $e->getMessage());
            $error = 'Unable to reset password. Please try again.';
        }
    }
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
                <p class="auth-subtitle">Set a new password for your account</p>
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

            <?php if ($tokenValid): ?>
            <form class="auth-form" action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

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
