<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Requests\StoreProductRequest;
use App\Modules\Products\Requests\SyncProductPricesRequest;
use App\Modules\Products\Requests\UpdateProductRequest;
use App\Modules\Products\Resources\ProductPriceListResource;
use App\Modules\Products\Resources\ProductPriceResource;
use App\Modules\Products\Resources\ProductResource;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Tenancy\Concerns\SharedCatalogWriteGuard;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use SharedCatalogWriteGuard;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $search = trim((string) $request->query('search', ''));
        $normalizedSearch = mb_strtolower($search);
        $limit = min(max((int) $request->query('limit', 25), 1), 100);

        $relations = ['saleExchangeRateType', 'warrantyPolicy', 'brand', 'categories.parent', 'tags'];
        if ($request->boolean('with_images')) {
            $relations[] = 'images.variants';
        }

        $query = Product::query()
            ->with($relations)
            ->withCount('units');

        // Suma de stock_balances.quantity_available. Usamos SIEMPRE el
        // formato con AS explicito `as available_stock` para que el
        // alias no dependa del nombre de la relacion (sin esto, el
        // alias seria 'stock_balances_sum_quantity_available' y el
        // ProductResource no lo encontraria). Verificado en diagnostico
        // de bug: sin esto, available_stock siempre es null.
        if ($request->filled('warehouse_id')) {
            $warehouseId = $request->integer('warehouse_id');
            $query->whereHas('stockBalances', fn ($q) => $q->where('warehouse_id', $warehouseId));
            $query->withSum(
                ['stockBalances as available_stock' => fn ($q) => $q->where('warehouse_id', $warehouseId)],
                'quantity_available',
            );
        } else {
            $query->withSum(
                ['stockBalances as available_stock' => fn ($q) => $q],
                'quantity_available',
            );
        }

        if ($search !== '') {
            $query->where(function ($q) use ($normalizedSearch): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSearch}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$normalizedSearch}%"])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', ["%{$normalizedSearch}%"]);
            });

            $query->orderByRaw(
                'CASE
                    WHEN LOWER(COALESCE(barcode, \'\')) = ? THEN 0
                    WHEN LOWER(COALESCE(sku, \'\')) = ? THEN 1
                    WHEN LOWER(COALESCE(name, \'\')) = ? THEN 2
                    WHEN LOWER(COALESCE(barcode, \'\')) LIKE ? THEN 3
                    WHEN LOWER(COALESCE(sku, \'\')) LIKE ? THEN 4
                    WHEN LOWER(COALESCE(name, \'\')) LIKE ? THEN 5
                    ELSE 6
                END',
                [
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    "%{$normalizedSearch}%",
                    "%{$normalizedSearch}%",
                    "%{$normalizedSearch}%",
                ]
            );
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }

        if ($request->filled('category_id')) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $request->integer('category_id')));
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $request->integer('tag_id')));
        }

        if ($request->filled('tracking_type')) {
            $query->where('tracking_type', $request->string('tracking_type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('is_active', true);
        }

        if ($search === '') {
            $query->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        return ProductResource::collection($query->paginate($limit));
    }

    public function store(StoreProductRequest $request, SyncCatalogOutboxService $syncCatalog, SharedCatalogPropagationService $propagation): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        $data = $this->prepareProductData($data);
        $userId = $request->user()?->id;

        // Defensa explicita: forzamos tenant_id al tenant del request
        // (resuelto por el middleware ResolveTenant desde X-Tenant). El
        // producto SIEMPRE pertenece al tenant operativo actual, no al
        // grupo padre. Esto es coherente con el modelo "scope estricto
        // por tenant" introducido en Fase 6: el maestro del grupo se
        // crea solo cuando el tenant actual es el grupo (is_group=true);
        // los spinoffs crean su propia copia local al propagarse.
        $tenantManager = app(TenantManager::class);
        $currentTenant = $tenantManager->current();
        $tenantId = $currentTenant?->id ?? $tenantManager->require()->id;
        $data['tenant_id'] = $tenantId;
        $data['is_catalog_master'] = $currentTenant?->isGroup() ?? false;

        $product = DB::transaction(function () use ($data, $categoryIds, $tagIds, $userId, $syncCatalog, $propagation): Product {
            $created = Product::create($data)
                ->refresh()
                ->load(['saleExchangeRateType', 'warrantyPolicy', 'brand', 'categories', 'tags']);

            if ($categoryIds !== []) {
                $created->categories()->syncWithPivotValues($categoryIds, ['tenant_id' => $created->tenant_id]);
            }
            if ($tagIds !== []) {
                $created->tags()->syncWithPivotValues($tagIds, ['tenant_id' => $created->tenant_id]);
            }

            $this->recordAudit($created, ProductAudit::ACTION_CREATED, [], $created->only($this->auditedFields()), $userId);
            $syncCatalog->productCreated($created);

            if ($created->isCatalogMaster()) {
                // Propaga el producto maestro a cada spinoff. Tambien propaga
                // los catalogos referenciados (brand/categorias/tags) si
                // todavia no existen localmente en el spinoff.
                $propagation->propagateMaster($created);
                $propagation->propagateReferencedCatalogForMaster($created);
            }

            return $created->load(['saleExchangeRateType', 'warrantyPolicy', 'brand', 'categories', 'tags']);
        });

        return ProductResource::make($product)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return ProductResource::make(
            $product
                ->load([
                    'saleExchangeRateType',
                    'warrantyPolicy',
                    'brand',
                    'categories.parent',
                    'tags',
                ])
                ->loadCount('units')
        );
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

        $priceLists = PriceList::query()
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

        DB::transaction(function () use ($request, $product, $syncCatalog): void {
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
        });

        return $this->prices($product);
    }

    public function update(UpdateProductRequest $request, Product $product, SyncCatalogOutboxService $syncCatalog, SharedCatalogPropagationService $propagation): ProductResource
    {
        Gate::authorize('update', $product);

        if (
            $this->modelBelongsToSharedCatalog($product)
            && ! $this->canWriteSharedCatalog($request->user())
        ) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $data = $request->validated();
        $userId = $request->user()?->id;

        if (
            array_key_exists('tracking_type', $data)
            && $data['tracking_type'] !== $product->tracking_type
            && $product->units()->exists()
        ) {
            throw ValidationException::withMessages([
                'tracking_type' => 'No se puede cambiar el tipo de control de un producto que ya tiene unidades serializadas.',
            ]);
        }

        $product = DB::transaction(function () use ($data, $product, $userId, $syncCatalog, $propagation): Product {
            $before = $product->only(array_keys($data));
            $product->update($data);

            if (
                array_key_exists('profit_margin', $data)
                && $product->profit_margin !== null
                && $product->last_purchase_cost !== null
                && ! array_key_exists('base_price', $data)
            ) {
                $cost = (float) $product->last_purchase_cost;
                $margin = (float) $product->profit_margin;
                $product->base_price = round($cost * (1 + ($margin / 100)), 2);
                $product->save();
                $data['base_price'] = $product->base_price;
            }

            // Sincronizar categorias y tags si vienen en el payload.
            // Antes el controller los descartaba (ver prepareProductData)
            // y solo se sincronizaban via endpoints dedicados, lo cual el
            // frontend no llamaba al editar.
            $syncedCategories = false;
            if (array_key_exists('category_ids', $data)) {
                $categoryIds = array_values(array_map('intval', (array) $data['category_ids']));
                $product->categories()->syncWithPivotValues(
                    $categoryIds,
                    ['tenant_id' => $product->tenant_id],
                );
                $syncedCategories = true;
            }
            $syncedTags = false;
            if (array_key_exists('tag_ids', $data)) {
                $tagIds = array_values(array_map('intval', (array) $data['tag_ids']));
                $product->tags()->syncWithPivotValues(
                    $tagIds,
                    ['tenant_id' => $product->tenant_id],
                );
                $syncedTags = true;
            }

            $after = $product->refresh()->only(array_keys($data));
            $changes = $this->changedValues($before, $after);

            if ($changes !== [] || $syncedCategories || $syncedTags) {
                $beforeValues = $changes['before'] ?? [];
                $afterValues = $changes['after'] ?? [];
                $this->recordAudit($product, ProductAudit::ACTION_UPDATED, $beforeValues, $afterValues, $userId);
                $syncCatalog->productUpdated($product);
            }

            if ($product->isCatalogMaster() && $this->touchedMasterField($data)) {
                $propagation->syncMasterFieldsToCopies($product);
            }

            if ($syncedCategories && $product->isCatalogMaster()) {
                $propagation->propagateReferencedCatalogForMaster($product);
            }

            return $product->refresh()
                ->load(['saleExchangeRateType', 'warrantyPolicy', 'brand', 'categories.parent', 'tags'])
                ->loadCount('units');
        });

        return ProductResource::make($product);
    }

    public function destroy(Product $product, SyncCatalogOutboxService $syncCatalog, SharedCatalogPropagationService $propagation): Response
    {
        Gate::authorize('delete', $product);

        if (
            $this->modelBelongsToSharedCatalog($product)
            && ! $this->canWriteSharedCatalog(request()->user())
        ) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $before = ['is_active' => $product->is_active];

        DB::transaction(function () use ($product, $before, $syncCatalog, $propagation): void {
            $product->update(['is_active' => false, 'is_catalog_active' => false]);
            $this->recordAudit($product, ProductAudit::ACTION_DEACTIVATED, $before, ['is_active' => false], request()->user()?->id);
            $syncCatalog->productDeactivated($product->refresh());

            if ($product->isCatalogMaster()) {
                $propagation->deactivateCopiesForMaster($product);
            }
        });

        return response()->noContent();
    }

    public function syncCategories(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);

        $data = $request->validate([
            'category_ids' => ['present', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $product->categories()->syncWithPivotValues($data['category_ids'], ['tenant_id' => $product->tenant_id]);

        return response()->json([
            'data' => $product->categories()->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])->all(),
        ]);
    }

    public function syncTags(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);

        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ]);

        $product->tags()->syncWithPivotValues($data['tag_ids'], ['tenant_id' => $product->tenant_id]);

        return response()->json([
            'data' => $product->tags()->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'color' => $t->color,
            ])->all(),
        ]);
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

    private function touchedMasterField(array $data): bool
    {
        return (bool) array_intersect_key(array_flip(Product::MASTER_FIELDS), $data);
    }

    private function prepareProductData(array $data): array
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        $data['sku'] = $sku !== '' ? $sku : $this->generateSkuFromName((string) $data['name']);

        if (isset($data['barcode'])) {
            $data['barcode'] = trim((string) $data['barcode']) ?: null;
        }

        unset($data['category_ids'], $data['tag_ids']);

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
