<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Requests\StoreBrandRequest;
use App\Modules\Products\Requests\UpdateBrandRequest;
use App\Modules\Products\Resources\BrandResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BrandController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Brand::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower((string) $request->input('search')).'%';
                $q->whereRaw('LOWER(name) LIKE ?', [$term]);
            })
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->withCount('products')
            ->orderBy('name');

        return BrandResource::collection($query->paginate(25));
    }

    public function store(StoreBrandRequest $request): BrandResource
    {
        $brand = Brand::create($request->validated())->refresh();

        return BrandResource::make($brand);
    }

    public function show(Brand $brand): BrandResource
    {
        $brand->loadCount('products');

        return BrandResource::make($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        $brand->fill($request->validated())->save();

        return BrandResource::make($brand);
    }

    public function destroy(Brand $brand): Response
    {
        $brand->delete();

        return response()->noContent();
    }
}
