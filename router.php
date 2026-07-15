<?php

$uri = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$publicRoot = __DIR__;

if ($uri === '' || $uri === '/') {
    if (is_file($publicRoot . '/index.php')) {
        return false;
    }
    header('Location: /index.php');
    return true;
}

$publicPath = $publicRoot . $uri;
if (is_file($publicPath) || (is_dir($publicPath) && is_file($publicPath . '/index.php'))) {
    return false;
}

http_response_code(404);
echo 'Not Found';
return true;
