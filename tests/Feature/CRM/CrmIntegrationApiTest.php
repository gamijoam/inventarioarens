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
        app(TenantManager::class)->clear();

        return $balance;
    }

    private function issueCrmToken(Tenant $tenant, User $manager, array $overrides = []): array
    {
        $session = $this->loginToken($tenant, $manager);
        $payload = array_merge([
            'name' => 'CRM test',
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
