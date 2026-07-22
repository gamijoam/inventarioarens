<?php

namespace App\Modules\DataImport\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImportRow extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'data_import_entity_id',
        'row_number',
        'status',
        'payload',
        'errors',
        'natural_key',
        'resulting_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'errors' => 'array',
        'row_number' => 'integer',
        'resulting_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function dataImportEntity(): BelongsTo
    {
        return $this->belongsTo(DataImportEntity::class);
    }
}
