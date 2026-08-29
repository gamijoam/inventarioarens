<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branches\Models\Branch;
use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CrmApiTokenService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CrmScopeService $scope,
    ) {}

    public function issue(array $data, Tenant $tenant, User $actor, Request $request): array
    {
        $branchIds = $this->normalizeIds($data['branch_ids'] ?? null);
        $warehouseIds = $this->normalizeIds($data['warehouse_ids'] ?? null);
        $tenantScope = $data['tenant_scope'] ?? CrmApiToken::TENANT_SCOPE_TENANT;

        if ($tenantScope === CrmApiToken::TENANT_SCOPE_SUBTREE
            && (! $tenant->isGroup() || ! $actor->isStrictOwnerOf($tenant))) {
            throw new AccessDeniedHttpException('Solo el Owner estricto del grupo puede emitir un token subtree.');
        }

        $this->validateLocationRelationship($tenant, $tenantScope, $branchIds, $warehouseIds);

        $plainToken = $this->newPlainToken();
        $token = CrmApiToken::create([
            'tenant_id' => $tenant->id,
            'tenant_scope' => $tenantScope,
            'created_by' => $actor->id,
            'name' => $data['name'],
            'token_prefix' => substr($plainToken, 0, 16),
            'token_hash' => hash('sha256', $plainToken),
            'scopes' => array_values($data['scopes']),
            'branch_ids' => $branchIds,
            'warehouse_ids' => $warehouseIds,
            'expires_at' => Carbon::parse($data['expires_at']),
        ]);

        $this->audit->record(
            action: 'crm.token.issued',
            entity: $token,
            user: $actor,
            newValues: [
                'token_id' => $token->id,
                'token_prefix' => $token->token_prefix,
                'name' => $token->name,
                'tenant_scope' => $token->tenant_scope,
                'scopes' => $token->scopes,
                'branch_ids' => $token->branch_ids,
                'warehouse_ids' => $token->warehouse_ids,
                'expires_at' => $token->expires_at?->toISOString(),
                'ip_address' => $request->ip(),
            ],
        );

        return ['token' => $token, 'plain_token' => $plainToken];
    }

    public function rotate(CrmApiToken $token, User $actor, Request $request): array
    {
        $plainToken = $this->newPlainToken();
        $oldPrefix = $token->token_prefix;

        DB::transaction(function () use ($token, $plainToken): void {
            $token->forceFill([
                'token_prefix' => substr($plainToken, 0, 16),
                'token_hash' => hash('sha256', $plainToken),
                'last_used_at' => null,
                'revoked_at' => null,
                'expires_at' => $token->expires_at?->isFuture()
                    ? $token->expires_at
                    : now()->addYear(),
            ])->save();
        });

        $this->audit->record(
            action: 'crm.token.rotated',
            entity: $token,
            user: $actor,
            newValues: [
                'token_id' => $token->id,
                'old_token_prefix' => $oldPrefix,
                'token_prefix' => $token->token_prefix,
                'ip_address' => $request->ip(),
            ],
        );

        return ['token' => $token->refresh(), 'plain_token' => $plainToken];
    }

    public function revoke(CrmApiToken $token, User $actor, Request $request): void
    {
        if ($token->revoked_at !== null) {
            return;
        }

        $token->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(
            action: 'crm.token.revoked',
            entity: $token,
            user: $actor,
            newValues: [
                'token_id' => $token->id,
                'token_prefix' => $token->token_prefix,
                'ip_address' => $request->ip(),
            ],
        );
    }

    private function validateLocationRelationship(
        Tenant $tenant,
        string $tenantScope,
        ?array $branchIds,
        ?array $warehouseIds,
    ): void {
        $tenantIds = $this->scope->tenantIdsFor($tenant, $tenantScope);

        if ($branchIds !== null) {
            $count = Branch::withoutGlobalScopes()
                ->whereIn('tenant_id', $tenantIds)
                ->whereIn('id', $branchIds)
                ->count();

            if ($count !== count($branchIds)) {
                throw ValidationException::withMessages([
                    'branch_ids' => 'Una o más sucursales no pertenecen a la empresa actual.',
                ]);
            }
        }

        if ($warehouseIds === null) {
            return;
        }

        $warehouses = Warehouse::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('id', $warehouseIds)
            ->get(['id', 'branch_id']);

        if ($warehouses->count() !== count($warehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Uno o más almacenes no pertenecen a la empresa actual.',
            ]);
        }

        if ($branchIds !== null && $warehouses->whereNotIn('branch_id', $branchIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Cada almacén debe pertenecer a una sucursal autorizada.',
            ]);
        }
    }

    private function normalizeIds(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function newPlainToken(): string
    {
        return 'crm_'.Str::random(80);
    }
}
