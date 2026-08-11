<?php

namespace App\Modules\TelegramBot\Services;

use App\Modules\Dashboard\Services\DashboardSummaryService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;

/**
 * Genera el texto de resumen de una empresa para el bot de Telegram.
 * Setea el tenant actual para que los servicios scoped lo usen.
 */
class TelegramReportService
{
    public function __construct(
        private readonly DashboardSummaryService $dashboard,
        private readonly TenantManager $tenants,
    ) {}

    public function summaryText(Tenant $tenant): string
    {
        $previous = $this->tenants->current();
        $this->tenants->set($tenant);

        try {
            $summary = $this->dashboard->summary(['period' => 'today']);
        } finally {
            if ($previous) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->clear();
            }
        }

        $sales = $summary['sales'];
        $pos = $summary['pos'];
        $inv = $summary['inventory'];
        $fin = $summary['finance'];

        $lines = [
            "📊 <b>{$tenant->name}</b>",
            '',
            "Ventas hoy: <b>{$sales['confirmed_count']}</b> — \$".number_format($sales['total_base_amount'], 2),
            "POS pagado: <b>{$pos['paid_orders_count']}</b> — \$".number_format($pos['paid_base_amount'], 2),
            "Cajas abiertas: <b>{$summary['cash_register']['open_sessions_count']}</b>",
            '',
            "⚠️ Stock bajo: <b>{$inv['low_stock_count']}</b>",
            ...$this->lowStockLines($inv['low_stock_items']),
            '',
            'CxC pendiente: $'.number_format($fin['accounts_receivable_balance_base_amount'], 2),
            'CxP pendiente: $'.number_format($fin['accounts_payable_balance_base_amount'], 2),
        ];

        return implode("\n", $lines);
    }

    private function lowStockLines(array $items): array
    {
        return array_map(
            fn (array $item): string => sprintf(
                '  • %s (%s) — %s',
                $item['product_name'] ?? 'Producto',
                $item['sku'] ?? 'sin SKU',
                number_format((float) $item['quantity_available'], 2),
            ),
            array_slice($items, 0, 5),
        );
    }
}
