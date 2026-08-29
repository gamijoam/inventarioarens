<?php

namespace App\Modules\InventoryCenter\Jobs;

use App\Modules\InventoryCenter\Models\ProductBulkOperation;
use App\Modules\InventoryCenter\Services\InventoryCenterBulkActionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyProductFiscalClassification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $operationId,
        public readonly int $tenantId,
    ) {}

    public function handle(InventoryCenterBulkActionService $service, TenantManager $tenantManager): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantManager->set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $operation = ProductBulkOperation::query()
                ->whereKey($this->operationId)
                ->firstOrFail();
            $service->processFiscalOperation($operation);
        } finally {
            $tenantManager->clear();
        }
    }
}
