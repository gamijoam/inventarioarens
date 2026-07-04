<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Requests\StorePriceListRequest;
use App\Modules\Products\Requests\UpdatePriceListRequest;
use App\Modules\Products\Resources\PriceListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PriceListController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('products.view'), Response::HTTP_FORBIDDEN);

        return PriceListResource::collection(
            PriceList::query()
                ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StorePriceListRequest $request): JsonResponse
    {
        $data = $this->normalize($request->validated());

        $priceList = DB::transaction(function () use ($data): PriceList {
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->update(['is_default' => false]);
            }

            return PriceList::create($data);
        });

        return PriceListResource::make($priceList)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): PriceListResource
    {
        $data = $this->normalize($request->validated());

        DB::transaction(function () use ($priceList, $data): void {
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()
                    ->whereKeyNot($priceList->id)
                    ->update(['is_default' => false]);
            }

            $priceList->update($data);
        });

        return PriceListResource::make($priceList->refresh());
    }

    public function destroy(Request $request, PriceList $priceList): Response
    {
        abort_unless($request->user()?->can('products.update'), Response::HTTP_FORBIDDEN);

        $priceList->update([
            'is_active' => false,
            'is_default' => false,
        ]);

        return response()->noContent();
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }

        return $data;
    }
}
