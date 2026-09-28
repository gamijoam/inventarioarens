<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\BulkPriceAdjustment;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\BulkPriceAdjustmentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkPriceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulate_calculates_price_adjustment_and_margins(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Test', 'slug' => 'empresa-test']);
        app(TenantManager::class)->set($tenant);

        $p1 = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Filtro de Aceite',
            'sku' => 'FL-001',
            'base_price' => 10.00,
            'average_cost' => 6.00,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $service = app(BulkPriceAdjustmentService::class);

        // Aumento del 20%
        $preview = $service->simulate(
            filters: ['product_ids' => [$p1->id]],
            rules: [
                'target' => 'base_price',
                'adjustment_type' => BulkPriceAdjustment::TYPE_PERCENTAGE_INCREASE,
                'adjustment_value' => 20,
                'rounding' => 'none',
            ]
        );

        $this->assertCount(1, $preview['items']);
        $item = $preview['items'][0];
        $this->assertEquals(10.00, $item['current_price']);
        $this->assertEquals(12.00, $item['new_price']);
        $this->assertEquals(2.00, $item['diff_amount']);
        $this->assertEquals(20.0, $item['diff_percent']);
        // Costo = 6, Precio = 12 -> Margen = (12 - 6) / 6 = 100%
        $this->assertEquals(100.0, $item['margin_after']);
    }

    public function test_apply_and_rollback_bulk_price_adjustment(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Empresa Test', 'slug' => 'empresa-test']);
        app(TenantManager::class)->set($tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $p1 = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pastillas de Freno',
            'sku' => 'BRK-001',
            'base_price' => 25.00,
            'average_cost' => 15.00,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $service = app(BulkPriceAdjustmentService::class);

        // 1. Aplicar aumento de $5 fijos
        $adjustment = $service->apply(
            user: $user,
            filters: ['product_ids' => [$p1->id]],
            rules: [
                'target' => 'base_price',
                'adjustment_type' => BulkPriceAdjustment::TYPE_FIXED_AMOUNT,
                'adjustment_value' => 5.00,
                'rounding' => 'none',
            ],
            name: 'Aumento $5 frena'
        );

        $this->assertEquals(30.00, (float) $p1->refresh()->base_price);
        $this->assertEquals(BulkPriceAdjustment::STATUS_APPLIED, $adjustment->status);
        $this->assertEquals(1, $adjustment->items_count);

        // Verificar que emitió evento WebSocket
        Http::assertSent(function ($request) use ($tenant) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === "tenant:{$tenant->id}"
                && $request['event'] === 'products.prices.updated';
        });

        // 2. Revertir (Rollback)
        $reverted = $service->rollback($adjustment, $user);
        $this->assertEquals(BulkPriceAdjustment::STATUS_REVERTED, $reverted->status);
        $this->assertEquals(25.00, (float) $p1->refresh()->base_price);
    }

    public function test_api_bulk_prices_simulate_and_apply_flow(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Empresa Test', 'slug' => 'empresa-test']);
        app(TenantManager::class)->set($tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $p = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Bujia Denso',
            'sku' => 'BUJ-001',
            'base_price' => 5.00,
            'average_cost' => 3.00,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        // 1. Simulate
        $simResponse = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products/bulk-prices/simulate', [
                'target' => 'base_price',
                'adjustment_type' => 'percentage_increase',
                'adjustment_value' => 50,
                'rounding' => 'none',
                'filters' => [
                    'product_ids' => [$p->id],
                ],
            ])
            ->assertOk();

        $this->assertEquals(7.50, $simResponse->json('items.0.new_price'));

        // 2. Apply
        $applyResponse = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products/bulk-prices/apply', [
                'name' => 'Aumento Bujias 50%',
                'target' => 'base_price',
                'adjustment_type' => 'percentage_increase',
                'adjustment_value' => 50,
                'rounding' => 'none',
                'filters' => [
                    'product_ids' => [$p->id],
                ],
            ])
            ->assertCreated();

        $adjId = $applyResponse->json('adjustment.id');
        $this->assertEquals(7.50, (float) $p->refresh()->base_price);

        // 3. History
        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/products/bulk-prices/history')
            ->assertOk()
            ->assertJsonPath('data.0.id', $adjId);

        // 4. Rollback
        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/bulk-prices/{$adjId}/rollback")
            ->assertOk()
            ->assertJsonPath('adjustment.status', 'reverted');

        $this->assertEquals(5.00, (float) $p->refresh()->base_price);
    }
}

