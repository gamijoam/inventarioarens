<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * Rutas web no-API.
 *
 * El backend de INVENTARIOARENS es API puro (todo bajo /api/*). Las rutas aqui
 * son catch-all que sirven el frontend SPA React que esta en /frontend/dist.
 * El backend sirve el index.html y los assets JS/CSS del frontend para que
 * el usuario pueda acceder a la aplicacion web entrando a /
 *
 * El propio frontend hace sus requests a /api/* (que NO se ven afectadas por
 * estas reglas porque estan registradas con prefijo /api en routes/api.php).
 *
 * Si en el futuro se quiere migrar esto a nginx directo (mas performante),
 * agregar un location / { alias /opt/inventarioarens-cloud/frontend/dist; }
 * y un try_files que sirva index.html para SPA fallback.
 */

$spaIndex = base_path('frontend/dist/index.html');

Route::get('/', function () use ($spaIndex) {
    if (! is_file($spaIndex)) {
        // Fallback si el frontend aun no esta construido.
        return response()->json([
            'name' => config('app.name', 'INVENTARIOARENS'),
            'env' => app()->environment(),
            'api' => url('/api'),
            'message' => 'Frontend SPA no construido. La API esta disponible en ' . url('/api'),
            'health' => url('/up'),
        ], 200);
    }

    return response()->file($spaIndex, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);
})->name('spa.root');

// Assets estaticos del frontend (JS, CSS, fonts). En produccion, esto deberia
// servirse desde nginx via alias; aca lo servimos via Laravel por simplicidad
// hasta que migrar el frontend a servir desde nginx directamente.
Route::get('assets/{path}', function (string $path) {
    // Bloquear path-traversal.
    if (preg_match('#\.\.|^\.|\/$#', $path) || ! preg_match('#^[a-zA-Z0-9._/-]+$#', $path)) {
        abort(404);
    }
    $full = base_path("frontend/dist/assets/{$path}");
    if (! is_file($full)) {
        abort(404);
    }

    $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
        'js', 'mjs' => 'application/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };

    return response()->file($full, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '[a-zA-Z0-9._/-]+')->name('spa.assets');

// Catch-all SPA: cualquier ruta que NO sea /api/* o /up devuelve el index.html.
// Esto es el comportamiento estandar de React Router/Vite SPA: el server siempre
// devuelve index.html para rutas desconocidas y el cliente resuelve el routing.
Route::fallback(function () use ($spaIndex) {
    // Si el frontend no esta construido, devolvemos un 404 JSON informativo
    // en vez de devolver HTML vacio.
    if (! is_file($spaIndex)) {
        return response()->json([
            'error' => 'Not Found',
            'message' => 'La ruta solicitada no existe. La API esta disponible en ' . url('/api'),
        ], 404);
    }

    return response()->file($spaIndex, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);
});
