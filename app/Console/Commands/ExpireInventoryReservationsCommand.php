<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\InventoryReservationExpirationService;
use App\Modules\POS\Services\PosReservationExpiryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class ExpireInventoryReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-reservations
        {tenant? : ID o slug del tenant; por defecto todos}
        {--limit=500 : Maximo de reservas a procesar por tenant}';

    protected $description = 'Libera reservas de inventario vencidas y sus IMEI/seriales.';

    public function handle(
        InventoryReservationExpirationService $service,
        PosReservationExpiryService $posService,
    ): int {
        $tenants = Tenant::query()
            ->when($this->argument('tenant') !== null, function ($query): void {
                $tenant = (string) $this->argument('tenant');
                $query->where(fn ($nested) => $nested
                    ->where('id', (int) $tenant)
                    ->orWhere('slug', $tenant));
            })
            ->get();
        if ($tenants->isEmpty()) {
            $this->error('No se encontro el tenant indicado.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $total = 0;
        foreach ($tenants as $tenant) {
            $expired = $posService->expire($tenant, $limit);
            $expired += $service->expireTenant($tenant, $limit);
            $total += $expired;
            $this->line("Tenant {$tenant->slug}: {$expired} expired reservation(s).");
        }
        $this->info("Reservation expiration complete: {$total} expired.");

        return self::SUCCESS;
    }
}
