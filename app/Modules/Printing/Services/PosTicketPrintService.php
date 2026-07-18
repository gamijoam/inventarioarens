<?php

namespace App\Modules\Printing\Services;

use App\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Printing\Models\PrinterStation;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintProfile;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

class PosTicketPrintService
{
    public function createJobs(PosOrder $order, User $user, array $data): array
    {
        $order = $this->loadOrder($order);
        $station = $this->resolveStation($order, $data['printer_station_id'] ?? null);
        $profile = $station?->profile ?? $this->defaultProfile();
        $output = $data['output'] ?? ($station?->output_mode ?? PrinterStation::OUTPUT_DIGITAL);
        $outputs = $output === PrinterStation::OUTPUT_BOTH
            ? [PrintJob::OUTPUT_THERMAL, PrintJob::OUTPUT_DIGITAL]
            : [$output];
        $snapshot = $this->snapshot($order, $profile, (bool) ($data['copy'] ?? false));

        return collect($outputs)
            ->map(fn (string $target): PrintJob => PrintJob::create([
                'printer_station_id' => $station?->id,
                'print_profile_id' => $profile->id,
                'source_type' => PosOrder::class,
                'source_id' => $order->id,
                'pos_order_id' => $order->id,
                'sale_id' => $order->sale_id,
                'cash_register_session_id' => $order->cash_register_session_id,
                'requested_by' => $user->id,
                'output' => $target,
                'status' => PrintJob::STATUS_CREATED,
                'is_copy' => (bool) ($data['copy'] ?? false),
                'payload_snapshot' => $snapshot + ['output' => $target],
            ]))
            ->all();
    }

    public function renderHtml(PrintJob $job): string
    {
        return View::make('printing.pos-ticket', [
            'job' => $job->loadMissing(['profile', 'station']),
            'ticket' => $job->payload_snapshot,
        ])->render();
    }

