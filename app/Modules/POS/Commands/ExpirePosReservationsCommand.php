<?php

namespace App\Modules\POS\Commands;

use App\Modules\POS\Services\PosReservationExpiryService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;

class ExpirePosReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-reservations {tenant? : Slug de la empresa, o todas si se omite} {--limit=100 : Maximo de ordenes por empresa}';

    protected $description = 'Libera las reservas POS vencidas de inventario.';

    public function handle(PosReservationExpiryService $service, TenantManager $tenants): int
    {
        $query = Tenant::query()->where('status', 'active');
        if ($this->argument('tenant')) {
            $query->where('slug', $this->argument('tenant'));
        }

        $total = 0;
        foreach ($query->get() as $tenant) {
            $tenants->set($tenant);
            $expired = $service->expire($tenant, max(1, (int) $this->option('limit')));
            $total += $expired;
            $this->line("{$tenant->slug}: {$expired} reservas liberadas.");
        }
        $tenants->clear();

        $this->info("Total de reservas liberadas: {$total}.");

        return self::SUCCESS;
    }
}
