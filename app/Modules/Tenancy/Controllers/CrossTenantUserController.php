<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Resources\TenantUserResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\AttachUserToTenantRequest;
use App\Modules\Tenancy\Services\CrossTenantUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CrossTenantUserController extends Controller
{
    public function __construct(private readonly CrossTenantUserService $service) {}

    /**
     * Lista usuarios de un tenant.
     *
     * Query params:
     *   - scope=tenant        (default): solo el tenant especifico.
     *   - scope=organization  : si el tenant es un grupo o tiene parent, retorna
     *                           usuarios del grupo + todos sus spinoffs.
     *                           Solo valido para usuarios con rol "Owner" del
     *                           grupo raiz (o de cualquier spinoff).
     */
    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'tenants.view');

        $scope = $request->query('scope', 'tenant');
        if (! in_array($scope, ['tenant', 'organization'], true)) {
            $scope = 'tenant';
        }

        if ($scope === 'organization') {
            $user = $request->user();
            $root = $tenant->isGroup() ? $tenant : $tenant->parent;
            abort_unless(
                $root !== null && $user?->isOwnerOf($root),
                Response::HTTP_FORBIDDEN,
                'Solo los Owners del grupo pueden ver usuarios de toda la organizacion.',
            );
        }

        return TenantUserResource::collection($this->service->listUsers($tenant, $scope));
    }

    public function store(AttachUserToTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorizePermission($request, 'tenants.users.attach');

        $user = $this->service->attachUser($tenant, $request->validated(), $request->user());

        return TenantUserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Tenant $tenant, User $user): Response
    {
        $this->authorizePermission($request, 'tenants.users.detach');

        $this->service->detachUser($tenant, $user, $request->user());

        return response()->noContent();
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}
