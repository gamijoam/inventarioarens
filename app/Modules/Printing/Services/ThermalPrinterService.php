<?php

namespace App\Modules\Printing\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ThermalPrinterService - envio de tickets a impresora termica.
 *
 * Cross-platform (Windows + Linux). Reemplaza la logica Out-Printer
 * de PowerShell del legacy thermal-printer-agent.ps1.
 *
 *  - Windows: usa `Get-Content | Out-Printer -Name xxx` (via shell) o
 *    `print /D:\\\\127.0.0.1\\<printer> file.txt` para impresoras de red.
 *  - Linux: usa `lpr -P <printer>` (CUPS) o `lp -d <printer>`. Si no hay
 *    CUPS configurado, devuelve error claro.
 *  - Fallback: si no hay printer_name, NO imprime; devuelve error
 *    sugiriendo configurar la estacion.
 *
 * El texto enviado es ASCII seguro (sin tildes), longitud <= 64 chars
 * por linea (compatibilidad 80mm generica).
 */
class ThermalPrinterService
{
    public function print(string $text, ?string $printerName = null): array
    {
        if (! $printerName) {
            return [
                'ok' => false,
                'message' => 'Estacion sin printer_name. Configura la estacion con un nombre de impresora.',
            ];
        }

        // Sanear texto: solo ASCII printable + saltos de linea. Quitar tildes
        // y caracteres que Win32 printer drivers suelen romper.
        $clean = $this->sanitize($text);
        $tmpFile = tempnam(sys_get_temp_dir(), 'invtkt_');
        file_put_contents($tmpFile, $clean);
        try {
            $cmd = $this->buildCommand($tmpFile, $printerName);
            $output = [];
            $rc = 0;
            exec($cmd . ' 2>&1', $output, $rc);
            $msg = trim(implode("\n", $output));
            if ($rc !== 0) {
                return [
                    'ok' => false,
                    'message' => $msg ?: "Fallo al imprimir (rc={$rc})",
                ];
            }
            return [
                'ok' => true,
                'message' => "Enviado a {$printerName}",
                'printer' => $printerName,
            ];
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Devuelve el comando segun el OS.
     *  - Windows: print /D:<printer> <file>
     *  - Linux:   lpr -P <printer> <file>  (con fallback a lp)
     */
    private function buildCommand(string $file, string $printer): string
    {
        $file = $this->escapePath($file);
        $printer = $this->escapeArg($printer);
        if ($this->isWindows()) {
            // print /D acepta UNC \\server\printer o nombre local.
            return sprintf('print /D:"%s" "%s"', $printer, $file);
        }
        // Linux: lpr es lo standard (CUPS). lp es un alias con la misma sintaxis.
        if ($this->commandExists('lpr')) {
            return sprintf('lpr -P %s %s', $printer, $file);
        }
        if ($this->commandExists('lp')) {
            return sprintf('lp -d %s %s', $printer, $file);
        }
        throw new RuntimeException(
            'lpr/lp no encontrado. Instala CUPS (apt install cups-bsd lpr) o configura una impresora termica de red.'
        );
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private function commandExists(string $cmd): bool
    {
        $out = [];
        $rc = 0;
        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($cmd)), $out, $rc);

        return $rc === 0;
    }

    private function escapePath(string $path): string
    {
        return $this->isWindows()
            ? str_replace('"', '\\"', $path)
            : escapeshellarg($path);
    }

    private function escapeArg(string $arg): string
    {
        return $this->isWindows()
            ? str_replace('"', '\\"', $arg)
            : escapeshellarg($arg);
    }

    /**
     * Sanitiza el texto para impresoras termicas genericas:
     *  - Solo printable ASCII (32-126) + tab + LF.
     *  - Reemplaza tildes/acentos comunes por su equivalente ASCII.
     *  - Limita cada linea a 64 chars (compatibilidad 80mm generica).
     *  - Sufijo "..." si se trunca.
     */
    private function sanitize(string $text): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
            'ü' => 'u', 'Ü' => 'U', 'ç' => 'c', 'Ç' => 'C',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            '¿' => '?', '¡' => '!', '€' => 'EUR', '—' => '-', '–' => '-',
        ];
        $text = strtr($text, $map);

        $out = '';
        foreach (explode("\n", $text) as $line) {
            // Quitar caracteres de control que no sean \t.
            $clean = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $line) ?? $line;
            // Limitar longitud.
            if (function_exists('mb_strimwidth') && mb_detect_encoding($clean) !== 'ASCII') {
                if (mb_strlen($clean) > 64) {
                    $clean = mb_strimwidth($clean, 0, 61, '...');
                }
            } elseif (strlen($clean) > 64) {
                $clean = substr($clean, 0, 61) . '...';
            }
            $out .= $clean . "\n";
        }

        return $out;
    }
}
