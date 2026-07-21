<?php

namespace App\Modules\Products\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Wrapper sobre GD nativo de PHP para generar las 3 variantes de una imagen.
 *
 * No usamos Intervention Image (no esta instalada) — solo GD que viene por default
 * con php8.4-gd. Soporte WebP verificado en el VPS nuevo: read YES, write YES.
 *
 * Si GD no soporta WebP en un entorno particular, fallback automatico a JPG (mismo
 * tamano / calidad). Asi la app sigue funcionando aunque falte la libreria del sistema.
 *
 * Las 3 variantes son siempre en el mismo formato (WebP preferido, JPG fallback):
 *  - original: lado max 2048px (mantiene aspect ratio). Sin crop.
 *  - medium:  800x800 cover crop (centrado, no distorsiona).
 *  - thumb:   200x200 cover crop.
 *
 * El crop "cover" resize al cuadrado mas chico que la imagen, luego crop centrado.
 */
class ImageProcessor
{
    public const MAX_INPUT_SIZE = 5 * 1024 * 1024; // 5 MB
    public const MAX_INPUT_DIMENSION = 4096;       // 4K
    public const ORIGINAL_MAX_SIDE = 2048;
    public const MEDIUM_SIZE = 800;
    public const THUMB_SIZE = 200;

    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Procesa un archivo subido y devuelve la metadata + paths donde escribir.
     * NO escribe en disco — eso lo hace `ProductImageService` con el resultado.
     *
     * @return array{
     *   sha256: string,
     *   source: array{width: int, height: int, mime: string, size: int},
     *   variants: array<string, array{width: int, height: int, mime: string, quality: int, gd_format: string}>
     * }
     */
    public function analyze(UploadedFile $file): array
    {
        $this->guardInput($file);

        $sha256 = hash_file('sha256', $file->getPathname());
        $imageInfo = @\getimagesize($file->getPathname());
        if ($imageInfo === false) {
            throw new RuntimeException('El archivo no es una imagen valida.');
        }

        [$width, $height] = $imageInfo;
        $mime = $file->getMimeType() ?: 'image/jpeg';
        $size = $file->getSize() ?: 0;

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException("Tipo de imagen no soportado: {$mime}. Usa JPG, PNG o WebP.");
        }

        $format = $this->preferredGdFormat();