    public function renderPdf(PrintJob $job): string
    {
        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($this->renderHtml($job));
        $widthPoints = ((int) data_get($job->payload_snapshot, 'profile.paper_width_mm', 80)) === 58 ? 164.4 : 226.8;
        $dompdf->setPaper([0, 0, $widthPoints, 900], 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function markStatus(PrintJob $job, array $data): PrintJob
    {
        $status = $data['status'];
        $updates = [
            'status' => $status,
            'last_error' => $status === PrintJob::STATUS_FAILED ? ($data['message'] ?? 'Error de impresion.') : null,
            'digital_pdf_path' => $data['digital_pdf_path'] ?? $job->digital_pdf_path,
            'digital_html_path' => $data['digital_html_path'] ?? $job->digital_html_path,
        ];

        if ($status === PrintJob::STATUS_SENT) {
            $updates['sent_at'] = now();
            $updates['attempts'] = $job->attempts + 1;
        }

        if ($status === PrintJob::STATUS_PRINTED) {
            $updates['printed_at'] = now();
        }

        if ($status === PrintJob::STATUS_GENERATED) {
            $updates['generated_at'] = now();
        }

        $job->update($updates);

        return $job->refresh()->load(['station.profile', 'profile']);
    }

    private function loadOrder(PosOrder $order): PosOrder
    {
        return PosOrder::query()
            ->with([
                'cashier',
                'customer',
                'cashRegisterSession.branch',
                'cashRegisterSession.cashRegister',
                'sale.customer',
                'sale.receivable',
                'sale.items.product',
                'sale.items.warehouse',
                'payments.paymentMethod',
            ])
            ->findOrFail($order->id);
    }

    private function resolveStation(PosOrder $order, ?int $stationId): ?PrinterStation
    {
        if ($stationId) {
            return PrinterStation::query()
                ->with('profile')
                ->where('is_active', true)
                ->findOrFail($stationId);
        }

        $session = $order->cashRegisterSession;

        return PrinterStation::query()
            ->with('profile')
            ->where('is_active', true)
            ->when($session?->cash_register_id, fn ($query) => $query->where('cash_register_id', $session->cash_register_id))
            ->when(! $session?->cash_register_id && $session?->branch_id, fn ($query) => $query->where('branch_id', $session->branch_id))
            ->orderByDesc('cash_register_id')
            ->orderBy('name')
            ->first();
    }

    private function defaultProfile(): PrintProfile
    {
        $profile = PrintProfile::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($profile) {
            return $profile;
        }

        return PrintProfile::create([
            'name' => 'POS 80mm',
            'paper_width_mm' => PrintProfile::WIDTH_80,
            'characters_per_line' => 48,
            'header_text' => 'Sistema de Inventario',
            'footer_text' => 'Gracias por su compra.',
            'show_warranty_summary' => true,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function snapshot(PosOrder $order, PrintProfile $profile, bool $copy): array
    {
        if ($order->status !== PosOrder::STATUS_PAID) {
            throw ValidationException::withMessages([
                'pos_order' => 'Solo se pueden imprimir tickets de ordenes POS pagadas.',
            ]);
        }

        $tenant = app(TenantManager::class)->require();
        $sale = $order->sale;
        $items = $sale?->items ?? collect();
        $payments = $order->payments;

        return [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'profile' => [
                'paper_width_mm' => (int) $profile->paper_width_mm,
                'characters_per_line' => (int) $profile->characters_per_line,
                'header_text' => $profile->header_text,
                'footer_text' => $profile->footer_text,
                'warranty_policy_text' => $profile->warranty_policy_text,
                'legal_text' => $profile->legal_text ?: 'Documento no fiscal',
                'logo_text' => $profile->logo_text,
                'show_tenant_slug' => (bool) $profile->show_tenant_slug,
                'show_sale_number' => (bool) $profile->show_sale_number,
                'show_paid_at' => (bool) $profile->show_paid_at,
                'show_cashier' => (bool) $profile->show_cashier,
                'show_cash_register' => (bool) $profile->show_cash_register,
                'show_branch' => (bool) $profile->show_branch,
                'show_customer' => (bool) $profile->show_customer,
                'show_item_sku' => (bool) $profile->show_item_sku,
                'show_item_discount' => (bool) $profile->show_item_discount,
                'show_item_serials' => (bool) $profile->show_item_serials,
                'show_warranty_summary' => (bool) $profile->show_warranty_summary,
                'show_total_local' => (bool) $profile->show_total_local,
                'show_payment_rate' => (bool) $profile->show_payment_rate,
                'show_payment_reference' => (bool) $profile->show_payment_reference,
                'show_receivable_balance' => (bool) $profile->show_receivable_balance,
                'show_non_fiscal_text' => (bool) $profile->show_non_fiscal_text,
                'cut_paper' => (bool) $profile->cut_paper,
                'open_cash_drawer' => (bool) $profile->open_cash_drawer,
                'copies' => (int) $profile->copies,
            ],
            'copy' => $copy,
            'pos_order' => [
                'id' => $order->id,
                'sale_id' => $order->sale_id,
                'status' => $order->status,
                'paid_at' => $order->paid_at?->toISOString(),
                'customer_name' => $order->customer?->name ?? $order->customer_name ?? 'Consumidor Final',
                'cashier_name' => $order->cashier?->name,
                'branch_name' => $order->cashRegisterSession?->branch?->name,
                'cash_register_name' => $order->cashRegisterSession?->cashRegister?->name,
            ],
            'totals' => [
                'total_base_amount' => (float) $order->total_base_amount,
                'total_local_amount' => (float) $order->total_local_amount,
                'paid_base_amount' => (float) $order->paid_base_amount,
                'paid_local_amount' => (float) $order->paid_local_amount,
                'balance_base_amount' => (float) ($sale?->receivable?->balance_base_amount ?? 0),
            ],
            'items' => $items->map(fn ($item): array => [
                'product_name' => $item->product?->name ?? 'Producto',
                'sku' => $item->product?->sku,
                'warehouse_name' => $item->warehouse?->name,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->base_unit_price,
                'total' => (float) $item->base_total_amount,
                'discount' => (float) $item->discount_base_amount,
                'serials' => $this->serialUnits($item->product_unit_ids ?? []),
                'warranty' => [
                    'name' => $item->warranty_policy_name,
                    'duration_days' => $item->warranty_duration_days,
                    'expires_at' => $item->warranty_expires_at?->toDateString(),
                    'coverage_type' => $item->warranty_coverage_type,
                ],
            ])->values()->all(),
            'payments' => $payments->map(fn ($payment): array => [
                'method' => $payment->paymentMethod?->name ?? $payment->method,
                'currency' => $payment->currency,
                'amount' => (float) $payment->amount,
                'amount_base' => (float) $payment->amount_base,
                'amount_local' => (float) $payment->amount_local,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate ? (float) $payment->exchange_rate : null,
                'reference' => $payment->reference,
            ])->values()->all(),
        ];
    }

    private function serialUnits(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->get()
            ->sortBy(fn (ProductUnit $unit): int => array_search($unit->id, $unitIds, true))
            ->map(fn (ProductUnit $unit): array => [
                'id' => $unit->id,
                'serial_type' => $unit->serial_type,
                'serial_number' => $unit->serial_number,
                'status' => $unit->status,
            ])
            ->values()
            ->all();
    }
}
