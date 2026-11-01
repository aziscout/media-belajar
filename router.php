<?php
/**
 * Local Development Router for PHP Built-in Server
 * Usage: php -S localhost:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// 1. Static files (assets, favicon, images, fonts, uploads)
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    // Determine proper content type
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'js'    => 'application/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'json'  => 'application/json; charset=utf-8',
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
        header('Access-Control-Allow-Origin: *');
        readfile($file);
        exit;
    }

    return false; // Let PHP built-in server handle other files
}

// 2. Direct API requests (e.g. /api/media.php or /api/auth.php)
if (str_starts_with($uri, '/api/')) {
    $script = __DIR__ . $uri;
    if (file_exists($script) && !is_dir($script)) {
        require $script;
        exit;
    }
}

// 3. SPA Route Fallback -> index.php
require __DIR__ . '/index.php';