        return [
            'sha256' => (string) $sha256,
            'source' => [
                'width' => $width,
                'height' => $height,
                'mime' => $mime,
                'size' => $size,
            ],
            'variants' => [
                self::VARIANT_KEY_ORIGINAL => [
                    'width' => self::ORIGINAL_MAX_SIDE,
                    'height' => self::ORIGINAL_MAX_SIDE,
                    'mime' => $format['mime'],
                    'quality' => 85,
                    'gd_format' => $format['gd_constant'],
                ],
                self::VARIANT_KEY_MEDIUM => [
                    'width' => self::MEDIUM_SIZE,
                    'height' => self::MEDIUM_SIZE,
                    'mime' => $format['mime'],
                    'quality' => 80,
                    'gd_format' => $format['gd_constant'],
                ],
                self::VARIANT_KEY_THUMB => [
                    'width' => self::THUMB_SIZE,
                    'height' => self::THUMB_SIZE,
                    'mime' => $format['mime'],
                    'quality' => 75,
                    'gd_format' => $format['gd_constant'],
                ],
            ],
        ];
    }

    /**
     * Genera las 3 variantes a partir de la imagen original.
     * Retorna un array de paths temporales en /tmp donde se escribieron los archivos.
     *
     * @param array $config Output de `analyze()`
     * @return array<string, array{path: string, width: int, height: int, mime: string, size: int}> key = 'original'|'medium'|'thumb'
     */
    public function generateVariants(UploadedFile $file, array $config, string $baseTmpPath): array
    {
        $sourceImage = $this->loadGd($file, $config['source']['mime']);
        if ($sourceImage === null) {
            throw new RuntimeException('GD no pudo cargar la imagen. Verifica que el formato este soportado.');
        }

        $written = [];

        try {
            foreach ($config['variants'] as $key => $variantConfig) {
                $resized = $this->resizeCover($sourceImage, $variantConfig['width'], $variantConfig['height']);
                $path = "{$baseTmpPath}.{$key}.{$this->extFor($variantConfig['gd_format'])}";

                $result = $this->writeGd(
                    $resized,
                    $path,
                    $variantConfig['gd_format'],
                    $variantConfig['quality']
                );

                if (! $result) {
                    throw new RuntimeException("GD no pudo escribir variante {$key} en {$path}.");
                }

                $size = filesize($path) ?: 0;
                [$w, $h] = \getimagesize($path) ?: [0, 0];

                $written[$key] = [
                    'path' => $path,
                    'mime' => $variantConfig['mime'],
                    'size' => $size,
                    'width' => $w,
                    'height' => $h,
                ];

                // imagedestroy($resized); // deprecated since PHP 8.5
            }
        } finally {
            // imagedestroy($sourceImage); // deprecated since PHP 8.5
        }

        return $written;
    }

    // ---- Keys constantes para variants array ----
    public const VARIANT_KEY_ORIGINAL = 'original';
    public const VARIANT_KEY_MEDIUM = 'medium';
    public const VARIANT_KEY_THUMB = 'thumb';

    // ---- Helpers privados ----

    private function guardInput(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_INPUT_SIZE) {
            throw new RuntimeException('La imagen excede el tamano maximo de 5 MB.');
        }
        $imageInfo = @\getimagesize($file->getPathname());
        if ($imageInfo === false) {
            throw new RuntimeException('El archivo no es una imagen valida.');
        }
        if ($imageInfo[0] > self::MAX_INPUT_DIMENSION || $imageInfo[1] > self::MAX_INPUT_DIMENSION) {
            throw new RuntimeException('La imagen excede la dimension maxima de '.self::MAX_INPUT_DIMENSION.'x'.self::MAX_INPUT_DIMENSION.'.');
        }
    }

    /**
     * Devuelve ['gd_constant' => IMAGETYPE_WEBP, 'mime' => 'image/webp'] si GD WebP esta disponible.
     * Fallback a JPG si no lo esta.
     *
     * @return array{gd_constant: int, mime: string, ext: string}
     */
    public function preferredGdFormat(): array
    {
        if (! \function_exists('imagetypes')) {
            throw new RuntimeException('La extension PHP GD no esta habilitada. Activa GD para procesar imagenes de productos.');
        }

        $webpInfo = @\imagetypes() & \IMG_WEBP;
        if ($webpInfo) {
            return ['gd_constant' => \IMAGETYPE_WEBP, 'mime' => 'image/webp', 'ext' => 'webp'];
        }

        return ['gd_constant' => \IMAGETYPE_JPEG, 'mime' => 'image/jpeg', 'ext' => 'jpg'];
    }

    private function extFor(int $gdConstant): string
    {
        return $gdConstant === \IMAGETYPE_WEBP ? 'webp' : 'jpg';
    }

    private function loadGd(UploadedFile $file, string $mime)
    {
        $path = $file->getPathname();
        return match ($mime) {
            'image/jpeg' => @\imagecreatefromjpeg($path),
            'image/png' => @\imagecreatefrompng($path),
            'image/webp' => @\imagecreatefromwebp($path),
            default => null,
        };
    }

    /**
     * @param resource $gdImage
     */
    private function writeGd($gdImage, string $destPath, int $gdFormat, int $quality): bool
    {
        if ($gdFormat === \IMAGETYPE_WEBP && \function_exists('imagewebp')) {
            return \imagewebp($gdImage, $destPath, $quality);
        }

        return \imagejpeg($gdImage, $destPath, $quality);
    }

    /**
     * Resize "cover": escala al cuadrado mas chico y crop centrado al tamano exacto.
     * Asi medium y thumb son siempre 800x800 y 200x200 sin distorsionar.
     *
     * @param resource $source
     * @return resource|false
     */
    private function resizeCover($source, int $targetW, int $targetH)
    {
        $srcW = \imagesx($source);
        $srcH = \imagesy($source);

        $scale = max($targetW / $srcW, $targetH / $srcH);
        $resizedW = (int) ceil($srcW * $scale);
        $resizedH = (int) ceil($srcH * $scale);

        $resized = \imagecreatetruecolor($resizedW, $resizedH);
        if ($resized === false) {
            return false;
        }

        \imagecopyresampled($resized, $source, 0, 0, 0, 0, $resizedW, $resizedH, $srcW, $srcH);

        $cropX = (int) (($resizedW - $targetW) / 2);
        $cropY = (int) (($resizedH - $targetH) / 2);

        $cropped = \imagecrop($resized, [
            'x' => $cropX,
            'y' => $cropY,
            'width' => $targetW,
            'height' => $targetH,
        ]);
        // imagedestroy($resized); // deprecated since PHP 8.5

        return $cropped;
    }
}
