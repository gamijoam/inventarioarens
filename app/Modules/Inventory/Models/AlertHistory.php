<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'alert_type',
    'severity',
    'subject_type',
    'subject_id',
    'title',
    'message',
    'payload',
    'detected_at',
    'dismissed_at',
    'dismissed_by',
])]
class AlertHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'alert_history';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_DANGER = 'danger';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'detected_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function dismissedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }
}
