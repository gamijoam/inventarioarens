<?php

namespace App\Modules\DataImport\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataImportEntity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'data_import_id',
        'entity',
        'status',
        'source_path',
        'total_rows',
        'succeeded_rows',
        'skipped_rows',
        'failed_rows',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'succeeded_rows' => 'integer',
        'skipped_rows' => 'integer',
        'failed_rows' => 'integer',
        'error_summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function dataImport(): BelongsTo
    {
        return $this->belongsTo(DataImport::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class);
    }
}
