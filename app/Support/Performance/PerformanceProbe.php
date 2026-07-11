<?php

namespace App\Support\Performance;

use Closure;
use Illuminate\Support\Facades\Log;

class PerformanceProbe
{
    public static function measure(string $operation, Closure $callback, int $warnAfterMilliseconds = 300, array $context = []): mixed
    {
        $startedAt = microtime(true);

        try {
            return $callback();
        } finally {
            self::log($operation, $startedAt, $warnAfterMilliseconds, $context);
        }
    }

    public static function log(string $operation, float $startedAt, int $warnAfterMilliseconds = 300, array $context = []): void
    {
        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
        $level = $elapsed >= $warnAfterMilliseconds ? 'LENTO' : 'OK';

        Log::info("perf_probe.{$level}", array_filter([
            'level' => $level,
            'operation' => $operation,
            'elapsed_ms' => $elapsed,
            'warn_after_ms' => $warnAfterMilliseconds,
            'tenant_id' => app(\App\Support\Tenancy\TenantManager::class)->current()?->id,
            ...$context,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []));
    }
}
