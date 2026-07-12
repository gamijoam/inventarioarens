<?php

namespace Tests\Feature\InventoryTransfers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\InventoryTransfers\Models\TenantTransferSetting;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantTransferSettingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant_trait_is_applied(): void
    {
        $traits = class_uses_recursive(TenantTransferSetting::class);

        $this->assertContains(
            \App\Support\Tenancy\Concerns\BelongsToTenant::class,
            $traits,
            'TenantTransferSetting debe usar BelongsToTenant para multi-tenancy'
        );
    }

    public function test_setting_is_scoped_per_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        TenantTransferSetting::create([
            'tenant_id' => $tenantA->id,
            'validation_mode' => TenantTransferSetting::MODE_LOGISTICS,
        ]);

        TenantTransferSetting::create([
            'tenant_id' => $tenantB->id,
            'validation_mode' => TenantTransferSetting::MODE_SIMPLE,
        ]);

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $settingForA = TenantTransferSetting::query()->first();
        $this->assertNotNull($settingForA, 'Tenant A debe ver su setting');
        $this->assertSame(TenantTransferSetting::MODE_LOGISTICS, $settingForA->validation_mode);
        $this->assertSame($tenantA->id, $settingForA->tenant_id);

        app(TenantManager::class)->set($tenantB);
        setPermissionsTeamId($tenantB->id);
        $settingForB = TenantTransferSetting::query()->first();
        $this->assertNotNull($settingForB, 'Tenant B debe ver su setting');
        $this->assertSame(TenantTransferSetting::MODE_SIMPLE, $settingForB->validation_mode);
        $this->assertSame($tenantB->id, $settingForB->tenant_id);
    }

    public function test_setting_is_unique_per_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant', 'slug' => 'tenant-unique']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        TenantTransferSetting::create([
            'tenant_id' => $tenant->id,
            'validation_mode' => TenantTransferSetting::MODE_LOGISTICS,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        TenantTransferSetting::create([
            'tenant_id' => $tenant->id,
            'validation_mode' => TenantTransferSetting::MODE_SIMPLE,
        ]);
    }

    public function test_casts_apply_correctly(): void
    {
        $tenant = Tenant::create(['name' => 'Casts', 'slug' => 'tenant-casts']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $setting = TenantTransferSetting::create([
            'tenant_id' => $tenant->id,
            'validation_mode' => TenantTransferSetting::MODE_SIMPLE,
            'reserve_on_request' => true,
            'require_preparation_checklist' => false,
            'require_reception_checklist' => true,
            'settings' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $fresh = $setting->fresh();
        $this->assertTrue($fresh->reserve_on_request);
        $this->assertFalse($fresh->require_preparation_checklist);
        $this->assertTrue($fresh->require_reception_checklist);
        $this->assertSame(['key' => 'value', 'nested' => ['a' => 1]], $fresh->settings);
    }

    public function test_belongs_to_tenant_relation(): void
    {
        $tenant = Tenant::create(['name' => 'Relation', 'slug' => 'tenant-relation']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $setting = TenantTransferSetting::create([
            'tenant_id' => $tenant->id,
            'validation_mode' => TenantTransferSetting::MODE_SIMPLE,
        ]);

        $this->assertSame($tenant->id, $setting->tenant->id);
    }
}