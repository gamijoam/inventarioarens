<?php

namespace Tests\Feature\AdminPortal;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_returns_tenant_metrics_and_sync_status(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['reports.view']);
        $this->seedTenant($tenant, $user);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/dashboard?period=today&low_stock_threshold=3')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'empresa-a')
            ->assertJsonPath('data.sales.confirmed_count', 1)
            ->assertJsonPath('data.sales.confirmed_base_amount', 150.25)
            ->assertJsonPath('data.sales.pos_paid_count', 1)
            ->assertJsonPath('data.sales.pos_paid_base_amount', 150.25)
            ->assertJsonPath('data.sales.pending_pos_count', 1)
            ->assertJsonPath('data.cash_register.physical_registers_count', 1)
            ->assertJsonPath('data.cash_register.open_sessions_count', 1)
            ->assertJsonPath('data.cash_register.expected_base_amount', 25)
            ->assertJsonPath('data.inventory.active_products_count', 3)
            ->assertJsonPath('data.inventory.available_quantity', 7)
            ->assertJsonPath('data.inventory.low_stock_count', 1)
            ->assertJsonPath('data.inventory.without_stock_count', 1)
            ->assertJsonPath('data.sync.nodes_count', 1)
            ->assertJsonPath('data.sync.pending_outbox_count', 1)
            ->assertJsonPath('data.sync.failed_outbox_count', 1)
            ->assertJsonPath('data.sync.readiness_status', 'ready')
            ->assertJsonPath('data.alerts.0.type', 'without_stock')
            ->assertJsonPath('data.alerts.1.type', 'low_stock')
            ->assertJsonPath('data.alerts.2.type', 'sync_errors')
            ->assertJsonPath('data.alerts.3.type', 'sync_pending');
    }

    public function test_admin_dashboard_does_not_mix_tenant_data(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA, ['reports.view']);
        $userB = $this->userInTenant($tenantB, ['reports.view']);

        $this->seedTenant($tenantA, $userA);
        $this->seedTenant($tenantB, $userB, 1000);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/admin-portal/dashboard?period=today')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'empresa-a')
            ->assertJsonPath('data.sales.confirmed_base_amount', 150.25)
            ->assertJsonPath('data.inventory.available_quantity', 7);
    }

    public function test_admin_dashboard_requires_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/dashboard')
            ->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function seedTenant(Tenant $tenant, User $user, int $offset = 0): void
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal '.$offset, 'code' => 'BR-'.$offset]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda', 'code' => 'WH-'.$offset]);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CJ-'.$offset]);

        CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'expected_base_amount' => 25,
            'opened_at' => now(),
        ]);

        $available = Product::create([
            'name' => 'Producto disponible',
            'sku' => 'DISP-'.$offset,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $low = Product::create([
            'name' => 'Producto bajo',
            'sku' => 'LOW-'.$offset,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 20,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        Product::create([
            'name' => 'Producto sin stock',
            'sku' => 'OUT-'.$offset,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 30,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $available->id, 'quantity_available' => 5]);
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $low->id, 'quantity_available' => 2]);

        $confirmedSale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 150.25 + $offset,
            'total_local_amount' => 150.25 + $offset,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        PosOrder::create([
            'sale_id' => $confirmedSale->id,
            'status' => PosOrder::STATUS_PAID,
            'cashier_id' => $user->id,
            'total_base_amount' => 150.25 + $offset,
            'paid_base_amount' => 150.25 + $offset,
            'opened_at' => now(),
            'paid_at' => now(),
            'closed_at' => now(),
        ]);

        $openSale = Sale::create([
            'status' => Sale::STATUS_DRAFT,
            'total_base_amount' => 50,
            'total_local_amount' => 50,
            'created_by' => $user->id,
        ]);
        PosOrder::create([
            'sale_id' => $openSale->id,
            'status' => PosOrder::STATUS_OPEN,
            'cashier_id' => $user->id,
            'total_base_amount' => 50,
            'paid_base_amount' => 0,
            'opened_at' => now(),
        ]);

        $nodeId = DB::table('sync_nodes')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-'.$offset,
            'name' => 'Local '.$offset,
            'type' => 'local',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sync_outbox')->insert([
            [
                'tenant_id' => $tenant->id,
                'event_uuid' => fake()->uuid(),
                'origin_node_id' => $nodeId,
                'target_scope' => 'tenant',
                'event_type' => 'product.updated',
                'aggregate_type' => 'product',
                'payload' => json_encode(['sku' => 'DISP-'.$offset]),
                'occurred_at' => now(),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenant->id,
                'event_uuid' => fake()->uuid(),
                'origin_node_id' => $nodeId,
                'target_scope' => 'tenant',
                'event_type' => 'product.updated',
                'aggregate_type' => 'product',
                'payload' => json_encode(['sku' => 'LOW-'.$offset]),
                'occurred_at' => now(),
                'status' => 'failed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('sync_tenant_readiness')->insert([
            'tenant_id' => $tenant->id,
            'installation_code' => 'INSTALL-'.$offset,
            'node_code' => 'LOCAL-'.$offset,
            'node_name' => 'Local '.$offset,
            'status' => 'ready',
            'last_success_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Portal '.md5(implode('|', $permissions).$tenant->id), $permissions);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
