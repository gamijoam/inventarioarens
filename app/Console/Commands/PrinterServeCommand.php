<?php

namespace App\Console\Commands;

use App\Modules\Printing\Services\PrinterServer;
use Illuminate\Console\Command;

class PrinterServeCommand extends Command
{
    protected $signature = 'printer:serve
        {--port=17777 : Puerto TCP donde escucha el agente de impresion}
        {--bind=127.0.0.1 : IP donde escucha (default loopback; cambiar a 0.0.0.0 solo si se quiere exponer afuera)}
        {--max-requests=0 : Salir despues de N requests (0 = infinito, util para tests)}';

    protected $description = 'Inicia el agente HTTP de impresion (sustituye al thermal-printer-agent.ps1).';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $bind = (string) $this->option('bind');
        $maxRequests = (int) $this->option('max-requests');

        // Sanity check: puerto valido y libre.
        if ($port < 1024 || $port > 65535) {
            $this->error("Puerto invalido: {$port} (usa 1024-65535).");

            return self::FAILURE;
        }

        $this->info("Iniciando agente de impresion en {$bind}:{$port}");

        try {
            app(PrinterServer::class)->run($port, $bind, $maxRequests);
        } catch (\Throwable $e) {
            $this->error('No se pudo iniciar el agente: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
