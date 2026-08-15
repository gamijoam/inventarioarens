<?php

namespace App\Modules\Sync\Services;

use App\Models\User;
use App\Modules\Sync\Models\SyncPairingCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncPairingService
{
    public function __construct(private readonly SyncTokenService $tokens) {}

    public function create(Tenant $currentTenant, User $actor, array $data): array
    {
        $group = $currentTenant->isGroup()
            ? $currentTenant
            : Tenant::query()->find($currentTenant->parent_id);

        abort_unless($group && $actor->isOwnerOf($group), 403, 'Solo el Owner puede crear codigos de vinculacion.');

        $target = Tenant::query()->findOrFail((int) $data['target_tenant_id']);
        abort_unless(
            $target->id === $group->id || $target->parent_id === $group->id,
            403,
            'La empresa seleccionada no pertenece al grupo actual.',
        );

        $email = Str::lower(trim((string) $data['user_email']));
        $user = User::query()->where('email', $email)->first();
        if (! $user || ! $user->belongsToTenant($target)) {
            throw ValidationException::withMessages([
                'user_email' => 'El usuario no pertenece a la empresa seleccionada.',
            ]);
        }

        $plainCode = 'ARNS-'.Str::upper(Str::random(35));
        $pairing = SyncPairingCode::create([
            'target_tenant_id' => $target->id,
            'created_by_user_id' => $actor->id,
            'target_user_id' => $user->id,
            'code_hash' => hash('sha256', $plainCode),
            'node_name' => $data['node_name'],
            'expires_at' => now()->addMinutes((int) ($data['expires_in_minutes'] ?? 15)),
        ]);

        return [
            'code' => $plainCode,
            'expires_at' => $pairing->expires_at->toISOString(),
            'tenant' => [
                'id' => $target->id,
                'name' => $target->name,
                'slug' => $target->slug,
            ],
            'node_name' => $pairing->node_name,
        ];
    }

    public function createGroup(Tenant $currentTenant, User $actor, array $data): array
    {
        $group = $currentTenant->isGroup()
            ? $currentTenant
            : Tenant::query()->find($currentTenant->parent_id);

        abort_unless($group && $actor->isOwnerOf($group), 403, 'Solo el Owner puede crear codigos de vinculacion.');

        $email = Str::lower(trim((string) $data['user_email']));
        $user = User::query()->where('email', $email)->first();
        abort_unless($user, 422, 'El usuario autorizado no existe.');

        $tenants = collect([$group])
            ->merge($group->children()->where('is_group', false)->orderBy('id')->get())
            ->values();

        foreach ($tenants as $tenant) {
            if (! $user->belongsToTenant($tenant)) {
                throw ValidationException::withMessages([
                    'user_email' => 'El usuario autorizado debe pertenecer a todas las empresas del grupo.',
                ]);
            }
        }

        $plainCode = 'ARNS-'.Str::upper(Str::random(35));
        $pairing = SyncPairingCode::create([
            'target_tenant_id' => $group->id,
            'created_by_user_id' => $actor->id,
            'target_user_id' => $user->id,
            'is_group_bundle' => true,
            'code_hash' => hash('sha256', $plainCode),
            'node_name' => $data['node_name'],
            'expires_at' => now()->addMinutes((int) ($data['expires_in_minutes'] ?? 15)),
        ]);

        return [
            'code' => $plainCode,
            'expires_at' => $pairing->expires_at->toISOString(),
            'group' => $this->tenantSummary($group),
            'tenants' => $tenants->map(fn (Tenant $tenant): array => $this->tenantSummary($tenant))->all(),
            'node_name' => $pairing->node_name,
        ];
    }

    public function redeem(array $data, string $ipAddress, ?string $userAgent): array
    {
        return DB::transaction(function () use ($data, $ipAddress, $userAgent): array {
            $pairing = SyncPairingCode::query()
                ->with(['targetTenant', 'targetUser'])
                ->where('code_hash', hash('sha256', $data['code']))
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pairing) {
                throw ValidationException::withMessages([
                    'code' => 'El codigo es invalido, ya fue utilizado o expiro.',
                ]);
            }

            if ($pairing->is_group_bundle) {
                $group = $pairing->targetTenant;
                $tenants = $this->groupTenants($group);
                $selectedTenantIds = array_values(array_map(
                    'intval',
                    (array) ($data['selected_tenant_ids'] ?? []),
                ));

                if ($selectedTenantIds !== []) {
                    $allowedTenantIds = $tenants->pluck('id')->all();
                    if (array_diff($selectedTenantIds, $allowedTenantIds) !== []) {
                        throw ValidationException::withMessages([
                            'selected_tenant_ids' => 'Solo puedes seleccionar empresas del grupo autorizado.',
                        ]);
                    }

                    $tenants = $tenants
                        ->filter(fn (Tenant $tenant): bool => in_array($tenant->id, $selectedTenantIds, true))
                        ->values();
                }

                $tokens = $tenants->map(fn (Tenant $tenant): array => [
                    'tenant' => $this->tenantSummary($tenant),
                    'token' => $this->tokens->issue(
                        tenant: $tenant,
                        user: $pairing->targetUser,
                        name: $data['node_name'] ?? $pairing->node_name,
                        days: 365,
                        ipAddress: $ipAddress,
                        userAgent: $userAgent,
                    ),
                ])->all();
            } else {
                $token = $this->tokens->issue(
                    tenant: $pairing->targetTenant,
                    user: $pairing->targetUser,
                    name: $data['node_name'] ?? $pairing->node_name,
                    days: 365,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            $pairing->forceFill([
                'redeemed_at' => now(),
                'redeemed_node_code' => $data['node_code'],
            ])->save();

            if ($pairing->is_group_bundle) {
                return [
                    'group' => $this->tenantSummary($pairing->targetTenant),
                    'tenants' => $tokens,
                    'bootstrap_required' => true,
                ];
            }

            return [
                'tenant' => $this->tenantSummary($pairing->targetTenant),
                'token' => $token,
                'bootstrap_required' => true,
            ];
        });
    }

    public function preview(string $plainCode): array
    {
        $pairing = SyncPairingCode::query()
            ->with('targetTenant')
            ->where('code_hash', hash('sha256', $plainCode))
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $pairing) {
            throw ValidationException::withMessages([
                'code' => 'El codigo es invalido, ya fue utilizado o expiro.',
            ]);
        }

        if (! $pairing->is_group_bundle) {
            return [
                'tenant' => $this->tenantSummary($pairing->targetTenant),
                'tenants' => [$this->tenantSummary($pairing->targetTenant)],
            ];
        }

        $group = $pairing->targetTenant;

        return [
            'group' => $this->tenantSummary($group),
            'tenants' => $this->groupTenants($group)
                ->map(fn (Tenant $tenant): array => $this->tenantSummary($tenant))
                ->all(),
        ];
    }

    private function tenantSummary(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'parent_id' => $tenant->parent_id,
            'is_group' => $tenant->isGroup(),
        ];
    }

    private function groupTenants(Tenant $group)
    {
        return collect([$group])
            ->merge($group->children()->where('is_group', false)->orderBy('id')->get())
            ->values();
    }
}
