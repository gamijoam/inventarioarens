<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\AlertHistory;
use Illuminate\Support\Facades\DB;

class AlertHistoryService
{
    /**
     * Registra una alerta detectada. Si ya existe una alerta del mismo tipo
     * para el mismo subject en las ultimas 24h, no la duplica.
     */
    public function record(
        string $alertType,
        string $title,
        string $message,
        ?string $subjectType = null,
        ?int $subjectId = null,
        string $severity = AlertHistory::SEVERITY_INFO,
        array $payload = []
    ): ?AlertHistory {
        if ($subjectType !== null && $subjectId !== null) {
            $exists = AlertHistory::query()
                ->where('alert_type', $alertType)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('detected_at', '>=', now()->subDay())
                ->exists();

            if ($exists) {
                return null;
            }
        }

        return AlertHistory::create([
            'alert_type' => $alertType,
            'severity' => $severity,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'title' => $title,
            'message' => $message,
            'payload' => $payload,
            'detected_at' => now(),
        ]);
    }

    /**
     * Snapshot de las alertas activas del tenant. Llamar periodicamente (cron o job).
     */
    public function snapshotAlerts(int $tenantId, float $fallbackThreshold = 3): int
    {
        $created = 0;

        $rows = DB::table('products')
            ->leftJoin('stock_balances', function ($join) use ($tenantId) {
                $join->on('stock_balances.product_id', '=', 'products.id')
                    ->where('stock_balances.tenant_id', '=', $tenantId);
            })
            ->where('products.tenant_id', $tenantId)
            ->where('products.is_active', true)
            ->where('products.track_stock', true)
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.min_stock')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'products.min_stock',
                DB::raw('COALESCE(SUM(stock_balances.quantity_available), 0) as available')
            )
            ->get();

        foreach ($rows as $row) {
            $available = (float) $row->available;
            $min = $row->min_stock !== null ? (float) $row->min_stock : $fallbackThreshold;

            $severity = null;
            $type = null;
            if ($available <= 0) {
                $severity = AlertHistory::SEVERITY_DANGER;
                $type = 'product.out_of_stock';
            } elseif ($min > 0 && $available <= $min) {
                $severity = $available <= $min / 2 ? AlertHistory::SEVERITY_DANGER : AlertHistory::SEVERITY_WARNING;
                $type = 'product.low_stock';
            }

            if ($type !== null) {
                $result = $this->record(
                    alertType: $type,
                    title: $type === 'product.out_of_stock' ? 'Sin stock' : 'Stock bajo',
                    message: "{$row->name} ({$row->sku}) tiene {$available} unidades disponibles (minimo {$min}).",
                    subjectType: 'product',
                    subjectId: (int) $row->id,
                    severity: $severity,
                    payload: ['available' => $available, 'min_stock' => $min],
                );
                if ($result !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }
}
