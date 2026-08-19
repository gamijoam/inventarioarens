<?php

namespace Tests\Feature\Quotations;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Quotations\Models\Quotation;
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

class QuotationApiTest extends TestCase
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

    public function test_vendor_can_create_quotation_with_items(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza SRL', 'slug' => 'cotiza-srl']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-001');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor Cotiza', [
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.delete', 'quotations.convert',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', [
                'customer_name' => 'Cliente Prueba',
                'warehouse_id' => $warehouse->id,
                'status' => 'issued',
                'valid_until' => '2026-09-30',
                'notes' => 'Oferta especial',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'COT-000001')
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.customer_name', 'Cliente Prueba')
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.total_base', 200)
            ->assertJsonPath('data.total_base_amount', 200);

        $this->assertDatabaseHas('quotations', [
            'tenant_id' => $tenant->id,
            'document_number' => 'COT-000001',
            'status' => 'issued',
        ]);
        $this->assertDatabaseHas('quotation_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity' => '2.0000',
            'unit_price_base' => '100.0000',
            'total_base' => '200.0000',
        ]);
    }

    public function test_quotation_rejects_variant_not_belonging_to_product(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza Var', 'slug' => 'cotiza-var']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-VAR-1');
        [, $otherProduct] = $this->warehouseAndProduct($tenant, 'COT-VAR-2');
        $this->useTenant($tenant);
        $foreignVariant = ProductVariant::create(['product_id' => $otherProduct->id, 'color' => 'Rojo', 'position' => 0]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor Cotiza Var', ['quotations.view', 'quotations.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', [
                'warehouse_id' => $warehouse->id,
                'items' => [
                    ['product_id' => $product->id, 'product_variant_id' => $foreignVariant->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);
    }

    public function test_quotation_list_filters_by_status(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza List', 'slug' => 'cotiza-list']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-LIST');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor Cotiza List', ['quotations.view', 'quotations.create']);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated();
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', ['warehouse_id' => $warehouse->id, 'status' => 'issued', 'items' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertCreated();

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/quotations?status=issued')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/quotations?search=COT-000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'COT-000001');
    }

    public function test_user_without_quotation_permission_cannot_create(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza Sin Permiso', 'slug' => 'cotiza-sin-permiso']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-NO');
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', [
                'warehouse_id' => $warehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_quotation_cross_tenant_does_not_leak(): void
    {
        $tenantA = Tenant::create(['name' => 'Cotiza A', 'slug' => 'cotiza-a']);
        $tenantB = Tenant::create(['name' => 'Cotiza B', 'slug' => 'cotiza-b']);
        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'COT-A');
        [, $productB] = $this->warehouseAndProduct($tenantB, 'COT-B');
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Vendedor A', ['quotations.view', 'quotations.create']);
        $this->grantRole($tenantB, $userB, 'Vendedor B', ['quotations.view', 'quotations.create']);

        $quotationId = $this->actingAs($userA)->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/quotations', ['warehouse_id' => $warehouseA->id, 'items' => [['product_id' => $productA->id, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');

        $this->actingAs($userB)->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/quotations/{$quotationId}")
            ->assertNotFound();
        $this->actingAs($userB)->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/quotations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_issued_quotation_can_be_converted_to_pending_pos_order(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza Convert', 'slug' => 'cotiza-convert']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-CONV');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor Convert', [
            'quotations.view', 'quotations.create', 'quotations.convert', 'pos.orders.hold',
        ]);
        $this->stock($tenant, $warehouse, $product, $user, 10);

        $quotationId = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', [
                'customer_name' => 'Cliente Convert',
                'warehouse_id' => $warehouse->id,
                'status' => 'issued',
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated()->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/quotations/{$quotationId}/convert")
            ->assertOk()
            ->assertJsonPath('data.quotation.status', 'converted')
            ->assertJsonPath('data.pos_order.status', PosOrder::STATUS_OPEN);

        $quotation = Quotation::query()->findOrFail($quotationId);
        $this->assertNotNull($quotation->converted_pos_order_id);
        $this->assertNotNull($quotation->converted_at);
        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $quotation->converted_pos_order_id,
            'status' => PosOrder::STATUS_OPEN,
            'customer_name' => 'Cliente Convert',
        ]);
    }

    public function test_draft_quotation_cannot_be_converted(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza Draft', 'slug' => 'cotiza-draft']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-DRAFT');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor Draft', ['quotations.view', 'quotations.create', 'quotations.convert']);

        $quotationId = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/quotations/{$quotationId}/convert")
            ->assertUnprocessable();
    }

    public function test_quotation_pdf_html_includes_company_and_items(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza PDF', 'slug' => 'cotiza-pdf']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-PDF');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor PDF', ['quotations.view', 'quotations.create']);
        DB::table('tenant_settings')
            ->where('tenant_id', $tenant->id)
            ->update(['settings' => json_encode([
                'company' => [
                    'razon_social' => 'Cotiza PDF C.A.',
                    'rif' => 'J-33333333-3',
                    'show_on' => ['quotation' => true],
                ],
            ])]);

        $quotationId = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', [
                'customer_name' => 'Cliente PDF',
                'warehouse_id' => $warehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertCreated()->json('data.id');

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/quotations/{$quotationId}/pdf.html")
            ->assertOk()
            ->assertSee('J-33333333-3', false)
            ->assertSee('Cotiza PDF C.A.', false)
            ->assertSee($product->name, false);
    }

    public function test_quotation_can_be_cancelled(): void
    {
        $tenant = Tenant::create(['name' => 'Cotiza Cancel', 'slug' => 'cotiza-cancel']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'COT-CANCEL');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor Cancel', ['quotations.view', 'quotations.create', 'quotations.delete']);

        $quotationId = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/quotations', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/quotations/{$quotationId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    private function warehouseAndProduct(Tenant $tenant, string $sku): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product];
    }

    private function stock(Tenant $tenant, Warehouse $warehouse, Product $product, User $user, float $quantity): void
    {
        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            reason: 'Stock cotizacion',
        );
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
