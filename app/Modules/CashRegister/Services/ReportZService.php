<?php

namespace App\Modules\CashRegister\Services;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\SalesReversals\Models\SaleReversal;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\CompanySettings;
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

        if ($session->cash_register_id !== null) {
            CashRegister::withoutGlobalScopes()
                ->where('tenant_id', $session->tenant_id)
                ->whereKey($session->cash_register_id)
                ->lockForUpdate()
                ->firstOrFail();
        } else {
            Tenant::query()
                ->withoutGlobalScopes()
                ->whereKey($session->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();
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
        $session->loadMissing([
            'branch',
            'cashRegister',
            'cashier',
            'counts',
            'posOrders.payments.paymentMethod',
            'posOrders.customer',
            'posOrders.sale.items.product.categories',
        ]);

        $paidOrders = $session->posOrders->whereIn('status', [PosOrder::STATUS_PAID, PosOrder::STATUS_VOIDED]);
        $payments = $paidOrders->flatMap->payments->where('status', PosPayment::STATUS_CAPTURED);
        $reversals = SaleReversal::query()
            ->where('cash_register_session_id', $session->id)
            ->get();

        $categoryAggregates = [];
        $customerAggregates = [];

        foreach ($paidOrders as $order) {
            $customerId = $order->customer_id;
            $customerName = $order->customer?->name ?? $order->customer_name ?? 'Consumidor Final';
            $customerDoc = $order->customer
                ? trim(($order->customer->document_type ?? '').'-'.($order->customer->document_number ?? ''), '-')
                : null;
            if ($customerDoc === '') {
                $customerDoc = null;
            }
            $customerKey = $customerId ? 'id:'.$customerId : 'name:'.mb_strtolower($customerName);

            if (! isset($customerAggregates[$customerKey])) {
                $customerAggregates[$customerKey] = [
                    'id' => $customerId,
                    'name' => $customerName,
                    'document' => $customerDoc,
                    'orders_count' => 0,
                    'amount_base' => 0.0,
                    'amount_local' => 0.0,
                ];
            }
            $customerAggregates[$customerKey]['orders_count']++;
            $customerAggregates[$customerKey]['amount_base'] += (float) $order->paid_base_amount;
            $customerAggregates[$customerKey]['amount_local'] += (float) $order->paid_local_amount;

            $orderExchangeRate = (float) ($order->payments->where('exchange_rate', '>', 0)->first()?->exchange_rate ?? 0);
            $items = $order->sale?->items ?? collect();

            foreach ($items as $item) {
                $qty = (float) $item->quantity;
                $amountBase = (float) $item->base_total_amount;
                $itemRate = (float) ($item->exchange_rate ?: $orderExchangeRate);
                $amountLocal = $item->sale_currency === 'VES'
                    ? (float) $item->total_amount
                    : round($amountBase * $itemRate, 4);

                $product = $item->product;
                $categories = $product?->categories;

                $prodId = $product?->id;
                $prodKey = $prodId ? (string) $prodId : 'item:'.$item->id;
                $prodName = $product?->name ?? 'Producto #'.$item->product_id;
                $prodSku = $product?->sku;

                $targetCategories = ($categories && $categories->isNotEmpty())
                    ? $categories
                    : collect([null]);

                foreach ($targetCategories as $category) {
                    $catId = $category?->id;
                    $catName = $category?->name ?? 'Sin categoría';
                    $catKey = $catId !== null ? 'id:'.$catId : 'none';

                    if (! isset($categoryAggregates[$catKey])) {
                        $categoryAggregates[$catKey] = [
                            'id' => $catId,
                            'name' => $catName,
                            'items_count' => 0.0,
                            'amount_base' => 0.0,
                            'amount_local' => 0.0,
                            'products' => [],
                        ];
                    }

                    $categoryAggregates[$catKey]['items_count'] += $qty;
                    $categoryAggregates[$catKey]['amount_base'] += $amountBase;
                    $categoryAggregates[$catKey]['amount_local'] += $amountLocal;

                    if (! isset($categoryAggregates[$catKey]['products'][$prodKey])) {
                        $categoryAggregates[$catKey]['products'][$prodKey] = [
                            'id' => $prodId,
                            'name' => $prodName,
                            'sku' => $prodSku,
                            'quantity' => 0.0,
                            'amount_base' => 0.0,
                            'amount_local' => 0.0,
                        ];
                    }
                    $categoryAggregates[$catKey]['products'][$prodKey]['quantity'] += $qty;
                    $categoryAggregates[$catKey]['products'][$prodKey]['amount_base'] += $amountBase;
                    $categoryAggregates[$catKey]['products'][$prodKey]['amount_local'] += $amountLocal;
                }
            }
        }

        $categories = collect($categoryAggregates)
            ->map(fn ($cat) => [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'items_count' => round((float) $cat['items_count'], 4),
                'amount_base' => round((float) $cat['amount_base'], 4),
                'amount_local' => round((float) $cat['amount_local'], 4),
                'products' => collect($cat['products'])
                    ->map(fn ($prod) => [
                        'id' => $prod['id'],
                        'name' => $prod['name'],
                        'sku' => $prod['sku'],
                        'quantity' => round((float) $prod['quantity'], 4),
                        'amount_base' => round((float) $prod['amount_base'], 4),
                        'amount_local' => round((float) $prod['amount_local'], 4),
                    ])
                    ->sortByDesc('amount_base')
                    ->values()
                    ->all(),
            ])
            ->sortByDesc('amount_base')
            ->values()
            ->all();

        $customers = collect($customerAggregates)
            ->map(fn ($cust) => [
                'id' => $cust['id'],
                'name' => $cust['name'],
                'document' => $cust['document'],
                'orders_count' => (int) $cust['orders_count'],
                'amount_base' => round((float) $cust['amount_base'], 4),
                'amount_local' => round((float) $cust['amount_local'], 4),
            ])
            ->sortByDesc('amount_base')
            ->values()
            ->all();

        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($session->tenant_id)->first();
        $company = $tenant ? CompanySettings::getForTenant($tenant) : CompanySettings::defaults();

        return [
            'z_number' => $session->z_number,
            'emitted_at' => $session->z_emitted_at?->toISOString(),
            'status' => $session->status,
            'tenant' => [
                'name' => $tenant?->name ?? '',
                'slug' => $tenant?->slug ?? '',
                'company' => $company,
                'show_company' => (bool) ($company['show_on']['report_z'] ?? false),
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
                'reversals_count' => $reversals->count(),
                'reversed_base_amount' => round((float) $reversals->sum('reversed_base_amount'), 4),
                'reversed_local_amount' => round((float) $reversals->sum('reversed_local_amount'), 4),
                'net_paid_base_amount' => round(
                    (float) $paidOrders->sum('paid_base_amount') - (float) $reversals->sum('reversed_base_amount'),
                    4
                ),
                'net_paid_local_amount' => round(
                    (float) $paidOrders->sum('paid_local_amount') - (float) $reversals->sum('reversed_local_amount'),
                    4
                ),
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
            'categories' => $categories,
            'customers' => $customers,
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
