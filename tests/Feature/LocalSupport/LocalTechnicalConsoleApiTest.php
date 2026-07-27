<?php

namespace Tests\Feature\LocalSupport;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalTechnicalConsoleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_technical_console_lists_local_tenants_only_when_enabled(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');

        Tenant::create(['name' => 'Caracas local', 'slug' => 'caracas-local']);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/local-support/status')
            ->assertOk()
            ->assertJsonPath('data.cloud_url', 'https://cloud.test/api')
            ->assertJsonPath('data.tenants.0.slug', 'caracas-local')
            ->assertJsonPath('data.tenants.0.worker.active', false);
    }

    public function test_local_technical_console_is_hidden_when_not_enabled(): void
    {
        config()->set('services.local_support.enabled', false);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/local-support/status')
            ->assertNotFound();
    }
}
