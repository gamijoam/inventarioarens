<?php

namespace App\Modules\Sync\Commands;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EmitSyncLabPosCreditCommand extends Command
{
    protected $signature = 'sync:lab:emit-pos-credit {tenant} {marker} {phase : sale|collection}';

    protected $description = 'Emite una venta POS a credito o su cobro posterior para el laboratorio Sync.';

    public function handle(PosCheckoutService $pos, AccountsReceivableService $receivables, TenantManager $tenants): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        $marker = Str::lower(trim((string) $this->argument('marker')));
        $phase = (string) $this->argument('phase');

        if (! $tenant || ! preg_match('/^[a-z0-9-]{4,24}$/', $marker) || ! in_array($phase, ['sale', 'collection'], true)) {
            $this->error('Argumentos de laboratorio invalidos.');

            return self::FAILURE;
        }

        $tenants->set($tenant);

        try {
            $suffix = Str::upper($marker);
            $user = User::query()->where('email', "sync-pos-{$marker}@lab.local")->firstOrFail();
            $customer = Customer::query()->where('document_number', "E2E-{$suffix}")->firstOrFail();
            $product = Product::query()->where('sku', "E2E-{$suffix}-POS")->firstOrFail();
            $session = CashRegisterSession::query()->where('cashier_id', $user->id)->where('status', CashRegisterSession::STATUS_OPEN)->firstOrFail();

            if ($phase === 'sale') {
                if (PosOrder::query()->where('customer_id', $customer->id)->exists()) {
                    $this->info('La venta POS de laboratorio ya existe.');

                    return self::SUCCESS;
                }

                $order = $pos->checkout($user, $session, [[
                    'warehouse_id' => $product->stockBalances()->firstOrFail()->warehouse_id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'price_source' => 'base',
                ]], [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                ]], $customer->id, $customer->name, true);

                $this->info("Venta POS a credito emitida: {$order->id}.");

                return self::SUCCESS;
            }

            $account = AccountsReceivable::query()->where('customer_id', $customer->id)->where('balance_base_amount', '>', 0)->firstOrFail();
            $receivables->registerPayment($account, $user, [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => (float) $account->balance_base_amount,
                'method' => 'sync_lab_collection',
                'reference' => "E2E-COLLECT-{$suffix}",
                'notes' => 'Cobro de laboratorio Sync E2E.',
            ]);
            $this->info('Cobro CxC de laboratorio emitido.');

            return self::SUCCESS;
        } finally {
            $tenants->clear();
        }
    }
}
