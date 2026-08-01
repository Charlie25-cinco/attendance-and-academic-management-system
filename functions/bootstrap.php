<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('APP_REQUEST_STARTED_AT')) {
    define('APP_REQUEST_STARTED_AT', $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
}

require_once APP_ROOT . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    register_shutdown_function(function (): void {
        if (function_exists('appPerfLoggingEnabled') && !appPerfLoggingEnabled()) {
            return;
        }

        $durationMs = (microtime(true) - (float)APP_REQUEST_STARTED_AT) * 1000;
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $status = http_response_code();
        $role = (string)($_SESSION['role'] ?? 'guest');

        error_log(sprintf(
            '[perf] method=%s path=%s status=%d role=%s duration_ms=%.1f',
            $method,
            $path,
            $status,
            $role,
            $durationMs
        ));
    });
}

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
