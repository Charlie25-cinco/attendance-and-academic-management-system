<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    require_once APP_ROOT . '/config/session.php';
}
