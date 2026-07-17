<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    require_once APP_ROOT . '/config/session.php';
    if (!empty($_SESSION['logged_in']) && function_exists('enforceScriptPermission')) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            if ($db instanceof PDO) {
                enforceScriptPermission($db);
            }
        } catch (Throwable $e) {
            error_log('RBAC enforcement failed: ' . $e->getMessage());
        }
    }
}
