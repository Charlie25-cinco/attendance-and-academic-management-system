<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../functions/sf-exporter.php';
require_once __DIR__ . '/../functions/db-helper.php';
require_once __DIR__ . '/../functions/simple-xlsx-writer.php';
require_once __DIR__ . '/../functions/grade-helper.php';
require_once __DIR__ . '/../functions/ecr-exporter.php';
