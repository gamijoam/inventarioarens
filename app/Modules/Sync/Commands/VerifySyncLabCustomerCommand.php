<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Customers\Models\Customer;
use App\Modules\Sync\Models\SyncInbox;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class VerifySyncLabCustomerCommand extends Command
{
    protected $signature = 'sync:lab:verify-customer
        {tenant : Slug de la empresa de laboratorio}
        {marker : Identificador corto de la corrida}
        {--require-inbox : Exige evidencia de aplicacion desde sync_inbox}';

    protected $description = 'Verifica que un cliente E2E fue aplicado una sola vez por sincronizacion.';

    public function handle(TenantManager $tenants): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        $marker = $this->normalizedMarker((string) $this->argument('marker'));

        if (! $tenant || $marker === null) {
            $this->error('Empresa o identificador de laboratorio invalido.');

            return self::FAILURE;
        }

        $tenants->set($tenant);

        try {
            $document = 'E2E-'.$marker;
            $customers = Customer::query()
                ->where('document_type', Customer::DOCUMENT_V)
                ->where('document_number', $document)
                ->count();

            if ($customers !== 1) {
                $this->error('Se esperaban exactamente un cliente y se encontraron '.$customers.'.');

                return self::FAILURE;
            }

            if ($this->option('require-inbox')) {
                $inboxCount = SyncInbox::query()
                    ->where('event_type', 'customer.updated')
                    ->where('status', 'applied')
                    ->whereJsonContains('payload->document_number', $document)
                    ->count();

                if ($inboxCount < 1) {
                    $this->error('El cliente existe, pero no hay evidencia aplicada en el inbox.');

                    return self::FAILURE;
                }
            }

            $this->info('Verificacion E2E correcta: cliente unico '.$document.'.');

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
