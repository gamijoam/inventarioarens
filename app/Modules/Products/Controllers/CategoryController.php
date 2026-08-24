<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Requests\StoreCategoryRequest;
use App\Modules\Products\Requests\UpdateCategoryRequest;
use App\Modules\Products\Resources\CategoryResource;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('products.view'), 403);

        $query = Category::query()
            ->with(['parent'])
            ->withCount('products')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower((string) $request->input('search')).'%';
                $q->whereRaw('LOWER(name) LIKE ?', [$term]);
            })
            ->when($request->filled('parent_id'), function ($q) use ($request) {
                $q->where('parent_id', $request->integer('parent_id'));
            })
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->boolean('roots_only'), fn ($q) => $q->whereNull('parent_id'))
            ->orderBy('sort_order')
            ->orderBy('name');

        return CategoryResource::collection($query->paginate(50));
    }

    public function tree(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('products.view'), 403);

        $categories = Category::query()
            ->with(['children' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')])
            ->withCount('products')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => CategoryResource::collection($categories),
        ]);
    }

    public function store(StoreCategoryRequest $request, SyncCatalogOutboxService $syncCatalog): CategoryResource
    {
        $category = Category::create($request->validated())->refresh();
        $syncCatalog->categoryCreated($category);

        return CategoryResource::make($category);
    }

    public function show(Request $request, Category $category): CategoryResource
    {
        abort_unless($request->user()?->can('products.view'), 403);

        $category->load(['parent', 'children'])->loadCount('products');

        return CategoryResource::make($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category, SyncCatalogOutboxService $syncCatalog): CategoryResource
    {
        $category->fill($request->validated())->save();
        $syncCatalog->categoryUpdated($category->refresh());

        return CategoryResource::make($category->fresh(['parent', 'children'])->loadCount('products'));
    }

    public function destroy(Request $request, Category $category, SyncCatalogOutboxService $syncCatalog): Response
    {
        abort_unless($request->user()?->can('products.delete'), 403);

        $deleted = clone $category;
        $category->delete();
        $syncCatalog->categoryDeleted($deleted);

        return response()->noContent();
    }
}
