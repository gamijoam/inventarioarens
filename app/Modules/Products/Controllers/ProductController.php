<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Requests\StoreProductRequest;
use App\Modules\Products\Requests\SyncProductPricesRequest;
use App\Modules\Products\Requests\UpdateProductRequest;
use App\Modules\Products\Resources\ProductPriceResource;
use App\Modules\Products\Resources\ProductPriceListResource;
use App\Modules\Products\Resources\ProductResource;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $search = trim((string) $request->query('search', ''));
        $normalizedSearch = mb_strtolower($search);
        $limit = min(max((int) $request->query('limit', 25), 1), 100);

        return ProductResource::collection(
            Product::query()
                ->with(['saleExchangeRateType', 'warrantyPolicy'])
                ->when($search !== '', function ($query) use ($normalizedSearch): void {
                    $query->where(function ($query) use ($normalizedSearch): void {
                        $query
                            ->whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$normalizedSearch}%"]);
                    });
                })
                ->orderBy('name')
                ->paginate($limit)
        );
    }

    public function store(StoreProductRequest $request, SyncCatalogOutboxService $syncCatalog): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $data = $this->prepareProductData($request->validated());

        $product = Product::create($data)
            ->refresh()
            ->load(['saleExchangeRateType', 'warrantyPolicy']);
        $this->recordAudit($product, ProductAudit::ACTION_CREATED, [], $product->only($this->auditedFields()), $request->user()?->id);
        $syncCatalog->productCreated($product);

        return ProductResource::make($product)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return ProductResource::make($product->load(['saleExchangeRateType', 'warrantyPolicy'])->loadCount('units'));
    }

    public function price(Product $product, ProductPriceService $priceService): ProductPriceResource
    {
        Gate::authorize('view', $product);

        $priceListId = request()->query('price_list_id');

        return ProductPriceResource::make($priceService->quote(
            $product,
            $priceListId === null ? null : (int) $priceListId
        ));
    }

    public function prices(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('view', $product);

        return ProductPriceListResource::collection(
            $product->prices()
                ->with(['priceList', 'exchangeRateType'])
                ->orderBy('price_list_id')
                ->get()
        );
    }

    public function priceHistory(Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        $audits = ProductAudit::query()
            ->with('creator')
            ->where('product_id', $product->id)
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (ProductAudit $audit): bool => $this->extractProductPriceChange($audit) !== null)
            ->take(50)
            ->values();

        $priceListIds = $audits
            ->flatMap(function (ProductAudit $audit): array {
                $change = $this->extractProductPriceChange($audit);

                return array_filter([
                    $change['before']['price_list_id'] ?? null,
                    $change['after']['price_list_id'] ?? null,
                ]);
            })
            ->unique()
            ->values();

        $priceLists = \App\Modules\Products\Models\PriceList::query()
            ->whereIn('id', $priceListIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $audits->map(function (ProductAudit $audit) use ($priceLists): array {
                $change = $this->extractProductPriceChange($audit) ?? ['before' => null, 'after' => null];
                $priceListId = $change['after']['price_list_id'] ?? $change['before']['price_list_id'] ?? null;
                $priceList = $priceListId ? $priceLists->get($priceListId) : null;

                return [
                    'id' => $audit->id,
                    'action' => $audit->action,
                    'price_list_id' => $priceListId,
                    'price_list_name' => $priceList?->name ?? 'Lista no disponible',
                    'price_list_code' => $priceList?->code,
                    'before' => $change['before'],
                    'after' => $change['after'],
                    'created_by_name' => $audit->creator?->name,
                    'created_by_email' => $audit->creator?->email,
                    'created_at' => $audit->created_at?->toISOString(),
                ];
            }),
        ]);
    }

    public function syncPrices(SyncProductPricesRequest $request, Product $product, SyncCatalogOutboxService $syncCatalog): AnonymousResourceCollection
    {
        Gate::authorize('update', $product);

        foreach ($request->validated('prices') as $price) {
            $productPrice = ProductPrice::query()
                ->where('product_id', $product->id)
                ->where('price_list_id', $price['price_list_id'])
                ->first();

            $attributes = [
                'price' => $price['price'],
                'currency' => $price['currency'],
                'exchange_rate_type_id' => $price['exchange_rate_type_id'] ?? null,
                'is_active' => $price['is_active'] ?? true,
            ];
            $before = $productPrice ? $this->productPriceAuditData($productPrice) : null;

            if ($productPrice) {
                $productPrice->update($attributes);
            } else {
                $productPrice = ProductPrice::create([
                    'product_id' => $product->id,
                    'price_list_id' => $price['price_list_id'],
                    ...$attributes,
                ]);
            }

            $after = $this->productPriceAuditData($productPrice->refresh());
            if ($before != $after) {
                $this->recordAudit(
                    $product,
                    ProductAudit::ACTION_UPDATED,
                    ['product_price' => $before],
                    ['product_price' => $after],
                    $request->user()?->id
                );
                $before === null
                    ? $syncCatalog->productPriceCreated($productPrice)
                    : $syncCatalog->productPriceUpdated($productPrice);
            }
        }

        return $this->prices($product);
    }

    public function update(UpdateProductRequest $request, Product $product, SyncCatalogOutboxService $syncCatalog): ProductResource
    {
        Gate::authorize('update', $product);

        $data = $request->validated();

        if (
            array_key_exists('tracking_type', $data)
            && $data['tracking_type'] !== $product->tracking_type
            && $product->units()->exists()
        ) {
            throw ValidationException::withMessages([
                'tracking_type' => 'No se puede cambiar el tipo de control de un producto que ya tiene unidades serializadas.',
            ]);
        }

        $before = $product->only(array_keys($data));
        $product->update($data);
        $after = $product->refresh()->only(array_keys($data));
        $changes = $this->changedValues($before, $after);

        if ($changes !== []) {
            $this->recordAudit($product, ProductAudit::ACTION_UPDATED, $changes['before'], $changes['after'], $request->user()?->id);
            $syncCatalog->productUpdated($product);
        }

        return ProductResource::make($product->refresh()->load(['saleExchangeRateType', 'warrantyPolicy'])->loadCount('units'));
    }

    public function destroy(Product $product, SyncCatalogOutboxService $syncCatalog): Response
    {
        Gate::authorize('delete', $product);

        $before = ['is_active' => $product->is_active];
        $product->update(['is_active' => false]);
        $this->recordAudit($product, ProductAudit::ACTION_DEACTIVATED, $before, ['is_active' => false], request()->user()?->id);
        $syncCatalog->productDeactivated($product->refresh());

        return response()->noContent();
    }

    private function auditedFields(): array
    {
        return [
            'name',
            'sku',
            'tracking_type',
            'base_price',
            'sale_currency',
            'sale_exchange_rate_type_id',
            'warranty_policy_id',
            'is_active',
        ];
    }

    private function prepareProductData(array $data): array
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        $data['sku'] = $sku !== '' ? $sku : $this->generateSkuFromName((string) $data['name']);

        return $data;
    }

    private function generateSkuFromName(string $name): string
    {
        $base = Str::of($name)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->limit(32, '')
            ->toString();

        if ($base === '') {
            $base = 'PRODUCTO';
        }

        $candidate = $base;
        $counter = 2;

        while (Product::query()->where('sku', $candidate)->exists()) {
            $suffix = "-{$counter}";
            $candidate = Str::limit($base, 32 - strlen($suffix), '').$suffix;
            $counter++;
        }

        return $candidate;
    }

    private function changedValues(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) != $value) {
                $changedBefore[$field] = $before[$field] ?? null;
                $changedAfter[$field] = $value;
            }
        }

        return $changedAfter === [] ? [] : [
            'before' => $changedBefore,
            'after' => $changedAfter,
        ];
    }

    private function recordAudit(Product $product, string $action, array $before, array $after, ?int $userId): void
    {
        if (! Schema::hasTable('product_audits')) {
            return;
        }

        ProductAudit::create([
            'product_id' => $product->id,
            'action' => $action,
            'changes' => [
                'before' => $before,
                'after' => $after,
            ],
            'created_by' => $userId,
        ]);
    }

    private function productPriceAuditData(ProductPrice $productPrice): array
    {
        return [
            'price_list_id' => $productPrice->price_list_id,
            'price' => round((float) $productPrice->price, 4),
            'currency' => $productPrice->currency,
            'exchange_rate_type_id' => $productPrice->exchange_rate_type_id,
            'is_active' => (bool) $productPrice->is_active,
        ];
    }

    private function extractProductPriceChange(ProductAudit $audit): ?array
    {
        $changes = $audit->changes ?? [];
        $before = $changes['before']['product_price'] ?? null;
        $after = $changes['after']['product_price'] ?? null;

        if ($before === null && $after === null) {
            return null;
        }

        return [
            'before' => $before,
            'after' => $after,
        ];
    }
}
