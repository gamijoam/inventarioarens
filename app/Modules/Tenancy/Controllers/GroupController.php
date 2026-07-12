<?php

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\StoreSpinoffRequest;
use App\Modules\Tenancy\Resources\SpinoffResource;
use App\Modules\Tenancy\Services\TenantSpinoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class GroupController extends Controller
{
    public function __construct(private readonly TenantSpinoffService $service)
    {
    }

    public function listSpinoffs(Request $request, Tenant $group): AnonymousResourceCollection
    {
        abort_unless($group->isOwnedBy($request->user()), Response::HTTP_FORBIDDEN);

        return SpinoffResource::collection($this->service->listSpinoffs($group));
    }

    public function storeSpinoff(StoreSpinoffRequest $request, Tenant $group): JsonResponse
    {
        abort_unless($group->isOwnedBy($request->user()), Response::HTTP_FORBIDDEN);

        $tenant = $this->service->createSpinoff($group, $request->validated(), $request->user());
        $tenant->loadCount('users');

        return SpinoffResource::make($tenant)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}