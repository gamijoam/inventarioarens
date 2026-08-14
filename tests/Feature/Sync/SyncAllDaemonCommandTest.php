<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncDaemonSchedule;
use App\Modules\Sync\Services\SyncWorkerService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncAllDaemonCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $settingsPath;

    private ?string $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsPath = storage_path('app/sync-worker/sync-config.json');
        $this->previousSettings = is_file($this->settingsPath)
            ? (string) file_get_contents($this->settingsPath)
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousSettings === null) {
            File::delete($this->settingsPath);
        } else {
            File::put($this->settingsPath, $this->previousSettings);
        }

        parent::tearDown();
    }

    public function test_supervisor_runs_every_configured_tenant_without_exposing_tokens(): void
    {
        $first = Tenant::create(['name' => 'Primera', 'slug' => 'primera']);
        $second = Tenant::create(['name' => 'Segunda', 'slug' => 'segunda']);
        $this->writeSettings([
            'primera' => $this->configuration('secret-one', 'NODE-01'),
            'segunda' => $this->configuration('secret-two', 'NODE-02'),
            'sin-token' => ['cloud_url' => 'https://cloud.test/api'],
        ]);

        $this->mock(SyncWorkerService::class, function (MockInterface $mock) use ($first, $second): void {
            $mock->shouldReceive('run')->once()->withArgs(
                fn (Tenant $tenant, string $node, string $name, string $url, string $token): bool => $tenant->is($first) && $node === 'NODE-01' && $name === 'Nodo NODE-01'
                    && $url === 'https://cloud.test/api' && $token === 'secret-one'
            )->andReturn($this->summary());
            $mock->shouldReceive('run')->once()->withArgs(
                fn (Tenant $tenant, string $node, string $name, string $url, string $token): bool => $tenant->is($second) && $node === 'NODE-02' && $name === 'Nodo NODE-02'
                    && $url === 'https://cloud.test/api' && $token === 'secret-two'
            )->andReturn($this->summary());
        });

        $this->artisan('sync:daemon-all', ['--once' => true])
            ->expectsOutputToContain('Supervisor de sincronizacion iniciado.')
            ->expectsOutputToContain('primera: OK')
            ->expectsOutputToContain('segunda: OK')
            ->doesntExpectOutputToContain('secret-one')
            ->doesntExpectOutputToContain('secret-two')
            ->assertSuccessful();
    }

    public function test_failure_in_one_tenant_does_not_prevent_other_tenants_from_running(): void
    {
        Tenant::create(['name' => 'Primera', 'slug' => 'primera']);
        Tenant::create(['name' => 'Segunda', 'slug' => 'segunda']);
        $this->writeSettings([
            'primera' => $this->configuration('secret-one', 'NODE-01'),
            'segunda' => $this->configuration('secret-two', 'NODE-02'),
        ]);

        $this->mock(SyncWorkerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('nube temporalmente fuera de linea'));
            $mock->shouldReceive('run')->once()->andReturn($this->summary());
        });

        $this->artisan('sync:daemon-all', ['--once' => true])
            ->expectsOutputToContain('primera: ERROR - nube temporalmente fuera de linea')
            ->expectsOutputToContain('segunda: OK')
            ->assertExitCode(1);
    }

    public function test_supervisor_passes_each_tenant_interval_to_the_schedule(): void
    {
        $first = Tenant::create(['name' => 'Primera', 'slug' => 'primera']);
        $second = Tenant::create(['name' => 'Segunda', 'slug' => 'segunda']);
        $firstConfiguration = $this->configuration('secret-one', 'NODE-01');
        $firstConfiguration['interval'] = 5;
        $secondConfiguration = $this->configuration('secret-two', 'NODE-02');
        $secondConfiguration['interval'] = 30;
        $this->writeSettings([
            'primera' => $firstConfiguration,
            'segunda' => $secondConfiguration,
        ]);

        $this->mock(SyncDaemonSchedule::class, function (MockInterface $mock): void {
            $mock->shouldReceive('claim')->once()->withArgs(
                fn (string $slug, float $now, int $interval): bool => $slug === 'primera' && $interval === 5,
            )->andReturnTrue();
            $mock->shouldReceive('claim')->once()->withArgs(
                fn (string $slug, float $now, int $interval): bool => $slug === 'segunda' && $interval === 30,
            )->andReturnTrue();
        });
        $this->mock(SyncWorkerService::class, function (MockInterface $mock) use ($first, $second): void {
            $mock->shouldReceive('run')->once()->withArgs(fn (Tenant $tenant): bool => $tenant->is($first))->andReturn($this->summary());
            $mock->shouldReceive('run')->once()->withArgs(fn (Tenant $tenant): bool => $tenant->is($second))->andReturn($this->summary());
        });

        $this->artisan('sync:daemon-all', ['--once' => true])->assertSuccessful();
    }

    private function writeSettings(array $tenants): void
    {
        File::ensureDirectoryExists(dirname($this->settingsPath));
        File::put($this->settingsPath, json_encode(['version' => 2, 'tenants' => $tenants], JSON_PRETTY_PRINT));
    }

    private function configuration(string $token, string $node): array
    {
        return [
            'token' => $token,
            'cloud_url' => 'https://cloud.test/api',
            'node_code' => $node,
            'node_name' => 'Nodo '.$node,
            'installation_code' => 'INSTALL-'.$node,
            'limit' => 25,
            'interval' => 15,
        ];
    }

    private function summary(): array
    {
        return ['pushed' => 0, 'pulled' => 0, 'applied' => 0, 'ignored' => 0, 'failed' => 0];
    }
}
