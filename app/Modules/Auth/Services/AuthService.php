<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function availableTenants(string $email): array
    {
        $user = User::query()
            ->where('email', Str::lower($email))
            ->first();

        if (! $user) {
            return [];
        }

        return $user->tenants()
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
            ])
            ->values()
            ->all();
    }

    public function login(string $email, string $password, Tenant $tenant, Request $request): array
    {
        $user = $this->validateCredentials($email, $password);

        return $this->issueSessionForTenant($user, $tenant, $request);
    }

    public function switchTenant(User $user, Tenant $tenant, Request $request): array
    {
        return $this->issueSessionForTenant($user, $tenant, $request);
    }

    private function issueSessionForTenant(User $user, Tenant $tenant, Request $request): array
    {
        if (! $user->belongsToTenant($tenant)) {
            throw ValidationException::withMessages([
                'tenant' => 'El usuario no pertenece a esta empresa o esta inactivo.',
            ]);
        }

        $plainToken = Str::random(80);
        $token = AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $request->input('device_name', 'api'),
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['*'],
            'expires_at' => now()->addDays(30),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at?->toISOString(),
            'user' => $user,
            'tenant' => $tenant,
            'roles' => $this->roles($user),
            'permissions' => $this->permissions($user),
        ];
    }

    public function currentSession(User $user, Tenant $tenant): array
    {
        setPermissionsTeamId($tenant->id);

        return [
            'user' => $user,
            'tenant' => $tenant,
            'roles' => $this->roles($user),
            'permissions' => $this->permissions($user),
        ];
    }

    public function revokeCurrentToken(?AuthToken $token): void
    {
        if ($token) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAllTenantTokens(User $user, Tenant $tenant): int
    {
        return AuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function validateCredentials(string $email, string $password): User
    {
        $user = User::query()
            ->where('email', Str::lower($email))
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son validas.',
            ]);
        }

        return $user;
    }

    private function roles(User $user): array
    {
        return $user->roles()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function permissions(User $user): array
    {
        return $user->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }
}
