<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public const PLACEHOLDER_ENTITY_TYPE = 'system';
    public const PLACEHOLDER_ENTITY_ID = 0;

    public function record(
        string $action,
        ?Model $entity = null,
        ?User $user = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? $entity::class : self::PLACEHOLDER_ENTITY_TYPE,
            'entity_id' => $entity ? $entity->getKey() : self::PLACEHOLDER_ENTITY_ID,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
