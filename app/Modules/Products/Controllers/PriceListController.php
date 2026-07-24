<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Requests\StorePriceListRequest;
use App\Modules\Products\Requests\UpdatePriceListRequest;
use App\Modules\Products\Resources\PriceListResource;
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
                ->with('paymentMethods')
                ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StorePriceListRequest $request, SyncCatalogOutboxService $syncCatalog): JsonResponse
    {
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
        $syncCatalog->priceListCreated($priceList->refresh()->load('paymentMethods'));

        return PriceListResource::make($priceList->load('paymentMethods'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList, SyncCatalogOutboxService $syncCatalog): PriceListResource
    {
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
        $syncCatalog->priceListUpdated($priceList->refresh()->load('paymentMethods'));

        return PriceListResource::make($priceList->refresh()->load('paymentMethods'));
    }

    public function destroy(Request $request, PriceList $priceList, SyncCatalogOutboxService $syncCatalog): Response
    {
        abort_unless($request->user()?->can('products.update'), Response::HTTP_FORBIDDEN);

        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $priceList->update([
            'is_active' => false,
            'is_default' => false,
        ]);
        $syncCatalog->priceListDeactivated($priceList->refresh()->load('paymentMethods'));

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
