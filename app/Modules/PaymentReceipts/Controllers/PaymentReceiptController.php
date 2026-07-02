<?php

namespace App\Modules\PaymentReceipts\Controllers;

use App\Modules\PaymentReceipts\Models\PaymentReceipt;
use App\Modules\PaymentReceipts\Requests\VoidPaymentReceiptRequest;
use App\Modules\PaymentReceipts\Resources\PaymentReceiptResource;
use App\Modules\PaymentReceipts\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PaymentReceiptController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PaymentReceipt::class);

        return PaymentReceiptResource::collection(
            PaymentReceipt::query()
                ->latest('issued_at')
                ->paginate(25)
        );
    }

    public function show(PaymentReceipt $paymentReceipt): PaymentReceiptResource
    {
        Gate::authorize('view', $paymentReceipt);

        return PaymentReceiptResource::make($paymentReceipt);
    }

    public function void(
        VoidPaymentReceiptRequest $request,
        PaymentReceipt $paymentReceipt,
        PaymentReceiptService $service,
    ): JsonResponse {
        Gate::authorize('void', $paymentReceipt);

        return PaymentReceiptResource::make(
            $service->void($paymentReceipt, $request->user(), $request->validated('reason'))
        )->response();
    }
}
