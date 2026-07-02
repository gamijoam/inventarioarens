<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_movements_and_audit_logs_do_not_mix_between_multiple_companies(): void
    {
        [$tenantA, $tenantB, $tenantC] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
            Tenant::create(['name' => 'Empresa C', 'slug' => 'empresa-c']),
        ];

        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        $this->service()->purchase($warehouseA, $productA, 10, 80, reason: 'Compra A');

        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        $this->service()->purchase($warehouseB, $productB, 5, 70, reason: 'Compra B');

        [$warehouseC, $productC] = $this->warehouseAndProduct($tenantC, 'C');
        $this->service()->purchase($warehouseC, $productC, 3, 60, reason: 'Compra C');

        $this->useTenant($tenantA);
        $this->assertSame(['Producto A'], Product::query()->pluck('name')->all());
        $this->assertSame([10.0], $this->numericValues(StockBalance::query()->pluck('quantity_available')->all()));
        $this->assertSame(['Compra A'], AuditLog::query()->pluck('new_values')->map(fn (array $values): ?string => $values['reason'] ?? null)->all());

        $this->useTenant($tenantB);
        $this->assertSame(['Producto B'], Product::query()->pluck('name')->all());
        $this->assertSame([5.0], $this->numericValues(StockBalance::query()->pluck('quantity_available')->all()));
        $this->assertSame(['Compra B'], AuditLog::query()->pluck('new_values')->map(fn (array $values): ?string => $values['reason'] ?? null)->all());

        $this->useTenant($tenantC);
        $this->assertSame(['Producto C'], Product::query()->pluck('name')->all());
        $this->assertSame([3.0], $this->numericValues(StockBalance::query()->pluck('quantity_available')->all()));
        $this->assertSame(['Compra C'], AuditLog::query()->pluck('new_values')->map(fn (array $values): ?string => $values['reason'] ?? null)->all());

        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_inventory_api_writes_audit_log_with_user_and_request_context(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Almacen', ['inventory.adjust']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('User-Agent', 'InventoryAuditTest/1.0')
            ->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
            ->postJson('/api/inventory/adjustments/in', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 4,
                'reason' => 'Ajuste auditado',
            ])
            ->assertCreated();

        $this->useTenant($tenant);

        $audit = AuditLog::query()->firstOrFail();

        $this->assertSame('inventory.movement.created', $audit->action);
        $this->assertSame(StockMovement::class, $audit->entity_type);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Ajuste auditado', $audit->new_values['reason']);
        $this->assertSame('adjustment_in', $audit->new_values['type']);
        $this->assertSame(4.0, (float) $audit->new_values['quantity']);
        $this->assertSame('10.10.10.10', $audit->ip_address);
        $this->assertSame('InventoryAuditTest/1.0', $audit->user_agent);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function warehouseAndProduct(Tenant $tenant, string $suffix): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$suffix}", 'code' => "BR-{$suffix}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$suffix}", 'code' => "WH-{$suffix}"]);
        $product = Product::create(['name' => "Producto {$suffix}", 'sku' => "SKU-{$suffix}"]);

        return [$warehouse, $product];
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function service(): InventoryMovementService
    {
        return app(InventoryMovementService::class);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function numericValues(array $values): array
    {
        return array_map(static fn ($value): float => (float) $value, $values);
    }
}
