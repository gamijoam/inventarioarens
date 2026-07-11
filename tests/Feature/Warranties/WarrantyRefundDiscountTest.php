<?php

namespace Tests\Feature\Warranties;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarrantyRefundDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_cap_uses_discounted_total_not_list_price(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Garantia', 'slug' => 'tienda-garantia']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Garantias', [
            'warranty_policies.view', 'warranty_policies.manage',
            'warranties.view', 'warranties.create', 'warranties.review', 'warranties.deliver', 'warranties.resolve',
            'sales.view', 'sales.create', 'sales.cancel',
            'accounts_receivable.view', 'accounts_receivable.collect',
            'cash_register.view', 'cash_register.open', 'cash_register.move', 'cash_register.close',
        ]);

        $policy = $this->policy($tenant, 'Garantia 30 dias', 30);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy);

        $this->useTenant($tenant);
        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, 'Stock para venta con descuento');

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'discount_reason' => 'Promocion lanzamiento',
        ]]);

        $sale = app(SaleService::class)->confirm($sale, $user);
        $saleItem = $sale->items()->firstOrFail();

        $this->assertSame('100.0000', (string) $saleItem->base_unit_price, 'Precio lista debe ser 100 USD');
        $this->assertSame('150.0000', (string) $saleItem->base_total_amount, 'Despues de descuento 50 USD, total = 150 USD');

        $claimResponse = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'issue_description' => 'Pantalla rota',
            ])
            ->assertCreated()
            ->json('data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claimResponse['id']}/review", [
                'status' => WarrantyClaim::STATUS_APPROVED,
                'diagnosis' => 'Falla cubierta',
                'resolution_type' => WarrantyClaim::RESOLUTION_REFUND,
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claimResponse['id']}/resolve", [
                'resolution_type' => WarrantyClaim::RESOLUTION_REFUND,
                'refund_currency' => Product::CURRENCY_USD,
                'refund_amount' => 100,
                'apply_to_receivable_balance' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_amount']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claimResponse['id']}/resolve", [
                'resolution_type' => WarrantyClaim::RESOLUTION_REFUND,
                'refund_currency' => Product::CURRENCY_USD,
                'refund_amount' => 76,
                'apply_to_receivable_balance' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_amount']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claimResponse['id']}/resolve", [
                'resolution_type' => WarrantyClaim::RESOLUTION_REFUND,
                'refund_currency' => Product::CURRENCY_USD,
                'refund_amount' => 75,
                'apply_to_receivable_balance' => true,
            ])
            ->assertOk();

        $claim = WarrantyClaim::findOrFail($claimResponse['id']);
        $this->assertSame(75.0, (float) $claim->refund_amount_base);
    }

    public function test_refund_cap_with_full_line_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Garantia 2', 'slug' => 'tienda-garantia-2']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Garantias', [
            'warranty_policies.view', 'warranty_policies.manage',
            'warranties.view', 'warranties.create', 'warranties.review', 'warranties.deliver', 'warranties.resolve',
            'sales.view', 'sales.create', 'sales.cancel',
            'accounts_receivable.view', 'accounts_receivable.collect',
            'cash_register.view', 'cash_register.open', 'cash_register.move', 'cash_register.close',
        ]);

        $policy = $this->policy($tenant, 'Garantia 30 dias', 30);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy);

        $this->useTenant($tenant);
        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, 'Stock para venta con descuento total');

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'discount_type' => 'percent',
            'discount_value' => 100,
            'discount_reason' => 'Cortesia por reclamo',
        ]]);

        $sale = app(SaleService::class)->confirm($sale, $user);
        $saleItem = $sale->items()->firstOrFail();

        $this->assertEquals(0.0, (float) $saleItem->base_total_amount, 'Descuento 100% deja total en 0');

        $branch = Branch::where('id', $warehouse->branch_id)->firstOrFail();
        $session = $this->cashRegisterSession($tenant, $user, $branch->id);

        $claimResponse = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'issue_description' => 'Defecto de fabrica',
            ])
            ->assertCreated()
            ->json('data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claimResponse['id']}/review", [
                'status' => WarrantyClaim::STATUS_APPROVED,
                'diagnosis' => 'Aprobado',
                'resolution_type' => WarrantyClaim::RESOLUTION_REFUND,
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claimResponse['id']}/resolve", [
                'resolution_type' => WarrantyClaim::RESOLUTION_REFUND,
                'refund_currency' => Product::CURRENCY_USD,
                'refund_amount' => 0.01,
                'refund_method' => CashRegisterMovement::METHOD_CASH,
                'refund_cash_register_session_id' => $session->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_amount']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function policy(Tenant $tenant, string $name, int $days): WarrantyPolicy
    {
        $this->useTenant($tenant);

        return WarrantyPolicy::create([
            'name' => $name,
            'duration_days' => $days,
            'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
            'conditions' => 'Cubre defectos',
        ]);
    }

    private function warehouseAndProduct(Tenant $tenant, WarrantyPolicy $policy): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Principal', 'code' => 'MAIN-01']);
        $product = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'SAMSUNG-A06-'.uniqid(),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'warranty_policy_id' => $policy->id,
        ]);

        return [$warehouse, $product];
    }

    private function cashRegisterSession(Tenant $tenant, User $cashier, int $branchId): CashRegisterSession
    {
        $this->useTenant($tenant);

        return CashRegisterSession::create([
            'branch_id' => $branchId,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): Role
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $role;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
