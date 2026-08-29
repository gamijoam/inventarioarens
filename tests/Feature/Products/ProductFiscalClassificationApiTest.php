<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductFiscalClassificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_be_assigned_a_tax_rate_and_syncs_its_code(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Productos IVA', 'slug' => 'empresa-productos-iva']);
        $taxRate = $this->taxRate($tenant, 'IVA16', FiscalTaxRate::CATEGORY_TAXABLE, 16);
        $user = $this->userInTenant($tenant, ['products.create', 'products.update']);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Producto Gravado',
                'sku' => 'PRODUCTO-IVA-16',
                'base_price' => 100,
                'fiscal_tax_rate_id' => $taxRate->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fiscal_tax_rate_id', $taxRate->id)
            ->assertJsonPath('data.fiscal_tax_rate.code', 'IVA16')
            ->assertJsonPath('data.fiscal_tax_rate.category', FiscalTaxRate::CATEGORY_TAXABLE);

        $productId = (int) $response->json('data.id');
        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product.created')
            ->latest('id')
            ->first();
        $payload = json_decode((string) $event->payload, true);

        $this->assertSame('IVA16', $payload['fiscal_tax_rate_code']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$productId}", ['fiscal_tax_rate_id' => null])
            ->assertOk()
            ->assertJsonPath('data.fiscal_tax_rate_id', null)
            ->assertJsonPath('data.fiscal_tax_rate', null);
    }

    public function test_product_cannot_use_a_tax_rate_from_another_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-productos-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-productos-b']);
        $product = $this->product($tenantA);
        $taxRateB = $this->taxRate($tenantB, 'IVA16', FiscalTaxRate::CATEGORY_TAXABLE, 16);
        $userA = $this->userInTenant($tenantA, ['products.update']);

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->patchJson("/api/products/{$product->id}", ['fiscal_tax_rate_id' => $taxRateB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fiscal_tax_rate_id');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'tenant_id' => $tenantA->id,
            'fiscal_tax_rate_id' => null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function product(Tenant $tenant): Product
    {
        $this->useTenant($tenant);

        return Product::create([
            'name' => 'Producto sin IVA',
            'sku' => 'PRODUCTO-SIN-IVA',
            'base_price' => 100,
        ]);
    }

    private function taxRate(Tenant $tenant, string $code, string $category, float $rate): FiscalTaxRate
    {
        $this->useTenant($tenant);

        return FiscalTaxRate::create([
            'code' => $code,
            'name' => $code,
            'rate' => $rate,
            'category' => $category,
            'is_active' => true,
        ]);
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        $role = Role::findOrCreate('Product Fiscal Tester', 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
