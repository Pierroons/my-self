<?php
// Router for PHP built-in server
// Maps api/index.php for all /api/ requests, serves static files otherwise

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

if (strpos($path, '/api/') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Anti path-traversal : on canonicalise le chemin demandé puis on vérifie
// qu'il reste strictement à l'intérieur du dossier de la démo. Sinon on tombe
// dans le fallback index.html (page d'accueil démo).
$base = realpath(__DIR__);
$file = realpath(__DIR__ . $path);
if ($path !== '/' && $file !== false && $base !== false
    && str_starts_with($file, $base . DIRECTORY_SEPARATOR)
    && is_file($file)) {
    return false;
}

require __DIR__ . '/index.html';
return true;
