<?php

namespace App\Modules\CashRegister\Services;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Tenancy\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el Reporte Z de una caja: documento fiscal de cierre con
 * consecutivo por caja, totales y desglose por metodo de pago.
 */
class ReportZService
{
    public function assignZNumber(CashRegisterSession $session): void
    {
        if ($session->z_number !== null) {
            return;
        }

        $next = (int) CashRegisterSession::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('cash_register_id', $session->cash_register_id)
            ->max('z_number');

        $session->forceFill([
            'z_number' => $next + 1,
            'z_emitted_at' => now(),
        ])->save();
    }

    public function build(CashRegisterSession $session): array
    {
        $session->loadMissing(['branch', 'cashRegister', 'cashier', 'counts', 'posOrders.payments.paymentMethod']);

        $paidOrders = $session->posOrders->where('status', PosOrder::STATUS_PAID);
        $payments = $paidOrders->flatMap->payments->where('status', PosPayment::STATUS_CAPTURED);

        return [
            'z_number' => $session->z_number,
            'emitted_at' => $session->z_emitted_at?->toISOString(),
            'status' => $session->status,
            'tenant' => [
                'name' => Tenant::query()->withoutGlobalScopes()->whereKey($session->tenant_id)->value('name') ?? '',
                'slug' => Tenant::query()->withoutGlobalScopes()->whereKey($session->tenant_id)->value('slug') ?? '',
            ],
            'branch' => $session->branch?->name,
            'cash_register' => $session->cashRegister?->name,
            'cashier' => $session->cashier?->name,
            'opened_at' => $session->opened_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'totals' => [
                'orders_count' => $paidOrders->count(),
                'paid_base_amount' => round((float) $paidOrders->sum('paid_base_amount'), 4),
                'paid_local_amount' => round((float) $paidOrders->sum('paid_local_amount'), 4),
                'expected_base_amount' => round((float) ($session->expected_base_amount ?? 0), 4),
                'expected_local_amount' => round((float) ($session->expected_local_amount ?? 0), 4),
                'counted_base_amount' => round((float) ($session->counted_base_amount ?? 0), 4),
                'counted_local_amount' => round((float) ($session->counted_local_amount ?? 0), 4),
                'difference_base_amount' => round((float) ($session->difference_base_amount ?? 0), 4),
                'difference_local_amount' => round((float) ($session->difference_local_amount ?? 0), 4),
                'difference_cash_usd' => round((float) ($session->difference_cash_usd ?? 0), 4),
                'difference_cash_ves' => round((float) ($session->difference_cash_ves ?? 0), 4),
            ],
            'payments' => $payments
                ->groupBy(fn (PosPayment $payment): string => ($payment->paymentMethod?->name ?? $payment->method).'|'.$payment->currency)
                ->map(fn ($group) => [
                    'name' => $group->first()->paymentMethod?->name ?? $group->first()->method,
                    'method' => $group->first()->method,
                    'currency' => $group->first()->currency,
                    'payments_count' => $group->count(),
                    'amount_base' => round((float) $group->sum('amount_base'), 4),
                    'amount_local' => round((float) $group->sum('amount_local'), 4),
                    'exchange_rate' => $group->first()->exchange_rate ? round((float) $group->first()->exchange_rate, 6) : null,
                ])
                ->values()
                ->all(),
            'counts' => $session->counts->map(fn ($count) => [
                'currency' => $count->currency,
                'denomination' => $count->denomination,
                'quantity' => $count->quantity,
                'total_amount' => round((float) $count->total_amount, 4),
            ])->values()->all(),
        ];
    }

    public function renderHtml(CashRegisterSession $session): string
    {
        $z = $this->build($session);
        $profile = $this->profile($session);

        return view('printing.report-z-ticket', [
            'z' => $z,
            'profile' => [
                'paper_width_mm' => $profile?->paper_width_mm ?? PrintProfile::WIDTH_58,
                'logo_text' => $profile?->logo_text,
                'header_text' => $profile?->header_text,
                'footer_text' => $profile?->footer_text,
                'legal_text' => $profile?->legal_text ?? 'Documento no fiscal',
                'show_non_fiscal_text' => $profile?->show_non_fiscal_text ?? true,
            ],
        ])->render();
    }

    public function renderPdf(CashRegisterSession $session): string
    {
        $html = $this->renderHtml($session);
        $width = (int) ($this->profile($session)?->paper_width_mm ?? PrintProfile::WIDTH_58);
        $widthPoints = $width === 58 ? 164.4 : 226.8;

        $dompdf = Pdf::getDomPdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, $widthPoints, 900], 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function profile(CashRegisterSession $session): ?PrintProfile
    {
        return PrintProfile::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
