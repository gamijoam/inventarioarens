<?php

namespace App\Modules\AccessControl\Controllers;

use App\Modules\AccessControl\Services\PermissionCatalogService;
use App\Modules\AccessControl\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PermissionCatalogController extends Controller
{
    public function __construct(
        private readonly PermissionCatalogService $catalog,
        private readonly AccessControlService $access,
    ) {
    }

    /**
     * Catalogo plano agrupado por modulo (legacy).
     * GET /api/access/permissions
     */
    public function __invoke(Request $request, AccessControlService $service): JsonResponse
    {
        abort_unless(
            $request->user()?->can('roles.view') || $request->user()?->can('users.view'),
            Response::HTTP_FORBIDDEN,
        );

        return response()->json([
            'data' => $service->groupedPermissions(),
        ]);
    }

    /**
     * Catalogo en formato ARBOL jerarquico navegable para la UI.
     * GET /api/access/permission-catalog
     */
    public function catalog(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can('roles.view') || $request->user()?->can('users.view'),
            Response::HTTP_FORBIDDEN,
        );

        return response()->json([
            'data' => $this->catalog->tree(),
        ]);
    }
}