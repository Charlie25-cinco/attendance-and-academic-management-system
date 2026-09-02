<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Logout handler - Attendance and Academic Management System

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$requestToken = trim((string)($_GET['csrf_token'] ?? ''));
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    header("Location: login.php");
    exit();
}

$db = (new Database())->getConnection();
if ($db && function_exists('appRememberRevokeByCookie')) {
    appRememberRevokeByCookie($db, (string)($_COOKIE['remember_token'] ?? ''));
}
if (function_exists('appAuthClearRememberCookie')) {
    appAuthClearRememberCookie();
}

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
