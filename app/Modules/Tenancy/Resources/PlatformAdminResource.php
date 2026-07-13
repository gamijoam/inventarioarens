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
            'created_at' => optional($user->created_at)->toIso8601String(),
        ];
    }
}
