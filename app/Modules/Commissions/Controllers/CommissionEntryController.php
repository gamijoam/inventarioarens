<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Resources\CommissionEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CommissionEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('commissions.view_all'), Response::HTTP_FORBIDDEN);

        return $this->response($request, CommissionEntry::query());
    }

    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('commissions.view_own'), Response::HTTP_FORBIDDEN);

        return $this->response(
            $request,
            CommissionEntry::query()->where('beneficiary_user_id', $request->user()->id)
        );
    }

    private function response(Request $request, Builder $query): JsonResponse
    {
        CommissionEntry::query()
            ->where('status', CommissionEntry::STATUS_PENDING)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->update(['status' => CommissionEntry::STATUS_AVAILABLE, 'updated_at' => now()]);

        $query
            ->with('beneficiary')
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn (Builder $query) => $query->where('beneficiary_user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('earned_at', '>=', $request->string('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('earned_at', '<=', $request->string('to')));

        $entries = $query->latest('earned_at')->latest('id')->get();
        $total = round((float) $entries->sum('commission_base_amount'), 4);
        $available = round((float) $entries->where('status', CommissionEntry::STATUS_AVAILABLE)->sum('commission_base_amount'), 4);
        $pending = round((float) $entries->where('status', CommissionEntry::STATUS_PENDING)->sum('commission_base_amount'), 4);
        $approved = round((float) $entries->where('status', CommissionEntry::STATUS_APPROVED)->sum('commission_base_amount'), 4);
        $paid = round((float) $entries->where('status', CommissionEntry::STATUS_PAID)->sum('commission_base_amount'), 4);

        return response()->json([
            'data' => CommissionEntryResource::collection($entries)->resolve($request),
            'summary' => [
                'total_base_amount' => number_format($total, 4, '.', ''),
                'available_base_amount' => number_format($available, 4, '.', ''),
                'pending_base_amount' => number_format($pending, 4, '.', ''),
                'approved_base_amount' => number_format($approved, 4, '.', ''),
                'paid_base_amount' => number_format($paid, 4, '.', ''),
                ...$this->paymentSummary($entries),
            ],
        ]);
    }

    private function paymentSummary($entries): array
    {
        $totals = [
            'total_usd' => 0.0,
            'total_ves' => 0.0,
            'available_usd' => 0.0,
            'available_ves' => 0.0,
            'approved_usd' => 0.0,
            'approved_ves' => 0.0,
            'paid_usd' => 0.0,
            'paid_ves' => 0.0,
        ];
        $payables = [];

        foreach ($entries as $entry) {
            $amounts = $this->entryCurrencyAmounts($entry);
            $totals['total_usd'] += $amounts['usd'];
            $totals['total_ves'] += $amounts['ves'];

            foreach (['available', 'approved', 'paid'] as $status) {
                if ($entry->status === $status) {
                    $totals[$status.'_usd'] += $amounts['usd'];
                    $totals[$status.'_ves'] += $amounts['ves'];
                }
            }

            $userId = (int) $entry->beneficiary_user_id;
            $payables[$userId] ??= [
                'user_id' => $userId,
                'name' => $entry->beneficiary?->name ?? 'Sin beneficiario',
                'email' => $entry->beneficiary?->email,
                'available_usd' => 0.0,
                'available_ves' => 0.0,
                'approved_usd' => 0.0,
                'approved_ves' => 0.0,
                'paid_usd' => 0.0,
                'paid_ves' => 0.0,
                'total_usd' => 0.0,
                'total_ves' => 0.0,
            ];
            $payables[$userId]['total_usd'] += $amounts['usd'];
            $payables[$userId]['total_ves'] += $amounts['ves'];

            if (in_array($entry->status, ['available', 'approved', 'paid'], true)) {
                $payables[$userId][$entry->status.'_usd'] += $amounts['usd'];
                $payables[$userId][$entry->status.'_ves'] += $amounts['ves'];
            }
        }

        return [
            'currency_breakdown' => collect($totals)->map(fn (float $amount): string => number_format($amount, 4, '.', ''))->all(),
            'payables' => collect($payables)->map(function (array $payable): array {
                return collect($payable)->map(function ($value, string $key) {
                    return is_float($value) ? number_format($value, 4, '.', '') : $value;
                })->all();
            })->values()->all(),
        ];
    }

    private function entryCurrencyAmounts(CommissionEntry $entry): array
    {
        $baseAmount = (float) $entry->commission_base_amount;

        return $entry->sale_currency === 'VES' && (float) $entry->exchange_rate > 0
            ? ['usd' => 0.0, 'ves' => $baseAmount * (float) $entry->exchange_rate]
            : ['usd' => $baseAmount, 'ves' => 0.0];
    }
}
