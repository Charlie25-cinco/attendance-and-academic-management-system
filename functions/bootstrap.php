<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('APP_REQUEST_STARTED_AT')) {
    define('APP_REQUEST_STARTED_AT', $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
}

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/functions/app-helpers.php';

// Lazy alias loader for legacy un-namespaced class references
spl_autoload_register(function (string $class): void {
    static $aliases = [
        'Database' => \BshsAms\Database\Database::class,
        'SchemaCache' => \BshsAms\Database\SchemaCache::class,
        'ScheduleParser' => \BshsAms\Schedule\ScheduleParser::class,
        'SimpleXlsxParser' => \BshsAms\Xlsx\SimpleXlsxParser::class,
        'SimpleXlsxWriter' => \BshsAms\Xlsx\SimpleXlsxWriter::class,
        'SimpleXlsxTemplateEditor' => \BshsAms\Xlsx\SimpleXlsxTemplateEditor::class,
        'Sf1Parser' => \BshsAms\Export\Sf1Parser::class,
        'Sf1Exporter' => \BshsAms\Export\Sf1Exporter::class,
        'Sf2Exporter' => \BshsAms\Export\Sf2Exporter::class,
        'Sf5Exporter' => \BshsAms\Export\Sf5Exporter::class,
        'Sf9Exporter' => \BshsAms\Export\Sf9Exporter::class,
        'EcrParser' => \BshsAms\Export\EcrParser::class,
        'EcrExporter' => \BshsAms\Export\EcrExporter::class,
        'ReportFilterHelper' => \BshsAms\Report\ReportFilterHelper::class,
        'SshsGradeCalculator' => \BshsAms\Grade\SshsGradeCalculator::class,
        'GradeImporter' => \BshsAms\Grade\GradeImporter::class,
        'AppDatabaseSessionHandler' => \BshsAms\Database\SessionHandler::class,
    ];
    if (isset($aliases[$class]) && class_exists($aliases[$class])) {
        class_alias($aliases[$class], $class);
    }
});

if (PHP_SAPI !== 'cli') {
    require_once APP_ROOT . '/config/session.php';
}

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
    if (!empty($_SESSION['logged_in']) && function_exists('enforceScriptPermission')) {
        try {
            // enforceScriptPermission validates cached session permissions first,
            // connecting to the database only when cache is missing, expired, or role changed.
            enforceScriptPermission();
        } catch (Throwable $e) {
            error_log('RBAC enforcement failed: ' . $e->getMessage());
            $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $permission = function_exists('permissionForScript') ? permissionForScript($script) : '';
            if ($permission !== '') {
                $isJson = str_ends_with(strtolower($script), '_action.php')
                    || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
                http_response_code(403);
                if ($isJson) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Insufficient permissions.']);
                } else {
                    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access denied</title></head><body style="font-family:Arial,sans-serif;padding:2rem;"><h1>Access denied</h1><p>You do not have permission to access this page.</p></body></html>';
                }
                exit();
            }
        }
    }
}
