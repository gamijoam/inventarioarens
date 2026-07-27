<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$documentRoot = realpath(__DIR__.'/../../frontend/dist');
$candidate = $documentRoot === false ? false : realpath($documentRoot.$path);

if ($candidate !== false && str_starts_with($candidate, $documentRoot) && is_file($candidate)) {
    return false;
}

if ($documentRoot === false) {
    http_response_code(500);
    echo 'Frontend dist not found.';
    return true;
}

readfile($documentRoot.'/index.html');
