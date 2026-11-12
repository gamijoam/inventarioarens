<?php

namespace App\Modules\Currency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Currency\Models\ExchangeDifference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeDifferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('finance_reports.view'), 403);

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'account_type' => ['nullable', Rule::in([ExchangeDifference::ACCOUNT_RECEIVABLE, ExchangeDifference::ACCOUNT_PAYABLE])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ExchangeDifference::query()
            ->when($filters['date_from'] ?? null, fn ($query, $from) => $query->where('occurred_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($query, $to) => $query->where('occurred_at', '<=', $to.' 23:59:59'))
            ->when($filters['account_type'] ?? null, fn ($query, $type) => $query->where('account_type', $type));

        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(CASE WHEN difference_local_amount > 0 THEN difference_local_amount ELSE 0 END), 0) as gain_local')
            ->selectRaw('COALESCE(SUM(CASE WHEN difference_local_amount < 0 THEN difference_local_amount ELSE 0 END), 0) as loss_local')
            ->selectRaw('COALESCE(SUM(CASE WHEN difference_base_amount > 0 THEN difference_base_amount ELSE 0 END), 0) as gain_base')
            ->selectRaw('COALESCE(SUM(CASE WHEN difference_base_amount < 0 THEN difference_base_amount ELSE 0 END), 0) as loss_base')
            ->first();

        $page = $query->latest('occurred_at')->latest('id')->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'totals' => [
                'gain_local_amount' => round((float) $totals->gain_local, 4),
                'loss_local_amount' => round((float) $totals->loss_local, 4),
                'net_local_amount' => round((float) $totals->gain_local + (float) $totals->loss_local, 4),
                'gain_base_amount' => round((float) $totals->gain_base, 4),
                'loss_base_amount' => round((float) $totals->loss_base, 4),
                'net_base_amount' => round((float) $totals->gain_base + (float) $totals->loss_base, 4),
            ],
        ]);
    }
}
