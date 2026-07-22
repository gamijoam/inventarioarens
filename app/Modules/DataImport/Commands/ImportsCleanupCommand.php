<?php

namespace App\Modules\DataImport\Commands;

use App\Modules\DataImport\Models\DataImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportsCleanupCommand extends Command
{
    protected $signature = 'imports:cleanup {--days=30 : Antiguedad minima en dias para borrar} {--dry-run : Solo reportar, no borrar}';

    protected $description = 'Elimina sesiones de importacion y archivos subidos mas antiguos que N dias (default 30).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $sessions = DataImport::query()
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->get();

        $this->line(sprintf(
            'Encontradas %d sesiones anteriores a %s (mas viejas que %d dias).',
            $sessions->count(),
            $cutoff->toDateTimeString(),
            $days,
        ));

        $deletedRows = 0;
        $deletedFiles = 0;
        $freedBytes = 0;

        foreach ($sessions as $session) {
            $entityDir = storage_path("app/imports/{$session->tenant_id}/{$session->id}");
            if (is_dir($entityDir)) {
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($entityDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                ) as $file) {
                    if ($file->isFile()) {
                        $freedBytes += $file->getSize();
                        if (! $dryRun) {
                            @unlink($file->getPathname());
                        }
                        $deletedFiles++;
                    }
                }
                if (! $dryRun) {
                    @rmdir($entityDir);
                }
            }

            $deletedRows += DB::table('data_import_rows')
                ->whereIn('data_import_entity_id', function ($q) use ($session) {
                    $q->select('id')->from('data_import_entities')->where('data_import_id', $session->id);
                })
                ->delete();

            if (! $dryRun) {
                $session->delete();
            }

            Log::info('imports.cleanup.deleted', [
                'session_id' => $session->id,
                'tenant_id' => $session->tenant_id,
                'age_days' => $session->created_at?->diffInDays(now()),
            ]);
        }

        $this->info(sprintf(
            'Limpieza%s: %d sesiones, %d filas, %d archivos (%.2f KB) liberados.',
            $dryRun ? ' [DRY-RUN]' : '',
            $sessions->count(),
            $deletedRows,
            $deletedFiles,
            $freedBytes / 1024,
        ));

        return self::SUCCESS;
    }
}
