<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyClaim;
use Database\Seeders\OperationalReportsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperationalReportsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_reports_demo_seeder_creates_full_demo_context_and_is_idempotent(): void
    {
        $this->seed(OperationalReportsDemoSeeder::class);
        $this->seed(OperationalReportsDemoSeeder::class);

        $reportsGroup = Tenant::query()->where('slug', 'grupo-demo-reportes')->firstOrFail();
        $multiGroup = Tenant::query()->where('slug', 'grupo-demo-multiempresa')->firstOrFail();

        $this->assertTrue($reportsGroup->isGroup());
        $this->assertTrue($multiGroup->isGroup());
        $this->assertSame(2, $reportsGroup->spinoffs()->count());
        $this->assertSame(4, $multiGroup->spinoffs()->count());

        foreach (['demo-caracas', 'demo-valencia'] as $slug) {
            $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

            $this->assertTrue($tenant->isSpinoff());
            $this->assertGreaterThanOrEqual(1, CashRegister::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, CashRegisterSession::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(7, PaymentMethod::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, Product::query()->where('tenant_id', $tenant->id)->where('tracking_type', Product::TRACKING_SERIALIZED)->count());
            $this->assertGreaterThanOrEqual(1, Product::query()->where('tenant_id', $tenant->id)->where('tracking_type', Product::TRACKING_QUANTITY)->count());
            $this->assertGreaterThanOrEqual(1, ProductUnit::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, Customer::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, Supplier::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, PurchaseOrder::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, AccountsPayable::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, PosOrder::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, Sale::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, AccountsReceivable::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, SalesReturn::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(1, WarrantyClaim::query()->where('tenant_id', $tenant->id)->count());
        }

        $owner = User::query()->where('email', 'owner.reportes@demo.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin.operativo@demo.test')->firstOrFail();
        $auditor = User::query()->where('email', 'auditor.demo@demo.test')->firstOrFail();
        $platformAdmin = User::query()->where('email', 'saas.master.demo@demo.test')->firstOrFail();
        $tenant = Tenant::query()->where('slug', 'demo-caracas')->firstOrFail();

        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($owner->hasRole('Owner'));
        $this->assertTrue($admin->hasPermissionTo('reports.cash.view'));
        $this->assertTrue($admin->hasPermissionTo('reports.export'));
        $this->assertTrue($auditor->hasPermissionTo('reports.sales.view'));
        $this->assertFalse($auditor->hasPermissionTo('reports.export'));
        $this->assertTrue($platformAdmin->isPlatformAdmin());

        $this->assertSame(1, User::query()->where('email', 'owner.reportes@demo.test')->count());
        $this->assertSame(1, User::query()->where('email', 'saas.master.demo@demo.test')->count());
        $this->assertSame(7, PaymentMethod::query()->where('tenant_id', $tenant->id)->count());
    }
}
