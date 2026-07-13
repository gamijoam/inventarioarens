<?php

namespace App\Modules\AccessControl\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Models\UserBranchScope;
use App\Modules\AccessControl\Models\UserCustomerGroupScope;
use App\Modules\AccessControl\Models\UserVendorAssignment;
use App\Modules\AccessControl\Models\UserWarehouseScope;
use App\Modules\AccessControl\Requests\ReplaceUserScopeRequest;
use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class UserScopeController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        abort_unless($request->user()?->can('users.view'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);

        $branchIds = $this->resolver->branchIdsFor($user) ?? [];
        $warehouseIds = $this->resolver->warehouseIdsFor($user) ?? [];
        $groupIds = $this->resolver->customerGroupIdsFor($user) ?? [];
        $vendorIds = $this->resolver->vendorOfGroupIdsFor($user) ?? [];

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'branches' => $branchIds,
                'warehouses' => $warehouseIds,
                'customer_groups' => $groupIds,
                'vendor_of' => $vendorIds,
                'counts' => [
                    'branches' => count($branchIds),
                    'warehouses' => count($warehouseIds),
                    'customer_groups' => count($groupIds),
                    'vendor_of' => count($vendorIds),
                ],
                'expanded' => [
                    'branches' => Branch::query()->whereIn('id', $branchIds)->get(['id', 'code', 'name'])->toArray(),
                    'warehouses' => Warehouse::query()->whereIn('id', $warehouseIds)->get(['id', 'code', 'name'])->toArray(),
                    'customer_groups' => CustomerGroup::query()->whereIn('id', $groupIds)->get(['id', 'code', 'name'])->toArray(),
                    'vendor_of' => CustomerGroup::query()->whereIn('id', $vendorIds)->get(['id', 'code', 'name'])->toArray(),
                ],
            ],
        ]);
    }

    public function replaceAll(ReplaceUserScopeRequest $request, Tenant $tenant, User $user): JsonResponse
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);

        $data = $request->validated();

        $this->resolver->replaceScope($user, UserBranchScope::class, 'branch_id', $data['branch_ids'] ?? [], $request->user());
        $this->resolver->replaceScope($user, UserWarehouseScope::class, 'warehouse_id', $data['warehouse_ids'] ?? [], $request->user());
        $this->resolver->replaceScope($user, UserCustomerGroupScope::class, 'customer_group_id', $data['customer_group_ids'] ?? [], $request->user());
        $this->resolver->replaceScope($user, UserVendorAssignment::class, 'customer_group_id', $data['customer_group_ids'] ?? [], $request->user());

        $this->audit->record('access.user.scopes_replaced', $user, $request->user(), null, [
            'branches' => count($data['branch_ids'] ?? []),
            'warehouses' => count($data['warehouse_ids'] ?? []),
            'customer_groups' => count($data['customer_group_ids'] ?? []),
        ]);

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'branches' => $data['branch_ids'] ?? [],
                'warehouses' => $data['warehouse_ids'] ?? [],
                'customer_groups' => $data['customer_group_ids'] ?? [],
                'vendor_of' => $data['customer_group_ids'] ?? [],
            ],
        ]);
    }

    public function replaceBranches(ReplaceUserScopeRequest $request, Tenant $tenant, User $user): Response
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);
        $this->resolver->replaceScope($user, UserBranchScope::class, 'branch_id', $request->validated('branch_ids') ?? [], $request->user());
        $this->audit->record('access.user.scope_assigned', $user, $request->user(), null, [
            'scope_type' => 'branches',
            'count' => count($request->validated('branch_ids') ?? []),
        ]);
        return response()->noContent();
    }

    public function replaceWarehouses(ReplaceUserScopeRequest $request, Tenant $tenant, User $user): Response
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);
        $this->resolver->replaceScope($user, UserWarehouseScope::class, 'warehouse_id', $request->validated('warehouse_ids') ?? [], $request->user());
        $this->audit->record('access.user.scope_assigned', $user, $request->user(), null, [
            'scope_type' => 'warehouses',
            'count' => count($request->validated('warehouse_ids') ?? []),
        ]);
        return response()->noContent();
    }

    public function replaceCustomerGroups(ReplaceUserScopeRequest $request, Tenant $tenant, User $user): Response
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);
        $this->resolver->replaceScope($user, UserCustomerGroupScope::class, 'customer_group_id', $request->validated('customer_group_ids') ?? [], $request->user());
        $this->audit->record('access.user.scope_assigned', $user, $request->user(), null, [
            'scope_type' => 'customer_groups',
            'count' => count($request->validated('customer_group_ids') ?? []),
        ]);
        return response()->noContent();
    }

    public function replaceVendorOf(ReplaceUserScopeRequest $request, Tenant $tenant, User $user): Response
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);
        $this->resolver->replaceScope($user, UserVendorAssignment::class, 'customer_group_id', $request->validated('customer_group_ids') ?? [], $request->user());
        $this->audit->record('access.user.scope_assigned', $user, $request->user(), null, [
            'scope_type' => 'vendor_of',
            'count' => count($request->validated('customer_group_ids') ?? []),
        ]);
        return response()->noContent();
    }

    private function ensureTenantContext(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}