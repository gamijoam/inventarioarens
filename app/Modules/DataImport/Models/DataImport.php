<?php

namespace App\Modules\DataImport\Models;

use App\Models\User;
use App\Modules\DataImport\Support\ImportStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataImport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'status',
        'total_entities',
        'total_rows',
        'processed_rows',
        'succeeded_rows',
        'skipped_rows',
        'failed_rows',
        'meta',
        'report_path',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'total_entities' => 'integer',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'succeeded_rows' => 'integer',
        'skipped_rows' => 'integer',
        'failed_rows' => 'integer',
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entities(): HasMany
    {
        return $this->hasMany(DataImportEntity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ImportStatus::SESSION_PENDING,
            ImportStatus::SESSION_RUNNING,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            ImportStatus::SESSION_PENDING,
            ImportStatus::SESSION_RUNNING,
        ], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            ImportStatus::SESSION_COMPLETED,
            ImportStatus::SESSION_FAILED,
            ImportStatus::SESSION_CANCELLED,
        ], true);
    }
}
