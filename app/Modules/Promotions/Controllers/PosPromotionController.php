<?php

namespace App\Modules\Promotions\Controllers;

use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Resources\PromotionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PosPromotionController extends Controller
{
    public function available(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('pos.promotions.view'), Response::HTTP_FORBIDDEN);

        $productIds = collect($request->input('product_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $selectable = $request->boolean('selectable');

        $promotions = Promotion::query()
            ->with(['items.product'])
            ->when($scope = $request->route('promotion_scope'), fn ($query) => $query->where('scope', $scope))
            ->activeAt()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->when($productIds->isNotEmpty(), function ($promotions) use ($productIds) {
                return $promotions->filter(function (Promotion $promotion) use ($productIds): bool {
                    $required = $promotion->items->pluck('product_id')->unique()->sort()->values();
                    $available = $productIds->sort()->values();

                    return $required->diff($available)->isEmpty();
                });
            })
            ->when(! $selectable, fn ($promotions) => $promotions->take(1));

        return PromotionResource::collection($promotions->values());
    }
}
