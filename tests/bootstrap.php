<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_TESTING')) {
    define('APP_TESTING', true);
}

$testSessionPath = APP_ROOT . '/.phpunit.cache/sessions';
if (!is_dir($testSessionPath)) {
    mkdir($testSessionPath, 0777, true);
}
ini_set('session.save_path', $testSessionPath);

require_once __DIR__ . '/../functions/bootstrap.php';
require_once __DIR__ . '/../functions/app-helpers.php';
require_once __DIR__ . '/../functions/report-aggregates.php';
