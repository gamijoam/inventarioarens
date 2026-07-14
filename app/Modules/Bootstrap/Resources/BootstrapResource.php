<?php

namespace App\Modules\Bootstrap\Resources;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BootstrapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{user: User, tenant: ?Tenant, plain_token: string, generated_password: ?string} $payload */
        $payload = $this->resource;

        $user = $payload['user'];
        $tenant = $payload['tenant'];

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_platform_admin' => true,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
            ] : null,
            'token' => $payload['plain_token'],
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'initial_password' => $payload['generated_password'],
            'next_steps' => [
                'platform_login' => 'POST /api/auth/platform-login con email + password',
                'create_groups' => 'POST /api/master/groups para crear un grupo (tenant padre)',
                'create_spinoffs' => 'POST /api/master/groups/{group}/tenants para empresas hijas',
                'create_tenant_users' => 'POST /api/access/users dentro de una empresa para usuarios locales',
            ],
        ];
    }
}
