<?php

namespace App\Modules\Sync\Services;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

class SyncTokenService
{
    public function issue(
        Tenant $tenant,
        User $user,
        string $name = 'sync-worker',
        int $days = 365,
        string $ipAddress = 'api',
        ?string $userAgent = null,
    ): array {
        $plainToken = Str::random(80);
        $expiresAt = now()->addDays(max(1, $days));

        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'name' => $name,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'expires_at' => $expiresAt->toISOString(),
        ];
    }
}
