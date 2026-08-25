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

$error = '';
$success = '';

if (empty($_SESSION['pending_password_change']) || empty($_SESSION['pending_user_id'])) {
    header("Location: login.php");
    exit();
}

function establishLoginSession($db, $user) {
    $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':id', $user['id']);
    $updateStmt->execute();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['reference_code'] = $user['reference_code'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function redirectByRole($role) {
    switch ($role) {
        case 'admin': header("Location: ../admin/admin.php"); break;
        case 'teacher': header("Location: ../teacher/teacher.php"); break;
        case 'student': header("Location: ../student/Student.php"); break;
        case 'parent': header("Location: ../parent/Parent.php"); break;
        default: header("Location: ../index.php"); break;
    }
    exit();
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    $error = 'Database connection failed!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $error = 'Invalid request. Please refresh and try again.';
    }

    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if ($error === '' && ($newPassword === '' || $confirmPassword === '')) {
        $error = 'Please fill in all fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!validateStrongPassword($newPassword, $error)) {
        // Error message populated by shared validator.
    } else {
        $userId = (int)$_SESSION['pending_user_id'];
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hash, $userId]);
        bumpUserApiTokenVersion($db, $userId);

        $userStmt = $db->prepare("SELECT id, reference_code, email, first_name, last_name, role
                                  FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            unset($_SESSION['pending_password_change'], $_SESSION['pending_user_id'], $_SESSION['pending_reference_code']);
            establishLoginSession($db, $user);
            redirectByRole($user['role']);
        } else {
            $error = 'Unable to load user. Please login again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Balingasag Senior High School</title>
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
                <h1 class="auth-title">Set New Password</h1>
                <p class="auth-subtitle">Update your temporary password to continue</p>
            </div>

            <?php if ($error): ?>
            <div class="auth-alert error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <form class="auth-form" action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-group">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               placeholder="Enter new password" autocomplete="new-password" required>
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
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                               placeholder="Re-enter new password" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Toggle password visibility">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div id="passwordMatchIndicator" class="password-match-indicator" aria-live="polite"></div>
                </div>

                <button type="submit" class="auth-btn">
                    <i class="bi bi-check-circle"></i>
                    Update Password
                </button>
            </form>
        </div>
    </div>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
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
</body>
</html>
