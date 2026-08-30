<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
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

class FiscalDocumentPreviewApiTest extends TestCase
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

    public function test_user_can_create_internal_preview_with_immutable_snapshots(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Fiscal', 'slug' => 'empresa-fiscal']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $sale = $this->confirmedSale($tenant, $user);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $sale->id])
            ->assertCreated()
            ->assertJsonPath('data.sale_id', $sale->id)
            ->assertJsonPath('data.status', 'preview')
            ->assertJsonPath('data.document_type', 'internal_preview')
            ->assertJsonPath('data.document_mode', 'internal_preview')
            ->assertJsonPath('data.officially_issued', false)
            ->assertJsonPath('data.customer_snapshot.fiscal_name', 'Cliente Fiscal')
            ->assertJsonPath('data.items.0.product_snapshot.sku', 'SKU-FISCAL')
            ->assertJsonPath('data.items.0.fiscal_snapshot.tax_code', 'IVA16')
            ->assertJsonPath('data.items.0.fiscal_snapshot.tax_rate', 16);

        $documentId = $response->json('data.id');

        $response->assertJsonMissingPath('data.series');
        $response->assertJsonMissingPath('data.number');
        $response->assertJsonMissingPath('data.control_number');
        $this->assertDatabaseHas('fiscal_documents', [
            'id' => $documentId,
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'status' => 'preview',
        ]);
        $this->assertDatabaseHas('fiscal_document_items', [
            'tenant_id' => $tenant->id,
            'fiscal_document_id' => $documentId,
            'sale_item_id' => $sale->items()->first()->id,
        ]);
    }

    public function test_repeating_preview_for_same_sale_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Idempotente', 'slug' => 'empresa-idempotente']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $sale = $this->confirmedSale($tenant, $user);
        $headers = ['X-Tenant' => $tenant->slug];

        $first = $this->actingAs($user)->withHeaders($headers)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $sale->id])
            ->assertCreated();
        $second = $this->actingAs($user)->withHeaders($headers)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $sale->id])
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('fiscal_documents')->where('sale_id', $sale->id)->count());
        $this->assertSame(1, DB::table('fiscal_document_items')->where('sale_item_id', $sale->items()->first()->id)->count());
    }

    public function test_preview_rejects_draft_sales_and_cross_tenant_sales(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA, ['sales.view']);
        $userB = $this->userInTenant($tenantB, ['sales.view']);
        $draft = $this->saleForTenant($tenantA, $userA, Sale::STATUS_DRAFT);
        $confirmedB = $this->confirmedSale($tenantB, $userB);

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $draft->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_id']);

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $confirmedB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_id']);
    }

    public function test_preview_requires_sales_view_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Protegida', 'slug' => 'empresa-protegida']);
        $user = $this->userInTenant($tenant, []);
        $sale = $this->confirmedSale($tenant, $user);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $sale->id])
            ->assertForbidden();
    }

    public function test_preview_keeps_source_values_after_sale_changes(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Inmutable', 'slug' => 'empresa-inmutable']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $sale = $this->confirmedSale($tenant, $user);

        $documentId = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $sale->id])
            ->assertCreated()
            ->json('data.id');

        $sale->update(['total_base_amount' => 999]);
        $sale->customer->update(['fiscal_name' => 'Nombre Cambiado']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/fiscal/documents/{$documentId}")
            ->assertOk()
            ->assertJsonPath('data.customer_snapshot.fiscal_name', 'Cliente Fiscal')
            ->assertJsonPath('data.totals_snapshot.total_base_amount', 116);
    }

    public function test_user_can_list_filtered_previews_without_cross_tenant_leakage(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa Lista A', 'slug' => 'empresa-lista-a']);
        $tenantB = Tenant::create(['name' => 'Empresa Lista B', 'slug' => 'empresa-lista-b']);
        $userA = $this->userInTenant($tenantA, ['sales.view']);
        $userB = $this->userInTenant($tenantB, ['sales.view']);
        $saleA1 = $this->confirmedSale($tenantA, $userA);
        $saleA2 = $this->confirmedSale($tenantA, $userA, '-2');
        $saleB = $this->confirmedSale($tenantB, $userB);

        $documentA1 = $this->createPreview($tenantA, $userA, $saleA1);
        $documentA2 = $this->createPreview($tenantA, $userA, $saleA2);
        $documentB = $this->createPreview($tenantB, $userB, $saleB);

        DB::table('fiscal_documents')->where('id', $documentA1)->update(['snapshot_at' => now()->subDays(2)]);
        DB::table('fiscal_documents')->where('id', $documentA2)->update(['snapshot_at' => now()->subDay()]);

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/fiscal/documents?status=preview&date_from='.now()->subDay()->toDateString().'&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $documentA2)
            ->assertJsonPath('data.0.items.0.sale_item_id', $saleA2->items()->first()->id)
            ->assertJsonPath('meta.total', 1);

        $response = $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/fiscal/documents?per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotContains($documentB, collect($response->json('data'))->pluck('id')->all());
        $this->assertContains($documentA1, collect($response->json('data'))->pluck('id')->all());
        $this->assertContains($documentA2, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_preview_list_validates_filters_and_requires_sales_view_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Filtros', 'slug' => 'empresa-filtros']);
        $user = $this->userInTenant($tenant, []);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/fiscal/documents?date_from=not-a-date&per_page=101')
            ->assertForbidden();

        $authorizedUser = $this->userInTenant($tenant, ['sales.view']);

        $this->actingAs($authorizedUser)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/fiscal/documents?date_from=not-a-date&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'per_page']);
    }

    private function confirmedSale(Tenant $tenant, User $user, string $suffix = ''): Sale
    {
        return $this->saleForTenant($tenant, $user, Sale::STATUS_CONFIRMED, $suffix);
    }

    private function createPreview(Tenant $tenant, User $user, Sale $sale): int
    {
        return $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/documents/previews', ['sale_id' => $sale->id])
            ->assertCreated()
            ->json('data.id');
    }

    private function saleForTenant(Tenant $tenant, User $user, string $status, string $suffix = ''): Sale
    {
        $this->useTenant($tenant);
        $branch = Branch::create(['name' => 'Sucursal Fiscal'.$suffix, 'code' => 'BR-FISCAL'.$suffix]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacen Fiscal'.$suffix,
            'code' => 'WH-FISCAL'.$suffix,
        ]);
        $product = Product::create(['name' => 'Producto Fiscal'.$suffix, 'sku' => 'SKU-FISCAL'.$suffix]);
        $customer = Customer::create([
            'name' => 'Cliente Comercial'.$suffix,
            'fiscal_name' => 'Cliente Fiscal'.$suffix,
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => '12345678'.($suffix === '' ? '' : '2'),
            'fiscal_address' => 'Caracas',
        ]);
        $sale = Sale::create([
            'status' => $status,
            'customer_id' => $customer->id,
            'total_base_amount' => $status === Sale::STATUS_CONFIRMED ? 116 : 0,
            'total_local_amount' => $status === Sale::STATUS_CONFIRMED ? 116 : 0,
            'fiscal_taxable_base_amount' => $status === Sale::STATUS_CONFIRMED ? 100 : 0,
            'fiscal_taxable_local_amount' => $status === Sale::STATUS_CONFIRMED ? 100 : 0,
            'fiscal_tax_base_amount' => $status === Sale::STATUS_CONFIRMED ? 16 : 0,
            'fiscal_tax_local_amount' => $status === Sale::STATUS_CONFIRMED ? 16 : 0,
            'fiscal_snapshot_at' => $status === Sale::STATUS_CONFIRMED ? now() : null,
            'created_by' => $user->id,
            'confirmed_at' => $status === Sale::STATUS_CONFIRMED ? now() : null,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_price' => 116,
            'total_amount' => 116,
            'base_unit_price' => 100,
            'base_total_amount' => 100,
            'local_total_amount' => 100,
            'fiscal_tax_code' => 'IVA16',
            'fiscal_tax_name' => 'IVA general',
            'fiscal_tax_category' => 'taxable',
            'fiscal_tax_rate' => 16,
            'fiscal_taxable_base_amount' => 100,
            'fiscal_taxable_local_amount' => 100,
            'fiscal_tax_base_amount' => 16,
            'fiscal_tax_local_amount' => 16,
            'fiscal_total_base_amount' => 116,
            'fiscal_total_local_amount' => 116,
            'fiscal_snapshot_at' => $status === Sale::STATUS_CONFIRMED ? now() : null,
        ]);

        return $sale->refresh();
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);
        $role = Role::findOrCreate('Fiscal Document Test '.md5($tenant->id.implode('|', $permissions)), 'web');
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
