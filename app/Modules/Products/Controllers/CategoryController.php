<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Requests\StoreCategoryRequest;
use App\Modules\Products\Requests\UpdateCategoryRequest;
use App\Modules\Products\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
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

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $category = Category::create($request->validated())->refresh();

        return CategoryResource::make($category);
    }

    public function show(Category $category): CategoryResource
    {
        $category->load(['parent', 'children'])->loadCount('products');

        return CategoryResource::make($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->fill($request->validated())->save();

        return CategoryResource::make($category->fresh(['parent', 'children'])->loadCount('products'));
    }

    public function destroy(Category $category): Response
    {
        $category->delete();

        return response()->noContent();
    }
}
