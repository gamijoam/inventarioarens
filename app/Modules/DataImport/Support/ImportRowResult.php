<?php

namespace App\Modules\DataImport\Support;

final class ImportRowResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?int $resultingId = null,
        public readonly ?string $naturalKey = null,
        public readonly array $errors = [],
    ) {}

    public static function ok(?int $resultingId = null, ?string $naturalKey = null, ?string $message = null): self
    {
        return new self(self::STATUS_OK, $message, $resultingId, $naturalKey);
    }

    public static function skipped(string $message, ?string $naturalKey = null): self
    {
        return new self(self::STATUS_SKIPPED, $message, null, $naturalKey);
    }

    /**
     * @param  array<string, string|array<int, string>>  $errors
     */
    public static function failed(array $errors, ?string $naturalKey = null): self
    {
        return new self(self::STATUS_FAILED, null, null, $naturalKey, $errors);
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
