<?php

namespace App\Modules\Sync\Services;

class SyncDaemonSchedule
{
    private const MIN_INTERVAL = 5;

    private const MAX_INTERVAL = 300;

    /** @var array<string, float> */
    private array $nextRunAt = [];

    public function claim(string $tenantSlug, float $now, int $interval): bool
    {
        $nextRunAt = $this->nextRunAt[$tenantSlug] ?? null;
        if ($nextRunAt !== null && $now < $nextRunAt) {
            return false;
        }

        $this->nextRunAt[$tenantSlug] = $now + $this->normalizeInterval($interval);

        return true;
    }

    public function secondsUntilNext(float $now, int $fallback): int
    {
        if ($this->nextRunAt === []) {
            return $this->normalizeInterval($fallback);
        }

        $nearest = min($this->nextRunAt);

        return max(1, (int) ceil($nearest - $now));
    }

    private function normalizeInterval(int $interval): int
    {
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));
    }
}
