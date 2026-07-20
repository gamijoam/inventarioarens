<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this['tenant'] ?? null;

        return [
            'user' => [
                'id' => $this['user']->id,
                'name' => $this['user']->name,
                'email' => $this['user']->email,
                'is_platform_admin' => (bool) $this['user']->is_platform_admin,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'parent_id' => $tenant->parent_id,
                'is_group' => (bool) $tenant->is_group,
            ] : null,
            'roles' => $this['roles'] ?? [],
            'permissions' => $this['permissions'] ?? [],
        ];
    }
}
