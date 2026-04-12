<?php

/**
 * Router script for PHP's built-in development server.
 * Serves static files directly, routes everything else through index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $_SERVER['DOCUMENT_ROOT'] . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
