<?php

namespace App\Modules\Sync\Commands;

use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class VerifySyncLabPosCreditCommand extends Command
{
    protected $signature = 'sync:lab:verify-pos-credit {tenant} {marker}';

    protected $description = 'Verifica venta POS, stock y CxC conciliada en un nodo de laboratorio.';

    public function handle(TenantManager $tenants): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        $marker = Str::upper(trim((string) $this->argument('marker')));
        if (! $tenant || ! preg_match('/^[A-Z0-9-]{4,24}$/', $marker)) {
            return self::FAILURE;
        }
        $tenants->set($tenant);
        try {
            $product = Product::query()->where('sku', "E2E-{$marker}-POS")->firstOrFail();
            $stock = StockBalance::query()->where('product_id', $product->id)->value('quantity_available');
            $orderCount = PosOrder::query()->count();
            $account = AccountsReceivable::query()->firstOrFail();
            if ((float) $stock !== 3.0 || $orderCount !== 1 || $account->status !== AccountsReceivable::STATUS_PAID || (float) $account->balance_base_amount !== 0.0 || $account->payments()->count() !== 2) {
                $this->error('La verificacion POS/CxC no coincide con el resultado esperado.');

                return self::FAILURE;
            }
            $this->info('Verificacion POS/CxC correcta: venta unica, stock 3 y CxC pagada.');

            return self::SUCCESS;
        } finally {
            $tenants->clear();
        }
    }
}
