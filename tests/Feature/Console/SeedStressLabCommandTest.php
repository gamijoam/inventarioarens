<?php

namespace Tests\Feature\Console;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedStressLabCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_three_isolated_web_only_tenants_with_catalogs(): void
    {
        $this->artisan('stress:seed', [
            '--tenants' => 3,
            '--products' => 10,
            '--password' => 'loadtest-password',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('tenants', 3);

        $first = Tenant::query()->where('slug', 'loadtest-01')->firstOrFail();
        $second = Tenant::query()->where('slug', 'loadtest-02')->firstOrFail();

        $this->assertFalse($first->is_group);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $first->id,
            'status' => 'active',
        ]);
        $this->assertSame(12, Product::query()->withoutGlobalScopes()->where('tenant_id', $first->id)->count());
        $this->assertSame(12, Product::query()->withoutGlobalScopes()->where('tenant_id', $second->id)->count());
        $this->assertDatabaseHas('cash_register_sessions', [
            'tenant_id' => $first->id,
            'status' => CashRegisterSession::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'tenant_id' => $first->id,
            'code' => 'LAB-CASH-USD',
            'currency_mode' => PaymentMethod::CURRENCY_USD,
        ]);

        $serializedProduct = Product::query()->withoutGlobalScopes()
            ->where('tenant_id', $first->id)
            ->where('sku', 'LOADTEST-01-0010')
            ->where('tracking_type', Product::TRACKING_SERIALIZED)
            ->firstOrFail();
        $this->assertSame(100, ProductUnit::query()->withoutGlobalScopes()
            ->where('tenant_id', $first->id)
            ->where('product_id', $serializedProduct->id)
            ->where('status', ProductUnit::STATUS_AVAILABLE)
            ->count());
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $first->id,
            'product_id' => Product::query()->withoutGlobalScopes()
                ->where('tenant_id', $first->id)
                ->where('sku', 'LOADTEST-01-RACE-QTY')
                ->value('id'),
            'quantity_available' => 1,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $first->id,
            'serial_number' => 'LAB-RACE-IMEI-01',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('price_list_payment_method', [
            'tenant_id' => $first->id,
            'price_list_id' => PriceList::query()->withoutGlobalScopes()
                ->where('tenant_id', $first->id)
                ->where('code', 'LAB-BASE')
                ->value('id'),
        ]);
        $this->assertFalse(
            Product::query()->withoutGlobalScopes()
                ->where('tenant_id', $first->id)
                ->where('sku', 'LOADTEST-02-0001')
                ->exists()
        );
    }

    public function test_it_requires_explicit_confirmation(): void
    {
        $this->artisan('stress:seed', [
            '--password' => 'loadtest-password',
        ])->expectsOutput('Esta accion requiere --force para crear datos de carga.')
            ->assertFailed();
    }
}
