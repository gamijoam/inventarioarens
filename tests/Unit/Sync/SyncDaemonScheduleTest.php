<?php

namespace Tests\Unit\Sync;

use App\Modules\Sync\Services\SyncDaemonSchedule;
use PHPUnit\Framework\TestCase;

class SyncDaemonScheduleTest extends TestCase
{
    public function test_each_tenant_has_its_own_next_run_time(): void
    {
        $schedule = new SyncDaemonSchedule;

        self::assertTrue($schedule->claim('fast', now: 100.0, interval: 5));
        self::assertTrue($schedule->claim('slow', now: 100.0, interval: 30));
        self::assertFalse($schedule->claim('fast', now: 104.9, interval: 5));
        self::assertTrue($schedule->claim('fast', now: 105.0, interval: 5));
        self::assertFalse($schedule->claim('slow', now: 105.0, interval: 30));
        self::assertTrue($schedule->claim('slow', now: 130.0, interval: 30));
    }

    public function test_next_sleep_uses_the_nearest_configured_tenant(): void
    {
        $schedule = new SyncDaemonSchedule;
        $schedule->claim('fast', now: 100.0, interval: 5);
        $schedule->claim('slow', now: 100.0, interval: 30);

        self::assertSame(5, $schedule->secondsUntilNext(now: 100.0, fallback: 15));
        self::assertSame(1, $schedule->secondsUntilNext(now: 104.5, fallback: 15));
        self::assertSame(1, $schedule->secondsUntilNext(now: 140.0, fallback: 15));
    }

    public function test_intervals_are_clamped_to_safe_bounds(): void
    {
        $schedule = new SyncDaemonSchedule;

        $schedule->claim('minimum', now: 100.0, interval: 1);
        $schedule->claim('maximum', now: 100.0, interval: 9999);

        self::assertSame(5, $schedule->secondsUntilNext(now: 100.0, fallback: 15));
        self::assertTrue($schedule->claim('minimum', now: 105.0, interval: 1));
        self::assertFalse($schedule->claim('maximum', now: 105.0, interval: 9999));
        self::assertTrue($schedule->claim('maximum', now: 400.0, interval: 9999));
    }
}
