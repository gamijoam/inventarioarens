<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

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
        $this->setTenantContext($tenant);

        try {
            $user = $this->validateCredentials($email, $password);
        } catch (ValidationException $exception) {
            $this->audit->record(
                action: 'auth.login.failed',
                user: null,
                oldValues: null,
                newValues: [
                    'email' => $email,
                    'tenant_slug' => $tenant->slug,
                    'reason' => 'invalid_credentials',
                    'ip_address' => $request->ip(),
                ],
            );

            throw $exception;
        }

        $this->audit->record(
            action: 'auth.login.success',
            entity: $user,
            user: $user,
            newValues: [
                'tenant_slug' => $tenant->slug,
                'ip_address' => $request->ip(),
            ],
        );

        return $this->issueSessionForTenant($user, $tenant, $request);
    }

    public function switchTenant(User $user, Tenant $tenant, Request $request): array
    {
        $this->setTenantContext($tenant);

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

        $this->audit->record(
            action: 'auth.token.issued',
            entity: $user,
            user: $user,
            newValues: [
                'tenant_slug' => $tenant->slug,
                'token_id' => $token->id,
                'token_name' => $token->name,
                'expires_at' => $token->expires_at?->toISOString(),
                'ip_address' => $request->ip(),
            ],
        );

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

    /**
     * Set the tenant in TenantManager + Spatie Permission before the audit log
     * is created, so BelongsToTenant::creating can resolve the current tenant.
     */
    private function setTenantContext(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }
    }

    public function currentSession(User $user, ?Tenant $tenant): array
    {
        if ($tenant !== null) {
            setPermissionsTeamId($tenant->id);
        }

        return [
            'user' => $user,
            'tenant' => $tenant,
            'roles' => $tenant ? $this->roles($user) : [],
            'permissions' => $tenant ? $this->permissions($user) : [],
        ];
    }

    /**
     * Login for Platform Admins (SaaS Master 3er nivel).
     *
     * Emite un AuthToken con tenant_id = null (token global). Solo se permite
     * si el usuario tiene is_platform_admin=true. No requiere header X-Tenant
     * porque opera sobre todos los grupos/spinoffs.
     */
    public function platformLogin(string $email, string $password, Request $request): array
    {
        $user = User::query()
            ->where('email', Str::lower($email))
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->consumeDummyBcryptTime();

            $this->audit->record(
                action: 'auth.platform_login.failed',
                user: null,
                oldValues: null,
                newValues: [
                    'email' => $email,
                    'reason' => 'invalid_credentials',
                    'ip_address' => $request->ip(),
                ],
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son validas.',
            ]);
        }

        if (! $user->is_platform_admin) {
            $this->audit->record(
                action: 'auth.platform_login.failed',
                user: $user,
                oldValues: null,
                newValues: [
                    'email' => $email,
                    'reason' => 'not_platform_admin',
                    'ip_address' => $request->ip(),
                ],
            );

            throw ValidationException::withMessages([
                'email' => 'Este usuario no es Platform Admin.',
            ]);
        }

        $plainToken = Str::random(80);
        $token = AuthToken::create([
            'tenant_id' => null,
            'user_id' => $user->id,
            'name' => $request->input('device_name', 'platform-desktop'),
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['platform'],
            'expires_at' => now()->addDays(30),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->audit->record(
            action: 'auth.platform_login.success',
            entity: $user,
            user: $user,
            newValues: [
                'token_id' => $token->id,
                'token_name' => $token->name,
                'expires_at' => $token->expires_at?->toISOString(),
                'ip_address' => $request->ip(),
            ],
        );

        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at?->toISOString(),
            'user' => $user,
            'tenant' => null,
            'roles' => $this->roles($user),
            'permissions' => $this->permissions($user),
        ];
    }

    public function revokeCurrentToken(?AuthToken $token): void
    {
        if ($token) {
            $token->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(
                action: 'auth.token.revoked',
                entity: null,
                newValues: [
                    'token_id' => $token->id,
                    'token_name' => $token->name,
                ],
            );
        }
    }

    public function revokeAllTenantTokens(User $user, Tenant $tenant): int
    {
        $count = AuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($count > 0) {
            $this->audit->record(
                action: 'auth.token.revoked_all',
                entity: $user,
                user: $user,
                newValues: [
                    'tenant_slug' => $tenant->slug,
                    'count' => $count,
                ],
            );
        }

        return $count;
    }

    private function validateCredentials(string $email, string $password): User
    {
        $user = User::query()
            ->where('email', Str::lower($email))
            ->first();

        if (! $user) {
            $this->consumeDummyBcryptTime();

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son validas.',
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son validas.',
            ]);
        }

        return $user;
    }

    /**
     * Consume un dummy bcrypt para que el tiempo de respuesta cuando el email
     * no existe sea similar al de cuando existe (cierra timing attack).
     */
    private function consumeDummyBcryptTime(): void
    {
        Hash::check(Str::random(32), Hash::make(Str::random(32)));
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
