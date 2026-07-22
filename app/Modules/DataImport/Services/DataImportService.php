<?php

namespace App\Modules\DataImport\Services;

use App\Models\User;
use App\Modules\DataImport\Importers\ImporterRegistry;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Models\DataImportEntity;
use App\Modules\DataImport\Models\DataImportRow;
use App\Modules\DataImport\Support\CsvReportWriter;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\DataImport\Support\ImportStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class DataImportService
{
    public function uploadFile(DataImport $session, string $entity, UploadedFile $file): DataImportEntity
    {
        if (! ImportStatus::isValidEntity($entity)) {
            throw new \InvalidArgumentException("Entidad invalida: {$entity}");
        }

        $dir = $this->storageDir($session);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $extension = $file->getClientOriginalExtension() ?: 'csv';
        $filename = $entity.'_'.time().'_'.uniqid().'.'.$extension;
        $path = $dir.'/'.$filename;

        try {
            $file->move($dir, $filename);
        } catch (FileException $e) {
            throw new \RuntimeException('No se pudo guardar el archivo: '.$e->getMessage());
        }

        $entityRow = DataImportEntity::query()->updateOrCreate(
            ['data_import_id' => $session->id, 'entity' => $entity],
            ['source_path' => $path, 'status' => ImportStatus::ENTITY_PENDING],
        );

        return $entityRow;
    }

    /**
     * Ejecuta el import de una entidad. Procesa fila por fila,
     * cada fila en su propia transaccion para no abortar el lote
     * si una fila falla.
     */
    public function runEntity(DataImport $session, string $entity, User $user): DataImportEntity
    {
        if (! ImportStatus::isValidEntity($entity)) {
            throw new \InvalidArgumentException("Entidad invalida: {$entity}");
        }

        $entityRow = DataImportEntity::query()
            ->where('data_import_id', $session->id)
            ->where('entity', $entity)
            ->firstOrFail();

        if (! $entityRow->source_path || ! is_file($entityRow->source_path)) {
            throw new \RuntimeException("No hay archivo para procesar la entidad {$entity}.");
        }

        if ($entityRow->status === ImportStatus::ENTITY_RUNNING) {
            throw new \RuntimeException("La entidad {$entity} ya esta en ejecucion.");
        }

        $session->update(['status' => ImportStatus::SESSION_RUNNING, 'started_at' => now()]);
        $entityRow->update([
            'status' => ImportStatus::ENTITY_RUNNING,
            'started_at' => now(),
            'succeeded_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'total_rows' => 0,
            'error_summary' => null,
        ]);
        DataImportRow::query()->where('data_import_entity_id', $entityRow->id)->delete();

        $importer = ImporterRegistry::get($entity);
        $counts = ['total' => 0, 'ok' => 0, 'skipped' => 0, 'failed' => 0];
        $errorSummary = [];
        $rowNumber = 0;

        try {
            foreach ($importer->import($entityRow->source_path) as $result) {
                $rowNumber++;
                $counts['total']++;
                $this->recordRow($entityRow, $rowNumber, $result, $entity);
                $this->bumpCount($counts, $result);

                if ($result->isFailed() && count($errorSummary) < 20) {
                    $errorSummary[] = [
                        'row' => $rowNumber,
                        'natural_key' => $result->naturalKey,
                        'errors' => $result->errors,
                    ];
                }
            }

            $finalStatus = $counts['failed'] > 0 && $counts['ok'] === 0
                ? ImportStatus::ENTITY_FAILED
                : ImportStatus::ENTITY_COMPLETED;

            $entityRow->update([
                'status' => $finalStatus,
                'finished_at' => now(),
                'total_rows' => $counts['total'],
                'succeeded_rows' => $counts['ok'],
                'skipped_rows' => $counts['skipped'],
                'failed_rows' => $counts['failed'],
                'error_summary' => $errorSummary !== [] ? $errorSummary : null,
            ]);

            $this->updateSessionCounts($session);

            return $entityRow->fresh();
        } catch (\Throwable $e) {
            $entityRow->update([
                'status' => ImportStatus::ENTITY_FAILED,
                'finished_at' => now(),
                'error_summary' => array_merge($errorSummary, [
                    ['row' => 0, 'natural_key' => null, 'errors' => ['_fatal' => $e->getMessage()]],
                ]),
            ]);
            $this->updateSessionCounts($session);
            throw $e;
        }
    }

    public function generateReport(DataImport $session): string
    {
        $reportRows = DB::table('data_import_rows')
            ->whereIn('data_import_entity_id', function ($q) use ($session) {
                $q->select('id')->from('data_import_entities')
                    ->where('data_import_id', $session->id);
            })
            ->orderBy('data_import_entity_id')
            ->orderBy('row_number')
            ->get();

        $reportData = $reportRows->map(function ($r) {
            $payload = json_decode($r->payload ?? '{}', true) ?: [];
            $errors = json_decode($r->errors ?? '[]', true) ?: [];

            return [
                'row_number' => $r->row_number,
                'entity' => $this->entityNameForEntityId($r->data_import_entity_id),
                'status' => $r->status,
                'natural_key' => $r->natural_key,
                'resulting_id' => $r->resulting_id,
                'errors' => $errors,
                'message' => $payload['_message'] ?? null,
            ];
        })->all();

        $writer = app(CsvReportWriter::class);

        return $writer->write($reportData);
    }

    private function recordRow(DataImportEntity $entityRow, int $rowNumber, ImportRowResult $result, string $entity): void
    {
        DataImportRow::create([
            'data_import_entity_id' => $entityRow->id,
            'tenant_id' => $entityRow->tenant_id,
            'row_number' => $rowNumber,
            'status' => $result->status,
            'payload' => ['_message' => $result->message],
            'errors' => $result->errors !== [] ? $result->errors : null,
            'natural_key' => $result->naturalKey,
            'resulting_id' => $result->resultingId,
        ]);
    }

    private function bumpCount(array &$counts, ImportRowResult $result): void
    {
        if ($result->isOk()) {
            $counts['ok']++;
        } elseif ($result->isSkipped()) {
            $counts['skipped']++;
        } else {
            $counts['failed']++;
        }
    }

    private function updateSessionCounts(DataImport $session): void
    {
        $aggregates = DB::table('data_import_rows')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok")
            ->selectRaw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->whereIn('data_import_entity_id', function ($q) use ($session) {
                $q->select('id')->from('data_import_entities')
                    ->where('data_import_id', $session->id);
            })
            ->first();

        $session->update([
            'processed_rows' => (int) ($aggregates->total ?? 0),
            'succeeded_rows' => (int) ($aggregates->ok ?? 0),
            'skipped_rows' => (int) ($aggregates->skipped ?? 0),
            'failed_rows' => (int) ($aggregates->failed ?? 0),
            'finished_at' => now(),
        ]);

        $session->refresh();

        if (! $session->isActive()) {
            $hasFailures = $session->failed_rows > 0 && $session->succeeded_rows === 0;
            $session->update([
                'status' => $hasFailures ? ImportStatus::SESSION_FAILED : ImportStatus::SESSION_COMPLETED,
            ]);
        }
    }

    private function entityNameForEntityId(int $entityId): string
    {
        static $cache = [];
        if (! isset($cache[$entityId])) {
            $cache[$entityId] = DataImportEntity::query()->where('id', $entityId)->value('entity') ?? 'unknown';
        }

        return $cache[$entityId];
    }

    private function storageDir(DataImport $session): string
    {
        return storage_path("app/imports/{$session->tenant_id}/{$session->id}");
    }
}
