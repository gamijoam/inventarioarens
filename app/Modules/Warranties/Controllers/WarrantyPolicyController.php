<?php

namespace App\Modules\Warranties\Controllers;

use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Modules\Warranties\Requests\StoreWarrantyPolicyRequest;
use App\Modules\Warranties\Requests\UpdateWarrantyPolicyRequest;
use App\Modules\Warranties\Resources\WarrantyPolicyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WarrantyPolicyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'warranty_policies.view');

        return WarrantyPolicyResource::collection(
            WarrantyPolicy::query()
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(StoreWarrantyPolicyRequest $request, SyncCatalogOutboxService $syncCatalog): JsonResponse
    {
        $this->authorizePermission($request, 'warranty_policies.manage');

        $policy = WarrantyPolicy::create($request->validated())->refresh();
        $syncCatalog->warrantyPolicyCreated($policy);

        return WarrantyPolicyResource::make($policy)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, WarrantyPolicy $warrantyPolicy): WarrantyPolicyResource
    {
        $this->authorizePermission($request, 'warranty_policies.view');

        return WarrantyPolicyResource::make($warrantyPolicy);
    }

    public function update(UpdateWarrantyPolicyRequest $request, WarrantyPolicy $warrantyPolicy, SyncCatalogOutboxService $syncCatalog): WarrantyPolicyResource
    {
        $this->authorizePermission($request, 'warranty_policies.manage');

        $warrantyPolicy->update($request->validated());
        $syncCatalog->warrantyPolicyUpdated($warrantyPolicy->refresh());

        return WarrantyPolicyResource::make($warrantyPolicy->refresh());
    }

    public function destroy(Request $request, WarrantyPolicy $warrantyPolicy, SyncCatalogOutboxService $syncCatalog): Response
    {
        $this->authorizePermission($request, 'warranty_policies.manage');
        $warrantyPolicy->update(['is_active' => false]);
        $syncCatalog->warrantyPolicyUpdated($warrantyPolicy->refresh());

        return response()->noContent();
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}
