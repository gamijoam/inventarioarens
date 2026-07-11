<?php

namespace Tests\Feature\Performance;

use App\Support\Performance\PerformanceProbe;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PerformanceProbeJsonTest extends TestCase
{
    public function test_performance_probe_logs_with_json_key_format(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $event, array $context) {
                return $event === 'perf_probe.OK'
                    && $context['level'] === 'OK'
                    && $context['operation'] === 'TEST_OP'
                    && $context['elapsed_ms'] >= 0
                    && $context['warn_after_ms'] === 300;
            });

        PerformanceProbe::log('TEST_OP', microtime(true) - 0.1, 300, ['elapsed_ms' => 0]);
    }

    public function test_performance_probe_emits_lento_level_when_above_threshold(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $event, array $context) {
                return $event === 'perf_probe.LENTO'
                    && $context['level'] === 'LENTO'
                    && $context['operation'] === 'SLOW_OP'
                    && $context['elapsed_ms'] >= 500;
            });

        PerformanceProbe::log('SLOW_OP', microtime(true) - 0.6, 500, []);
    }

    public function test_performance_probe_measure_uses_log_under_the_hood(): void
    {
        $logged = false;
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $event, array $context) use (&$logged) {
                $logged = true;

                return $event === 'perf_probe.OK' && $context['operation'] === 'MEASURE_TEST';
            });

        $result = PerformanceProbe::measure('MEASURE_TEST', fn () => 'callback_result', 300);
        $this->assertSame('callback_result', $result);
        $this->assertTrue($logged);
    }

    public function test_performance_probe_filters_null_empty_values(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $event, array $context) {
                return ! array_key_exists('user_id', $context)
                    && ! array_key_exists('metadata', $context)
                    && $context['operation'] === 'CLEAN_OP';
            });

        PerformanceProbe::log('CLEAN_OP', microtime(true), 300, [
            'user_id' => null,
            'metadata' => '',
            'elapsed_ms' => 5,
        ]);
    }

    public function test_performance_probe_includes_tenant_id_when_tenant_resolved(): void
    {
        $tenant = \App\Modules\Tenancy\Models\Tenant::create(['name' => 'Tienda Perf', 'slug' => 'tienda-perf']);
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $event, array $context) use ($tenant) {
                return $context['tenant_id'] === $tenant->id;
            });

        PerformanceProbe::log('TENANT_OP', microtime(true), 300, []);
    }
}