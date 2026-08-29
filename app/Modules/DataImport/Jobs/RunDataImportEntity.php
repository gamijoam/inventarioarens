<?php

namespace App\Modules\DataImport\Jobs;

use App\Models\User;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Services\DataImportService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDataImportEntity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $dataImportId,
        public readonly string $entity,
        public readonly int $userId,
        public readonly int $tenantId,
    ) {
        $this->onQueue((string) config('data_import.queue', 'imports'));
    }

    public function handle(DataImportService $service, TenantManager $tenantManager): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantManager->set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $session = DataImport::query()->findOrFail($this->dataImportId);
            $service->runEntity($session, $this->entity, User::query()->findOrFail($this->userId));
        } finally {
            $tenantManager->clear();
        }
    }
}
