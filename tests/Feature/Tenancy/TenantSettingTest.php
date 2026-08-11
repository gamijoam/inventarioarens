<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TenantSetting: configuracion por empresa en JSON, creada automaticamente
 * al registrar el tenant.
 */
class TenantSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_row_is_created_automatically_when_tenant_is_created(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);

        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id]);
        $this->assertNotNull($tenant->setting);
    }

    public function test_setting_row_is_created_for_spinoffs_too(): void
    {
        $group = Tenant::create(['name' => 'Grupo', 'slug' => 'grupo']);
        $spinoff = Tenant::create([
            'name' => 'Hija',
            'slug' => 'hija',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $group->id]);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $spinoff->id]);
    }

    public function test_get_and_set_typed_sections(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $setting = $tenant->setting;

        // Defaults cuando no existe la clave.
        $this->assertTrue($setting->get('telegram.enabled', true));
        $this->assertSame('21:00', $setting->get('telegram.report_time', '21:00'));

        // Setear y releer.
        $setting->set('telegram.enabled', false);
        $setting->set('telegram.report_time', '18:30');

        $fresh = TenantSetting::where('tenant_id', $tenant->id)->first();
        $this->assertFalse($fresh->get('telegram.enabled', true));
        $this->assertSame('18:30', $fresh->get('telegram.report_time'));
    }

    public function test_setting_is_isolated_per_tenant(): void
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $b = Tenant::create(['name' => 'B', 'slug' => 'b']);

        $a->setting->set('telegram.enabled', false);

        $this->assertTrue($b->setting->fresh()->get('telegram.enabled', true));
        $this->assertFalse(TenantSetting::where('tenant_id', $a->id)->first()->get('telegram.enabled', true));
    }
}
