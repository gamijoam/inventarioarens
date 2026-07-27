<?php

namespace Tests\Feature\Local;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AddLocalSyncTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prepares_the_local_tenant_and_registers_its_worker(): void
    {
        $path = storage_path('app/sync-worker/sync-config.json');
        $backup = File::exists($path) ? File::get($path) : null;
        File::delete($path);
        putenv('TEST_NEW_TENANT_PASSWORD=local-secret');
        putenv('TEST_NEW_TENANT_TOKEN=cloud-token');

        try {
            $this->assertSame(0, Artisan::call('local:add-sync-tenant', [
                'tenant_slug' => 'nueva-empresa',
                'tenant_name' => 'Nueva Empresa',
                'email' => 'admin@nueva.test',
                '--password-env' => 'TEST_NEW_TENANT_PASSWORD',
                '--token-env' => 'TEST_NEW_TENANT_TOKEN',
                '--cloud-url' => 'https://app.example.com/api',
                '--installation' => 'POS-02',
            ]));

            $tenant = Tenant::query()->where('slug', 'nueva-empresa')->firstOrFail();
            $this->assertTrue(User::query()->where('email', 'admin@nueva.test')->firstOrFail()->belongsToTenant($tenant));
            $config = json_decode(File::get($path), true);
            $this->assertSame('cloud-token', $config['tenants']['nueva-empresa']['token']);
            $this->assertSame('POS-02', $config['installation_code']);
        } finally {
            putenv('TEST_NEW_TENANT_PASSWORD');
            putenv('TEST_NEW_TENANT_TOKEN');
            if ($backup === null) {
                File::delete($path);
            } else {
                File::put($path, $backup);
            }
        }
    }

    public function test_it_rejects_missing_credentials_before_creating_the_tenant(): void
    {
        putenv('TEST_NEW_TENANT_PASSWORD');
        putenv('TEST_NEW_TENANT_TOKEN');

        $this->assertSame(1, Artisan::call('local:add-sync-tenant', [
            'tenant_slug' => 'no-debe-crearse',
            'tenant_name' => 'No Debe Crearse',
            'email' => 'admin@no.test',
        ]));
        $this->assertDatabaseMissing('tenants', ['slug' => 'no-debe-crearse']);
    }
}
