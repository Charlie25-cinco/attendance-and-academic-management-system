<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/config/constants.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/functions/db-helper.php';
require_once APP_ROOT . '/functions/grade-helper.php';
require_once APP_ROOT . '/functions/app-helpers.php';
require_once APP_ROOT . '/functions/sf-exporter.php';
require_once APP_ROOT . '/functions/ecr-exporter.php';

if (PHP_SAPI !== 'cli') {
    require_once APP_ROOT . '/config/session.php';
}
