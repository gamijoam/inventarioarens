<?php

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Tenancy\Requests\StoreGroupRequest;
use App\Modules\Tenancy\Resources\GroupResource;
use App\Modules\Tenancy\Services\TenantGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class MasterController extends Controller
{
    public function __construct(private readonly TenantGroupService $service)
    {
    }

    public function listGroups(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        return GroupResource::collection($this->service->listGroups());
    }

    public function storeGroup(StoreGroupRequest $request): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        $tenant = $this->service->createGroup($request->validated(), $request->user());
        $tenant->loadCount(['children', 'users']);

        return GroupResource::make($tenant)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}