<?php

namespace App\Modules\Auth\Resources;

use App\Modules\Tenancy\Services\CompanySettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this['tenant'] ?? null;

        $payload = [
            'requires_tenant_selection' => (bool) ($this['requires_tenant_selection'] ?? false),
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
                'logo_url' => CompanySettings::getForTenant($tenant)['logo_url'] ?? null,
            ] : null,
            'roles' => $this['roles'] ?? [],
            'permissions' => $this['permissions'] ?? [],
            'capabilities' => $this['capabilities'] ?? [],
        ];

        if (isset($this['tenants'])) {
            $payload['tenants'] = $this['tenants'];
        }

        return $payload;
    }
}
