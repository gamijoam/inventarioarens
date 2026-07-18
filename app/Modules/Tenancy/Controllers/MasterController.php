<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\StoreGroupRequest;
use App\Modules\Tenancy\Requests\StoreSpinoffRequest;
use App\Modules\Tenancy\Requests\UpdateGroupRequest;
use App\Modules\Tenancy\Requests\UpdateSpinoffRequest;
use App\Modules\Tenancy\Resources\GroupResource;
use App\Modules\Tenancy\Resources\SpinoffResource;
use App\Modules\Tenancy\Services\TenantGroupService;
use App\Modules\Tenancy\Services\TenantSpinoffService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Spatie\Permission\PermissionRegistrar;

class MasterController extends Controller
{
    public function __construct(
        private readonly TenantGroupService $groupService,
        private readonly TenantSpinoffService $spinoffService,
        private readonly AuditLogger $audit,
    ) {}

    public function listGroups(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        return GroupResource::collection($this->groupService->listGroups());
    }

    public function showGroup(Request $request, Tenant $group): GroupResource
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($group->isGroup(), Response::HTTP_NOT_FOUND, 'Tenant is not a group root.');
        $group->loadCount(['children', 'users']);

        return GroupResource::make($group);
    }

    public function storeGroup(StoreGroupRequest $request): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        $tenant = $this->groupService->createGroup($request->validated(), $request->user());
        $tenant->loadCount(['children', 'users']);

        return GroupResource::make($tenant)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateGroup(UpdateGroupRequest $request, Tenant $group): GroupResource
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($group->isGroup(), Response::HTTP_NOT_FOUND, 'Tenant is not a group root.');

        $oldValues = [
            'name' => $group->name,
            'slug' => $group->slug,
            'domain' => $group->domain,
            'status' => $group->status,
            'plan' => $group->plan,
        ];

        $data = $request->validated();
        if (isset($data['plan']) && empty($data['plan'])) {
            $data['plan'] = null;
        }
        $group->fill(collect($data)->only(['name', 'slug', 'domain', 'status', 'plan'])->all());
        $group->save();

        $this->auditWithGroupContext($group, function () use ($group, $oldValues, $request): void {
            $this->audit->record('tenant_group.updated', $group, $request->user(), $oldValues, [
                'name' => $group->name,
                'slug' => $group->slug,
                'domain' => $group->domain,
                'status' => $group->status,
                'plan' => $group->plan,
            ]);
        });

        $group->loadCount(['children', 'users']);

        return GroupResource::make($group);
    }

    public function destroyGroup(Request $request, Tenant $group): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($group->isGroup(), Response::HTTP_NOT_FOUND, 'Tenant is not a group root.');

        $this->auditWithGroupContext($group, function () use ($group, $request): void {
            $group->update(['status' => 'inactive']);
            $this->audit->record('tenant_group.deactivated', $group, $request->user(), [
                'status' => 'active',
            ], [
                'status' => 'inactive',
            ]);
        });

        return response()->noContent();
    }

    public function listGroupSpinoffs(Request $request, Tenant $group): AnonymousResourceCollection
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($group->isGroup(), Response::HTTP_NOT_FOUND, 'Tenant is not a group root.');

        return SpinoffResource::collection($this->spinoffService->listSpinoffs($group));
    }

    public function createGroupSpinoff(StoreSpinoffRequest $request, Tenant $group): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($group->isGroup(), Response::HTTP_NOT_FOUND, 'Tenant is not a group root.');

        $actor = $request->user();
        $tenant = $this->auditWithGroupContext($group, function () use ($group, $request): Tenant {
            return $this->spinoffService->createSpinoff($group, $request->validated(), $request->user());
        });

        $this->audit->record(
            action: 'tenant_group.spinoff_created_by_platform_admin',
            entity: $tenant,
            user: $actor,
            newValues: [
                'group_slug' => $group->slug,
                'group_id' => $group->id,
                'spinoff_slug' => $tenant->slug,
                'spinoff_id' => $tenant->id,
                'actor_is_platform_admin' => true,
            ],
        );

        $tenant->loadCount('users');

        return SpinoffResource::make($tenant)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function showSpinoff(Request $request, Tenant $tenant): SpinoffResource
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($tenant->isSpinoff(), Response::HTTP_NOT_FOUND, 'Tenant is not a spinoff.');
        $tenant->loadCount('users');

        return SpinoffResource::make($tenant);
    }

    public function updateSpinoff(UpdateSpinoffRequest $request, Tenant $tenant): SpinoffResource
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($tenant->isSpinoff(), Response::HTTP_NOT_FOUND, 'Tenant is not a spinoff.');

        $oldValues = [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'parent_id' => $tenant->parent_id,
        ];

        $data = $request->validated();
        if (isset($data['plan']) && empty($data['plan'])) {
            $data['plan'] = null;
        }

        $tenant->fill(collect($data)->only(['name', 'slug', 'domain', 'status', 'plan', 'parent_id'])->all());
        $tenant->save();

        $this->audit->record('tenant_spinoff.updated_by_platform_admin', $tenant, $request->user(), $oldValues, [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'parent_id' => $tenant->parent_id,
        ]);

        $tenant->loadCount('users');

        return SpinoffResource::make($tenant);
    }

    public function destroySpinoff(Request $request, Tenant $tenant): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($tenant->isSpinoff(), Response::HTTP_NOT_FOUND, 'Tenant is not a spinoff.');

        $oldValues = [
            'status' => $tenant->status,
            'parent_id' => $tenant->parent_id,
        ];

        $tenant->update(['status' => 'inactive']);

        $this->audit->record('tenant_spinoff.deactivated_by_platform_admin', $tenant, $request->user(), $oldValues, [
            'status' => 'inactive',
            'parent_id' => $tenant->parent_id,
        ]);

        return response()->noContent();
    }

    public function stats(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        $totals = [
            'platform_admins' => User::query()->where('is_platform_admin', true)->count(),
            'total_tenants' => Tenant::query()->count(),
            'total_groups' => Tenant::query()->groups()->count(),
            'total_spinoffs' => Tenant::query()->spinoffs()->count(),
            'active_tenants' => Tenant::query()->where('status', 'active')->count(),
            'inactive_tenants' => Tenant::query()->where('status', 'inactive')->count(),
        ];

        $byPlan = Tenant::query()
            ->groups()
            ->selectRaw('plan, COUNT(*) AS total')
            ->groupBy('plan')
            ->pluck('total', 'plan')
            ->all();

        return response()->json(['data' => [
            'totals' => $totals,
            'groups_by_plan' => $byPlan,
        ]]);
    }

    private function auditWithGroupContext(Tenant $group, \Closure $callback): mixed
    {
        $manager = app(TenantManager::class);
        $previous = $manager->current();

        $manager->set($group);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($group->id);
        }
        PermissionRegistrar::class;
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            return $callback();
        } finally {
            if ($previous) {
                $manager->set($previous);
                if (function_exists('setPermissionsTeamId')) {
                    setPermissionsTeamId($previous->id);
                }
            } else {
                $manager->clear();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
