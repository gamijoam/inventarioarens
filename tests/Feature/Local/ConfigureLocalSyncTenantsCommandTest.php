<?php

namespace Tests\Feature\Local;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ConfigureLocalSyncTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_independent_worker_credentials_for_each_tenant(): void
    {
        $path = storage_path('app/sync-worker/sync-config.json');
        $backup = File::exists($path) ? File::get($path) : null;
        File::delete($path);

        try {
            $this->assertSame(0, Artisan::call('local:configure-sync-tenants', [
                '--tenant' => ['caracas=token-caracas', 'valencia=token-valencia'],
                '--cloud-url' => 'https://app.example.com/api',
                '--installation' => 'INSTALL-01',
            ]));

            $config = json_decode(File::get($path), true);
            $this->assertSame('token-caracas', $config['tenants']['caracas']['token']);
            $this->assertSame('token-valencia', $config['tenants']['valencia']['token']);
            $this->assertSame('LOCAL-CARACAS', $config['tenants']['caracas']['node_code']);
            $this->assertSame('LOCAL-VALENCIA', $config['tenants']['valencia']['node_code']);
            $this->assertSame('INSTALL-01', $config['installation_code']);
        } finally {
            if ($backup === null) {
                File::delete($path);
            } else {
                File::put($path, $backup);
            }
        }
    }

    public function test_it_rejects_an_entry_without_a_token(): void
    {
        $this->assertSame(1, Artisan::call('local:configure-sync-tenants', [
            '--tenant' => ['caracas'],
            '--cloud-url' => 'https://app.example.com/api',
        ]));
    }
}
