<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Services\ExchangeRateActivationService;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Services\IntercompanyNotificationService;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WsHubEventBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_exchange_rate_broadcasts_to_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        app(TenantManager::class)->set($tenant);

        $type = ExchangeRateType::create([
            'tenant_id' => $tenant->id,
            'code' => 'BCV',
            'name' => 'Banco Central de Venezuela',
            'is_default' => true,
        ]);

        $rate = ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $type->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 45.85,
            'effective_at' => now(),
            'is_active' => false,
        ]);

        $service = app(ExchangeRateActivationService::class);
        $service->activate($rate);

        Http::assertSent(function ($request) use ($tenant) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === "tenant:{$tenant->id}"
                && $request['event'] === 'rate.updated'
                && (float) $request['data']['rate'] === 45.85;
        });
    }

    public function test_deactivating_exchange_rate_broadcasts_to_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Test Tenant 2', 'slug' => 'test-tenant-2']);
        app(TenantManager::class)->set($tenant);

        $type = ExchangeRateType::create([
            'tenant_id' => $tenant->id,
            'code' => 'PARALELO',
            'name' => 'Paralelo',
            'is_default' => false,
        ]);

        $rate = ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $type->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 50.00,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $service = app(ExchangeRateActivationService::class);
        $service->deactivate($rate);

        Http::assertSent(function ($request) use ($tenant) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === "tenant:{$tenant->id}"
                && $request['event'] === 'rate.updated'
                && $request['data']['is_active'] === false;
        });
    }

    public function test_closing_cash_register_session_broadcasts_to_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Cash Tenant', 'slug' => 'cash-tenant']);
        app(TenantManager::class)->set($tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUC-01',
            'name' => 'Principal',
            'address' => 'Av 1',
            'status' => 'active',
        ]);

        $register = CashRegister::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-1',
            'name' => 'Caja 1',
            'status' => CashRegister::STATUS_ACTIVE,
        ]);

        $service = app(CashRegisterService::class);
        $session = $service->open($user, $branch, $register, $user, [
            'opening_cash_usd' => 100,
            'opening_cash_ves' => 0,
        ]);

        $service->close($session, [
            'counted_base_amount' => 100,
            'counted_local_amount' => 0,
            'counted_cash_usd' => 100,
            'counted_cash_ves' => 0,
            'closing_notes' => 'Cierre normal del dia',
        ], $user);

        Http::assertSent(function ($request) use ($tenant, $session) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === "tenant:{$tenant->id}"
                && $request['event'] === 'cash_register.closed'
                && $request['data']['session_id'] === $session->id;
        });
    }

    public function test_intercompany_notification_broadcasts_to_destination_and_parent_group(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $group = Tenant::create(['name' => 'Grupo Arens', 'slug' => 'grupo-arens', 'is_group' => true]);
        $spinoff = Tenant::create(['name' => 'Sucursal Norte', 'slug' => 'sucursal-norte', 'parent_id' => $group->id, 'is_group' => false]);
        app(TenantManager::class)->set($group);

        $user = User::factory()->create();
        $user->tenants()->attach($group->id);

        $branch = Branch::create(['tenant_id' => $group->id, 'code' => 'BR-GRP', 'name' => 'Branch Group', 'status' => 'active']);
        $wh = Warehouse::create(['tenant_id' => $group->id, 'branch_id' => $branch->id, 'code' => 'WH-GRP', 'name' => 'Wh Group', 'status' => 'active']);

        $request = InventoryTransferRequest::create([
            'sequence' => 1,
            'document_number' => 'ITR-001',
            'origin_tenant_id' => $group->id,
            'destination_tenant_id' => $spinoff->id,
            'from_warehouse_id' => $wh->id,
            'status' => InventoryTransferRequest::STATUS_REQUESTED,
            'flow_type' => InventoryTransferRequest::FLOW_STOCK_REQUEST,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $service = app(IntercompanyNotificationService::class);
        $service->record($request, IntercompanyNotificationService::CREATED, $user);

        // Debe enviar al tenant destino
        Http::assertSent(function ($req) use ($spinoff) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "tenant:{$spinoff->id}"
                && $req['event'] === 'intercompany.notification'
                && $req['data']['document_number'] === 'ITR-001';
        });

        // Debe enviar al canal del grupo (matriz)
        Http::assertSent(function ($req) use ($group) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "group:{$group->id}"
                && $req['event'] === 'intercompany.notification'
                && $req['data']['document_number'] === 'ITR-001';
        });
    }

    public function test_activating_exchange_rate_on_parent_broadcasts_to_group_and_daughters(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $parent = Tenant::create(['name' => 'Matriz Arens', 'slug' => 'matriz-arens', 'is_group' => true]);
        $daughter = Tenant::create(['name' => 'Tienda 2', 'slug' => 'tienda-2', 'parent_id' => $parent->id, 'is_group' => false]);
        app(TenantManager::class)->set($parent);

        $type = ExchangeRateType::create([
            'tenant_id' => $parent->id,
            'code' => 'BCV',
            'name' => 'Banco Central',
            'is_default' => true,
        ]);

        $rate = ExchangeRate::create([
            'tenant_id' => $parent->id,
            'exchange_rate_type_id' => $type->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 50.00,
            'effective_at' => now(),
            'is_active' => false,
        ]);

        $service = app(ExchangeRateActivationService::class);
        $service->activate($rate);

        // Canal del grupo
        Http::assertSent(function ($req) use ($parent) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "group:{$parent->id}"
                && $req['event'] === 'rate.updated';
        });

        // Canal de la tienda hija
        Http::assertSent(function ($req) use ($daughter) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "tenant:{$daughter->id}"
                && $req['event'] === 'rate.updated';
        });
    }

    public function test_internal_inventory_transfer_broadcasts_to_tenant_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Tienda Local', 'slug' => 'tienda-local']);
        app(TenantManager::class)->set($tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'code' => 'BR-01',
            'name' => 'Branch 1',
            'status' => 'active',
        ]);

        $w1 = Warehouse::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Almacen Origen',
            'code' => 'WH-ORIG',
            'status' => 'active',
        ]);

        $w2 = Warehouse::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Almacen Destino',
            'code' => 'WH-DEST',
            'status' => 'active',
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Transferencia',
            'sku' => 'SKU-TRF',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        \App\Modules\Inventory\Models\StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $w1->id,
            'product_id' => $product->id,
            'quantity_available' => 50,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $group = Tenant::create(['name' => 'Grupo Central', 'slug' => 'grupo-central', 'is_group' => true]);
        $tenant->update(['parent_id' => $group->id]);

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'from_warehouse_id' => $w1->id,
            'to_warehouse_id' => $w2->id,
            'validation_mode' => 'simple',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ]);

        Http::assertSent(function ($req) use ($tenant, $transfer) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "tenant:{$tenant->id}"
                && $req['event'] === 'inventory-transfer.created'
                && $req['data']['id'] === $transfer->id;
        });

        Http::assertSent(function ($req) use ($group, $transfer) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "group:{$group->id}"
                && $req['event'] === 'inventory-transfer.created'
                && $req['data']['id'] === $transfer->id;
        });
    }

    public function test_pos_order_paid_in_daughter_broadcasts_to_parent_group_live_feed(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $group = Tenant::create(['name' => 'Grupo Tiendas', 'slug' => 'grupo-tiendas', 'is_group' => true]);
        $daughter = Tenant::create(['name' => 'Sucursal 1', 'slug' => 'sucursal-1', 'parent_id' => $group->id, 'is_group' => false]);
        app(TenantManager::class)->set($daughter);

        $user = User::factory()->create();
        $user->tenants()->attach($daughter->id);

        $branch = Branch::create(['tenant_id' => $daughter->id, 'code' => 'BR-POS', 'name' => 'Branch 1', 'status' => 'active']);
        $warehouse = Warehouse::create(['tenant_id' => $daughter->id, 'branch_id' => $branch->id, 'code' => 'WH-POS', 'name' => 'Warehouse 1', 'status' => 'active']);

        $rateType = ExchangeRateType::create(['tenant_id' => $daughter->id, 'code' => 'BCV', 'name' => 'BCV', 'is_default' => true]);
        ExchangeRate::create([
            'tenant_id' => $daughter->id,
            'exchange_rate_type_id' => $rateType->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 45.0,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $cashRegister = CashRegister::create([
            'tenant_id' => $daughter->id,
            'branch_id' => $branch->id,
            'code' => 'CR-01',
            'name' => 'Caja 1',
            'status' => CashRegister::STATUS_ACTIVE,
        ]);

        $session = app(CashRegisterService::class)->open($user, $branch, $cashRegister, $user, [
            'opening_cash_usd' => 50,
            'opening_cash_ves' => 0,
        ]);

        $product = Product::create([
            'tenant_id' => $daughter->id,
            'name' => 'Producto Venta',
            'sku' => 'SKU-VENTA',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 20,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        \App\Modules\Inventory\Models\StockBalance::create([
            'tenant_id' => $daughter->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 10,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $checkout = app(\App\Modules\POS\Services\PosCheckoutService::class);
        $order = $checkout->checkout($user, $session, [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'warehouse_id' => $warehouse->id,
                'unit_price' => 20,
            ],
        ], [
            [
                'method' => 'cash',
                'currency' => 'USD',
                'amount' => 20,
            ],
        ]);

        // Debe haber enviado a tenant de la hija
        Http::assertSent(function ($req) use ($daughter, $order) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "tenant:{$daughter->id}"
                && $req['event'] === 'pos.order.paid'
                && $req['data']['order_id'] === $order->id;
        });

        // Y debe haber enviado al canal del grupo (matriz) con datos de tienda e importe
        Http::assertSent(function ($req) use ($group, $order, $daughter) {
            return $req->url() === 'http://127.0.0.1:16666/publish'
                && $req['channel'] === "group:{$group->id}"
                && $req['event'] === 'pos.order.paid'
                && $req['data']['order_id'] === $order->id
                && $req['data']['tenant_name'] === $daughter->name
                && (float) $req['data']['total_base_amount'] === 20.0;
        });
    }
}
