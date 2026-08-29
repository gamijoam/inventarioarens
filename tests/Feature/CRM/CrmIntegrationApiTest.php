<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CrmIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_issue_a_scoped_crm_token_without_persisting_plaintext(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        [$branch] = $this->locations($tenant);

        $data = $this->issueCrmToken($tenant, $manager, [
            'name' => 'CRM production',
            'scopes' => ['catalog.read', 'inventory.read', 'branches.read'],
            'branch_ids' => [$branch->id],
            'warehouse_ids' => null,
        ]);

        $this->assertStringStartsWith('crm_', $data['token']);
        $this->assertSame(['catalog.read', 'inventory.read', 'branches.read'], $data['scopes']);
        $this->assertSame([$branch->id], $data['branch_ids']);
        $this->assertNotSame($data['token'], $data['token_prefix']);
        $this->assertDatabaseHas('crm_api_tokens', [
            'id' => $data['id'],
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $data['token']),
        ]);
        $this->assertDatabaseMissing('crm_api_tokens', ['token_hash' => $data['token']]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'crm.token.issued',
        ]);
    }

    public function test_crm_token_returns_only_authorized_locations_and_safe_catalog_data(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        [$branchA, $branchB, $warehouseA, $warehouseB] = $this->locations($tenant);
        $product = $this->product($tenant, 'Phone A', 'PHONE-A');
        $this->product($tenant, 'Phone B', 'PHONE-B', 900, 450);
        $token = $this->issueCrmToken($tenant, $manager, [
            'branch_ids' => [$branchA->id],
            'warehouse_ids' => [$warehouseA->id],
        ])['token'];

        $this->stock($tenant, $warehouseA, $product, 7, 2, 1);
        $this->stock($tenant, $warehouseB, $product, 99, 3, 4);

        $this->crmGet($token, '/api/v1/integrations/crm/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $branchA->id)
            ->assertJsonPath('data.0.branch_id', $branchA->id)
            ->assertJsonPath('data.0.branch_code', 'CENTRO')
            ->assertJsonPath('data.0.branch_name', 'Sucursal Centro')
            ->assertJsonPath('data.0.slug', 'sucursal-centro')
            ->assertJsonMissing(['id' => $branchB->id]);

        $this->crmGet($token, '/api/v1/integrations/crm/warehouses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $warehouseA->id)
            ->assertJsonMissing(['id' => $warehouseB->id]);

        $this->crmGet($token, '/api/v1/integrations/crm/products?search=PHONE-A')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'PHONE-A')
            ->assertJsonPath('data.0.sale_price', 1200)
            ->assertJsonMissingPath('data.0.average_cost')
            ->assertJsonMissingPath('data.0.last_purchase_cost')
            ->assertJsonMissingPath('data.0.profit_margin');

        $this->crmGet($token, '/api/v1/integrations/crm/products/PHONE-A')
            ->assertOk()
            ->assertJsonPath('data.sku', 'PHONE-A')
            ->assertJsonMissingPath('data.average_cost');

        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?sku=PHONE-A')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'PHONE-A')
            ->assertJsonPath('data.0.available_quantity', 7)
            ->assertJsonPath('data.0.reserved_quantity', 2)
            ->assertJsonPath('data.0.damaged_quantity', 1)
            ->assertJsonPath('data.0.as_of', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.0.stock_source', 'stock_balances')
            ->assertJsonCount(1, 'data.0.warehouses')
            ->assertJsonPath('data.0.warehouses.0.warehouse_id', $warehouseA->id)
            ->assertJsonMissing(['warehouse_id' => $warehouseB->id]);

        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?sku=PHONE-A&branch_id='.$branchA->id)
            ->assertOk()
            ->assertJsonPath('data.0.branch_id', $branchA->id)
            ->assertJsonPath('data.0.branch_code', 'CENTRO')
            ->assertJsonPath('data.0.branch_name', 'Sucursal Centro')
            ->assertJsonPath('data.0.warehouse_id', $warehouseA->id)
            ->assertJsonPath('data.0.warehouse_name', 'Almacén Centro')
            ->assertJsonPath('data.0.available_quantity', 7)
            ->assertJsonPath('data.0.has_availability', true);
    }

    public function test_availability_reports_selected_branch_and_authorized_alternatives(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        [$branchA, $branchB, $warehouseA, $warehouseB] = $this->locations($tenant);
        $product = $this->product($tenant, 'Phone B', 'PHONE-B');
        $token = $this->issueCrmToken($tenant, $manager)['token'];
        $this->stock($tenant, $warehouseB, $product, 6, 1, 0);

        $this->crmGet(
            $token,
            '/api/v1/integrations/crm/inventory/availability?sku=PHONE-B&branch_id='.$branchA->id.'&include_alternatives=true'
        )
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'PHONE-B')
            ->assertJsonPath('data.0.branch_id', $branchA->id)
            ->assertJsonPath('data.0.branch_code', 'CENTRO')
            ->assertJsonPath('data.0.branch_name', 'Sucursal Centro')
            ->assertJsonPath('data.0.warehouse_id', $warehouseA->id)
            ->assertJsonPath('data.0.warehouse_name', 'Almacén Centro')
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.has_availability', false)
            ->assertJsonPath('data.0.alternatives.0.branch_id', $branchB->id)
            ->assertJsonPath('data.0.alternatives.0.branch_code', 'NORTE')
            ->assertJsonPath('data.0.alternatives.0.available_quantity', 6)
            ->assertJsonPath('data.0.alternatives.0.is_stale', false)
            ->assertJsonPath('data.0.alternatives.0.as_of', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_availability_marks_stale_inventory_and_does_not_invent_stock(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        [$branchA, , $warehouseA] = $this->locations($tenant);
        $stale = $this->product($tenant, 'Stale phone', 'STALE-PHONE');
        $empty = $this->product($tenant, 'Empty phone', 'EMPTY-PHONE');
        $token = $this->issueCrmToken($tenant, $manager)['token'];

        $this->stock($tenant, $warehouseA, $stale, 2, 0, 0, now()->subMinutes(31));
        $this->stock($tenant, $warehouseA, $empty, 0, 0, 8);

        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?branch_id='.$branchA->id)
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'EMPTY-PHONE')
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.has_availability', false)
            ->assertJsonPath('data.1.sku', 'STALE-PHONE')
            ->assertJsonPath('data.1.available_quantity', 2)
            ->assertJsonPath('data.1.has_availability', true)
            ->assertJsonPath('data.1.is_stale', true);
    }

    public function test_crm_never_exposes_the_excluded_tiendas_arens_branch(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        $this->locations($tenant);
        app(TenantManager::class)->set($tenant);
        Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tiendas Arens',
            'code' => 'TIENDAS-ARENS',
            'status' => Branch::STATUS_ACTIVE,
        ]);
        app(TenantManager::class)->clear();
        $token = $this->issueCrmToken($tenant, $manager)['token'];

        $this->crmGet($token, '/api/v1/integrations/crm/branches')
            ->assertOk()
            ->assertJsonMissing(['slug' => 'tiendas-arens'])
            ->assertJsonCount(2, 'data');
    }

    public function test_crm_scope_is_required_for_each_read_operation(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        $token = $this->issueCrmToken($tenant, $manager, [
            'scopes' => ['catalog.read'],
        ])['token'];

        $this->crmGet($token, '/api/v1/integrations/crm/products')
            ->assertOk();
        $this->crmGet($token, '/api/v1/integrations/crm/branches')
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability')
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    }

    public function test_crm_token_creation_requires_settings_permission_and_valid_location_relationships(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        [$branchA, , , $warehouseB] = $this->locations($tenant);
        $regularUser = User::factory()->create(['password' => 'secret123']);
        $regularUser->tenants()->attach($tenant, ['status' => 'active']);
        $regularSession = $this->loginToken($tenant, $regularUser);

        $this->withHeaders([
            'Authorization' => "Bearer {$regularSession}",
            'X-Tenant' => $tenant->slug,
        ])->postJson('/api/crm/integration-tokens', [
            'name' => 'Sin permiso',
            'scopes' => CrmApiToken::SCOPES,
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ])->assertForbidden();

        $managerSession = $this->loginToken($tenant, $manager);
        $this->withHeaders([
            'Authorization' => "Bearer {$managerSession}",
            'X-Tenant' => $tenant->slug,
        ])->postJson('/api/crm/integration-tokens', [
            'name' => 'Alcance inconsistente',
            'scopes' => CrmApiToken::SCOPES,
            'branch_ids' => [$branchA->id],
            'warehouse_ids' => [$warehouseB->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_ids']);

        $this->assertDatabaseMissing('crm_api_tokens', ['name' => 'Alcance inconsistente']);
    }

    public function test_subtree_token_requires_group_owner_and_lists_only_descendant_branches(): void
    {
        [$group, $manager] = $this->tenantWithManager('Tiendas Arens', 'tiendasarens');
        [$childBranch, $childWarehouse] = $this->branchFor(Tenant::create([
            'name' => 'Tucacas',
            'slug' => 'tucacas',
            'parent_id' => $group->id,
            'is_group' => false,
        ]), 'Tucacas', '001');
        [$otherGroupBranch] = $this->branchFor(Tenant::create([
            'name' => 'Otra empresa',
            'slug' => 'otra-empresa',
        ]), 'Otra empresa', '001');
        [$inactiveBranch] = $this->branchFor(Tenant::create([
            'name' => 'Sucursal suspendida',
            'slug' => 'sucursal-suspendida',
            'status' => 'inactive',
            'parent_id' => $group->id,
            'is_group' => false,
        ]), 'Sucursal suspendida', '001');

        $this->withHeaders([
            'Authorization' => "Bearer {$this->loginToken($group, $manager)}",
            'X-Tenant' => $group->slug,
        ])->postJson('/api/crm/integration-tokens', [
            'name' => 'CRM subtree sin Owner',
            'tenant_scope' => 'subtree',
            'scopes' => CrmApiToken::SCOPES,
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ])->assertForbidden();

        $this->makeStrictOwner($group, $manager);
        $token = $this->issueCrmToken($group, $manager, [
            'tenant_scope' => 'subtree',
        ]);

        $this->assertSame('subtree', $token['tenant_scope']);
        $this->assertDatabaseHas('crm_api_tokens', [
            'id' => $token['id'],
            'tenant_id' => $group->id,
            'tenant_scope' => 'subtree',
        ]);

        $this->crmGet($token['token'], '/api/v1/integrations/crm/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', $childBranch->tenant_id)
            ->assertJsonPath('data.0.tenant_slug', 'tucacas')
            ->assertJsonPath('data.0.branch_id', $childBranch->id)
            ->assertJsonPath('data.0.slug', 'tucacas')
            ->assertJsonMissing(['tenant_id' => $group->id])
            ->assertJsonMissing(['branch_id' => $otherGroupBranch->id])
            ->assertJsonMissing(['branch_id' => $inactiveBranch->id]);

        $this->crmGet($token['token'], '/api/v1/integrations/crm/warehouses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', $childBranch->tenant_id)
            ->assertJsonPath('data.0.tenant_slug', 'tucacas')
            ->assertJsonPath('data.0.id', $childWarehouse->id)
            ->assertJsonPath('data.0.branch_id', $childBranch->id);
    }

    public function test_subtree_availability_uses_preferred_child_and_returns_only_authorized_alternatives(): void
    {
        [$group, $manager] = $this->tenantWithManager('Tiendas Arens', 'tiendasarens');
        $this->makeStrictOwner($group, $manager);
        [$rootBranch] = $this->branchFor($group, 'MASTER', '000');
        [$tucacasBranch, $tucacasWarehouse] = $this->branchFor(
            $tucacas = Tenant::create([
                'name' => 'Tucacas',
                'slug' => 'tucacas',
                'parent_id' => $group->id,
                'is_group' => false,
            ]),
            'Tucacas',
            '001',
        );
        [$bocaBranch, $bocaWarehouse] = $this->branchFor(
            $boca = Tenant::create([
                'name' => 'Boca de Aroa',
                'slug' => 'boca-de-aroa',
                'parent_id' => $group->id,
                'is_group' => false,
            ]),
            'Boca de Aroa',
            '001',
        );
        [, $outsideWarehouse] = $this->branchFor(
            $outside = Tenant::create([
                'name' => 'Fuera del grupo',
                'slug' => 'fuera-del-grupo',
            ]),
            'Fuera del grupo',
            '001',
        );

        $master = $this->product($group, 'Phone B', 'PHONE-B');
        $master->forceFill(['is_catalog_master' => true])->save();
        $tucacasProduct = $this->product($tucacas, 'Phone B', 'PHONE-B');
        $tucacasProduct->forceFill([
            'catalog_product_id' => $master->id,
            'is_catalog_master' => false,
        ])->save();
        $bocaProduct = $this->product($boca, 'Phone B', 'PHONE-B');
        $bocaProduct->forceFill([
            'catalog_product_id' => $master->id,
            'is_catalog_master' => false,
        ])->save();
        $outsideProduct = $this->product($outside, 'Phone B', 'PHONE-B');
        $outsideProduct->forceFill([
            'catalog_product_id' => $master->id,
            'is_catalog_master' => false,
        ])->save();

        $this->stock($tucacas, $tucacasWarehouse, $tucacasProduct, 0, 1, 0);
        $this->stock($boca, $bocaWarehouse, $bocaProduct, 6, 0, 0);
        $this->stock($outside, $outsideWarehouse, $outsideProduct, 99, 0, 0);

        $token = $this->issueCrmToken($group, $manager, [
            'tenant_scope' => 'subtree',
        ])['token'];

        $this->crmGet($token, '/api/v1/integrations/crm/products?search=PHONE-B')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $master->id)
            ->assertJsonPath('data.0.sku', 'PHONE-B');

        $this->crmGet(
            $token,
            '/api/v1/integrations/crm/inventory/availability?sku=PHONE-B&branch_id='.$tucacasBranch->id.'&include_alternatives=true',
        )
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $master->id)
            ->assertJsonPath('data.0.branch_id', $tucacasBranch->id)
            ->assertJsonPath('data.0.tenant_id', $tucacas->id)
            ->assertJsonPath('data.0.tenant_slug', 'tucacas')
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.has_availability', false)
            ->assertJsonPath('data.0.alternatives.0.tenant_id', $boca->id)
            ->assertJsonPath('data.0.alternatives.0.tenant_slug', 'boca-de-aroa')
            ->assertJsonPath('data.0.alternatives.0.branch_id', $bocaBranch->id)
            ->assertJsonPath('data.0.alternatives.0.available_quantity', 6)
            ->assertJsonMissing(['branch_id' => $rootBranch->id])
            ->assertJsonMissing(['branch_id' => $outsideWarehouse->branch_id]);

        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?branch_id='.$rootBranch->id)
            ->assertNotFound();
    }

    public function test_subtree_token_cannot_query_a_branch_from_another_group(): void
    {
        [$group, $manager] = $this->tenantWithManager('Tiendas Arens', 'tiendasarens');
        $this->makeStrictOwner($group, $manager);
        [$outsideBranch] = $this->branchFor(Tenant::create([
            'name' => 'Fuera del grupo',
            'slug' => 'fuera-del-grupo',
        ]), 'Fuera del grupo', '001');
        $token = $this->issueCrmToken($group, $manager, [
            'tenant_scope' => 'subtree',
        ])['token'];

        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?branch_id='.$outsideBranch->id)
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_non_owner_with_settings_permission_cannot_rotate_or_revoke_a_subtree_token(): void
    {
        [$group, $owner] = $this->tenantWithManager('Tiendas Arens', 'tiendasarens');
        $this->makeStrictOwner($group, $owner);
        $operator = User::factory()->create(['password' => 'secret123']);
        $operator->tenants()->attach($group, ['status' => 'active']);
        $this->grantSettingsManager($group, $operator);
        $token = $this->issueCrmToken($group, $owner, [
            'tenant_scope' => 'subtree',
        ])['token'];
        $tokenId = CrmApiToken::query()->latest('id')->value('id');
        $session = $this->loginToken($group, $operator);

        $this->withHeaders([
            'Authorization' => "Bearer {$session}",
            'X-Tenant' => $group->slug,
        ])->postJson('/api/crm/integration-tokens/'.$tokenId.'/rotate')
            ->assertForbidden();

        $this->withHeaders([
            'Authorization' => "Bearer {$session}",
            'X-Tenant' => $group->slug,
        ])->deleteJson('/api/crm/integration-tokens/'.$tokenId)
            ->assertForbidden();

        $this->crmGet($token, '/api/v1/integrations/crm/branches')->assertOk();
    }

    public function test_crm_token_cannot_cross_tenants_or_call_mutating_api_routes(): void
    {
        [$tenantA, $managerA] = $this->tenantWithManager('Empresa A', 'empresa-a');
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $token = $this->issueCrmToken($tenantA, $managerA)['token'];
        $normalToken = $this->loginToken($tenantA, $managerA);

        $this->crmGet($normalToken, '/api/v1/integrations/crm/products')
            ->assertUnauthorized();
        $this->withCookie('auth_token', $token)
            ->getJson('/api/v1/integrations/crm/products')
            ->assertUnauthorized();

        $this->crmGet($token, '/api/v1/integrations/crm/products', [
            'X-Tenant' => $tenantB->slug,
        ])->assertForbidden();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant' => $tenantA->slug,
        ])->getJson('/api/products')
            ->assertUnauthorized();
    }

    public function test_crm_location_scope_rejects_direct_queries_for_another_warehouse(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        [, , $warehouseA, $warehouseB] = $this->locations($tenant);
        $token = $this->issueCrmToken($tenant, $manager, [
            'warehouse_ids' => [$warehouseA->id],
        ])['token'];

        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?warehouse_id='.$warehouseB->id)
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    }

    public function test_crm_returns_not_found_for_unknown_tenant_branch_and_product(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        $this->locations($tenant);
        $token = $this->issueCrmToken($tenant, $manager)['token'];

        $this->crmGet($token, '/api/v1/integrations/crm/products/NOT-FOUND')
            ->assertNotFound();
        $this->crmGet($token, '/api/v1/integrations/crm/inventory/availability?branch_id=999999')
            ->assertNotFound();
        $this->crmGet($token, '/api/v1/integrations/crm/products', [
            'X-Tenant' => 'tenant-that-does-not-exist',
        ])->assertNotFound();
    }

    public function test_branch_slugs_are_generated_stably_and_are_unique_per_tenant(): void
    {
        [$tenant] = $this->tenantWithManager();
        app(TenantManager::class)->set($tenant);
        $first = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal Centro',
            'code' => 'CENTRO-1',
        ]);
        $first->update(['name' => 'Sucursal Centro Nueva']);
        $second = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal Centro',
            'code' => 'CENTRO-2',
        ]);
        app(TenantManager::class)->clear();

        $this->assertSame('sucursal-centro', $first->fresh()->slug);
        $this->assertSame('sucursal-centro-2', $second->slug);
    }

    public function test_expired_and_revoked_crm_tokens_are_rejected(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        $data = $this->issueCrmToken($tenant, $manager);

        CrmApiToken::query()->whereKey($data['id'])->update(['expires_at' => now()->subMinute()]);
        $this->crmGet($data['token'], '/api/v1/integrations/crm/products')
            ->assertUnauthorized();

        $fresh = $this->issueCrmToken($tenant, $manager);
        $this->withHeaders([
            'Authorization' => "Bearer {$this->loginToken($tenant, $manager)}",
            'X-Tenant' => $tenant->slug,
        ])->deleteJson('/api/crm/integration-tokens/'.$fresh['id'])
            ->assertOk();

        $this->crmGet($fresh['token'], '/api/v1/integrations/crm/products')
            ->assertUnauthorized();
    }

    public function test_manager_can_rotate_a_crm_token_and_old_secret_stops_working(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        $old = $this->issueCrmToken($tenant, $manager);
        $session = $this->loginToken($tenant, $manager);

        $new = $this->withHeaders([
            'Authorization' => "Bearer {$session}",
            'X-Tenant' => $tenant->slug,
        ])->postJson('/api/crm/integration-tokens/'.$old['id'].'/rotate')
            ->assertCreated()
            ->assertJsonPath('data.id', $old['id'])
            ->json('data');

        $this->assertNotSame($old['token'], $new['token']);
        $this->crmGet($old['token'], '/api/v1/integrations/crm/products')
            ->assertUnauthorized();
        $this->crmGet($new['token'], '/api/v1/integrations/crm/products')
            ->assertOk();
    }

    public function test_crm_requests_are_rate_limited_per_token(): void
    {
        [$tenant, $manager] = $this->tenantWithManager();
        $token = $this->issueCrmToken($tenant, $manager)['token'];
        config(['services.crm.rate_limit_per_minute' => 2]);
        RateLimiter::clear('crm:'.CrmApiToken::query()->firstOrFail()->id);

        $this->crmGet($token, '/api/v1/integrations/crm/products')->assertOk();
        $this->crmGet($token, '/api/v1/integrations/crm/products')->assertOk();
        $this->crmGet($token, '/api/v1/integrations/crm/products')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Demasiadas solicitudes de integración CRM.');
    }

    private function tenantWithManager(string $name = 'Empresa CRM', string $slug = 'empresa-crm'): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => $slug]);
        $manager = User::factory()->create(['password' => 'secret123']);
        $manager->tenants()->attach($tenant, ['status' => 'active']);

        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('settings.manage', 'web');
        $role = Role::findOrCreate('CRM Manager', 'web');
        $role->syncPermissions(['settings.manage']);
        $manager->syncRoles($role);
        app(TenantManager::class)->clear();

        return [$tenant, $manager];
    }

    private function locations(Tenant $tenant): array
    {
        app(TenantManager::class)->set($tenant);
        $branchA = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal Centro',
            'code' => 'CENTRO',
            'status' => Branch::STATUS_ACTIVE,
        ]);
        $branchB = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal Norte',
            'code' => 'NORTE',
            'status' => Branch::STATUS_ACTIVE,
        ]);
        $warehouseA = Warehouse::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'name' => 'Almacén Centro',
            'code' => 'CENTRO-01',
            'status' => Warehouse::STATUS_ACTIVE,
        ]);
        $warehouseB = Warehouse::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id,
            'name' => 'Almacén Norte',
            'code' => 'NORTE-01',
            'status' => Warehouse::STATUS_ACTIVE,
        ]);
        app(TenantManager::class)->clear();

        return [$branchA, $branchB, $warehouseA, $warehouseB];
    }

    private function branchFor(Tenant $tenant, string $name, string $code): array
    {
        app(TenantManager::class)->set($tenant);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'status' => Branch::STATUS_ACTIVE,
        ]);
        $warehouse = Warehouse::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Almacén '.$name,
            'code' => $code.'-01',
            'status' => Warehouse::STATUS_ACTIVE,
        ]);
        app(TenantManager::class)->clear();

        return [$branch, $warehouse];
    }

    private function makeStrictOwner(Tenant $tenant, User $user): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(['settings.manage']);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(TenantManager::class)->clear();
    }

    private function grantSettingsManager(Tenant $tenant, User $user): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate([
            'name' => 'CRM Operator',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(['settings.manage']);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(TenantManager::class)->clear();
    }

    private function product(
        Tenant $tenant,
        string $name,
        string $sku,
        float $price = 1200,
        float $cost = 500,
    ): Product {
        app(TenantManager::class)->set($tenant);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'sku' => $sku,
            'base_price' => $price,
            'last_purchase_cost' => $cost,
            'average_cost' => $cost,
            'is_active' => true,
        ]);
        app(TenantManager::class)->clear();

        return $product;
    }

    private function stock(
        Tenant $tenant,
        Warehouse $warehouse,
        Product $product,
        float $available,
        float $reserved,
        float $damaged,
        ?\DateTimeInterface $updatedAt = null,
    ): StockBalance {
        app(TenantManager::class)->set($tenant);
        $balance = StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => $available,
            'quantity_reserved' => $reserved,
            'quantity_damaged' => $damaged,
        ]);
        if ($updatedAt !== null) {
            $balance->forceFill(['updated_at' => $updatedAt])->save();
        }
        app(TenantManager::class)->clear();

        return $balance;
    }

    private function issueCrmToken(Tenant $tenant, User $manager, array $overrides = []): array
    {
        $session = $this->loginToken($tenant, $manager);
        $payload = array_merge([
            'name' => 'CRM test',
            'tenant_scope' => 'tenant',
            'scopes' => CrmApiToken::SCOPES,
            'branch_ids' => null,
            'warehouse_ids' => null,
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ], $overrides);

        return $this->withHeaders([
            'Authorization' => "Bearer {$session}",
            'X-Tenant' => $tenant->slug,
        ])->postJson('/api/crm/integration-tokens', $payload)
            ->assertCreated()
            ->json('data');
    }

    private function loginToken(Tenant $tenant, User $user): string
    {
        return $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->json('data.token');
    }

    private function crmGet(string $token, string $uri, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ], $headers))->getJson($uri);
    }
}
