<?php

/**
 * BalanzaPro / Laravel Local Built-in Server Router
 * Permite ejecutar el backend directamente con: php -S 127.0.0.1:8788 server.php
 * sin sobrecarga de artisan serve, iniciando en menos de 50 milisegundos.
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

$publicFile = __DIR__ . '/public' . $uri;

if ($uri !== '/' && file_exists($publicFile) && !is_dir($publicFile)) {
    return false;
}

require_once __DIR__ . '/public/index.php';
