<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifica que la politica de catalogo compartido solo permite editar
 * productos/listas/metodos/tasas desde el grupo (Owner) o desde el
 * mismo tenant raiz, y rechaza a un Administrador de tienda (spinoff).
 */
class SharedCatalogWritePolicyTest extends TestCase
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function makeGroupWithOwner(string $name, string $slug): array
    {
        $group = Tenant::create(['name' => $name, 'slug' => $slug, 'is_group' => true]);

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-shared@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $owner->tenants()->attach($group, ['status' => 'active']);

        $role = Role::create(['name' => 'Owner', 'guard_name' => 'web']);
        $role->tenant_id = $group->id;
        $role->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($group->id);
        $owner->assignRole($role);
        $owner->givePermissionTo([
            'products.view', 'products.create', 'products.update', 'products.delete',
            'payment_methods.view', 'payment_methods.update',
            'currency.view', 'currency.manage',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$group, $owner];
    }

    private function makeSpinoffAdmin(Tenant $group, string $slug, string $name, string $email): array
    {
        $spinoff = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'is_group' => false,
            'parent_id' => $group->id,
        ]);

        $admin = User::create([
            'name' => 'Admin '.$name,
            'email' => $email,
            'password' => bcrypt('secret123'),
        ]);
        $admin->tenants()->attach($spinoff, ['status' => 'active']);
        setPermissionsTeamId($spinoff->id);
        $role = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $role->tenant_id = $spinoff->id;
        $role->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($spinoff->id);
        $admin->assignRole($role);
        $admin->givePermissionTo([
            'products.view', 'products.create', 'products.update', 'products.delete',
            'payment_methods.view', 'payment_methods.update',
            'currency.view', 'currency.manage',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$spinoff, $admin];
    }

    public function test_spinoff_admin_cannot_edit_product_owned_by_group(): void
    {
        [$group, $owner] = $this->makeGroupWithOwner('Holding', 'holding-shared-policy');
        [$spinoff, $admin] = $this->makeSpinoffAdmin($group, 'tienda-1', 'Tienda 1', 'admin1@shared.test');

        $this->useTenant($group);
        $brand = Brand::create(['name' => 'Generic', 'slug' => 'generic']);
        $product = Product::create([
            'name' => 'Producto Grupo',
            'sku' => 'GP-1',
            'tracking_type' => 'quantity',
            'brand_id' => $brand->id,
        ]);

        // Con scope estricto por tenant, el spinoff no encuentra el producto
        // del grupo en su contexto: el endpoint responde 404 (no 403) porque
        // el recurso no es visible para el spinoff.
        $this->useTenant($spinoff);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $spinoff->slug)
            ->patchJson("/api/products/{$product->id}", [
                'name' => 'Hackeado',
            ]);

        $response->assertStatus(404);

        $product->refresh();
        $this->assertSame('Producto Grupo', $product->name);
    }

    public function test_owner_can_edit_shared_product_from_group_context(): void
    {
        [$group, $owner] = $this->makeGroupWithOwner('Holding', 'holding-shared-policy-2');
        $this->useTenant($group);
        $brand = Brand::create(['name' => 'Generic', 'slug' => 'generic']);
        $product = Product::create([
            'name' => 'Original',
            'sku' => 'OWN-1',
            'tracking_type' => 'quantity',
            'brand_id' => $brand->id,
        ]);

        $this->useTenant($group);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->patchJson("/api/products/{$product->id}", [
                'name' => 'Actualizado por Owner',
            ]);

        $response->assertOk();
        $product->refresh();
        $this->assertSame('Actualizado por Owner', $product->name);
    }

    public function test_spinoff_admin_cannot_create_price_list(): void
    {
        [$group] = $this->makeGroupWithOwner('Holding', 'holding-shared-policy-3');
        [$spinoff, $admin] = $this->makeSpinoffAdmin($group, 'tienda-pl', 'Tienda PL', 'admin2@shared.test');
        $this->useTenant($spinoff);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $spinoff->slug)
            ->postJson('/api/price-lists', [
                'code' => 'PL-SUCURSAL',
                'name' => 'Lista desde sucursal',
                'is_default' => false,
                'is_active' => true,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('price_lists', ['code' => 'PL-SUCURSAL']);
    }

    public function test_spinoff_admin_cannot_edit_shared_payment_method(): void
    {
        [$group, $owner] = $this->makeGroupWithOwner('Holding', 'holding-shared-policy-4');
        $this->useTenant($group);
        $method = PaymentMethod::create([
            'code' => 'CASH-GRUPO',
            'name' => 'Efectivo Grupo',
            'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        [$spinoff, $admin] = $this->makeSpinoffAdmin($group, 'tienda-pm', 'Tienda PM', 'admin3@shared.test');
        $this->useTenant($spinoff);

        // Con scope estricto por tenant, el spinoff no ve el PaymentMethod
        // del grupo: el endpoint responde 404 (no 403).
        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $spinoff->slug)
            ->patchJson("/api/payment-methods/{$method->id}", [
                'name' => 'Cambiado por sucursal',
            ]);

        $response->assertStatus(404);
        $method->refresh();
        $this->assertSame('Efectivo Grupo', $method->name);
    }

    public function test_spinoff_admin_cannot_edit_shared_exchange_rate_type(): void
    {
        [$group, $owner] = $this->makeGroupWithOwner('Holding', 'holding-shared-policy-5');
        $this->useTenant($group);
        $rateType = ExchangeRateType::create([
            'code' => 'BCV-GRUPO',
            'name' => 'BCV Grupo',
            'is_default' => true,
            'is_active' => true,
        ]);

        [$spinoff, $admin] = $this->makeSpinoffAdmin($group, 'tienda-rt', 'Tienda RT', 'admin4@shared.test');

        $this->assertDatabaseMissing('exchange_rate_types', [
            'tenant_id' => $spinoff->id,
            'code' => 'BCV-GRUPO',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $spinoff->slug)
            ->patchJson("/api/currency/rate-types/{$rateType->id}", [
                'name' => 'Tasa modificada por sucursal',
            ]);

        $response->assertStatus(403);
        $rateType->refresh();
        $this->assertSame('BCV Grupo', $rateType->name);
    }
}
