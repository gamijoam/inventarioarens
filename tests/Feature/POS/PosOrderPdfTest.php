<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosOrderPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_generate_ticket_pdf_for_pos_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Test', 'slug' => 'empresa-test']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create([
            'name' => 'Sede Principal',
            'code' => 'SP-01',
            'status' => 'active',
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacén Central',
            'code' => 'ALM-01',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Producto Prueba',
            'sku' => 'SKU-001',
            'currency' => 'USD',
            'base_price' => 7.75,
            'status' => 'active',
        ]);

        $user = $this->userWithPosView($tenant);

        $cashRegister = CashRegister::create([
            'branch_id' => $branch->id,
            'code' => 'CAJA-01',
            'name' => 'Caja 1',
            'status' => 'open',
        ]);

        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $sale = Sale::create([
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $user->id,
            'total_amount' => 15.50,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'sale_currency' => 'USD',
            'unit_price' => 7.75,
            'total_amount' => 15.50,
            'base_unit_price' => 7.75,
            'base_total_amount' => 15.50,
        ]);

        $order = PosOrder::create([
            'branch_id' => $branch->id,
            'cash_register_session_id' => $session->id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'order_number' => 'ORD-0001',
            'status' => PosOrder::STATUS_PAID,
            'total_usd' => 15.50,
            'total_ves' => 620.00,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/pos/orders/{$order->id}/pdf?format=ticket");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_user_can_generate_invoice_pdf_for_pos_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Test', 'slug' => 'empresa-test']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create([
            'name' => 'Sede Principal',
            'code' => 'SP-01',
            'status' => 'active',
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacén Central',
            'code' => 'ALM-01',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Servicio Técnico',
            'sku' => 'SKU-002',
            'currency' => 'USD',
            'base_price' => 25.00,
            'status' => 'active',
        ]);

        $user = $this->userWithPosView($tenant);

        $sale = Sale::create([
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $user->id,
            'total_amount' => 25.00,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => 25.00,
            'total_amount' => 25.00,
            'base_unit_price' => 25.00,
            'base_total_amount' => 25.00,
        ]);

        $order = PosOrder::create([
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'order_number' => 'ORD-0002',
            'status' => PosOrder::STATUS_PAID,
            'total_usd' => 25.00,
            'total_ves' => 1000.00,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/pos/orders/{$order->id}/pdf?format=invoice");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    private function userWithPosView(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $tenant->users()->attach($user->id, ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $role = Role::firstOrCreate([
            'name' => 'Cajero',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        Permission::firstOrCreate(['name' => 'pos.view', 'guard_name' => 'web']);
        $role->syncPermissions(['pos.view']);
        $user->assignRole($role);

        return $user;
    }
}
