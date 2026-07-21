<?php

namespace App\Modules\Branches\Controllers;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Requests\StoreBranchRequest;
use App\Modules\Branches\Requests\UpdateBranchRequest;
use App\Modules\Branches\Resources\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Branch::class);

        $perPage = $this->resolvePerPage($request);

        return BranchResource::collection(
            Branch::query()
                ->orderBy('name')
                ->paginate($perPage)
        );
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        Gate::authorize('create', Branch::class);

        $branch = Branch::create($request->validated())->refresh();

        return BranchResource::make($branch)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('view', $branch);

        return BranchResource::make($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        Gate::authorize('update', $branch);

        $branch->update($request->validated());

        return BranchResource::make($branch->refresh());
    }

    public function destroy(Branch $branch): Response
    {
        Gate::authorize('delete', $branch);

        $branch->update(['status' => Branch::STATUS_INACTIVE]);

        return response()->noContent();
    }

    private function resolvePerPage(Request $request): int
    {
        $raw = $request->query('per_page', $request->query('limit', 25));

        return max(1, min(100, (int) $raw));
    }
}
