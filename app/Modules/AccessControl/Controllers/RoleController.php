<?php

namespace App\Modules\AccessControl\Controllers;

use App\Modules\AccessControl\Requests\StoreRoleRequest;
use App\Modules\AccessControl\Requests\UpdateRolePermissionsRequest;
use App\Modules\AccessControl\Requests\UpdateRoleRequest;
use App\Modules\AccessControl\Resources\RoleResource;
use App\Modules\AccessControl\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class RoleController extends Controller
{
    public function __construct(private readonly AccessControlService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'roles.view');

        return RoleResource::collection($this->service->roles()->paginate(25));
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

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}
