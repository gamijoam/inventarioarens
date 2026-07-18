<?php

namespace App\Modules\Bootstrap\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BootstrapService
{
    public const ENV_BOOTSTRAP_TOKEN = 'APP_BOOTSTRAP_TOKEN';

    public function __construct(private readonly AuditLogger $audit) {}

    public function isEnabled(): bool
    {
        return $this->bootstrapToken() !== '';
    }

    public function status(): array
    {
        $enabled = $this->isEnabled();
        $userCount = (int) User::query()->count();
        $tenantCount = (int) Tenant::query()->count();
        $databaseEmpty = $userCount === 0 && $tenantCount === 0;

        return [
            'enabled' => $enabled,
            'database_empty' => $databaseEmpty,
            'can_run' => $enabled && $databaseEmpty,
            'user_count' => $userCount,
            'tenant_count' => $tenantCount,
        ];
    }

    public function ensureCanRun(?string $providedToken, Request $request): void
    {
        if (! $this->isEnabled()) {
            $this->audit->record(
                action: 'bootstrap.rejected',
                user: null,
                oldValues: null,
                newValues: ['reason' => 'disabled', 'ip_address' => $request->ip()],
            );

            throw ValidationException::withMessages([
                'bootstrap' => 'El endpoint de bootstrap esta deshabilitado. Define APP_BOOTSTRAP_TOKEN en .env para habilitarlo.',
            ]);
        }

        $expected = $this->bootstrapToken();
        if ($expected === '') {
            throw new RuntimeException(self::ENV_BOOTSTRAP_TOKEN.' no esta definido en el entorno.');
        }

        if (! is_string($providedToken) || $providedToken === '' || ! hash_equals($expected, $providedToken)) {
            $this->audit->record(
                action: 'bootstrap.rejected',
                user: null,
                oldValues: null,
                newValues: ['reason' => 'invalid_token', 'ip_address' => $request->ip()],
            );

            throw ValidationException::withMessages([
                'bootstrap_token' => 'El token de bootstrap es invalido o no fue proporcionado. Pasa el valor de APP_BOOTSTRAP_TOKEN en el body (bootstrap_token) o en el header X-Bootstrap-Token.',
            ]);
        }

        $this->ensureDatabaseIsEmpty($request);
    }

    public function run(array $data, Request $request): array
    {
        $name = trim((string) $data['name']);
        $email = Str::lower(trim((string) $data['email']));
        $password = ! empty($data['password']) ? (string) $data['password'] : Str::random(32);
        $generatedPassword = empty($data['password']) ? $password : null;
        $tenantData = $data['tenant'] ?? null;

        $result = DB::transaction(function () use ($name, $email, $password, $tenantData, $request): array {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ]);

            $tenant = null;
            if (is_array($tenantData)) {
                $tenant = $this->createTenantWithAdminRole($user, $tenantData);
            }

            $plainToken = $this->issuePlatformToken($user, $request);

            return [
                'user' => $user,
                'tenant' => $tenant,
                'plain_token' => $plainToken,
            ];
        });

        $this->audit->record(
            action: 'bootstrap.completed',
            user: $result['user'],
            oldValues: null,
            newValues: [
                'email' => $result['user']->email,
                'tenant_id' => $result['tenant']?->id,
                'tenant_slug' => $result['tenant']?->slug,
                'created_tenant' => $result['tenant'] !== null,
                'ip_address' => $request->ip(),
            ],
        );

        return [
            'user' => $result['user'],
            'tenant' => $result['tenant'],
            'plain_token' => $result['plain_token'],
            'generated_password' => $generatedPassword,
        ];
    }

    private function ensureDatabaseIsEmpty(Request $request): void
    {
        $userCount = (int) User::query()->count();
        $tenantCount = (int) Tenant::query()->count();

        if ($userCount > 0 || $tenantCount > 0) {
            $this->audit->record(
                action: 'bootstrap.rejected',
                user: null,
                oldValues: null,
                newValues: [
                    'reason' => 'database_not_empty',
                    'user_count' => $userCount,
                    'tenant_count' => $tenantCount,
                    'ip_address' => $request->ip(),
                ],
            );

            throw ValidationException::withMessages([
                'bootstrap' => "La base de datos no esta vacia (usuarios: {$userCount}, empresas: {$tenantCount}). El bootstrap solo se permite en una instalacion nueva.",
            ]);
        }
    }

    private function createTenantWithAdminRole(User $adminUser, array $tenantData): Tenant
    {
        $tenant = Tenant::create([
            'name' => trim((string) $tenantData['name']),
            'slug' => trim((string) $tenantData['slug']),
            'domain' => ! empty($tenantData['domain']) ? trim((string) $tenantData['domain']) : null,
            'plan' => ! empty($tenantData['plan']) ? trim((string) $tenantData['plan']) : 'standard',
            'status' => 'active',
        ]);

        $tenant->users()->attach($adminUser->id, [
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedRolesAndPermissionsForTenant($tenant);
        $this->assignAdminRoleToUser($adminUser, $tenant);

        app(TenantManager::class)->set($tenant);

        return $tenant;
    }

    private function seedRolesAndPermissionsForTenant(Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        $this->ensurePermissionsExist();
        $this->syncRolesForTeam($tenant->id);
    }

    private function ensurePermissionsExist(): void
    {
        foreach (BasePermissions::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function syncRolesForTeam(int $teamId): void
    {
        $permissions = Permission::query()
            ->whereIn('name', BasePermissions::PERMISSIONS)
            ->where('guard_name', 'web')
            ->get();

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($teamId);
        }

        foreach (BasePermissions::ROLE_PERMISSIONS as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');

            $rolePermissionIds = $permissions
                ->whereIn('name', $rolePermissions)
                ->pluck('id')
                ->all();

            $role->syncPermissions($rolePermissionIds);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assignAdminRoleToUser(User $user, Tenant $tenant): void
    {
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user->assignRole('Administrador');
    }

    private function issuePlatformToken(User $user, Request $request): string
    {
        $plainToken = Str::random(80);

        AuthToken::create([
            'tenant_id' => null,
            'user_id' => $user->id,
            'name' => $request->input('device_name', 'bootstrap'),
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['platform'],
            'expires_at' => now()->addDays(30),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $plainToken;
    }

    private function bootstrapToken(): string
    {
        $value = getenv(self::ENV_BOOTSTRAP_TOKEN);

        if ($value === false) {
            $value = $_ENV[self::ENV_BOOTSTRAP_TOKEN]
                ?? $_SERVER[self::ENV_BOOTSTRAP_TOKEN]
                ?? env(self::ENV_BOOTSTRAP_TOKEN, '');
        }

        return trim((string) $value);
    }
}
