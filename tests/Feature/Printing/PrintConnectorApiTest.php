<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Printing\Models\PrintConnector;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrintConnectorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_pair_connector_and_connector_can_heartbeat(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $manager = $this->userInTenant($tenant);
        $this->grantRole($tenant, $manager, ['printing.manage', 'printing.view']);

        $pairing = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/connectors/pairing-codes')
            ->assertCreated()
            ->assertJsonStructure(['data' => ['code', 'expires_at']]);

        $registration = $this
            ->postJson('/api/printing/connectors/register', [
                'code' => strtolower($pairing->json('data.code')),
                'name' => 'Caja Principal',
                'installation_id' => 'INSTALL-A-001',
                'version' => '0.1.0',
            ])
            ->assertCreated()
            ->assertJsonPath('data.connector.name', 'Caja Principal')
            ->assertJsonPath('data.connector.tenant_id', $tenant->id)
            ->assertJsonPath('data.connector.status', PrintConnector::STATUS_ACTIVE);

        $this->assertNotNull($registration->json('data.connector.created_at'));
        $this->assertNotNull($registration->json('data.connector.last_seen_at'));

        $this
            ->withHeader('Authorization', 'Bearer '.$registration->json('data.token'))
            ->getJson('/api/printing/connector/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.connector.uuid', $registration->json('data.connector.uuid'))
            ->assertJsonPath('data.connector.status', PrintConnector::STATUS_ACTIVE);
    }

    public function test_pairing_code_is_one_time_and_requires_manager_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $viewer = $this->userInTenant($tenant);
        $this->grantRole($tenant, $viewer, ['printing.view']);

        $this
            ->actingAs($viewer)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/printing/connectors')
            ->assertOk();

        $noAccess = $this->userInTenant($tenant);
        $this
            ->actingAs($noAccess)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/printing/connectors')
            ->assertForbidden();

        $this
            ->actingAs($viewer)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/connectors/pairing-codes')
            ->assertForbidden();

        $manager = $this->userInTenant($tenant);
        $this->grantRole($tenant, $manager, ['printing.manage']);
        $pairing = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/connectors/pairing-codes')
            ->assertCreated();

        $payload = [
            'code' => $pairing->json('data.code'),
            'name' => 'Caja Principal',
            'installation_id' => 'INSTALL-A-002',
        ];

        $this->postJson('/api/printing/connectors/register', $payload)->assertCreated();
        $this->postJson('/api/printing/connectors/register', $payload)->assertUnprocessable();
    }

    public function test_connector_only_lists_claims_and_acknowledges_its_tenant_jobs(): void
    {
        [$tenantA, $tokenA, $connectorA] = $this->pairedConnector('empresa-a', 'INSTALL-A-003');
        [$tenantB, , $connectorB] = $this->pairedConnector('empresa-b', 'INSTALL-B-001');

        $jobA = $this->createJob($tenantA, $connectorA, 'A');
        $jobB = $this->createJob($tenantB, $connectorB, 'B');

        $headers = ['Authorization' => 'Bearer '.$tokenA];
        $jobs = $this->withHeaders($headers)
            ->getJson('/api/printing/connector/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.job_uuid', $jobA->uuid)
            ->assertJsonPath('data.0.payload_snapshot.tenant.slug', $tenantA->slug);

        $this->withHeaders($headers)
            ->get('/api/printing/connector/jobs/'.$jobA->uuid.'/ticket.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $claim = $this
            ->withHeaders($headers)
            ->postJson('/api/printing/connector/jobs/'.$jobs->json('data.0.job_uuid').'/claim')
            ->assertOk()
            ->assertJsonPath('data.job.status', PrintJob::STATUS_CLAIMED)
            ->assertJsonStructure(['data' => ['claim_token', 'job']]);

        $this
            ->withHeaders($headers)
            ->postJson('/api/printing/connector/jobs/'.$jobA->uuid.'/ack', [
                'claim_token' => $claim->json('data.claim_token'),
                'status' => PrintJob::STATUS_PRINTED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PrintJob::STATUS_PRINTED);

        $this->assertSame(PrintJob::STATUS_PRINTED, $jobA->refresh()->status);
        $this->assertSame(PrintJob::STATUS_CREATED, $jobB->refresh()->status);
    }

    public function test_revoked_connector_can_no_longer_access_transport(): void
    {
        [$tenant, $token, $connector] = $this->pairedConnector('empresa-a', 'INSTALL-A-004');
        $manager = $this->userInTenant($tenant);
        $this->grantRole($tenant, $manager, ['printing.manage']);

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/connectors/'.$connector->id.'/revoke')
            ->assertOk()
            ->assertJsonPath('data.status', PrintConnector::STATUS_REVOKED);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/printing/connector/heartbeat')
            ->assertUnauthorized();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /** @return array{0: Tenant, 1: string, 2: PrintConnector} */
    private function pairedConnector(string $slug, string $installationId): array
    {
        $tenant = Tenant::create(['name' => strtoupper($slug), 'slug' => $slug]);
        $manager = $this->userInTenant($tenant);
        $this->grantRole($tenant, $manager, ['printing.manage']);
        $pairing = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/connectors/pairing-codes')
            ->assertCreated();
        $registration = $this->postJson('/api/printing/connectors/register', [
            'code' => $pairing->json('data.code'),
            'name' => 'Conector '.$slug,
            'installation_id' => $installationId,
        ])->assertCreated();

        return [
            $tenant,
            $registration->json('data.token'),
            PrintConnector::withoutGlobalScopes()->findOrFail($registration->json('data.connector.id')),
        ];
    }

    private function createJob(Tenant $tenant, PrintConnector $connector, string $suffix): PrintJob
    {
        $this->useTenant($tenant);
        $profile = PrintProfile::create([
            'name' => 'Perfil '.$suffix,
            'paper_width_mm' => PrintProfile::WIDTH_80,
            'characters_per_line' => 48,
            'is_default' => true,
            'is_active' => true,
        ]);

        return PrintJob::create([
            'print_profile_id' => $profile->id,
            'print_connector_id' => $connector->id,
            'source_type' => 'test',
            'source_id' => 1,
            'output' => PrintJob::OUTPUT_THERMAL,
            'status' => PrintJob::STATUS_CREATED,
            'payload_snapshot' => [
                'tenant' => ['slug' => $tenant->slug],
                'profile' => ['logo_text' => null],
                'items' => [],
            ],
        ]);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, array $permissions): void
    {
        $this->useTenant($tenant);
        $role = Role::findOrCreate('Administrador-'.$tenant->slug, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
