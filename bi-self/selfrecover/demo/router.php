<?php
// Router for PHP built-in server
// Maps api/index.php for all /api/ requests, serves static files otherwise

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

if (strpos($path, '/api/') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

require __DIR__ . '/index.html';
return true;
