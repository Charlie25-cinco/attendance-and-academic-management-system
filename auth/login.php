<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Login Page - Attendance and Academic Management System

// Include database configuration

$error = '';
if (!empty($_SESSION['session_expired'])) {
    $error = 'Your session expired due to inactivity. Please login again.';
    unset($_SESSION['session_expired']);
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 300;
const REMEMBER_COOKIE_NAME = 'remember_token';
const REMEMBER_TTL_SECONDS = 2592000;

function authHasTable($db, $table) {
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

function authIdentifierHash($key) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower(trim((string)$key)) . '|' . $ip);
}

function authRateIsLocked($db, $action, $identifier, &$remaining = 0) {
    if (!authHasTable($db, 'rate_limits')) return false;
    $stmt = $db->prepare("SELECT lock_until FROM rate_limits WHERE context = 'web' AND action_key = ? AND identifier_hash = ? LIMIT 1");
    $stmt->execute([$action, authIdentifierHash($identifier)]);
    $lockUntil = $stmt->fetchColumn();
    if (!$lockUntil) return false;
    $secs = strtotime((string)$lockUntil) - time();
    if ($secs > 0) {
        $remaining = $secs;
        return true;
    }
    return false;
}

function authRateRecordFailure($db, $action, $identifier, $maxAttempts, $lockSeconds) {
    if (!authHasTable($db, 'rate_limits')) return;
    $hash = authIdentifierHash($identifier);
    $stmt = $db->prepare("INSERT INTO rate_limits (context, action_key, identifier_hash, attempts, first_attempt, lock_until, expires_at)
                          VALUES ('web', ?, ?, 1, UNIX_TIMESTAMP(), NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))
                          ON DUPLICATE KEY UPDATE
                          attempts = IF(lock_until IS NOT NULL AND lock_until > NOW(), attempts, attempts + 1),
                          first_attempt = IF(attempts = 0, UNIX_TIMESTAMP(), first_attempt),
                          lock_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                          expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                          updated_at = NOW()");
    $stmt->execute([$action, $hash, (int)$lockSeconds, (int)$maxAttempts, (int)$lockSeconds, (int)$lockSeconds]);
}

function authRateClear($db, $action, $identifier) {
    if (!authHasTable($db, 'rate_limits')) return;
    $stmt = $db->prepare("DELETE FROM rate_limits WHERE context = 'web' AND action_key = ? AND identifier_hash = ?");
    $stmt->execute([$action, authIdentifierHash($identifier)]);
}

function authSetCookie($name, $value, $expiresAt) {
    setcookie($name, $value, appCookieParams((int)$expiresAt, true));
}

function authSetThemeCookie($enabled) {
    setcookie('app_dark_mode', $enabled ? '1' : '0', appCookieParams(time() + (86400 * 365), false));
}

function authClearRememberCookie() {
    authSetCookie(REMEMBER_COOKIE_NAME, '', time() - 3600);
}

