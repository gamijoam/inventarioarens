<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FiscalTaxRateApiTest extends TestCase
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

    public function test_manager_can_create_and_list_tax_rates(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa IVA', 'slug' => 'empresa-iva']);
        $user = $this->userInTenant($tenant, ['settings.manage']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', [
                'code' => 'IVA16',
                'name' => 'IVA general',
                'rate' => 16,
                'category' => 'taxable',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'IVA16')
            ->assertJsonPath('data.category', 'taxable')
            ->assertJsonPath('data.rate', 16)
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', [
                'code' => 'EXENTO',
                'name' => 'Exento',
                'rate' => 0,
                'category' => 'exempt',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/fiscal/tax-rates')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'EXENTO')
            ->assertJsonPath('data.1.code', 'IVA16');
    }

    public function test_manager_can_update_tax_rate_and_sync_payload_contains_snapshot_fields(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync IVA', 'slug' => 'empresa-sync-iva']);
        $user = $this->userInTenant($tenant, ['settings.manage']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', [
                'code' => 'IVA16',
                'name' => 'IVA general',
                'rate' => 16,
                'category' => 'taxable',
            ])
            ->assertCreated();
        $rateId = (int) DB::table('fiscal_tax_rates')->where('tenant_id', $tenant->id)->value('id');

        DB::table('sync_outbox')->delete();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/fiscal/tax-rates/{$rateId}", [
                'name' => 'IVA general actualizado',
                'rate' => 16.0000,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'IVA general actualizado');

        $event = DB::table('sync_outbox')->where('event_type', 'fiscal_tax_rate.updated')->first();
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('IVA16', $payload['code']);
        $this->assertSame('taxable', $payload['category']);
        $this->assertSame('16.0000', $payload['rate']);
        $this->assertNotEmpty($payload['updated_at']);
    }

    public function test_tax_rates_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA, ['settings.manage']);
        $userB = $this->userInTenant($tenantB, ['settings.manage']);

        $this->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/fiscal/tax-rates', [
                'code' => 'IVA16',
                'name' => 'IVA general',
                'rate' => 16,
                'category' => 'taxable',
            ])
            ->assertCreated();
        $rateId = (int) DB::table('fiscal_tax_rates')->where('tenant_id', $tenantB->id)->value('id');

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/fiscal/tax-rates')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/fiscal/tax-rates/{$rateId}")
            ->assertNotFound();

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->patchJson("/api/fiscal/tax-rates/{$rateId}", ['name' => 'No debe cambiar'])
            ->assertNotFound();
    }

    public function test_tax_rate_writes_require_settings_manage(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Protegida', 'slug' => 'empresa-protegida']);
        $user = $this->userInTenant($tenant);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', [
                'code' => 'IVA16',
                'name' => 'IVA general',
                'rate' => 16,
                'category' => 'taxable',
            ])
            ->assertForbidden();
    }

    public function test_tax_rate_validates_unique_code_and_zero_rate_categories(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Validacion IVA', 'slug' => 'empresa-validacion-iva']);
        $user = $this->userInTenant($tenant, ['settings.manage']);
        $payload = [
            'code' => 'EXENTO',
            'name' => 'Exento',
            'rate' => 0,
            'category' => 'exempt',
        ];

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', $payload)
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/fiscal/tax-rates', [
                'code' => 'EXONERADO',
                'name' => 'Exonerado',
                'rate' => 16,
                'category' => 'exonerated',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rate']);
    }

    public function test_tax_rate_catalog_supports_taxable_exempt_exonerated_and_non_taxable_categories(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Categorias IVA', 'slug' => 'empresa-categorias-iva']);
        $user = $this->userInTenant($tenant, ['settings.manage']);

        foreach ([
            ['code' => 'IVA16', 'name' => 'IVA general', 'rate' => 16, 'category' => 'taxable'],
            ['code' => 'EXENTO', 'name' => 'Exento', 'rate' => 0, 'category' => 'exempt'],
            ['code' => 'EXONERADO', 'name' => 'Exonerado', 'rate' => 0, 'category' => 'exonerated'],
            ['code' => 'NO_GRAVADO', 'name' => 'No gravado', 'rate' => 0, 'category' => 'non_taxable'],
        ] as $taxRate) {
            $this->actingAs($user)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/fiscal/tax-rates', $taxRate)
                ->assertCreated()
                ->assertJsonPath('data.category', $taxRate['category'])
                ->assertJsonPath('data.rate', $taxRate['rate']);
        }

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/fiscal/tax-rates')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    private function userInTenant(Tenant $tenant, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->useTenant($tenant);
        $role = Role::findOrCreate('Fiscal Tax Test '.md5($tenant->id.implode('|', $permissions)), 'web');
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
