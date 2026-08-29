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
        $text = ($ticket['doc'] ?? '') === 'report_z'
            ? $this->buildPlainReportZ($ticket)
            : $this->buildPlainTicket($ticket);
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
        $printerType = $station['printer_type'] ?? 'windows_printer';
        $printerName = $station['printer_name'] ?? null;

        if ($printerType !== 'network' && ! $printerName) {
            return [
                'ok' => false,
                'message' => 'Estacion sin printer_name. Configura la estacion con un nombre de impresora.',
            ];
        }

        $profile = $ticket['profile'] ?? [];
        $text = ($ticket['doc'] ?? '') === 'report_z'
            ? $this->buildPlainReportZ($ticket)
            : $this->buildPlainTicket($ticket);

        $result = app(ThermalPrinterService::class)->print($text, $printerName, [
            'printer_type' => $printerType,
            'network_host' => $station['network_host'] ?? null,
            'network_port' => (int) ($station['network_port'] ?? ThermalPrinterService::PORT_9100),
            'cut_paper' => (bool) ($profile['cut_paper'] ?? false),
            'open_cash_drawer' => (bool) ($profile['open_cash_drawer'] ?? false),
        ]);

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => $result['message'] ?? 'No se pudo imprimir.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'printed',
            'message' => $result['message'] ?? 'Impreso.',
            'printer' => $result['printer'] ?? ($printerName ?? 'red'),
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

    public function buildPlainTicket(array $ticket): string
    {
        $profile = $ticket['profile'] ?? [];
        $width = (int) ($profile['paper_width_mm'] ?? 80);
        $max = $width === 58 ? 32 : 48;
        $money = static fn (float $value): string => '$'.number_format($value, 2, '.', '');

        $lines = [];

        // Encabezado: logo / nombre del tenant.
        $header = (string) ($profile['logo_text'] ?? '');
        if ($header === '') {
            $header = (string) ($ticket['tenant']['name'] ?? '');
        }
        if ($header !== '') {
            $lines[] = strtoupper($header);
        }
        $headerText = (string) ($profile['header_text'] ?? '');
        if ($headerText !== '') {
            $lines[] = $headerText;
        }
        if (($profile['show_tenant_slug'] ?? true) && ! empty($ticket['tenant']['slug'])) {
            $lines[] = $ticket['tenant']['slug'];
        }

        $lines[] = sprintf('Ticket POS #%s', $ticket['pos_order']['id'] ?? '?');
        if (($profile['show_sale_number'] ?? true) && ! empty($ticket['pos_order']['sale_id'])) {
            $lines[] = 'Venta #'.$ticket['pos_order']['sale_id'];
        }
        if (($profile['show_paid_at'] ?? true) && ! empty($ticket['pos_order']['paid_at'])) {
            $lines[] = 'Fecha: '.$ticket['pos_order']['paid_at'];
        }
        if (($profile['show_cashier'] ?? true) && ! empty($ticket['pos_order']['cashier_name'])) {
            $lines[] = 'Cajero: '.$ticket['pos_order']['cashier_name'];
        }
        if (($profile['show_cash_register'] ?? true) && ! empty($ticket['pos_order']['cash_register_name'])) {
            $lines[] = 'Caja: '.$ticket['pos_order']['cash_register_name'];
        }
        if (($profile['show_branch'] ?? true) && ! empty($ticket['pos_order']['branch_name'])) {
            $lines[] = 'Sucursal: '.$ticket['pos_order']['branch_name'];
        }
        if (($profile['show_customer'] ?? true)) {
            $lines[] = 'Cliente: '.($ticket['pos_order']['customer_name'] ?? 'Consumidor Final');
        }

        if (! empty($ticket['promotions'])) {
            $lines[] = str_repeat('-', $max);
            $lines[] = 'PROMOCIONES';
            foreach ($ticket['promotions'] as $promotion) {
                $lines[] = ($promotion['label'] ?? 'PROMOCION').': '.($promotion['promotion_name'] ?? 'Promoción');
            }
        }

        $lines[] = str_repeat('-', $max);
        foreach ($ticket['items'] ?? [] as $item) {
            $lines[] = $item['product_name'] ?? 'Producto';
            if (($profile['show_item_sku'] ?? true) && ! empty($item['sku'])) {
                $lines[] = '  '.$item['sku'];
            }
            foreach ($item['promotion_labels'] ?? [] as $promotionLabel) {
                $lines[] = '  '.$promotionLabel;
            }
            $unit = (float) ($item['unit_price'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $total = (float) ($item['total'] ?? 0);
            $lines[] = sprintf('  %s x %s = %s', $qty, $money($unit), $money($total));
            if (($profile['show_item_discount'] ?? true) && (float) ($item['discount'] ?? 0) > 0) {
                $lines[] = '  Desc: '.$money((float) $item['discount']);
            }
            if ($profile['show_item_serials'] ?? true) {
                foreach ($item['serials'] ?? [] as $serial) {
                    $lines[] = '  IMEI/Serial: '.($serial['serial_number'] ?? '');
                }
            }
            if (($profile['show_warranty_summary'] ?? true) && ! empty($item['warranty']['name'])) {
                $w = $item['warranty'];
                $line = '  Garantia: '.($w['name'] ?? '');
                if (! empty($w['duration_days'])) {
                    $line .= ' - '.$w['duration_days'].' dias';
                }
                if (! empty($w['expires_at'])) {
                    $line .= ' - vence '.$w['expires_at'];
                }
                $lines[] = $line;
            }
        }

        $lines[] = str_repeat('-', $max);
        $lines[] = 'Total USD: '.$money((float) ($ticket['totals']['total_base_amount'] ?? 0));
        if ($profile['show_total_local'] ?? true) {
            $lines[] = 'Total VES: Bs '.number_format((float) ($ticket['totals']['total_local_amount'] ?? 0), 2, ',', '.');
        }
        $lines[] = 'Pagado USD: '.$money((float) ($ticket['totals']['paid_base_amount'] ?? 0));
        if (($profile['show_receivable_balance'] ?? true) && (float) ($ticket['totals']['balance_base_amount'] ?? 0) > 0) {
            $lines[] = 'Saldo CxC: '.$money((float) $ticket['totals']['balance_base_amount']);
        }

        $lines[] = str_repeat('-', $max);
        foreach ($ticket['payments'] ?? [] as $payment) {
            $method = (string) ($payment['method'] ?? '');
            $currency = (string) ($payment['currency'] ?? 'USD');
            $amount = (float) ($payment['amount'] ?? 0);
            $lines[] = $method.' '.$currency.': '.($currency === 'VES' ? 'Bs '.number_format($amount, 2, ',', '.') : $money($amount));
            if (($profile['show_payment_rate'] ?? true) && ! empty($payment['exchange_rate'])) {
                $lines[] = '  '.($payment['exchange_rate_type_code'] ?? '').' @ '.number_format((float) $payment['exchange_rate'], 2, '.', '');
            }
            if (($profile['show_payment_reference'] ?? true) && ! empty($payment['reference'])) {
                $lines[] = '  Ref: '.$payment['reference'];
            }
        }

        $warrantyText = (string) ($profile['warranty_policy_text'] ?? '');
        if ($warrantyText !== '') {
            $lines[] = str_repeat('-', $max);
            $lines[] = $warrantyText;
        }

        $footer = (string) ($profile['footer_text'] ?? '');
        if ($footer !== '') {
            $lines[] = str_repeat('-', $max);
            $lines[] = $footer;
        }
        if ($profile['show_non_fiscal_text'] ?? true) {
            $lines[] = (string) ($profile['legal_text'] ?? 'Documento no fiscal');
        }

        // Ajustar cada linea al ancho del papel (32 chars en 58mm, 48 en 80mm).
        $lines = array_map(static function (string $line) use ($max): string {
            if (mb_strlen($line) <= $max) {
                return $line;
            }

            return mb_substr($line, 0, max(1, $max - 3)).'...';
        }, $lines);

        return implode("\n", $lines);
    }

    /**
     * Renderiza el Reporte Z en texto plano para impresion termica.
     * El payload llega con `doc = 'report_z'` y los datos del Z.
     */
    public function buildPlainReportZ(array $ticket): string
    {
        $profile = $ticket['profile'] ?? [];
        $width = (int) ($profile['paper_width_mm'] ?? 58);
        $max = $width === 58 ? 32 : 48;
        $money = static fn (float $value): string => '$'.number_format($value, 2, '.', '');
        $bs = static fn (float $value): string => 'Bs '.number_format($value, 2, ',', '.');
        $dt = static function (?string $value): string {
            if (! $value) {
                return '-';
            }

            return date('d/m/Y H:i', strtotime($value));
        };

        $lines = [];

        $header = (string) ($profile['logo_text'] ?? '');
        if ($header === '') {
            $header = (string) ($ticket['tenant']['name'] ?? '');
        }
        if ($header !== '') {
            $lines[] = strtoupper($header);
        }
        $lines[] = 'REPORTE Z';
        $lines[] = 'Z #'.($ticket['z_number'] ?? '?');
        $lines[] = str_repeat('-', $max);
        $lines[] = 'Caja: '.($ticket['cash_register'] ?? '-');
        $lines[] = 'Sucursal: '.($ticket['branch'] ?? '-');
        $lines[] = 'Cajero: '.($ticket['cashier'] ?? '-');
        $lines[] = 'Apertura: '.$dt($ticket['opened_at'] ?? null);
        $lines[] = 'Cierre: '.$dt($ticket['closed_at'] ?? null);

        $totals = $ticket['totals'] ?? [];
        $lines[] = str_repeat('-', $max);
        $lines[] = 'Tickets: '.(int) ($totals['orders_count'] ?? 0);
        $lines[] = 'Total USD: '.$money((float) ($totals['paid_base_amount'] ?? 0));
        $lines[] = 'Total VES: '.$bs((float) ($totals['paid_local_amount'] ?? 0));

        $lines[] = str_repeat('-', $max);
        foreach ($ticket['payments'] ?? [] as $payment) {
            $currency = (string) ($payment['currency'] ?? 'USD');
            $amount = $currency === 'VES'
                ? $bs((float) ($payment['amount_local'] ?? 0))
                : $money((float) ($payment['amount_base'] ?? 0));
            $lines[] = ($payment['name'] ?? $payment['method'] ?? 'Pago').': '.$amount;
            if (! empty($payment['exchange_rate'])) {
                $lines[] = '  tasa @ '.number_format((float) $payment['exchange_rate'], 2, '.', '');
            }
        }
        if (empty($ticket['payments'])) {
            $lines[] = 'Sin pagos registrados.';
        }

        $lines[] = str_repeat('-', $max);
        $lines[] = 'Dif efectivo USD: '.$money((float) ($totals['difference_cash_usd'] ?? 0));
        $lines[] = 'Dif efectivo VES: '.$bs((float) ($totals['difference_cash_ves'] ?? 0));

        $footer = (string) ($profile['footer_text'] ?? '');
        if ($footer !== '') {
            $lines[] = str_repeat('-', $max);
            $lines[] = $footer;
        }
        if ($profile['show_non_fiscal_text'] ?? true) {
            $lines[] = (string) ($profile['legal_text'] ?? 'Documento no fiscal');
        }

        $lines = array_map(static function (string $line) use ($max): string {
            if (mb_strlen($line) <= $max) {
                return $line;
            }

            return mb_substr($line, 0, max(1, $max - 3)).'...';
        }, $lines);

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
