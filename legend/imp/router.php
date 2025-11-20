<?php
// Custom router for PHP built-in server to set headers and serve files
header('X-DNS-Prefetch-Control: off');
header('Referrer-Policy: no-referrer');
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$docRoot = __DIR__;
$path = $docRoot . DIRECTORY_SEPARATOR . ltrim($uri, '/\\');
if ($uri !== '/' && file_exists($path) && is_file($path)) {
    return false;
}
if ($uri === '/' || $uri === '') {
    readfile($docRoot . '/index.html');
    return true;
}
return false;
