<?php

namespace Tests\Feature\CashRegister;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\ReportZService;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReportZCategoryBreakdownTest extends TestCase
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

    public function test_report_z_includes_categories_and_customers_breakdown(): void
    {
        $tenant = Tenant::create(['name' => 'Papelería y Más', 'slug' => 'papeleria-y-mas']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Sucursal Centro', 'code' => 'BR-CENTRO']);
        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'code' => 'WH-CENTRO', 'branch_id' => $branch->id]);
        $register = CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Caja 1',
            'code' => 'CJ-01',
            'status' => CashRegister::STATUS_ACTIVE,
        ]);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Gerente', 'web');
        $role->syncPermissions(['cash_register.open', 'cash_register.close', 'cash_register.view']);
        $user->assignRole($role);

        // Categories
        $catPapeleria = Category::create(['name' => 'Papelería', 'slug' => 'papeleria']);
        $catTecnologia = Category::create(['name' => 'Tecnología', 'slug' => 'tecnologia']);

        // Products
        $prodCuaderno = Product::create([
            'name' => 'Cuaderno 100 Hojas',
            'sku' => 'CUAD-100',
            'sale_currency' => 'USD',
            'sale_price' => 5.0,
        ]);
        $prodCuaderno->categories()->attach($catPapeleria->id, ['tenant_id' => $tenant->id]);

        $prodBoligrafo = Product::create([
            'name' => 'Bolígrafo Azul',
            'sku' => 'BOL-AZ',
            'sale_currency' => 'USD',
            'sale_price' => 1.5,
        ]);
        $prodBoligrafo->categories()->attach($catPapeleria->id, ['tenant_id' => $tenant->id]);

        $prodMouse = Product::create([
            'name' => 'Mouse Óptico USB',
            'sku' => 'MOUSE-USB',
            'sale_currency' => 'USD',
            'sale_price' => 15.0,
        ]);
        $prodMouse->categories()->attach($catTecnologia->id, ['tenant_id' => $tenant->id]);

        $prodSinCat = Product::create([
            'name' => 'Servicio de Fotocopia',
            'sku' => 'SERV-FOTO',
            'sale_currency' => 'USD',
            'sale_price' => 2.0,
        ]);

        // Customers
        $customerEmpresa = Customer::create([
            'name' => 'Inversiones Papelería San José',
            'document_type' => 'J',
            'document_number' => '123456789',
        ]);

        // Open Session
        $session = CashRegisterSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        // Sale 1: Inversiones Papelería San José buys 2 Cuadernos ($10) + 1 Bolígrafo ($1.5) = $11.5 USD
        $sale1 = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'customer_id' => $customerEmpresa->id,
            'total_base_amount' => 11.5,
            'total_local_amount' => 575.0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodCuaderno->id,
            'quantity' => 2,
            'sale_currency' => 'USD',
            'unit_price' => 5.0,
            'total_amount' => 10.0,
            'base_unit_price' => 5.0,
            'base_total_amount' => 10.0,
            'exchange_rate' => 50.0,
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodBoligrafo->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => 1.5,
            'total_amount' => 1.5,
            'base_unit_price' => 1.5,
            'base_total_amount' => 1.5,
            'exchange_rate' => 50.0,
        ]);
        $order1 = PosOrder::create([
            'sale_id' => $sale1->id,
            'cash_register_session_id' => $session->id,
            'customer_id' => $customerEmpresa->id,
            'cashier_id' => $user->id,
            'status' => PosOrder::STATUS_PAID,
            'customer_name' => $customerEmpresa->name,
            'total_base_amount' => 11.5,
            'total_local_amount' => 575.0,
            'paid_base_amount' => 11.5,
            'paid_local_amount' => 575.0,
            'opened_at' => now(),
            'paid_at' => now(),
        ]);
        PosPayment::create([
            'pos_order_id' => $order1->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => 'USD',
            'amount' => 11.5,
            'amount_base' => 11.5,
            'amount_local' => 575.0,
            'exchange_rate' => 50.0,
            'status' => PosPayment::STATUS_CAPTURED,
        ]);

        // Sale 2: Consumidor Final buys 1 Mouse ($15) + 1 Fotocopia ($2) = $17 USD
        $sale2 = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'customer_id' => null,
            'total_base_amount' => 17.0,
            'total_local_amount' => 850.0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale2->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodMouse->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => 15.0,
            'total_amount' => 15.0,
            'base_unit_price' => 15.0,
            'base_total_amount' => 15.0,
            'exchange_rate' => 50.0,
        ]);
        SaleItem::create([
            'sale_id' => $sale2->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodSinCat->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => 2.0,
            'total_amount' => 2.0,
            'base_unit_price' => 2.0,
            'base_total_amount' => 2.0,
            'exchange_rate' => 50.0,
        ]);
        $order2 = PosOrder::create([
            'sale_id' => $sale2->id,
            'cash_register_session_id' => $session->id,
            'customer_id' => null,
            'cashier_id' => $user->id,
            'status' => PosOrder::STATUS_PAID,
            'customer_name' => 'Consumidor Final',
            'total_base_amount' => 17.0,
            'total_local_amount' => 850.0,
            'paid_base_amount' => 17.0,
            'paid_local_amount' => 850.0,
            'opened_at' => now(),
            'paid_at' => now(),
        ]);
        PosPayment::create([
            'pos_order_id' => $order2->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => 'USD',
            'amount' => 17.0,
            'amount_base' => 17.0,
            'amount_local' => 850.0,
            'exchange_rate' => 50.0,
            'status' => PosPayment::STATUS_CAPTURED,
        ]);

        // Close session
        $session->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
            'z_number' => 1,
            'z_emitted_at' => now(),
            'counted_base_amount' => 28.5,
            'counted_local_amount' => 1425.0,
        ]);

        // 1. Check ReportZService::build
        $service = app(ReportZService::class);
        $data = $service->build($session);

        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('customers', $data);

        $categories = collect($data['categories']);
        $customers = collect($data['customers']);

        // Check Papelería category
        $catPap = $categories->firstWhere('name', 'Papelería');
        $this->assertNotNull($catPap, 'Category Papelería should be present');
        $this->assertEquals(3, $catPap['items_count']); // 2 cuadernos + 1 boligrafo
        $this->assertEquals(11.5, $catPap['amount_base']);
        $this->assertEquals(575.0, $catPap['amount_local']);
        $this->assertCount(2, $catPap['products']);

        // Check Tecnología category
        $catTec = $categories->firstWhere('name', 'Tecnología');
        $this->assertNotNull($catTec, 'Category Tecnología should be present');
        $this->assertEquals(1, $catTec['items_count']);
        $this->assertEquals(15.0, $catTec['amount_base']);
        $this->assertEquals(750.0, $catTec['amount_local']);

        // Check Sin categoría
        $catSin = $categories->firstWhere('name', 'Sin categoría');
        $this->assertNotNull($catSin, 'Category Sin categoría should be present');
        $this->assertEquals(1, $catSin['items_count']);
        $this->assertEquals(2.0, $catSin['amount_base']);

        // Check Customers
        $custEmpresa = $customers->firstWhere('name', 'Inversiones Papelería San José');
        $this->assertNotNull($custEmpresa, 'Customer Inversiones Papelería San José should be present');
        $this->assertEquals(1, $custEmpresa['orders_count']);
        $this->assertEquals(11.5, $custEmpresa['amount_base']);
        $this->assertEquals('J-123456789', $custEmpresa['document']);

        $custFinal = $customers->firstWhere('name', 'Consumidor Final');
        $this->assertNotNull($custFinal, 'Consumidor Final should be present');
        $this->assertEquals(1, $custFinal['orders_count']);
        $this->assertEquals(17.0, $custFinal['amount_base']);

        // 2. Check JSON API endpoint
        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/cash-register/sessions/{$session->id}/report-z")
            ->assertOk();

        $response->assertJsonPath('data.categories.0.name', 'Tecnología'); // sorted desc by amount_base
        $response->assertJsonPath('data.categories.1.name', 'Papelería');
        $response->assertJsonPath('data.customers.0.name', 'Consumidor Final');
        $response->assertJsonPath('data.customers.1.name', 'Inversiones Papelería San José');

        // 3. Check Ticket HTML contains Category and Customer sections
        $ticketHtml = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/cash-register/sessions/{$session->id}/report-z.ticket.html")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ventas por Categoria', $ticketHtml);
        $this->assertStringContainsString('Papelería', $ticketHtml);
        $this->assertStringContainsString('Tecnología', $ticketHtml);
        $this->assertStringContainsString('Ventas por Cliente', $ticketHtml);
        $this->assertStringContainsString('Inversiones Papelería San José', $ticketHtml);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
