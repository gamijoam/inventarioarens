<?php

namespace App\Modules\AccessControl\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Requests\StoreTenantUserRequest;
use App\Modules\AccessControl\Requests\UpdateTenantUserRequest;
use App\Modules\AccessControl\Requests\UpdateTenantUserRolesRequest;
use App\Modules\AccessControl\Requests\UpdateTenantUserStatusRequest;
use App\Modules\AccessControl\Resources\TenantUserResource;
use App\Modules\AccessControl\Services\AccessControlService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class TenantUserController extends Controller
{
    public function __construct(private readonly AccessControlService $service) {}

    /**
     * Lista usuarios del tenant actual.
     *
     * Query params:
     *   - scope=tenant        (default): solo usuarios del tenant actual.
     *   - scope=organization  : si el tenant es un grupo o spinoff, retorna
     *                           usuarios del grupo + todos sus spinoffs.
     *                           Solo valido para Owners del grupo.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'users.view');

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $scope = $request->string('scope')->toString() === 'organization'
            ? 'organization'
            : 'tenant';

        $users = $scope === 'organization'
            ? $this->service->organizationUsers($request, $perPage)
            : $this->service->tenantUsers($request)->paginate($perPage);

        return TenantUserResource::collection($users);
    }

    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'users.create');

        return TenantUserResource::make(
            $this->service->createOrAttachUser($request->validated(), $request->user())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.view');

        $scope = $request->string('scope')->toString() === 'organization'
            ? 'organization'
            : 'tenant';

        $user = $scope === 'organization'
            ? $this->service->organizationUser($tenantUser, $request)
            : $this->service->tenantUser($tenantUser);

        return TenantUserResource::make($user);
    }

    public function update(UpdateTenantUserRequest $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.update');

        return TenantUserResource::make(
            $this->service->updateUser($this->service->tenantUser($tenantUser), $request->validated(), $request->user())
        );
    }

    public function status(UpdateTenantUserStatusRequest $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.update');

        return TenantUserResource::make(
            $this->service->updateStatus(
                $this->service->tenantUser($tenantUser),
                $request->validated('status'),
                $request->user()
            )
        );
    }

    public function roles(UpdateTenantUserRolesRequest $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.update');

        $tenant = $this->resolveTargetTenant($request->user(), $request->validated('tenant_id'));
        $user = $tenant
            ? $this->service->tenantUserIn($tenant, $tenantUser)
            : $this->service->tenantUser($tenantUser);

        return TenantUserResource::make(
            $this->service->updateUserRoles($user, $request->validated('roles'), $request->user(), $tenant)
        );
    }

    public function permissions(Request $request, int $tenantUser): JsonResponse
    {
        $this->authorizePermission($request, 'users.view');
        $user = $this->service->tenantUser($tenantUser);

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'permissions' => $this->service->userPermissions($user),
            ],
        ]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }

    private function resolveTargetTenant(?User $actor, ?int $tenantId): ?Tenant
    {
        if ($tenantId === null) {
            return null;
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        abort_unless($actor instanceof User, Response::HTTP_FORBIDDEN);

        $allowedTenantIds = $this->service->organizationTenantIdsForCurrentTenant($actor);

        abort_unless(in_array($tenant->id, $allowedTenantIds, true), Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
