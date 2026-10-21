<?php

namespace App\Support\Barcode;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class BarcodeEngine
{
    /**
     * Generate a Code128 barcode PNG.
     */
    public static function generateCode128(string $text, ?string $outputPath = null, int $width = 300, int $height = 100): string|false
    {
        return self::execute('code128', $text, $outputPath, $width, $height);
    }

    /**
     * Generate a QR Code PNG.
     */
    public static function generateQR(string $content, ?string $outputPath = null, int $size = 200): string|false
    {
        return self::execute('qr', $content, $outputPath, $size, $size);
    }

    private static function execute(string $type, string $text, ?string $outputPath = null, int $width = 300, int $height = 100): string|false
    {
        $bin = base_path('tools/barcode-engine/bin/barcode-engine');
        if (PHP_OS_FAMILY === 'Windows') {
            $bin .= '.exe';
        }

        if (! file_exists($bin)) {
            Log::warning('[BarcodeEngine] Binary not found', ['bin' => $bin]);

            return false;
        }

        $targetFile = $outputPath ?: (tempnam(sys_get_temp_dir(), 'bc_out_').'.png');

        try {
            $process = Process::run([
                $bin,
                "-type={$type}",
                "-text={$text}",
                "-output={$targetFile}",
                "-width={$width}",
                "-height={$height}",
            ]);

            if (! $process->successful() || ! file_exists($targetFile)) {
                Log::warning('[BarcodeEngine] Process failed', [
                    'exit' => $process->exitCode(),
                    'error' => $process->errorOutput(),
                ]);

                return false;
            }

            return $targetFile;
        } catch (Throwable $e) {
            Log::warning('[BarcodeEngine] Exception while running barcode-engine', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
