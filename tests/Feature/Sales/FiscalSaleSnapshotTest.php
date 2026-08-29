<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Fiscal\Models\FiscalTaxRate;
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

class FiscalSaleSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_totals_include_iva_and_confirmation_freezes_final_rate(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Fiscal Ventas', 'slug' => 'empresa-fiscal-ventas']);
        [$warehouse, $product] = $this->pricedProduct($tenant);
        $taxRate = $this->taxRate($tenant, 16);
        $product->update(['fiscal_tax_rate_id' => $taxRate->id]);
        $user = $this->userInTenant($tenant, ['sales.create', 'sales.view']);

        $saleId = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_base_amount', 232)
            ->assertJsonPath('data.total_local_amount', 139200)
            ->assertJsonPath('data.fiscal_taxable_base_amount', 200)
            ->assertJsonPath('data.fiscal_tax_local_amount', 19200)
            ->assertJsonPath('data.fiscal_snapshot_at', null)
            ->assertJsonPath('data.items.0.fiscal_tax_code', 'IVA16')
            ->assertJsonPath('data.items.0.fiscal_tax_rate', 16)
            ->assertJsonPath('data.items.0.fiscal_tax_base_amount', 32)
            ->json('data.id');

        $taxRate->update(['rate' => 15]);
        $this->useTenant($tenant);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.total_base_amount', 230)
            ->assertJsonPath('data.total_local_amount', 138000)
            ->assertJsonPath('data.fiscal_tax_base_amount', 30)
            ->assertJsonPath('data.items.0.fiscal_tax_rate', 15)
            ->assertJsonPath('data.items.0.fiscal_tax_base_amount', 30);

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'sale.confirmed')
            ->latest('id')
            ->first();
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('30.0000', $payload['fiscal_tax_base_amount']);
        $this->assertSame('15.0000', $payload['items'][0]['fiscal_tax_rate']);
        $this->assertNotEmpty($payload['items'][0]['fiscal_snapshot_at']);

        $this->assertNotNull(Sale::query()->findOrFail($saleId)->fiscal_snapshot_at);

        $taxRate->update(['rate' => 5]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.total_base_amount', 230)
            ->assertJsonPath('data.fiscal_tax_base_amount', 30)
            ->assertJsonPath('data.items.0.fiscal_tax_rate', 15);
    }

    public function test_unclassified_product_keeps_legacy_total_but_records_missing_tax_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sin Clasificar', 'slug' => 'empresa-sin-clasificar']);
        [$warehouse, $product] = $this->pricedProduct($tenant);
        $user = $this->userInTenant($tenant, ['sales.create', 'sales.view']);

        $saleId = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_base_amount', 100)
            ->assertJsonPath('data.fiscal_tax_base_amount', 0)
            ->assertJsonPath('data.items.0.fiscal_tax_category', null)
            ->json('data.id');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.total_base_amount', 100)
            ->assertJsonPath('data.fiscal_tax_base_amount', 0)
            ->assertJsonPath('data.fiscal_snapshot_at', fn ($value): bool => $value !== null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function pricedProduct(Tenant $tenant): array
    {
        $this->useTenant($tenant);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-FISCAL']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-FISCAL']);
        $rateType = ExchangeRateType::create(['code' => 'PARALELO', 'name' => 'Tasa paralelo', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 600,
            'effective_at' => '2026-08-29 12:00:00',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Producto fiscal',
            'sku' => 'SKU-FISCAL',
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_VES,
            'sale_exchange_rate_type_id' => $rateType->id,
            'track_stock' => false,
        ]);

        return [$warehouse, $product];
    }

    private function taxRate(Tenant $tenant, float $rate): FiscalTaxRate
    {
        $this->useTenant($tenant);

        return FiscalTaxRate::create([
            'code' => 'IVA16',
            'name' => 'IVA general',
            'rate' => $rate,
            'category' => FiscalTaxRate::CATEGORY_TAXABLE,
            'is_active' => true,
        ]);
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);
        $role = Role::findOrCreate('Fiscal Sales Tester', 'web');
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
