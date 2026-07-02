<?php

namespace Tests\Feature\Seeders;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertDatabaseHas('customers', ['name' => 'Consumidor final', 'is_generic' => true]);
        $this->assertSame(6, Customer::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame(2, Supplier::withoutGlobalScopes()->where('name', 'like', 'Proveedor Demo%')->count());
        $this->assertSame(2, PurchaseOrder::withoutGlobalScopes()->where('status', PurchaseOrder::STATUS_RECEIVED)->count());
        $this->assertSame(2, PurchaseReturn::withoutGlobalScopes()->where('status', PurchaseReturn::STATUS_PROCESSED)->count());
        $this->assertSame(2, AccountsPayable::withoutGlobalScopes()->where('status', AccountsPayable::STATUS_PARTIAL)->count());
        $this->assertSame(2, AccountsPayablePayment::withoutGlobalScopes()->where('method', 'transferencia demo')->count());
        $this->assertDatabaseHas('stock_movements', ['type' => 'purchase']);
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'purchase_return')->count());
        $this->assertGreaterThanOrEqual(16, ProductUnit::withoutGlobalScopes()->count());
        $this->assertSame(2, PosOrder::withoutGlobalScopes()->where('customer_name', 'Cliente Demo POS Pagado')->count());
        $this->assertSame(2, PosOrder::withoutGlobalScopes()->where('customer_name', 'Cliente Demo Financiamiento')->count());
        $this->assertSame(4, PosOrder::withoutGlobalScopes()->whereNotNull('customer_id')->count());
        $this->assertSame(2, PosPayment::withoutGlobalScopes()->where('status', PosPayment::STATUS_PENDING)->count());
        $this->assertSame(2, Sale::withoutGlobalScopes()->where('status', Sale::STATUS_CONFIRMED)->count());
        $this->assertSame(2, Sale::withoutGlobalScopes()->where('status', Sale::STATUS_DRAFT)->count());
        $this->assertSame(4, Sale::withoutGlobalScopes()->whereNotNull('customer_id')->count());
        $this->assertSame(2, SalesReturn::withoutGlobalScopes()->where('status', SalesReturn::STATUS_PROCESSED)->count());
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'sale_return')->count());
        $this->assertSame(2, CashRegisterSession::withoutGlobalScopes()->where('status', CashRegisterSession::STATUS_OPEN)->count());
        $this->assertSame(2, CashRegisterMovement::withoutGlobalScopes()->where('type', CashRegisterMovement::TYPE_POS_PAYMENT)->count());
    }
}