function loginLogAttempt($db, $referenceCode, $success, $reason) {
    if (!$db || !authHasTable($db, 'auth_login_logs')) return;
    try {
        $stmt = $db->prepare("INSERT INTO auth_login_logs (reference_code, ip_address, success, reason, created_at)
                              VALUES (?, ?, ?, ?, NOW())");
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $stmt->execute([$referenceCode, $ip, $success ? 1 : 0, $reason]);
    } catch (Throwable $e) {
        error_log("Login log write error: " . $e->getMessage());
    }
}

function rememberRevokeByCookie($db, $cookieValue) {
    if (!$db || !authHasTable($db, 'auth_remember_tokens')) return;
    if (!is_string($cookieValue) || strpos($cookieValue, ':') === false) return;
    [$selector, $validator] = explode(':', $cookieValue, 2);
    if ($selector === '' || $validator === '') return;
    $stmt = $db->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW()
                          WHERE selector = ? AND token_hash = ? AND revoked_at IS NULL");
    $stmt->execute([$selector, hash('sha256', $validator)]);
}

function rememberIssueToken($db, $userId) {
    if (!$db || !authHasTable($db, 'auth_remember_tokens')) return;
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_TTL_SECONDS);

    $stmt = $db->prepare("INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at, created_at, last_used_at)
                          VALUES (?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([(int)$userId, $selector, $hash, $expiresAt]);
    authSetCookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, time() + REMEMBER_TTL_SECONDS);
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
    $_SESSION['last_activity'] = time();

    $_SESSION['ui_dark_mode'] = 0;
    try {
        if ($db && authHasTable($db, 'user_settings')) {
            $settingsStmt = $db->prepare("SELECT dark_mode
                                          FROM user_settings
                                          WHERE user_id = ?
                                          LIMIT 1");
            $settingsStmt->execute([(int)$user['id']]);
            $darkMode = (int)($settingsStmt->fetchColumn() ?: 0) === 1 ? 1 : 0;
            $_SESSION['ui_dark_mode'] = $darkMode;
            authSetThemeCookie($darkMode === 1);
        } else {
            authSetThemeCookie(false);
        }
    } catch (Throwable $e) {
        $_SESSION['ui_dark_mode'] = 0;
        authSetThemeCookie(false);
    }
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

function attemptRememberLogin($db) {
    if (!$db || !authHasTable($db, 'auth_remember_tokens')) return null;
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) return null;

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') return null;

    $stmt = $db->prepare("SELECT rt.id AS token_id, rt.token_hash, u.id, u.reference_code, u.email, u.password, u.first_name, u.last_name, u.role
                          FROM auth_remember_tokens rt
                          JOIN users u ON u.id = rt.user_id
                          WHERE rt.selector = ?
                          AND rt.revoked_at IS NULL
                          AND rt.expires_at > NOW()
                          AND u.status = 'active'
                          LIMIT 1");
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $valid = hash_equals((string)$row['token_hash'], hash('sha256', $validator));
    if (!$valid) {
        $revoke = $db->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE id = ?");
        $revoke->execute([(int)$row['token_id']]);
        authClearRememberCookie();
        return null;
    }

    $touch = $db->prepare("UPDATE auth_remember_tokens SET last_used_at = NOW() WHERE id = ?");
    $touch->execute([(int)$row['token_id']]);

    return [
        'id' => $row['id'],
        'reference_code' => $row['reference_code'],
        'email' => $row['email'],
        'password' => $row['password'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'role' => $row['role']
    ];
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    $error = 'Database connection failed!';
}

if ((!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) && $db) {
    $rememberUser = attemptRememberLogin($db);
    if ($rememberUser) {
        if (password_verify(getDefaultNewUserPassword(), (string)$rememberUser['password'])) {
            rememberRevokeByCookie($db, (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? ''));
            authClearRememberCookie();
            $_SESSION['pending_password_change'] = true;
            $_SESSION['pending_user_id'] = (int)$rememberUser['id'];
            $_SESSION['pending_reference_code'] = (string)$rememberUser['reference_code'];
            header("Location: change-password.php");
            exit();
        }
        establishLoginSession($db, $rememberUser);
        rememberIssueToken($db, (int)$rememberUser['id']);
        redirectByRole($rememberUser['role']);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    }

    $reference_code = strtoupper(trim((string)($_POST['reference_code'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']) && (string)$_POST['remember'] !== '';

    if ($error !== '') {
        // CSRF failed; do not process login flow.
    } elseif (!$db) {
        $error = 'Database connection failed!';
    } elseif ($reference_code === '' || $password === '') {
        $error = 'Please fill in all fields!';
    } else {
        $remaining = 0;
        if (authRateIsLocked($db, 'login', $reference_code, $remaining)) {
            $mins = (int)ceil($remaining / 60);
            $error = 'Too many login attempts. Try again in ' . $mins . ' minute(s).';
        } else {
            $query = "SELECT id, reference_code, email, password, first_name, middle_name, last_name, role, status
                      FROM users
                      WHERE reference_code = :reference_code AND status = 'active'
                      LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':reference_code', $reference_code);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if (password_verify(getDefaultNewUserPassword(), $user['password'])) {
                    $_SESSION['pending_password_change'] = true;
                    $_SESSION['pending_user_id'] = (int)$user['id'];
                    $_SESSION['pending_reference_code'] = (string)$user['reference_code'];
                    header("Location: change-password.php");
                    exit();
                }
                establishLoginSession($db, $user);
                authRateClear($db, 'login', $reference_code);
                loginLogAttempt($db, $reference_code, true, 'ok');

                if ($remember) {
                    rememberIssueToken($db, (int)$user['id']);
                } else {
                    rememberRevokeByCookie($db, (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? ''));
                    authClearRememberCookie();
                }
                redirectByRole($user['role']);
            }

            authRateRecordFailure($db, 'login', $reference_code, LOGIN_MAX_ATTEMPTS, LOGIN_LOCK_SECONDS);
            loginLogAttempt($db, $reference_code, false, $user ? 'invalid_password' : 'not_found_or_inactive');
            $error = $user ? 'Invalid password!' : 'Invalid reference code or account not active!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Balingasag Senior High School</title>
    <script>
    (function() {
        try {
            if (!navigator.onLine && localStorage.getItem('bshs_cached_teacher') === '1') {
                document.documentElement.classList.add('offline-launching');
                window.location.replace('../teacher/teacher.php');
            }
        } catch (e) {}
    })();
    </script>
    <style>
    html.offline-launching body {
        visibility: hidden !important;
        background-color: #f8fafc !important;
    }
    </style>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/auth.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <div class="auth-container">
        <div class="auth-layout auth-layout-single">
            <section class="auth-panel-wrap">
                <div class="auth-card">

                    <div class="auth-header">
                        <div class="auth-logo">
                            <img src="../assets/images/bshs-logo.jpg" alt="Balingasag SHS Logo" class="auth-logo-img" width="58" height="58" style="width: 58px; height: 58px; object-fit: cover;">
                        </div>

                        <h2 class="auth-title" id="greeting">Welcome!</h2>
                        <p class="auth-subtitle">Sign in using your reference code and password.</p>
                    </div>

                    <?php if ($error): ?>
                    <div class="auth-alert error">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php endif; ?>



                    <form class="auth-form" action="" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="form-group">
                            <label for="reference_code">Reference Code</label>
                            <div class="input-group">
                                <i class="bi bi-person-badge input-icon"></i>
                                <input type="text" class="form-control auth-uppercase" id="reference_code" name="reference_code" placeholder="Enter your reference code" autocomplete="username" autocapitalize="characters" spellcheck="false" maxlength="20" autofocus required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-group">
                                <i class="bi bi-lock input-icon"></i>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" data-target="password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="auth-options">
                            <label class="remember-me">
                                <input type="checkbox" name="remember" checked>
                                <span>Keep me signed in on this device</span>
                            </label>
                            <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
                        </div>

                        <button type="submit" class="auth-btn">
                            <i class="bi bi-box-arrow-in-right"></i>
                            Login
                        </button>
                    </form>

                    <div class="auth-footer">
                        <p class="auth-footer-text">Accounts are created by the administrator and activated through your first password setup.</p>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo appAssetPath('js/offlineStorage.js'); ?>"></script>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
    <script>
        async function checkOfflineSession() {
            if (!navigator.onLine && window.bshsOfflineStorage) {
                const session = await window.bshsOfflineStorage.getTeacherSession();
                const card = document.querySelector('.auth-card');

                if (session && session.teacher_id) {
                    window.location.replace('../teacher/teacher.php');
                } else if (!session && card && !document.getElementById('offlineNoSessionBanner')) {
                    const b = document.createElement('div');
                    b.id = 'offlineNoSessionBanner';
                    b.className = 'alert alert-warning mb-3';
                    b.innerHTML = '<i class="bi bi-wifi-off me-2"></i>Device is offline. Connect to the internet to log in for the first time.';
                    card.insertBefore(b, card.firstChild);
                }
            }
        }
        checkOfflineSession();
        window.addEventListener('offline', checkOfflineSession);
        document.addEventListener('DOMContentLoaded', function() {
            const hour = new Date().getHours();
            let greeting = 'Welcome!';
            if (hour < 12) {
                greeting = 'Good Morning!';
            } else if (hour < 18) {
                greeting = 'Good Afternoon!';
            } else {
                greeting = 'Good Evening!';
            }
            const greetingNode = document.getElementById('greeting');
            if (greetingNode) {
                greetingNode.innerText = greeting;
            }
        });

        document.querySelectorAll('.password-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
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

        const refInput = document.getElementById('reference_code');
        if (refInput) {
            refInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }
    </script>
</body>
</html>
