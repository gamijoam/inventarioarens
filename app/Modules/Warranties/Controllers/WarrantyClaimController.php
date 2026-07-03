<?php

namespace App\Modules\Warranties\Controllers;

use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\Warranties\Requests\DeliverWarrantyClaimRequest;
use App\Modules\Warranties\Requests\ResolveWarrantyClaimRequest;
use App\Modules\Warranties\Requests\ReviewWarrantyClaimRequest;
use App\Modules\Warranties\Requests\StoreWarrantyClaimRequest;
use App\Modules\Warranties\Resources\WarrantyClaimResource;
use App\Modules\Warranties\Services\WarrantyClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WarrantyClaimController extends Controller
{
    public function __construct(private readonly WarrantyClaimService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'warranties.view');

        return WarrantyClaimResource::collection(
            WarrantyClaim::query()
                ->with(['sale.customer', 'saleItem.product', 'product', 'productUnit'])
                ->latest('received_at')
                ->paginate(25)
        );
    }

    public function store(StoreWarrantyClaimRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'warranties.create');

        return WarrantyClaimResource::make(
            $this->service->create($request->user(), $request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, WarrantyClaim $warrantyClaim): WarrantyClaimResource
    {
        $this->authorizePermission($request, 'warranties.view');

        return WarrantyClaimResource::make($this->service->loadClaim($warrantyClaim));
    }

    public function review(ReviewWarrantyClaimRequest $request, WarrantyClaim $warrantyClaim): WarrantyClaimResource
    {
        $this->authorizePermission($request, 'warranties.review');

        return WarrantyClaimResource::make(
            $this->service->review($warrantyClaim, $request->user(), $request->validated())
        );
    }

    public function deliver(DeliverWarrantyClaimRequest $request, WarrantyClaim $warrantyClaim): WarrantyClaimResource
    {
        $this->authorizePermission($request, 'warranties.deliver');

        return WarrantyClaimResource::make(
            $this->service->deliver($warrantyClaim, $request->user(), $request->validated('resolution_notes'))
        );
    }

    public function resolve(ResolveWarrantyClaimRequest $request, WarrantyClaim $warrantyClaim): WarrantyClaimResource
    {
        $this->authorizePermission($request, 'warranties.resolve');

        return WarrantyClaimResource::make(
            $this->service->resolve($warrantyClaim, $request->user(), $request->validated())
        );
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}
