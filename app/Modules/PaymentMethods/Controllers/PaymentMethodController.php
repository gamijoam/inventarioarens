<?php

namespace App\Modules\PaymentMethods\Controllers;

use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\PaymentMethods\Requests\StorePaymentMethodRequest;
use App\Modules\PaymentMethods\Requests\UpdatePaymentMethodRequest;
use App\Modules\PaymentMethods\Resources\PaymentMethodResource;
use App\Support\Tenancy\Concerns\SharedCatalogWriteGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PaymentMethodController extends Controller
{
    use SharedCatalogWriteGuard;

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('payment_methods.view'), Response::HTTP_FORBIDDEN);

        return PaymentMethodResource::collection(
            PaymentMethod::query()
                ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $paymentMethod = PaymentMethod::create($this->normalize($request->validated()));

        return PaymentMethodResource::make($paymentMethod)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $paymentMethod->update($this->normalize($request->validated()));

        return PaymentMethodResource::make($paymentMethod->refresh());
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): Response
    {
        abort_unless($request->user()?->can('payment_methods.update'), Response::HTTP_FORBIDDEN);

        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $paymentMethod->update(['is_active' => false]);

        return response()->noContent();
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }

        if (array_key_exists('currency_mode', $data) && $data['currency_mode'] !== PaymentMethod::CURRENCY_FLEXIBLE) {
            $data['currency_mode'] = strtoupper($data['currency_mode']);
        }

        return $data;
    }
}
