<?php

namespace App\Modules\AccessControl\Controllers;

use App\Modules\AccessControl\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PermissionCatalogController extends Controller
{
    public function __invoke(Request $request, AccessControlService $service): JsonResponse
    {
        abort_unless($request->user()?->can('roles.view') || $request->user()?->can('users.view'), Response::HTTP_FORBIDDEN);

        return response()->json([
            'data' => $service->groupedPermissions(),
        ]);
    }
}
