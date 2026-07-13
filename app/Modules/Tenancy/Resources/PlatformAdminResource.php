<?php

namespace App\Modules\Tenancy\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformAdminResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'is_active' => (bool) $user->is_platform_admin,
            'auth_tokens_count' => $user->authTokens()->count(),
            'last_login_at' => optional($user->authTokens()->latest('last_used_at')->first())->last_used_at?->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'updated_at' => optional($user->updated_at)->toIso8601String(),
        ];
    }
}