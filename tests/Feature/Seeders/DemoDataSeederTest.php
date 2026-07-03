<?php

namespace Tests\Feature\Seeders;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Customers\Models\Customer;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\PaymentReceipts\Models\PaymentReceipt;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyClaim;
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

        $this->assertDatabaseHas('tenants', ['slug' => 'demo-caracas']);
        $this->assertDatabaseHas('tenants', ['slug' => 'demo-valencia']);
        $this->assertSame(2, Tenant::query()->where('slug', 'like', 'demo-%')->count());
        $this->assertDatabaseHas('users', ['email' => 'cajero.caracas@demo.test']);
        $this->assertDatabaseHas('exchange_rate_types', ['code' => 'BCV']);
        $this->assertDatabaseHas('exchange_rate_types', ['code' => 'PARALELO']);
        $this->assertDatabaseHas('warranty_policies', ['name' => 'Android 30 dias', 'duration_days' => 30]);
        $this->assertDatabaseHas('warranty_policies', ['name' => 'Accesorios 7 dias', 'duration_days' => 7]);
        $this->assertDatabaseHas('products', ['name' => 'Samsung A06 128GB']);
        $this->assertDatabaseHas('products', ['sku' => 'CARG-25W-CCS', 'name' => 'Cargador Rapido 25W']);
        $this->assertDatabaseHas('products', ['sku' => 'PROT-A06-CCS', 'name' => 'Protector Pantalla Samsung A06']);
        $this->assertDatabaseHas('products', ['sku' => 'IPH11-64-CCS', 'tracking_type' => Product::TRACKING_SERIALIZED]);
        $this->assertSame(24, Product::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertDatabaseHas('customers', ['name' => 'Consumidor final', 'is_generic' => true]);
        $this->assertSame(6, Customer::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame(2, Supplier::withoutGlobalScopes()->where('name', 'like', 'Proveedor Demo%')->count());
        $this->assertSame(2, PurchaseOrder::withoutGlobalScopes()->where('status', PurchaseOrder::STATUS_RECEIVED)->count());
        $this->assertSame(2, PurchaseReturn::withoutGlobalScopes()->where('status', PurchaseReturn::STATUS_PROCESSED)->count());
        $this->assertSame(2, AccountsPayable::withoutGlobalScopes()->where('status', AccountsPayable::STATUS_PARTIAL)->count());
        $this->assertSame(2, AccountsPayablePayment::withoutGlobalScopes()->where('method', 'transferencia demo')->count());
        $this->assertDatabaseHas('stock_movements', ['type' => 'purchase']);
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'purchase_return')->count());
        $this->assertSame(2, ProductEntry::withoutGlobalScopes()->where('reason', 'Entrada demo de 30 IMEIs Samsung A06')->count());
        $this->assertSame(2, ProductExit::withoutGlobalScopes()->where('reason', ProductExit::REASON_WARRANTY)->count());
        $this->assertSame(2, InventoryTransfer::withoutGlobalScopes()->where('type', InventoryTransfer::TYPE_INTERNAL)->count());
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'transfer_out')->count());
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'transfer_in')->count());
        $this->assertSame(1, InventoryTransferRequest::query()->where('status', InventoryTransferRequest::STATUS_COMPLETED)->count());
        $this->assertGreaterThanOrEqual(92, ProductUnit::withoutGlobalScopes()->count());
        $this->assertGreaterThanOrEqual(2, ProductUnit::withoutGlobalScopes()->where('status', ProductUnit::STATUS_REMOVED)->count());
        $this->assertGreaterThanOrEqual(2, StockBalance::withoutGlobalScopes()->where('quantity_reserved', '>', 0)->count());
        $this->assertGreaterThanOrEqual(2, StockBalance::withoutGlobalScopes()->where('quantity_damaged', '>', 0)->count());
        $this->assertSame(2, PosOrder::withoutGlobalScopes()->where('customer_name', 'Cliente Demo POS Pagado')->count());
        $this->assertSame(2, PosOrder::withoutGlobalScopes()->where('customer_name', 'Cliente Demo Financiamiento')->count());
        $this->assertSame(4, PosOrder::withoutGlobalScopes()->whereNotNull('customer_id')->count());
        $this->assertSame(2, PosPayment::withoutGlobalScopes()->where('status', PosPayment::STATUS_PENDING)->count());
        $this->assertSame(4, Sale::withoutGlobalScopes()->where('status', Sale::STATUS_CONFIRMED)->count());
        $this->assertSame(2, Sale::withoutGlobalScopes()->where('status', Sale::STATUS_DRAFT)->count());
        $this->assertSame(6, Sale::withoutGlobalScopes()->whereNotNull('customer_id')->count());
        $this->assertGreaterThanOrEqual(4, DB::table('sale_items')->whereNotNull('warranty_policy_name')->count());
        $this->assertGreaterThanOrEqual(4, DB::table('sale_items')->whereNotNull('warranty_expires_at')->count());
        $this->assertSame(2, WarrantyClaim::withoutGlobalScopes()->where('issue_description', 'Caso demo de garantia en revision.')->count());
        $this->assertSame(2, AccountsReceivable::withoutGlobalScopes()->where('status', AccountsReceivable::STATUS_PARTIAL)->count());
        $this->assertSame(2, AccountsReceivablePayment::withoutGlobalScopes()->where('method', 'cobro demo')->count());
        $this->assertSame(4, FinancialAdjustment::withoutGlobalScopes()->where('status', FinancialAdjustment::STATUS_APPLIED)->count());
        $this->assertSame(6, PaymentReceipt::withoutGlobalScopes()->where('status', PaymentReceipt::STATUS_ISSUED)->count());
        $this->assertSame(4, PaymentReceipt::withoutGlobalScopes()->where('type', PaymentReceipt::TYPE_CUSTOMER_COLLECTION)->count());
        $this->assertSame(2, PaymentReceipt::withoutGlobalScopes()->where('type', PaymentReceipt::TYPE_SUPPLIER_PAYMENT)->count());
        $this->assertSame(2, SalesReturn::withoutGlobalScopes()->where('status', SalesReturn::STATUS_PROCESSED)->count());
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'sale_return')->count());
        $this->assertSame(2, CashRegisterSession::withoutGlobalScopes()->where('status', CashRegisterSession::STATUS_OPEN)->count());
        $this->assertSame(2, CashRegisterMovement::withoutGlobalScopes()->where('type', CashRegisterMovement::TYPE_POS_PAYMENT)->count());
    }
}
