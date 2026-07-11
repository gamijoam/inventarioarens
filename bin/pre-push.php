<?php
/**
 * Pre-push hook: corre la suite COMPLETA de tests (composer test) antes
 * de cada push. Si falla aunque sea un test, el push se cancela. Para
 * saltar (emergencia): git push --no-verify.
 *
 * Cross-platform: localiza composer y php en PATH o en paths comunes
 * (Laragon, XAMPP, homebrew, etc.) porque la shell que usa git para
 * correr hooks no siempre hereda el PATH completo del usuario.
 *
 * Logica bash en .githooks/pre-push (shim). Logica de tests aca.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
chdir($repoRoot);

// Git escribe los refs en stdin. Hay que leerlos para no dejar stdin
// colgado en algunos runners.
$stdin = stream_get_contents(STDIN);
if ($stdin === false) {
    $stdin = '';
}

/**
 * Busca un ejecutable en PATH o en paths comunes segun el OS.
 * Devuelve la ruta absoluta o null si no se encuentra.
 *
 * NOTA: en Windows NO usamos is_executable() porque devuelve false
 * para .bat y .phar aunque sean ejecutables (limitación de PHP en
 * Windows). Confiamos en is_file() y dejamos que la shell los ejecute.
 */
function locateBinary(string $name): ?string
{
    $isWindows = stripos(PHP_OS, 'WIN') === 0;

    // Primero intentar el PATH actual
    $cmd = $isWindows
        ? sprintf('where %s 2>nul', escapeshellarg($name))
        : sprintf('command -v %s 2>/dev/null', escapeshellarg($name));
    $output = trim((string) @shell_exec($cmd));
    if ($output !== '' && $output !== 'null') {
        // where puede devolver varias lineas; probar cada una hasta
        // encontrar un archivo valido.
        $candidates = preg_split('/\r?\n/', $output) ?: [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($isWindows) {
                // En Windows aceptar si el archivo existe (PHP no
                // distingue bien .bat/.phar con is_executable).
                if (is_file($candidate)) {
                    return $candidate;
                }
            } else {
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
    }

    // Fallbacks segun OS
    $fallbacks = [];
    if ($isWindows) {
        $fallbacks = [
            "C:\\laragon\\bin\\composer\\composer.bat",
            "C:\\laragon\\bin\\composer\\composer",
            "C:\\laragon\\bin\\composer\\composer.phar",
            "C:\\xampp\\php\\composer.phar",
            "C:\\ProgramData\\ComposerSetup\\bin\\composer.bat",
            getenv('APPDATA') . '\\Composer\\composer.bat',
        ];
    } else {
        $fallbacks = [
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            getenv('HOME') . '/composer/vendor/bin/composer',
            getenv('HOME') . '/.composer/vendor/bin/composer',
        ];
    }

    foreach ($fallbacks as $path) {
        if ($path && is_file($path)) {
            if ($isWindows || is_executable($path)) {
                return $path;
            }
        }
    }

    return null;
}

$composer = locateBinary('composer');
if (! $composer) {
    fwrite(STDERR, "\n[pre-push] ERROR: no encontre 'composer' en PATH ni en paths comunes.\n");
    fwrite(STDERR, "[pre-push] Paths buscados: PATH, " . (stripos(PHP_OS, 'WIN') === 0
        ? 'C:\\laragon\\bin\\composer, C:\\xampp\\php\\composer.phar, %APPDATA%\\Composer\\composer.bat'
        : '/usr/local/bin/composer, /usr/bin/composer') . "\n");
    fwrite(STDERR, "[pre-push] Soluciones:\n");
    fwrite(STDERR, "[pre-push]   - Agregar composer.bat al PATH del sistema\n");
    fwrite(STDERR, "[pre-push]   - O instalar Composer globalmente: https://getcomposer.org\n");
    fwrite(STDERR, "[pre-push]   - O saltar el hook para este push: git push --no-verify\n");
    exit(1);
}

// Banner
fwrite(STDOUT, "\n\033[36m[pre-push] Corriendo suite COMPLETA de tests (Feature + Unit)...\033[0m\n");
fwrite(STDOUT, "\033[36m[pre-push] (para solo los cross-tenant rapido: composer test:critical)\033[0m");
fwrite(STDOUT, "\033[36m[pre-push] (para E2E antes de release: pnpm e2e)\033[0m\n");
fwrite(STDOUT, "[pre-push] composer: {$composer}\n\n");

$start = microtime(true);
$cmd = escapeshellarg($composer) . ' test --no-interaction --no-ansi 2>&1';
$exitCode = 0;
passthru($cmd, $exitCode);
$elapsed = round(microtime(true) - $start, 1);

if ($exitCode !== 0) {
    fwrite(STDOUT, "\n\033[31m[pre-push] Tests fallaron en {$elapsed}s. Push BLOQUEADO.\033[0m\n");
    fwrite(STDOUT, "\033[33m[pre-push] Si los fallos son esperados/emergencia: git push --no-verify\033[0m\n");
    exit(1);
}

fwrite(STDOUT, "\n\033[32m[pre-push] Suite completa OK ({$elapsed}s). Push permitido.\033[0m\n");
exit(0);
