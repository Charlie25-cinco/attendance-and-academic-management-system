<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Centralized .env parser — single implementation shared across all routes
function apiEnvValue(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return (string)$val;
    }
    static $envCache = null;
    if ($envCache === null) {
        $envCache = [];
        $root = dirname(__DIR__, 2);
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $envCache[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
    }
    return (string)($envCache[$key] ?? $default);
}
