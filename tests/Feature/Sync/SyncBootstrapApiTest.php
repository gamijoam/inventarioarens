<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncBootstrapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('sync.transport', 'web');
    }

    public function test_bootstrap_returns_a_tenant_scoped_snapshot_and_creates_a_pending_session(): void
    {
        [$tenant, $user] = $this->tenantUser('bootstrap-empresa');
        $this->seedCatalog($tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap', [
                'node_code' => 'LOCAL-BOOTSTRAP-01',
                'node_name' => 'Equipo bootstrap',
                'installation_code' => 'INSTALL-BOOTSTRAP-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', $tenant->slug)
            ->assertJsonPath('data.snapshot.version', 1)
            ->assertJsonPath('data.session.status', 'pending');

        $this->assertGreaterThan(0, $response->json('data.snapshot.event_count'));
        $this->assertNotEmpty($response->json('data.session.token'));
        $this->assertDatabaseHas('sync_bootstrap_sessions', [
            'tenant_id' => $tenant->id,
            'status' => 'pending',
            'installation_code' => 'INSTALL-BOOTSTRAP-01',
        ]);

        $event = collect($response->json('data.snapshot.events'))
            ->firstWhere('event_type', 'product.created');

        $this->assertSame('BOOT-001', $event['payload']['sku']);
        $this->assertNotEmpty($event['created_at']);
        $this->assertNotEmpty($event['updated_at']);
    }

    public function test_bootstrap_completion_marks_snapshot_events_processed_and_is_idempotent(): void
    {
        [$tenant, $user] = $this->tenantUser('bootstrap-complete');
        $this->seedCatalog($tenant);

        $bootstrap = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap', [
                'node_code' => 'LOCAL-COMPLETE-01',
                'node_name' => 'Equipo completo',
                'installation_code' => 'INSTALL-COMPLETE-01',
            ])
            ->assertCreated();

        $sessionToken = $bootstrap->json('data.session.token');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap/'.$sessionToken.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('sync_bootstrap_sessions', [
            'tenant_id' => $tenant->id,
            'status' => 'completed',
        ]);
        $this->assertSame(0, DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', 'like', 'initial-snapshot:%')
            ->where('status', 'pending')
            ->count());
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'direction' => 'pull',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap/'.$sessionToken.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_bootstrap_cannot_be_completed_from_another_tenant(): void
    {
        [$tenant, $user] = $this->tenantUser('bootstrap-owner');
        [$otherTenant, $otherUser] = $this->tenantUser('bootstrap-other');
        $this->seedCatalog($tenant);

        $bootstrap = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap', [
                'node_code' => 'LOCAL-ISOLATION-01',
                'node_name' => 'Equipo aislamiento',
                'installation_code' => 'INSTALL-ISOLATION-01',
            ])
            ->assertCreated();

        $sessionToken = $bootstrap->json('data.session.token');

        $this->actingAs($otherUser)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->postJson('/api/sync/bootstrap/'.$sessionToken.'/complete')
            ->assertUnprocessable();

        $this->assertDatabaseHas('sync_bootstrap_sessions', [
            'tenant_id' => $tenant->id,
            'status' => 'pending',
        ]);
    }

    public function test_an_old_bootstrap_session_cannot_complete_a_new_snapshot_for_the_same_node(): void
    {
        [$tenant, $user] = $this->tenantUser('bootstrap-retry');
        $this->seedCatalog($tenant);
        $payload = [
            'node_code' => 'LOCAL-RETRY-01',
            'node_name' => 'Equipo retry',
            'installation_code' => 'INSTALL-RETRY-01',
        ];

        $first = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap', $payload)
            ->assertCreated();
        $second = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap', $payload)
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap/'.$first->json('data.session.token').'/complete')
            ->assertOk();
        $this->assertGreaterThan(0, DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->count());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/bootstrap/'.$second->json('data.session.token').'/complete')
            ->assertOk();
        $this->assertSame(0, DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->count());
    }

    private function tenantUser(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'email' => $slug.'@bootstrap.test',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        setPermissionsTeamId($tenant->id);
        $user->givePermissionTo('sync.transport');

        return [$tenant, $user];
    }

    private function seedCatalog(Tenant $tenant): void
    {
        $now = now();
        $branchId = DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Principal',
            'code' => 'MAIN',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'name' => 'Almacen principal',
            'code' => 'MAIN-01',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $rateTypeId = DB::table('exchange_rate_types')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'BCV',
            'name' => 'BCV',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto bootstrap',
            'sku' => 'BOOT-001',
            'tracking_type' => 'quantity',
            'base_price' => '10.0000',
            'sale_currency' => 'USD',
            'sale_exchange_rate_type_id' => $rateTypeId,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('stock_movements')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'type' => 'purchase',
            'quantity' => '2.0000',
            'reason' => 'Bootstrap',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
