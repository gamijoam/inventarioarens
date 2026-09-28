<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossBranchStockLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_branch_stock_lookup_returns_stock_from_sibling_companies(): void
    {
        // 1. Crear Grupo y dos empresas hermanas (Spinoff 1 y Spinoff 2)
        $group = Tenant::create(['name' => 'Grupo Central', 'slug' => 'grupo-central', 'is_group' => true]);
        $spinoffA = Tenant::create(['name' => 'Sucursal Norte', 'slug' => 'sucursal-norte', 'parent_id' => $group->id, 'is_group' => false]);
        $spinoffB = Tenant::create(['name' => 'Sucursal Sur', 'slug' => 'sucursal-sur', 'parent_id' => $group->id, 'is_group' => false]);

        $tm = app(TenantManager::class);

        // Almacenes
        $tm->set($spinoffB);
        $branchB = Branch::create(['tenant_id' => $spinoffB->id, 'name' => 'Sede Sur', 'code' => 'SUR-01']);
        $warehouseB = Warehouse::create([
            'tenant_id' => $spinoffB->id,
            'branch_id' => $branchB->id,
            'name' => 'Almacén Principal Sur',
            'code' => 'ALM-SUR',
            'is_active' => true,
        ]);

        // Usuario en Spinoff A
        $userA = User::factory()->create();
        $userA->tenants()->attach($spinoffA->id, ['status' => 'active']);

        // Producto maestro en grupo
        $tm->set($group);
        $masterProduct = Product::create([
            'tenant_id' => $group->id,
            'name' => 'Batería Bosch 12V',
            'sku' => 'BAT-BOSCH-12V',
            'is_catalog_master' => true,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'sale_currency' => Product::CURRENCY_USD,
            'base_price' => 80.00,
        ]);

        // Copia en Spinoff A (sin stock)
        $tm->set($spinoffA);
        $productA = Product::create([
            'tenant_id' => $spinoffA->id,
            'catalog_product_id' => $masterProduct->id,
            'name' => 'Batería Bosch 12V',
            'sku' => 'BAT-BOSCH-12V',
            'is_catalog_master' => false,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'sale_currency' => Product::CURRENCY_USD,
            'base_price' => 80.00,
        ]);

        // Copia en Spinoff B (con 15 unidades de stock)
        $tm->set($spinoffB);
        $productB = Product::create([
            'tenant_id' => $spinoffB->id,
            'catalog_product_id' => $masterProduct->id,
            'name' => 'Batería Bosch 12V',
            'sku' => 'BAT-BOSCH-12V',
            'is_catalog_master' => false,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'sale_currency' => Product::CURRENCY_USD,
            'base_price' => 80.00,
        ]);

        StockBalance::create([
            'tenant_id' => $spinoffB->id,
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'quantity_available' => 15,
            'quantity_reserved' => 2,
            'quantity_damaged' => 0,
        ]);

        $tm->set($spinoffA);

        // 2. Consulta desde POS de Sucursal Norte (Spinoff A)
        $response = $this->actingAs($userA)
            ->withHeader('X-Tenant', $spinoffA->slug)
            ->getJson("/api/pos/products/{$productA->id}/cross-branch-stock")
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals($productA->id, $data['product']['id']);
        $this->assertEquals('BAT-BOSCH-12V', $data['product']['sku']);

        // Debe contener a Sucursal Sur con 15 disponibles
        $branches = $data['branches'];
        $this->assertNotEmpty($branches);

        $branchSur = collect($branches)->firstWhere('tenant_id', $spinoffB->id);
        $this->assertNotNull($branchSur);
        $this->assertEquals('Sucursal Sur', $branchSur['tenant_name']);
        $this->assertEquals(15, $branchSur['total_available']);
        $this->assertCount(1, $branchSur['warehouses']);
        $this->assertEquals('Almacén Principal Sur', $branchSur['warehouses'][0]['warehouse_name']);
        $this->assertEquals(15, $branchSur['warehouses'][0]['quantity_available']);
    }
}
