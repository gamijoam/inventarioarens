<?php

namespace App\Modules\InventoryTransfers\Services;

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Support\Facades\View;

/**
 * Genera la guia de traslado en HTML o PDF.
 *
 * - PDF: usa barryvdh/laravel-dompdf (instalado via composer).
 * - HTML: fallback para entornos sin composer; el browser puede hacer
 *   Ctrl+P para guardar como PDF.
 *
 * La guia contiene: header del transfer, datos del transportista (si
 * existe), lista de items con IMEIs/seriales, totales y espacio para
 * firmas (driver + receptor) cuando hay un driver asignado.
 */
class TransferGuidePdfService
{
    /**
     * Renderiza la guia como HTML (string).
     *
     * NOTA: el transfer se pasa como parametro (no en el constructor)
     * para evitar que Laravel autowire cree una instancia vacia del
     * modelo que interfiera con el route binding del controller.
     */
    public function renderHtml(InventoryTransfer $transfer): string
    {
        return View::make('inventory_transfers.guide', [
            'transfer' => $transfer->loadMissing([
                'fromWarehouse',
                'toWarehouse',
                'items.product',
                'guide.checklists.items',
                'driver',
            ]),
            'generatedAt' => now(),
        ])->render();
    }

    /**
     * Renderiza la guia como PDF (string de bytes).
     */
    public function renderPdf(InventoryTransfer $transfer): string
    {
        $html = $this->renderHtml($transfer);

        /** @var ServiceProvider $dompdf */
        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
