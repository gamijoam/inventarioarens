<?php

namespace Tests\Unit\Support;

use App\Support\Time\BusinessDateRange;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessDateRangeTest extends TestCase
{
    public function test_business_timezone_defaults_to_venezuela(): void
    {
        $this->assertSame('America/Caracas', BusinessDateRange::timezone());
    }

    public function test_day_boundaries_are_local_midnight_expressed_in_utc(): void
    {
        [$from, $to] = BusinessDateRange::day('2026-09-17');

        $this->assertSame('2026-09-17 04:00:00', $from->toDateTimeString());
        $this->assertSame('2026-09-18 03:59:59', $to->toDateTimeString());
        $this->assertSame('UTC', $from->getTimezone()->getName());
        $this->assertSame('UTC', $to->getTimezone()->getName());
    }

    public function test_a_late_utc_timestamp_belongs_to_the_previous_local_day(): void
    {
        $lateUtc = Carbon::parse('2026-09-17 01:17:47', 'UTC');

        [$from17, $to17] = BusinessDateRange::day('2026-09-17');
        $this->assertFalse($lateUtc->between($from17, $to17));

        [$from16, $to16] = BusinessDateRange::day('2026-09-16');
        $this->assertTrue($lateUtc->between($from16, $to16));
    }

    public function test_local_daytime_timestamps_stay_within_their_local_day(): void
    {
        $daytimeUtc = Carbon::parse('2026-09-17 13:00:00', 'UTC');

        [$from, $to] = BusinessDateRange::day('2026-09-17');
        $this->assertTrue($daytimeUtc->between($from, $to));
    }

    public function test_range_ignores_missing_bounds(): void
    {
        [$from, $to] = BusinessDateRange::range('2026-09-17', null);

        $this->assertSame('2026-09-17 04:00:00', $from->toDateTimeString());
        $this->assertNull($to);

        [$from, $to] = BusinessDateRange::range(null, null);
        $this->assertNull($from);
        $this->assertNull($to);
    }
}
