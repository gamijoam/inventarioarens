<?php

namespace App\Modules\Printing\Services;

use RuntimeException;

/**
 * ThermalPrinterService - envio de tickets a impresora termica.
 *
 * Cross-platform (Windows + Linux). Soporta dos modos:
 *
 *  1) driver (default): Windows usa `print /D:"<printer>"` o PowerShell
 *     `Out-Printer` (para nombres con espacios); Linux usa `lpr -P <printer>`
 *     (CUPS). Envia texto plano ASCII seguro.
 *
 *  2) network (ESC/POS raw por TCP 9100): abre un socket hacia
 *     network_host:network_port y envia el ticket con comandos ESC/POS
 *     opcionales: corte de papel (GS V) y apertura de gaveta (ESC p).
 *
 * El texto enviado es ASCII seguro (sin tildes), longitud <= 64 chars por
 * linea (compatibilidad 80mm generica).
 */
class ThermalPrinterService
{
    public const PORT_9100 = 9100;

    /**
     * @param  array{printer_type?:string, network_host?:string|null, network_port?:int, cut_paper?:bool, open_cash_drawer?:bool}  $options
     */
    public function print(string $text, ?string $printerName = null, array $options = []): array
    {
        $printerType = $options['printer_type'] ?? 'windows_printer';
        $cutPaper = (bool) ($options['cut_paper'] ?? false);
        $openDrawer = (bool) ($options['open_cash_drawer'] ?? false);

        if ($printerType === 'network') {
            return $this->printNetwork($text, $options, $cutPaper, $openDrawer);
        }

        return $this->printDriver($text, $printerName);
    }

    /**
     * Envia por el driver del SO (Windows print / PowerShell, Linux lpr/lp).
     */
    private function printDriver(string $text, ?string $printerName): array
    {
        if (! $printerName) {
            return [
                'ok' => false,
                'message' => 'Estacion sin printer_name. Configura la estacion con un nombre de impresora.',
            ];
        }

        $clean = $this->sanitize($text);
        $tmpFile = tempnam(sys_get_temp_dir(), 'invtkt_');
        file_put_contents($tmpFile, $clean);
        try {
            $cmd = $this->buildCommand($tmpFile, $printerName);
            $output = [];
            $rc = 0;
            exec($cmd.' 2>&1', $output, $rc);
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
     * Envia por red directa (raw TCP 9100) con comandos ESC/POS opcionales.
     *
     * @param  array{network_host?:string|null, network_port?:int}  $options
     */
    private function printNetwork(string $text, array $options, bool $cutPaper, bool $openDrawer): array
    {
        $host = $options['network_host'] ?? '';
        $port = (int) ($options['network_port'] ?? self::PORT_9100);

        if (! $host) {
            return [
                'ok' => false,
                'message' => 'Estacion de red sin network_host. Configura la IP de la impresora.',
            ];
        }

        $buffer = $this->buildEscPos($this->sanitize($text), $cutPaper, $openDrawer);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if ($socket === false) {
            return [
                'ok' => false,
                'message' => "No se pudo conectar a {$host}:{$port} (errno={$errno}: {$errstr})",
            ];
        }

        try {
            $written = fwrite($socket, $buffer);
            if ($written === false || $written !== strlen($buffer)) {
                return [
                    'ok' => false,
                    'message' => "Envio incompleto a {$host}:{$port} ({$written}/".strlen($buffer).' bytes).',
                ];
            }
            fflush($socket);
            usleep(150_000); // esperar a que la impresora procese antes de cerrar.

            return [
                'ok' => true,
                'message' => "Enviado a red {$host}:{$port}",
                'printer' => "{$host}:{$port}",
                'bytes' => $written,
            ];
        } finally {
            fclose($socket);
        }
    }

    /**
     * Construye el buffer ESC/POS para impresora termica de red.
     *
     *  - openDrawer: ESC p 0 25 250 (pin 2, EPSON estandar).
     *  - texto plano del ticket.
     *  - cutPaper: GS V 0 (corte de papel completo).
     */
    public function buildEscPos(string $text, bool $cutPaper = false, bool $openDrawer = false): string
    {
        $buffer = '';

        if ($openDrawer) {
            $buffer .= "\x1B\x70\x00\x19\xFA"; // ESC p 0 25 250 -> pin 2.
        }

        $buffer .= $text;

        if ($cutPaper) {
            $buffer .= "\x1D\x56\x00"; // GS V 0 -> cut paper full.
        }

        return $buffer;
    }

    /**
     * Devuelve el comando segun el OS.
     *  - Windows: PowerShell Out-Printer (soporta nombres con espacios);
     *    fallback `print /D:` si PowerShell no esta disponible.
     *  - Linux:   lpr -P <printer> <file>  (con fallback a lp)
     */
    public function buildCommand(string $file, string $printer): string
    {
        $file = $this->escapePath($file);
        $printer = $this->escapeArg($printer);
        if ($this->isWindows()) {
            if ($this->commandExists('powershell')) {
                return sprintf('powershell -NoProfile -NonInteractive -Command "Get-Content -LiteralPath %s -Raw | Out-Printer -Name %s"', $file, $printer);
            }

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
        if ($this->isWindows()) {
            $rc = $this->windowsCommandExists($cmd);

            return $rc === 0;
        }
        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($cmd)), $out, $rc);

        return $rc === 0;
    }

    private function windowsCommandExists(string $cmd): int
    {
        $out = [];
        $rc = 0;
        exec(sprintf('where %s 2>nul', escapeshellarg($cmd)), $out, $rc);

        return $rc;
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
    public function sanitize(string $text): string
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
                $clean = substr($clean, 0, 61).'...';
            }
            $out .= $clean."\n";
        }

        return $out;
    }
}
