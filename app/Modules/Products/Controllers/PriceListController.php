<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Requests\StorePriceListRequest;
use App\Modules\Products\Requests\UpdatePriceListRequest;
use App\Modules\Products\Resources\PriceListResource;
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

class PriceListController extends Controller
{
    use SharedCatalogWriteGuard;

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('products.view'), Response::HTTP_FORBIDDEN);

        return PriceListResource::collection(
            PriceList::query()
                ->with(['paymentMethods', 'paymentExchangeRateType'])
                ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(
        StorePriceListRequest $request,
        SyncCatalogOutboxService $syncCatalog,
        SharedCatalogPropagationService $catalogPropagation,
    ): JsonResponse {
        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $data = $this->normalize($request->validated());

        $paymentMethodIds = $data['payment_method_ids'] ?? null;
        unset($data['payment_method_ids']);

        $priceList = DB::transaction(function () use ($data, $paymentMethodIds): PriceList {
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->update(['is_default' => false]);
            }

            $priceList = PriceList::create($data);
            if ($paymentMethodIds !== null) {
                $priceList->paymentMethods()->sync($this->syncPayload($paymentMethodIds));
            }

            return $priceList;
        });
        $catalogPropagation->propagatePriceListConfiguration($priceList->refresh()->load(['paymentMethods', 'paymentExchangeRateType']));
        $syncCatalog->priceListCreated($priceList->refresh()->load(['paymentMethods', 'paymentExchangeRateType']));

        return PriceListResource::make($priceList->load(['paymentMethods', 'paymentExchangeRateType']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdatePriceListRequest $request,
        PriceList $priceList,
        SyncCatalogOutboxService $syncCatalog,
        SharedCatalogPropagationService $catalogPropagation,
    ): PriceListResource {
        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $data = $this->normalize($request->validated());

        $paymentMethodIds = $data['payment_method_ids'] ?? null;
        unset($data['payment_method_ids']);

        DB::transaction(function () use ($priceList, $data, $paymentMethodIds): void {
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()
                    ->whereKeyNot($priceList->id)
                    ->update(['is_default' => false]);
            }

            $priceList->update($data);
            if ($paymentMethodIds !== null) {
                $priceList->paymentMethods()->sync($this->syncPayload($paymentMethodIds));
            }
        });
        $catalogPropagation->propagatePriceListConfiguration($priceList->refresh()->load(['paymentMethods', 'paymentExchangeRateType']));
        $syncCatalog->priceListUpdated($priceList->refresh()->load(['paymentMethods', 'paymentExchangeRateType']));

        return PriceListResource::make($priceList->refresh()->load(['paymentMethods', 'paymentExchangeRateType']));
    }

    public function destroy(
        Request $request,
        PriceList $priceList,
        SyncCatalogOutboxService $syncCatalog,
        SharedCatalogPropagationService $catalogPropagation,
    ): Response {
        abort_unless($request->user()?->can('products.update'), Response::HTTP_FORBIDDEN);

        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $priceList->is_active = false;
        try {
            $syncCatalog->priceListDeactivated($priceList);
        } catch (\Throwable) {
            // sync outbox failure should not block deletion
        }

        DB::transaction(function () use ($priceList, $catalogPropagation): void {
            // 1. Desvincular como lista base de cualquier otra lista
            PriceList::withoutGlobalScopes()
                ->where('base_price_list_id', $priceList->id)
                ->update(['base_price_list_id' => null]);

            // 2. Si el tenant actual es un grupo (o tiene spinoffs), propagar la eliminación a todas las hijas
            $spinoffs = $catalogPropagation->spinoffsFor((int) $priceList->tenant_id);
            foreach ($spinoffs as $spinoff) {
                $spinoffCopies = PriceList::withoutGlobalScopes()
                    ->where('tenant_id', $spinoff->id)
                    ->where('code', $priceList->code)
                    ->get();

                foreach ($spinoffCopies as $copy) {
                    PriceList::withoutGlobalScopes()
                        ->where('tenant_id', $spinoff->id)
                        ->where('base_price_list_id', $copy->id)
                        ->update(['base_price_list_id' => null]);

                    DB::table('price_list_payment_method')
                        ->where('tenant_id', $spinoff->id)
                        ->where('price_list_id', $copy->id)
                        ->delete();

                    DB::table('product_prices')
                        ->where('tenant_id', $spinoff->id)
                        ->where('price_list_id', $copy->id)
                        ->delete();

                    $copy->delete();
                }
            }

            // 3. Eliminar métodos de pago y precios de la lista en el tenant actual
            DB::table('price_list_payment_method')
                ->where('tenant_id', $priceList->tenant_id)
                ->where('price_list_id', $priceList->id)
                ->delete();

            DB::table('product_prices')
                ->where('tenant_id', $priceList->tenant_id)
                ->where('price_list_id', $priceList->id)
                ->delete();

            // 4. Eliminar la lista en el tenant actual
            $priceList->delete();
        });

        return response()->noContent();
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }

        return $data;
    }

    private function syncPayload(array $paymentMethodIds): array
    {
        // El pivote price_list_payment_method tiene FK compuesta
        // (tenant_id, price_list_id, payment_method_id). Como PriceList y
        // PaymentMethod ahora son locales por tenant, el tenant_id del
        // pivote debe ser el del tenant actual (no el grupo padre).
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;

        return collect($paymentMethodIds)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => $tenantId]])
            ->all();
    }
}
