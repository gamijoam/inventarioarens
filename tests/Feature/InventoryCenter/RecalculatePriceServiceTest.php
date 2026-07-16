<?php

namespace Tests\Feature\InventoryCenter;

use App\Models\User;
use App\Modules\InventoryCenter\Services\RecalculatePriceService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecalculatePriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('products.update', 'web');

        $this->tenant = Tenant::create(['name' => 'Test Co', 'slug' => 'test-co']);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant->id, ['status' => 'active']);
        $this->user->givePermissionTo(['products.update']);
        $this->actingAs($this->user);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nevera',
            'sku' => 'N-001',
            'unit_of_measure' => Product::UNIT_UNIT,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'track_stock' => true,
            'base_price' => 100.00,
            'profit_margin' => 25.00,
            'average_cost' => 80.00,
            'sale_currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);
    }

    public function test_recalculate_uses_wac_and_margin_without_rounding(): void
    {
        $service = app(RecalculatePriceService::class);

        $result = $service->recalculate($this->product);

        // Formula: 80 * (1 + 25/100) = 100.00 (sin redondeo psicologico).
        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(25.0, $result['profit_margin']);
        $this->assertEquals(80.0, $result['wac']);
        $this->assertEquals(100.00, (float) $this->product->fresh()->base_price);
    }

    public function test_recalculate_with_overridden_margin_updates_and_recomputes(): void
    {
        $service = app(RecalculatePriceService::class);

        // 80 * (1 + 35/100) = 108.00
        $result = $service->recalculate($this->product, 35.00);

        $this->assertEquals(108.00, $result['base_price']);
        $this->assertEquals(35.0, (float) $this->product->fresh()->profit_margin);
    }

    public function test_recalculate_with_decimals_keeps_exact_result(): void
    {
        $this->product->update(['base_price' => 0, 'average_cost' => 33.33, 'profit_margin' => 33.33]);
        $service = app(RecalculatePriceService::class);

        // 33.33 * (1 + 33.33/100) = 44.4399 -> round a 2 decimales = 44.44
        $result = $service->recalculate($this->product);
        $this->assertEquals(44.44, $result['base_price']);
    }

    public function test_recalculate_throws_when_no_profit_margin(): void
    {
        $this->product->update(['profit_margin' => null]);
        $service = app(RecalculatePriceService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No hay margen definido');
        $service->recalculate($this->product);
    }

    public function test_recalculate_throws_when_no_wac(): void
    {
        $this->product->update(['average_cost' => null, 'profit_margin' => 25.00]);
        $service = app(RecalculatePriceService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No hay costo promedio');
        $service->recalculate($this->product);
    }
}
