<?php

namespace App\Modules\AccessControl\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->pivot?->status
            ?? $this->organization_status
            ?? $this->whenLoaded('tenants', fn () => $this->tenants->first()?->pivot?->status);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $status,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'tenants' => $this->whenLoaded('tenants', fn () => $this->tenants->map(fn ($tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_group' => (bool) $tenant->is_group,
                'status' => $tenant->pivot?->status,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
