<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class ImageOptimizer
{
    /**
     * Optimize an image file in-place or to a target destination.
     */
    public static function optimize(string $filePath, int $maxWidth = 1200, int $quality = 80): bool
    {
        if (! file_exists($filePath)) {
            return false;
        }

        $bin = base_path('tools/image-optimizer/bin/image-optimizer');
        if (PHP_OS_FAMILY === 'Windows') {
            $bin .= '.exe';
        }

        if (! file_exists($bin)) {
            return false;
        }

        try {
            $process = Process::run([
                $bin,
                "-input={$filePath}",
                "-max-width={$maxWidth}",
                "-quality={$quality}",
            ]);

            return $process->successful();
        } catch (Throwable $e) {
            Log::warning('[ImageOptimizer] Failed to optimize image', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
