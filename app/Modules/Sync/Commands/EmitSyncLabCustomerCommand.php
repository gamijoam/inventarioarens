<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Customers\Models\Customer;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmitSyncLabCustomerCommand extends Command
{
    protected $signature = 'sync:lab:emit-customer
        {tenant : Slug de la empresa de laboratorio}
        {marker : Identificador corto de la corrida}
        {--name=Cliente E2E Sync : Nombre visible del cliente de prueba}';

    protected $description = 'Crea un cliente de laboratorio y emite su evento de sincronizacion.';

    public function handle(SyncCatalogOutboxService $outbox, TenantManager $tenants): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        $marker = $this->normalizedMarker((string) $this->argument('marker'));

        if (! $tenant) {
            $this->error('No se encontro la empresa de laboratorio indicada.');

            return self::FAILURE;
        }

        if ($marker === null) {
            $this->error('El identificador debe tener entre 4 y 24 caracteres alfanumericos o guiones.');

            return self::INVALID;
        }

        $tenants->set($tenant);

        try {
            $customer = DB::transaction(function () use ($marker, $outbox): Customer {
                $customer = Customer::query()->updateOrCreate(
                    [
                        'document_type' => Customer::DOCUMENT_V,
                        'document_number' => 'E2E-'.$marker,
                    ],
                    [
                        'name' => trim((string) $this->option('name')) ?: 'Cliente E2E Sync',
                        'phone' => '0414-0000000',
                        'email' => 'sync-'.$marker.'@lab.local',
                        'fiscal_address' => 'Laboratorio de sincronizacion',
                        'is_generic' => false,
                        'is_active' => true,
                    ],
                );

                $outbox->customerUpdated($customer->fresh());

                return $customer;
            });

            $this->info('Evento de cliente de laboratorio emitido.');
            $this->line('Documento: V-'.$customer->document_number);

            return self::SUCCESS;
        } finally {
            $tenants->clear();
        }
    }

    private function normalizedMarker(string $marker): ?string
    {
        $marker = Str::upper(trim($marker));

        return preg_match('/^[A-Z0-9-]{4,24}$/', $marker) === 1 ? $marker : null;
    }
}
