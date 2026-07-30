<?php

namespace App\Modules\Sync\Commands;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PrepareSyncLabPosCreditCommand extends Command
{
    protected $signature = 'sync:lab:prepare-pos-credit {tenant} {marker}';

    protected $description = 'Prepara el catalogo minimo para una prueba POS/CxC de sincronizacion.';

    public function handle(TenantManager $tenants): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        $marker = $this->marker((string) $this->argument('marker'));

        if (! $tenant || ! $marker) {
            $this->error('Empresa o identificador de laboratorio invalido.');

            return self::FAILURE;
        }

        $tenants->set($tenant);

        try {
            $suffix = Str::upper($marker);
            $user = User::query()->firstOrCreate(
                ['email' => "sync-pos-{$marker}@lab.local"],
                ['name' => "POS Sync {$suffix}", 'password' => Hash::make(Str::random(48))],
            );
            $user->tenants()->syncWithoutDetaching([$tenant->id => ['status' => 'active']]);

            $branch = Branch::query()->firstOrCreate(
                ['code' => "E2E-{$suffix}-BR"],
                ['name' => "E2E {$suffix} Sucursal"],
            );
            $warehouse = $branch->warehouses()->firstOrCreate(
                ['code' => "E2E-{$suffix}-WH"],
                ['name' => "E2E {$suffix} Almacen"],
            );
            $product = Product::query()->firstOrCreate(
                ['sku' => "E2E-{$suffix}-POS"],
                [
                    'name' => "Producto E2E {$suffix}",
                    'tracking_type' => Product::TRACKING_QUANTITY,
                    'base_price' => 100,
                    'sale_currency' => Product::CURRENCY_USD,
                    'is_active' => true,
                ],
            );
            StockBalance::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                ['quantity_available' => 5, 'quantity_reserved' => 0],
            );
            $customer = Customer::query()->firstOrCreate(
                ['document_type' => Customer::DOCUMENT_V, 'document_number' => "E2E-{$suffix}"],
                ['name' => "Cliente E2E {$suffix}", 'is_active' => true],
            );
            ExchangeRateType::query()->where('is_default', true)->update(['is_default' => false]);
            $rateType = ExchangeRateType::query()->firstOrCreate(
                ['code' => "E2E-{$suffix}-RATE"],
                ['name' => "Tasa E2E {$suffix}", 'is_default' => true, 'is_active' => true],
            );
            $rateType->update(['is_default' => true, 'is_active' => true]);
            ExchangeRate::query()->firstOrCreate(
                [
                    'exchange_rate_type_id' => $rateType->id,
                    'base_currency' => ExchangeRate::BASE_USD,
                    'quote_currency' => ExchangeRate::QUOTE_VES,
                    'effective_at' => now()->startOfMinute(),
                ],
                ['rate' => 100, 'is_active' => true, 'source' => 'sync_lab'],
            );
            $register = CashRegister::query()->firstOrCreate(
                ['code' => "E2E-{$suffix}-CJ"],
                ['branch_id' => $branch->id, 'name' => "Caja E2E {$suffix}", 'status' => CashRegister::STATUS_ACTIVE],
            );
            CashRegisterSession::query()->firstOrCreate(
                ['cash_register_id' => $register->id, 'cashier_id' => $user->id, 'status' => CashRegisterSession::STATUS_OPEN],
                ['branch_id' => $branch->id, 'opened_by' => $user->id, 'opened_at' => now()],
            );

            $this->info("Fixture POS/CxC preparado: {$product->sku}.");

            return self::SUCCESS;
        } finally {
            $tenants->clear();
        }
    }

    private function marker(string $marker): ?string
    {
        $marker = Str::upper(trim($marker));

        return preg_match('/^[A-Z0-9-]{4,24}$/', $marker) === 1 ? Str::lower($marker) : null;
    }
}
