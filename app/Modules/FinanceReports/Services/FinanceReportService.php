<?php

namespace App\Modules\FinanceReports\Services;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use Illuminate\Database\Eloquent\Builder;

class FinanceReportService
{
    public function summary(array $filters): array
    {
        $receivables = $this->receivableQuery($filters);
        $payables = $this->payableQuery($filters);

        $receivableBalance = $this->sumClone($receivables, 'balance_base_amount');
        $payableBalance = $this->sumClone($payables, 'balance_base_amount');

        return [
            'currency' => 'USD',
            'accounts_receivable' => [
                'total_balance_base_amount' => $receivableBalance,
                'pending_count' => $this->countByStatus($receivables, AccountsReceivable::STATUS_PENDING),
                'partial_count' => $this->countByStatus($receivables, AccountsReceivable::STATUS_PARTIAL),
                'paid_count' => $this->countByStatus($receivables, AccountsReceivable::STATUS_PAID),
                'overdue_count' => $this->countByStatus($receivables, AccountsReceivable::STATUS_OVERDUE),
            ],
            'accounts_payable' => [
                'total_balance_base_amount' => $payableBalance,
                'pending_count' => $this->countByStatus($payables, AccountsPayable::STATUS_PENDING),
                'partial_count' => $this->countByStatus($payables, AccountsPayable::STATUS_PARTIAL),
                'paid_count' => $this->countByStatus($payables, AccountsPayable::STATUS_PAID),
                'overdue_count' => $this->countByStatus($payables, AccountsPayable::STATUS_OVERDUE),
            ],
            'cash_flow' => [
                'collections_base_amount' => $this->collectionsTotal($filters),
                'supplier_payments_base_amount' => $this->supplierPaymentsTotal($filters),
            ],
            'net_balance_base_amount' => round($receivableBalance - $payableBalance, 4),
        ];
    }

    public function receivables(array $filters): array
    {
        return $this->receivableQuery($filters)
            ->with('customer')
            ->latest('id')
            ->get()
            ->map(fn (AccountsReceivable $account): array => [
                'id' => $account->id,
                'customer_id' => $account->customer_id,
                'customer_name' => $account->customer?->name,
                'sale_id' => $account->sale_id,
                'document_number' => $account->document_number,
                'status' => $account->status,
                'original_base_amount' => $account->original_base_amount,
                'returned_base_amount' => $account->returned_base_amount,
                'collected_base_amount' => $account->collected_base_amount,
                'balance_base_amount' => $account->balance_base_amount,
                'due_date' => $account->due_date?->toDateString(),
                'opened_at' => $account->opened_at?->toISOString(),
            ])
            ->all();
    }

    public function payables(array $filters): array
    {
        return $this->payableQuery($filters)
            ->with('supplier')
            ->latest('id')
            ->get()
            ->map(fn (AccountsPayable $account): array => [
                'id' => $account->id,
                'supplier_id' => $account->supplier_id,
                'supplier_name' => $account->supplier?->name,
                'purchase_order_id' => $account->purchase_order_id,
                'document_number' => $account->document_number,
                'status' => $account->status,
                'original_base_amount' => $account->original_base_amount,
                'returned_base_amount' => $account->returned_base_amount,
                'paid_base_amount' => $account->paid_base_amount,
                'balance_base_amount' => $account->balance_base_amount,
                'due_date' => $account->due_date?->toDateString(),
                'opened_at' => $account->opened_at?->toISOString(),
            ])
            ->all();
    }

    private function receivableQuery(array $filters): Builder
    {
        return AccountsReceivable::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('opened_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('opened_at', '<=', $date));
    }

    private function payableQuery(array $filters): Builder
    {
        return AccountsPayable::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, int $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('opened_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('opened_at', '<=', $date));
    }

    private function collectionsTotal(array $filters): float
    {
        return round((float) AccountsReceivablePayment::query()
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->sum('amount_base'), 4);
    }

    private function supplierPaymentsTotal(array $filters): float
    {
        return round((float) AccountsPayablePayment::query()
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->sum('amount_base'), 4);
    }

    private function sumClone(Builder $query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }

    private function countByStatus(Builder $query, string $status): int
    {
        return (clone $query)->where('status', $status)->count();
    }
}
