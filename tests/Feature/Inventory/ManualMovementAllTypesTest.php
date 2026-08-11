<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Inventory\Models\StockBalance;
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

/**
 * Verifica que el endpoint POST /api/inventory/manual-movements/{id}/approve
 * funcione para los 8 tipos de movimiento manual (entradas, salidas, daños)
 * sin errores de servidor (500).
 *
 * Incidencia 2026-08-11: los tipos de salida/daño lanzaban
 * InsufficientStockException/InvalidStockQuantityException que no estaban
 * renderizadas -> el frontend recibia "Error de servidor" (500) en vez de un
 * 422 con mensaje amigable.
 */
class ManualMovementAllTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_approve_handles_all_manual_movement_types_without_500(): void
    {
        $cases = [
            // entrada: ajuste, devolucion interna, encontrado
            ['type' => 'adjustment_in', 'direction' => 'in'],
            ['type' => 'return_internal', 'direction' => 'in'],
            ['type' => 'found', 'direction' => 'in'],
            // salida: consumo interno, ajuste de salida
            ['type' => 'internal_consumption', 'direction' => 'out'],
            ['type' => 'adjustment_out', 'direction' => 'out'],
            // daño/pérdida: baja, dañado, pérdida
            ['type' => 'write_off', 'direction' => 'damaged'],
            ['type' => 'damaged', 'direction' => 'damaged'],
            ['type' => 'loss', 'direction' => 'damaged'],
        ];

        foreach ($cases as $case) {
            $this->runTypeCase($case['type'], $case['direction']);
        }
    }

    private function runTypeCase(string $type, string $direction): void
    {
        $tenant = Tenant::create(['name' => "Tenant {$type}", 'slug' => 'tenant-'.str_replace('_', '-', $type)]);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$type}", 'code' => "BR-{$type}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$type}", 'code' => "WH-{$type}"]);
        $product = Product::create([
            'name' => "Producto {$type}",
            'sku' => "SKU-{$type}",
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        // Stock previo para que salidas/daños tengan de donde restar.
        app(InventoryMovementService::class)->purchase($warehouse, $product, 50, 5.0);

        $user = $this->createUser($tenant);
        $this->grantRole($tenant, $user, "Role {$type}", [
            'inventory.manual_movements.create',
            'inventory.manual_movements.approve',
            'inventory.manual_movements.view',
        ]);

        $movement = InventoryManualMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => $type,
            'reason' => "Movimiento {$type}",
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $before = (float) StockBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first()
            ->quantity_available;

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory/manual-movements/{$movement->id}/approve");

        $response->assertOk();

        $after = (float) StockBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first()
            ->quantity_available;

        // Entradas suben, salidas/daños bajan.
        if ($direction === 'in') {
            $this->assertSame(53.0, $after, "Tipo {$type} debe incrementar stock.");
        } else {
            $this->assertSame(47.0, $after, "Tipo {$type} debe decrementar stock.");
        }

        $this->assertDatabaseHas('inventory_manual_movements', [
            'id' => $movement->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => $direction === 'in' ? 'adjustment_in' : ($direction === 'damaged' ? 'damaged' : 'adjustment_out'),
        ]);
    }

    public function test_approve_with_product_variant_moves_that_variant_stock(): void
    {
        $cases = [
            ['type' => 'adjustment_in', 'expected' => 6.0],   // 3 + 3
            ['type' => 'internal_consumption', 'expected' => 0.0], // 3 - 3
            ['type' => 'loss', 'expected' => 0.0],           // 3 dañadas -> available 0
        ];

        foreach ($cases as $case) {
            $this->runVariantTypeCase($case['type'], $case['expected']);
        }
    }

    private function runVariantTypeCase(string $type, float $expectedAvailable): void
    {
        $tenant = Tenant::create(['name' => "Tenant Var {$type}", 'slug' => 'tenant-var-'.str_replace('_', '-', $type)]);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal Var {$type}", 'code' => "BR-VAR-{$type}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen Var {$type}", 'code' => "WH-VAR-{$type}"]);
        $product = Product::create([
            'name' => "Producto Var {$type}",
            'sku' => "SKU-VAR-{$type}",
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $variant = $product->variants()->create([
            'color' => 'Azul',
            'color_hex' => '#2563EB',
            'sku_variant' => "SKU-VAR-{$type}-AZUL",
            'is_active' => true,
            'position' => 1,
        ]);

        // Stock SOLO en la variante Azul.
        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: 3,
            unitCost: 5.0,
            productVariantId: $variant->id,
        );

        $user = $this->createUser($tenant);
        $this->grantRole($tenant, $user, "Role Var {$type}", [
            'inventory.manual_movements.create',
            'inventory.manual_movements.approve',
            'inventory.manual_movements.view',
        ]);

        $movement = InventoryManualMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 3,
            'type' => $type,
            'reason' => "Movimiento variante {$type}",
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory/manual-movements/{$movement->id}/approve");

        $response->assertOk();

        $balance = StockBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertNotNull($balance, "Debe existir stock balance para la variante ({$type}).");
        $this->assertSame($expectedAvailable, (float) $balance->quantity_available, "Stock de la variante tras {$type}");

        // El stock de la variante NULL no debe tocarse.
        $nullBalance = StockBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();
        $this->assertNull($nullBalance, "No debe crearse balance sin variante ({$type}).");

        $this->assertDatabaseHas('stock_movements', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    public function test_approve_returns_422_when_insufficient_stock_instead_of_500(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant Sin Stock', 'slug' => 'tenant-sin-stock']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Sucursal', 'code' => 'BR-SIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-SIN']);
        $product = Product::create([
            'name' => 'Producto Sin Stock',
            'sku' => 'SKU-SIN-STOCK',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        // Sin stock previo.
        $user = $this->createUser($tenant);
        $this->grantRole($tenant, $user, 'Role Sin Stock', [
            'inventory.manual_movements.create',
            'inventory.manual_movements.approve',
            'inventory.manual_movements.view',
        ]);

        $movement = InventoryManualMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => 'adjustment_out',
            'reason' => 'Salida sin stock',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory/manual-movements/{$movement->id}/approve");

        $response->assertStatus(422);
        $this->assertDatabaseHas('inventory_manual_movements', [
            'id' => $movement->id,
            'status' => 'pending',
        ]);
    }

    private function createUser(Tenant $tenant): User
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
