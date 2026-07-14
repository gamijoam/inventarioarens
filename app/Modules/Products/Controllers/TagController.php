<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Models\Tag;
use App\Modules\Products\Requests\StoreTagRequest;
use App\Modules\Products\Requests\UpdateTagRequest;
use App\Modules\Products\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TagController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Tag::query()
            ->withCount('products')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower((string) $request->input('search')).'%';
                $q->whereRaw('LOWER(name) LIKE ?', [$term]);
            })
            ->orderBy('name');

        return TagResource::collection($query->paginate(50));
    }

    public function store(StoreTagRequest $request): TagResource
    {
        $tag = Tag::create($request->validated())->refresh();

        return TagResource::make($tag);
    }

    public function show(Tag $tag): TagResource
    {
        $tag->loadCount('products');

        return TagResource::make($tag);
    }

    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $tag->fill($request->validated())->save();

        return TagResource::make($tag);
    }

    public function destroy(Tag $tag): Response
    {
        $tag->delete();

        return response()->noContent();
    }
}
