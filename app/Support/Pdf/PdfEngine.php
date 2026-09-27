<?php

namespace App\Support\Pdf;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class PdfEngine
{
    /**
     * Generate a thermal receipt ticket PDF.
     */
    public static function generateTicket(array $data, ?string $outputPath = null): string|false
    {
        return self::execute('ticket', $data, $outputPath);
    }

    /**
     * Generate an invoice PDF.
     */
    public static function generateInvoice(array $data, ?string $outputPath = null): string|false
    {
        return self::execute('invoice', $data, $outputPath);
    }

    private static function execute(string $type, array $data, ?string $outputPath = null): string|false
    {
        $bin = base_path('tools/pdf-engine/bin/pdf-engine');
        if (PHP_OS_FAMILY === 'Windows') {
            $bin .= '.exe';
        }

        if (! file_exists($bin)) {
            Log::warning('[PdfEngine] Binary not found', ['bin' => $bin]);

            return false;
        }

        $tmpJson = tempnam(sys_get_temp_dir(), 'pdf_data_').'.json';
        file_put_contents($tmpJson, json_encode($data, JSON_UNESCAPED_UNICODE));

        $targetPdf = $outputPath ?: (tempnam(sys_get_temp_dir(), 'pdf_out_').'.pdf');

        try {
            $process = Process::run([
                $bin,
                "-type={$type}",
                "-input={$tmpJson}",
                "-output={$targetPdf}",
            ]);

            if (! $process->successful() || ! file_exists($targetPdf)) {
                Log::warning('[PdfEngine] Process failed', [
                    'exit' => $process->exitCode(),
                    'error' => $process->errorOutput(),
                ]);

                return false;
            }

            return $targetPdf;
        } catch (Throwable $e) {
            Log::warning('[PdfEngine] Exception while running pdf-engine', ['error' => $e->getMessage()]);

            return false;
        } finally {
            @unlink($tmpJson);
        }
    }
}
