<?php

namespace App\Support\Time;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class BusinessDateRange
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'America/Caracas');
    }

    public static function startOfDay(DateTimeInterface|string $date): Carbon
    {
        return self::localDate($date)->startOfDay()->utc();
    }

    public static function endOfDay(DateTimeInterface|string $date): Carbon
    {
        return self::localDate($date)->endOfDay()->utc();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function day(DateTimeInterface|string $date): array
    {
        return [self::startOfDay($date), self::endOfDay($date)];
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function range(DateTimeInterface|string|null $from, DateTimeInterface|string|null $to): array
    {
        return [
            $from !== null && $from !== '' ? self::startOfDay($from) : null,
            $to !== null && $to !== '' ? self::endOfDay($to) : null,
        ];
    }

    private static function localDate(DateTimeInterface|string $date): Carbon
    {
        if ($date instanceof DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        return Carbon::parse($date, self::timezone());
    }
}
