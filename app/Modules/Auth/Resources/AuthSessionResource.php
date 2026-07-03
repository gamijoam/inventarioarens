<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this['user']->id,
                'name' => $this['user']->name,
                'email' => $this['user']->email,
            ],
            'tenant' => [
                'id' => $this['tenant']->id,
                'name' => $this['tenant']->name,
                'slug' => $this['tenant']->slug,
                'domain' => $this['tenant']->domain,
            ],
            'roles' => $this['roles'],
            'permissions' => $this['permissions'],
        ];
    }
}
