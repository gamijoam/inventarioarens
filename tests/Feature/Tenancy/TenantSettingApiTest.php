<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Endpoints GET/PATCH /api/tenant-settings (configuracion por empresa).
 */
class TenantSettingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_read_settings(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $user = $this->member($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/tenant-settings')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonStructure(['data' => ['settings']]);
    }

    public function test_member_can_update_telegram_section(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $user = $this->member($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-settings', [
                'settings' => [
                    'telegram' => [
                        'enabled' => true,
                        'report_time' => '18:30',
                        'low_stock_alerts' => true,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.telegram.enabled', true)
            ->assertJsonPath('data.settings.telegram.report_time', '18:30');

        $stored = DB::table('tenant_settings')->where('tenant_id', $tenant->id)->value('settings');
        $this->assertSame('18:30', json_decode((string) $stored, true)['telegram']['report_time']);
    }

    public function test_update_preserves_unrelated_sections(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        DB::table('tenant_settings')
            ->where('tenant_id', $tenant->id)
            ->update(['settings' => json_encode(['otra_seccion' => ['valor' => 1]])]);

        $user = $this->member($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-settings', [
                'settings' => ['telegram' => ['enabled' => true]],
            ])
            ->assertOk();

        $stored = json_decode((string) DB::table('tenant_settings')->where('tenant_id', $tenant->id)->value('settings'), true);
        $this->assertSame(1, $stored['otra_seccion']['valor']);
        $this->assertTrue($stored['telegram']['enabled']);
    }

    public function test_user_not_member_of_tenant_cannot_read_or_write(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra']);
        $user = $this->member($other);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/tenant-settings')
            ->assertStatus(403);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-settings', ['settings' => ['telegram' => ['enabled' => false]]])
            ->assertStatus(403);
    }

    private function member(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);

        return $user;
    }
}
