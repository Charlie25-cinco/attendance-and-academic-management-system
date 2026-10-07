<?php

$uri = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$publicRoot = __DIR__;
require_once __DIR__ . '/src/Security/HttpAccessPolicy.php';

if (!\BshsAms\Security\HttpAccessPolicy::allows($uri)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

if ($uri === '' || $uri === '/') {
    if (is_file($publicRoot . '/index.php')) {
        return false;
    }
    header('Location: /index.php');
    return true;
}

$publicPath = $publicRoot . $uri;
// Do not follow public symlinks out of the document root.
$resolvedPath = realpath($publicPath);
if ($resolvedPath !== false && !str_starts_with($resolvedPath, $publicRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}
if (is_file($publicPath) || (is_dir($publicPath) && is_file($publicPath . '/index.php'))) {
    return false;
}

http_response_code(404);
echo 'Not Found';
return true;
