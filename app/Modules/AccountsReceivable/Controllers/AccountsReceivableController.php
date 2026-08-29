<?php

namespace App\Modules\AccountsReceivable\Controllers;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Requests\RegisterAccountsReceivablePaymentRequest;
use App\Modules\AccountsReceivable\Resources\AccountsReceivablePaymentResource;
use App\Modules\AccountsReceivable\Resources\AccountsReceivableResource;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AccountsReceivableController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsReceivable::class);

        $filters = $this->validateFilters($request);
        $limit = (int) ($filters['limit'] ?? 25);
        $query = $this->query($request, $filters);

        return AccountsReceivableResource::collection($query->paginate($limit));
    }

    public function export(Request $request): Response
    {
        Gate::authorize('viewAny', AccountsReceivable::class);

        $filters = $this->validateFilters($request);
        $format = strtolower((string) $request->query('format', 'csv'));
        $rows = $this->query($request, $filters)->get();

        if ($format === 'pdf') {
            return $this->pdfResponse($rows);
        }

        return $this->csvResponse($rows);
    }

    public function show(AccountsReceivable $accountsReceivable): AccountsReceivableResource
    {
        Gate::authorize('view', $accountsReceivable);

        return AccountsReceivableResource::make(
            $accountsReceivable->load(['customer', 'sale', 'payments', 'sale.items'])
        );
    }

    public function collect(
        RegisterAccountsReceivablePaymentRequest $request,
        AccountsReceivable $accountsReceivable,
        AccountsReceivableService $service,
    ): AccountsReceivablePaymentResource {
        Gate::authorize('collect', $accountsReceivable);

        return AccountsReceivablePaymentResource::make(
            $service->registerPayment(
                $accountsReceivable,
                $request->user(),
                $request->validated()
            )
        );
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120', 'regex:/^[a-z,]+$/'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * Normaliza el filtro status (multi-seleccion separada por comas).
     * 'all' => null (sin filtro). 'open' => pending+partial+overdue.
     */
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
                AccountsReceivable::STATUS_PENDING,
                AccountsReceivable::STATUS_PARTIAL,
                AccountsReceivable::STATUS_OVERDUE,
            ])));
        }

        return $parts === [] ? null : $parts;
    }

    private function query(Request $request, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = AccountsReceivable::query()
            ->with(['customer', 'sale'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = "%{$search}%";
                $query->where(function (Builder $innerQuery) use ($like): void {
                    $innerQuery
                        ->whereRaw('LOWER(COALESCE(document_number, \'\')) LIKE LOWER(?)', [$like])
                        ->orWhereHas('customer', function ($customerQuery) use ($like): void {
                            $customerQuery
                                ->whereRaw('LOWER(COALESCE(name, \'\')) LIKE LOWER(?)', [$like])
                                ->orWhereRaw('LOWER(COALESCE(document_number, \'\')) LIKE LOWER(?)', [$like]);
                        })
                        ->orWhereHas('sale', function ($saleQuery) use ($like): void {
                            $saleQuery->whereRaw('LOWER(COALESCE(document_number, \'\')) LIKE LOWER(?)', [$like]);
                        });
                });
            })
            ->when($statuses = $this->statusFilter($filters['status'] ?? null),
                fn ($query, array $statuses) => $query->whereIn('status', $statuses))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['due_from'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->latest();

        // Scope por customer_group: filtrar CxC cuyos clientes pertenezcan a los grupos del user.
        $groupIds = $this->scopes->customerGroupIdsFor($request->user());
        if ($groupIds !== null) {
            $query->whereIn('customer_id', function ($sub) use ($groupIds): void {
                $sub->select('id')->from('customers')
                    ->whereIn('customer_group_id', $groupIds);
            });
        }

        return $query;
    }

    private function csvResponse($rows): Response
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Documento', 'Cliente', 'Estado', 'Vence', 'Original', 'Cobrado', 'Saldo', 'Moneda']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row->document_number ?? ('CxC #'.$row->id),
                $row->customer?->name ?? 'Consumidor Final',
                $row->status,
                $row->due_date?->toDateString(),
                $row->original_base_amount,
                $row->collected_base_amount,
                $row->balance_base_amount,
                'USD',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cxc-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    private function pdfResponse($rows): Response
    {
        $html = view('reports.cxc-report', ['rows' => $rows, 'generatedAt' => now()])->render();
        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cxc-'.now()->format('Ymd-His').'.pdf"',
        ]);
    }
}
