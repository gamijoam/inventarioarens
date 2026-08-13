<?php

namespace App\Console\Commands;

use App\Modules\LocalSupport\Services\LocalTechnicalConsoleService;
use Illuminate\Console\Command;

class RepairLocalTasksCommand extends Command
{
    protected $signature = 'local:repair-tasks
        {--printer : Re-registra tambien el agente de impresion}
        {--force : No requerido; el comando es idempotente}';

    protected $description = 'Re-registra las tareas de Windows de sync y el agente de impresion con las rutas actuales del backend.';

    public function handle(LocalTechnicalConsoleService $console): int
    {
        $result = $console->repairWindowsTasks(withPrinter: (bool) $this->option('printer'));

        foreach ($result['output'] as $line) {
            $this->line($line);
        }

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info('Tareas de Windows verificadas y re-registradas.');

        return self::SUCCESS;
    }
}
