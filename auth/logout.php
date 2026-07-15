<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Logout handler - Attendance and Academic Management System

const REMEMBER_COOKIE_NAME = 'remember_token';

function logoutRevokeRememberToken() {
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) return;
    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') return;

    $db = (new Database())->getConnection();
    if (!$db) return;
    try {
        $hasTable = (bool)$db->query("SHOW TABLES LIKE 'auth_remember_tokens'")->fetch(PDO::FETCH_NUM);
        if (!$hasTable) return;
        $stmt = $db->prepare("UPDATE auth_remember_tokens
                              SET revoked_at = NOW()
                              WHERE selector = ? AND token_hash = ? AND revoked_at IS NULL");
        $stmt->execute([$selector, hash('sha256', $validator)]);
    } catch (Throwable $e) {
        error_log("Logout remember-token revoke error: " . $e->getMessage());
    }
}

function clearRememberCookie() {
    setcookie(REMEMBER_COOKIE_NAME, '', appCookieParams(time() - 3600, true));
}

logoutRevokeRememberToken();
clearRememberCookie();

// Clear all session data.
$_SESSION = [];

// Remove session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

header("Location: login.php");
exit();
?>
