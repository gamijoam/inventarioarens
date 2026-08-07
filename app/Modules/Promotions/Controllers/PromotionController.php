<?php

namespace App\Modules\Promotions\Controllers;

use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Requests\StorePromotionRequest;
use App\Modules\Promotions\Requests\UpdatePromotionRequest;
use App\Modules\Promotions\Resources\PromotionResource;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('promotions.view'), Response::HTTP_FORBIDDEN);

        return PromotionResource::collection(
            Promotion::query()
                ->with(['items.product'])
                ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
                ->orderByDesc('priority')
                ->orderBy('name')
                ->get()
        );
    }

    public function show(Request $request, Promotion $promotion): PromotionResource
    {
        abort_unless($request->user()?->can('promotions.view'), Response::HTTP_FORBIDDEN);

        return PromotionResource::make($promotion->load('items.product'));
    }

    public function store(StorePromotionRequest $request, SyncCatalogOutboxService $syncCatalog): JsonResponse
    {
        abort_unless($request->user()?->can('promotions.create'), Response::HTTP_FORBIDDEN);

        $promotion = DB::transaction(function () use ($request): Promotion {
            $data = $request->validated();
            $items = $data['items'];
            unset($data['items']);
            $data['price_currency'] ??= Promotion::PRICE_CURRENCY_USD;

            $promotion = Promotion::create($data);
            $promotion->items()->createMany($this->normalizeItems($items));

            return $promotion;
        });
        $syncCatalog->promotionCreated($promotion->load('items.product'));

        return PromotionResource::make($promotion->load('items.product'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion, SyncCatalogOutboxService $syncCatalog): PromotionResource
    {
        abort_unless($request->user()?->can('promotions.update'), Response::HTTP_FORBIDDEN);

        DB::transaction(function () use ($request, $promotion): void {
            $data = $request->validated();
            $items = $data['items'] ?? null;
            unset($data['items']);
            $promotion->update($data);

            if ($items !== null) {
                $promotion->items()->delete();
                $promotion->items()->createMany($this->normalizeItems($items));
            }
        });
        $syncCatalog->promotionUpdated($promotion->refresh()->load('items.product'));

        return PromotionResource::make($promotion->refresh()->load('items.product'));
    }

    public function destroy(Request $request, Promotion $promotion, SyncCatalogOutboxService $syncCatalog): Response
    {
        abort_unless($request->user()?->can('promotions.delete'), Response::HTTP_FORBIDDEN);

        $promotion->update(['is_active' => false]);
        $syncCatalog->promotionDeleted($promotion->refresh()->load('items.product'));

        return response()->noContent();
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->values()
            ->map(fn (array $item, int $index): array => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'item_role' => $item['item_role'] ?? 'eligible',
                'sort_order' => $index,
            ])
            ->all();
    }
}
