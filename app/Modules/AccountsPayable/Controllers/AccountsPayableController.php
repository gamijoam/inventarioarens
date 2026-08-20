<?php

namespace App\Modules\AccountsPayable\Controllers;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Requests\RegisterAccountsPayablePaymentRequest;
use App\Modules\AccountsPayable\Requests\StoreAccountsPayablePaymentRequestRequest;
use App\Modules\AccountsPayable\Resources\AccountsPayablePaymentRequestResource;
use App\Modules\AccountsPayable\Resources\AccountsPayablePaymentResource;
use App\Modules\AccountsPayable\Resources\AccountsPayableResource;
use App\Modules\AccountsPayable\Services\AccountsPayablePaymentRequestService;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AccountsPayableController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsPayable::class);

        $filters = $this->validateFilters($request);
        $limit = (int) ($filters['limit'] ?? 25);
        $query = $this->query($request, $filters);

        return AccountsPayableResource::collection($query->paginate($limit));
    }

    public function export(Request $request): Response
    {
        Gate::authorize('viewAny', AccountsPayable::class);

        $filters = $this->validateFilters($request);
        $format = strtolower((string) $request->query('format', 'csv'));
        $rows = $this->query($request, $filters)->get();

        if ($format === 'pdf') {
            return $this->pdfResponse($rows);
        }

        return $this->csvResponse($rows);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120', 'regex:/^[a-z,]+$/'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function statusFilter(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '' || $raw === 'all') {
            return null;
        }

        $parts = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($v) => $v !== '' && $v !== 'all'
        )));

        if (in_array('open', $parts, true)) {
            $parts = array_values(array_diff($parts, ['open']));
            $parts = array_values(array_unique(array_merge($parts, [
                AccountsPayable::STATUS_PENDING,
                AccountsPayable::STATUS_PARTIAL,
                AccountsPayable::STATUS_OVERDUE,
            ])));
        }

        return $parts === [] ? null : $parts;
    }

    private function query(Request $request, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return AccountsPayable::query()
            ->with(['supplier', 'purchaseOrder', 'paymentRequests'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = "%{$search}%";
                $query->where(function (Builder $innerQuery) use ($like): void {
                    $innerQuery
                        ->where('document_number', 'like', $like)
                        ->orWhereHas('supplier', function ($supplierQuery) use ($like): void {
                            $supplierQuery
                                ->where('name', 'like', $like)
                                ->orWhere('document_number', 'like', $like);
                        })
                        ->orWhereHas('purchaseOrder', function ($purchaseQuery) use ($like): void {
                            $purchaseQuery->where('document_number', 'like', $like);
                        });
                });
            })
            ->when($statuses = $this->statusFilter($filters['status'] ?? null),
                fn ($query, array $statuses) => $query->whereIn('status', $statuses))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['due_from'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->latest();
    }

    private function csvResponse($rows): Response
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Documento', 'Proveedor', 'Estado', 'Vence', 'Original', 'Pagado', 'Saldo', 'Moneda']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row->document_number ?? ('CxP #'.$row->id),
                $row->supplier?->name ?? 'Proveedor',
                $row->status,
                $row->due_date?->toDateString(),
                $row->original_base_amount,
                $row->paid_base_amount,
                $row->balance_base_amount,
                'USD',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cxp-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    private function pdfResponse($rows): Response
    {
        $html = view('reports.cxp-report', ['rows' => $rows, 'generatedAt' => now()])->render();
        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cxp-'.now()->format('Ymd-His').'.pdf"',
        ]);
    }

    public function show(AccountsPayable $accountsPayable): AccountsPayableResource
    {
        Gate::authorize('view', $accountsPayable);

        return AccountsPayableResource::make(
            $accountsPayable->load(['supplier', 'purchaseOrder.items.product', 'payments', 'paymentRequests.payment'])
        );
    }

    public function preparePaymentRequest(
        StoreAccountsPayablePaymentRequestRequest $request,
        AccountsPayable $accountsPayable,
        AccountsPayablePaymentRequestService $service,
    ): JsonResponse {
        Gate::authorize('view', $accountsPayable);

        if (! $request->user()->can('accounts_payable.payment_requests.prepare')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return AccountsPayablePaymentRequestResource::make(
            $service->prepare($accountsPayable, $request->user(), $request->validated())
        )
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function pay(
        RegisterAccountsPayablePaymentRequest $request,
        AccountsPayable $accountsPayable,
        AccountsPayableService $service,
    ): JsonResponse {
        Gate::authorize('pay', $accountsPayable);

        $data = $request->validated();

        if (($data['method'] ?? null) === CashRegisterMovement::METHOD_CASH && ! empty($data['cash_register_session_id'])) {
            Gate::authorize('move', CashRegisterSession::query()->findOrFail($data['cash_register_session_id']));
        }

        $payment = $service->registerPayment($accountsPayable, $request->user(), $data);

        return AccountsPayablePaymentResource::make($payment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
