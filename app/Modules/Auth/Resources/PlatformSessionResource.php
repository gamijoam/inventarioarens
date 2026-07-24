<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this['user'];

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_platform_admin' => (bool) $user->is_platform_admin,
            ],
            'tenant' => null,
            'roles' => $this['roles'] ?? [],
            'permissions' => $this['permissions'] ?? [],
        ];
    }
}
