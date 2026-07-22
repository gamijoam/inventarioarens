<?php

namespace App\Modules\Printing\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PrinterServer - servidor HTTP de larga duracion para el agente de impresion.
 *
 * Reemplaza al thermal-printer-agent.ps1 (Windows-only) por una version
 * cross-platform (Linux + Windows) que corre via `php artisan printer:serve`.
 *
 * API expuesta (mismo contrato que el legacy .ps1):
 *   GET  /health   -> 200 {ok: true, service: '...', port: N}
 *   POST /print    -> 200 {ok: true, status: 'printed'|'generated', ...}
 *                  500 en error de payload / impresion
 *   OPTIONS        -> 204 (CORS preflight)
 *   otro path      -> 404
 *
 * Single-threaded: suficiente para printing de bajo trafico (POS genera
 * 1 ticket cada N segundos en horario comercial). Si en el futuro
 * hace falta paralelismo, cambiar a ReactPHP/swoole (mismo Handler).
 *
 * No usa frameworks externos. Solo PHP built-in (socket_* + fread/fwrite)
 * y Laravel para el routing del payload (no es necesario, parseamos JSON
 * con json_decode que es estable).
 */
class PrinterServer
{
    private bool $running = false;

    /**
     * Inicia el servidor en el puerto indicado. Bloquea hasta que
     * reciba SIGTERM/SIGINT (o el proceso sea matado).
     */
    public function run(int $port, string $bind = '127.0.0.1', int $maxRequests = 0): void
    {
        $socket = $this->bind($bind, $port);
        $count = 0;

        $this->running = true;
        // Registrar senales para shutdown limpio.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use ($socket) {
                $this->shutdown($socket);
            });
            pcntl_signal(SIGINT, function () use ($socket) {
                $this->shutdown($socket);
            });
        }

        Log::info('printer.server.started', ['port' => $port, 'bind' => $bind]);

        while ($this->running) {
            $client = @stream_socket_accept($socket, 1.0);
            if ($client === false) {
                if ($maxRequests > 0 && $count >= $maxRequests) {
                    break;
                }

                continue;
            }
            $this->handleConnection($client, $port);
            $count++;
            if ($maxRequests > 0 && $count >= $maxRequests) {
                break;
            }
        }

        @fclose($socket);
        Log::info('printer.server.stopped', ['requests_handled' => $count]);
    }

    /**
     * Une socket en el host:puerto pedido. Lanza excepcion si falla.
     */
    private function bind(string $host, int $port)
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($socket === false) {
            throw new RuntimeException("No se puede bind a {$host}:{$port} (errno={$errno}: {$errstr})");
        }

        return $socket;
    }

    /**
     * Maneja una conexion entrante: lee el request, dispatch al handler,
     * escribe la response, cierra el socket.
     */
    private function handleConnection($client, int $serverPort): void
    {
        $raw = $this->readRequest($client);
        if ($raw === null) {
            @fclose($client);

            return;
        }
        $response = $this->dispatch($raw, $serverPort);
        $this->writeResponse($client, $response);
        @fclose($client);
    }

    /**
     * Lee el request HTTP crudo hasta el fin de los headers + body.
     */
    private function readRequest($client): ?string
    {
        $requestLine = trim((string) fgets($client, 1024));
        if ($requestLine === '') {
            return null;
        }
        $parts = explode(' ', $requestLine, 3);
        if (count($parts) < 3) {
            return null;
        }
        [$method, $path, $_] = $parts;

        $headers = [];
        $contentLength = 0;
        while (($line = fgets($client, 1024)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
                $contentLength = (int) $m[1];
            }
            $headers[] = $line;
        }

        $body = '';
        if ($contentLength > 0) {
            $body = fread($client, $contentLength);
        }

        return json_encode([
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => $body,
        ]);
    }

    /**
     * Despacha el request al handler segun ruta + metodo.
     * Devuelve [status, body_json].
     */
    private function dispatch(string $raw, int $port): array
    {
        $req = json_decode($raw, true);
        $method = $req['method'] ?? '';
        $path = $req['path'] ?? '';
        $body = (string) ($req['body'] ?? '');

        // CORS preflight.
        if ($method === 'OPTIONS') {
            return [204, ['ok' => true]];
        }
        // Health.
        if ($method === 'GET' && $path === '/health') {
            return [200, [
                'ok' => true,
                'service' => 'inventarioarens-printer-agent',
                'port' => $port,
            ]];
        }
        // Print.
        if ($method === 'POST' && $path === '/print') {
            try {
                $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                $result = $this->handlePrint($payload);
                $status = 200;
            } catch (\JsonException $e) {
                $result = ['ok' => false, 'message' => 'JSON invalido: '.$e->getMessage()];
                $status = 400;
            } catch (\Throwable $e) {
                Log::error('printer.server.handle_print_error', ['error' => $e->getMessage()]);
                $result = ['ok' => false, 'message' => $e->getMessage()];
                $status = 500;
            }

            return [$status, $result];
        }

        return [404, ['ok' => false, 'message' => 'Ruta no encontrada.']];
    }

    /**
     * Despacha /print a digital o thermal segun el payload.
     */
    private function handlePrint(array $payload): array
    {
        $output = $payload['output'] ?? 'digital';
        $station = $payload['station'] ?? [];
        $ticket = $payload['payload'] ?? [];
        $jobId = (string) ($payload['job_id'] ?? uniqid('job_', true));

        if ($output === 'digital') {
            return $this->saveDigital(
                $ticket,
                $station,
                $jobId,
                (bool) ($payload['copy'] ?? false),
                $payload['pdf_base64'] ?? null
            );
        }
        if ($output === 'thermal') {
            return $this->printThermal($ticket, $station, $jobId);
        }

        return ['ok' => false, 'message' => "output invalido: {$output}"];
    }

    private function saveDigital(array $ticket, array $station, string $jobId, bool $copy, ?string $pdfBase64 = null): array
    {
        $baseDir = $this->resolveDigitalDir($station['digital_directory'] ?? null);
        if (! is_dir($baseDir) && ! mkdir($baseDir, 0775, true) && ! is_dir($baseDir)) {
            throw new RuntimeException("No se pudo crear la carpeta digital: {$baseDir}");
        }
        $slug = $ticket['tenant']['slug'] ?? 'tenant';
        $orderId = $ticket['pos_order']['id'] ?? $jobId;
        $suffix = $copy ? 'copy' : 'original';
        $stamp = date('Ymd-His');
        $fileBase = sprintf('%s/Ticket-%s-%s-%s-%s', rtrim($baseDir, '/'), $slug, $orderId, $stamp, $suffix);

        // Si el cliente mando pdf_base64, lo guardamos; si no, generamos
        // una vista de texto (compatibilidad con estaciones que mandan
        // PDF via API pero este agente recibe el raw).
        if (! empty($pdfBase64)) {
            $path = $fileBase.'.pdf';
            $decoded = base64_decode($pdfBase64, true);
            if ($decoded === false) {
                throw new RuntimeException('pdf_base64 invalido.');
            }
            file_put_contents($path, $decoded);

            return ['status' => 'generated', 'pdf_path' => $path];
        }

        // Fallback de texto (para estaciones que mandan solo ticket en JSON).
        $text = $this->buildPlainTicket($ticket);
        $path = $fileBase.'.txt';
        file_put_contents($path, $text);

        return [
            'status' => 'generated',
            'pdf_path' => $path,
            'message' => 'PDF no recibido; se guardo texto de respaldo.',
        ];
    }

    private function printThermal(array $ticket, array $station, string $jobId): array
    {
        $printerName = $station['printer_name'] ?? null;
        if (! $printerName) {
            return [
                'ok' => false,
                'message' => 'Estacion sin printer_name. Configura la estacion con un nombre de impresora.',
            ];
        }

        $text = $this->buildPlainTicket($ticket);
        $result = app(ThermalPrinterService::class)->print($text, $printerName);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => $result['message'] ?? 'No se pudo imprimir.',
            ];
        }

        return [
            'status' => 'printed',
            'message' => $result['message'] ?? "Enviado a {$printerName}",
        ];
    }

    private function resolveDigitalDir(?string $requested): string
    {
        $home = $_SERVER['HOME'] ?? sys_get_temp_dir();
        if (! $requested || $requested === '') {
            return $home.'/Desktop/Tickets';
        }
        // Soportar paths absolutos y paths relativos a HOME/USERPROFILE.
        if ($requested[0] === '/') {
            return $requested;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $requested) === 1) {
                return $requested;
            }
            $up = $_SERVER['USERPROFILE'] ?? $home;
            $path = $up.'\\'.str_replace('/', '\\', $requested);
            if (is_dir($path)) {
                return $path;
            }
        }

        return $home.'/'.$requested;
    }

    private function buildPlainTicket(array $ticket): string
    {
        $lines = [];
        $lines[] = $ticket['tenant']['name'] ?? 'INVENTARIOARENS';
        $lines[] = sprintf('Ticket POS #%s', $ticket['pos_order']['id'] ?? '?');
        $lines[] = 'Cliente: '.($ticket['pos_order']['customer_name'] ?? 'Consumidor Final');
        $lines[] = str_repeat('-', 48);
        foreach ($ticket['items'] ?? [] as $item) {
            $lines[] = $item['product_name'] ?? 'Producto';
            $unit = (float) ($item['unit_price'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $total = (float) ($item['total'] ?? 0);
            $lines[] = sprintf('  %s x %s = %s', $qty, number_format($unit, 2, '.', ''), number_format($total, 2, '.', ''));
            foreach ($item['serials'] ?? [] as $serial) {
                $lines[] = '  IMEI/Serial: '.($serial['serial_number'] ?? '');
            }
        }
        $lines[] = str_repeat('-', 48);
        $lines[] = 'Total USD: '.number_format((float) ($ticket['totals']['total_base_amount'] ?? 0), 2, '.', '');
        $lines[] = 'Pagado USD: '.number_format((float) ($ticket['totals']['paid_base_amount'] ?? 0), 2, '.', '');

        return implode("\n", $lines);
    }

    /**
     * Escribe la response HTTP con headers CORS + JSON.
     */
    private function writeResponse($client, array $response): void
    {
        [$status, $body] = $response;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = [
            'HTTP/1.1 '.$status.' '.$this->statusText($status),
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: '.strlen($json),
            'Access-Control-Allow-Origin: *',
            'Access-Control-Allow-Methods: GET, POST, OPTIONS',
            'Access-Control-Allow-Headers: Content-Type',
            'Access-Control-Max-Age: 86400',
        ];
        foreach ($headers as $h) {
            fwrite($client, $h."\r\n");
        }
        fwrite($client, "\r\n");
        fwrite($client, $json);
    }

    private function statusText(int $code): string
    {
        return [
            200 => 'OK',
            204 => 'No Content',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ][$code] ?? 'OK';
    }

    private function shutdown($socket): void
    {
        $this->running = false;
        @fclose($socket);
    }
}
