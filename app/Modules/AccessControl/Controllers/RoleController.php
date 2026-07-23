<?php

namespace App\Modules\AccessControl\Controllers;

use App\Modules\AccessControl\Requests\StoreRoleRequest;
use App\Modules\AccessControl\Requests\UpdateRolePermissionsRequest;
use App\Modules\AccessControl\Requests\UpdateRoleRequest;
use App\Modules\AccessControl\Resources\RoleResource;
use App\Modules\AccessControl\Services\AccessControlService;
use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly AccessControlService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'roles.view');

        $tenant = $this->resolveTargetTenant($request);

        return RoleResource::collection($this->service->roles($tenant)->paginate(25));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'roles.create');

        return RoleResource::make(
            $this->service->createRole($request->validated(), $request->user())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, int $role): RoleResource
    {
        $this->authorizePermission($request, 'roles.view');

        return RoleResource::make($this->service->role($role));
    }

    public function update(UpdateRoleRequest $request, int $role): RoleResource
    {
        $this->authorizePermission($request, 'roles.update');

        return RoleResource::make(
            $this->service->updateRole($this->service->role($role), $request->validated(), $request->user())
        );
    }

    public function permissions(UpdateRolePermissionsRequest $request, int $role): RoleResource
    {
        $this->authorizePermission($request, 'roles.update');

        return RoleResource::make(
            $this->service->updateRolePermissions($this->service->role($role), $request->validated('permissions'), $request->user())
        );
    }

    public function destroy(Request $request, int $role): Response
    {
        $this->authorizePermission($request, 'roles.delete');
        $this->service->deleteRole($this->service->role($role), $request->user());

        return response()->noContent();
    }

    /**
     * Clona un rol existente (base o custom) en uno nuevo dentro del mismo tenant.
     * POST /api/access/roles/{role}/duplicate
     */
    public function duplicate(Request $request, Role $role): JsonResponse
    {
        $this->authorizePermission($request, 'roles.create');

        $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $name = trim((string) $request->input('name'));

        if (Role::query()->where('name', $name)->where($this->teamColumn(), $this->currentTenantId())->exists()) {
            throw ValidationException::withMessages([
                'name' => "Ya existe un rol con el nombre '{$name}' en esta empresa.",
            ]);
        }

        $newRole = $this->service->duplicateRole($role, $name, $request->user());

        return RoleResource::make($newRole)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Devuelve metadata de capacidades del rol para preview.
     * GET /api/access/roles/{role}/preview
     */
    public function preview(Request $request, Role $role): JsonResponse
    {
        $this->authorizePermission($request, 'roles.view');

        $permissions = $role->permissions->pluck('name')->all();
        $modules = [];
        foreach ($permissions as $permission) {
            $module = explode('.', $permission)[0] ?? 'other';
            $modules[$module] = true;
        }

        return response()->json([
            'data' => [
                'role_id' => $role->id,
                'name' => $role->name,
                'permission_count' => count($permissions),
                'module_count' => count($modules),
                'modules' => array_keys($modules),
                'wildcards_count' => 0,
                'protected' => in_array($role->name, \App\Modules\AccessControl\Services\AccessControlService::PROTECTED_ROLES, true),
            ],
        ]);
    }

    private function teamColumn(): string
    {
        return config('permission.column_names.team_foreign_key', 'team_id');
    }

    private function currentTenantId(): int
    {
        return (int) app(\App\Support\Tenancy\TenantManager::class)->require()->id;
    }

    private function resolveTargetTenant(Request $request): ?Tenant
    {
        $tenantId = $request->integer('tenant_id');
        if (! $tenantId) {
            return null;
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_FORBIDDEN);

        if ($tenant->isGroup()) {
            abort_unless($actor->isOwnerOf($tenant), Response::HTTP_FORBIDDEN);

            return $tenant;
        }

        $parent = $tenant->parent()->first();
        abort_unless(
            $actor->belongsToTenant($tenant) || ($parent !== null && $actor->isOwnerOf($parent)),
            Response::HTTP_FORBIDDEN,
        );

        return $tenant;
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}
