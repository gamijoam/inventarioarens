<?php

namespace App\Modules\AccessControl\Resources;

use App\Modules\AccessControl\Services\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_protected' => in_array($this->name, AccessControlService::PROTECTED_ROLES, true),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')->sort()->values()->all()
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
