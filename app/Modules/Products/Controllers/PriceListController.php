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
                ->with('paymentMethods')
                ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StorePriceListRequest $request): JsonResponse
    {
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

        return PriceListResource::make($priceList->load('paymentMethods'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): PriceListResource
    {
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

        return PriceListResource::make($priceList->refresh()->load('paymentMethods'));
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

    private function syncPayload(array $paymentMethodIds): array
    {
        return collect($paymentMethodIds)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => app(\App\Support\Tenancy\TenantManager::class)->require()->id]])
            ->all();
    }
}
