<?php

namespace Tests\Feature\Seeders;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_seeder_creates_visible_business_data_and_is_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseHas('tenants', ['slug' => 'arens-demo-caracas']);
        $this->assertDatabaseHas('tenants', ['slug' => 'arens-demo-valencia']);
        $this->assertSame(2, Tenant::query()->where('slug', 'like', 'arens-demo-%')->count());
        $this->assertDatabaseHas('users', ['email' => 'cajero.caracas@demo.test']);
        $this->assertDatabaseHas('exchange_rate_types', ['code' => 'BCV']);
        $this->assertDatabaseHas('exchange_rate_types', ['code' => 'PARALELO']);
        $this->assertDatabaseHas('products', ['name' => 'Samsung A06 128GB']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'purchase']);
        $this->assertGreaterThanOrEqual(16, ProductUnit::withoutGlobalScopes()->count());
        $this->assertSame(2, PosOrder::withoutGlobalScopes()->where('customer_name', 'Cliente Demo POS Pagado')->count());
        $this->assertSame(2, PosOrder::withoutGlobalScopes()->where('customer_name', 'Cliente Demo Financiamiento')->count());
        $this->assertSame(2, PosPayment::withoutGlobalScopes()->where('status', PosPayment::STATUS_PENDING)->count());
        $this->assertSame(2, Sale::withoutGlobalScopes()->where('status', Sale::STATUS_CONFIRMED)->count());
        $this->assertSame(2, Sale::withoutGlobalScopes()->where('status', Sale::STATUS_DRAFT)->count());
        $this->assertSame(2, CashRegisterSession::withoutGlobalScopes()->where('status', CashRegisterSession::STATUS_OPEN)->count());
        $this->assertSame(2, CashRegisterMovement::withoutGlobalScopes()->where('type', CashRegisterMovement::TYPE_POS_PAYMENT)->count());
    }
}
