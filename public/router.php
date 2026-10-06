<?php
// Router for PHP's local development server. Production uses deploy/nginx.conf.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}
$file = realpath(__DIR__ . $path);
if ($file && str_starts_with($file, __DIR__ . '/assets/') && is_file($file)) {
    return false;
}
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
http_response_code(404);
echo 'Not found';
