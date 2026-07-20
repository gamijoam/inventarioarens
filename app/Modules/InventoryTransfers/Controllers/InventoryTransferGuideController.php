<?php

namespace App\Modules\InventoryTransfers\Controllers;

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Services\TransferGuidePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Genera la guia de traslado en PDF o HTML.
 * - GET /api/inventory-transfers/{id}/guide.pdf  -> application/pdf
 * - GET /api/inventory-transfers/{id}/guide.html -> text/html (print friendly)
 *
 * Solo disponible si el transfer esta en un estado post-creacion
 * (prepared, dispatched, completed, etc). No se puede generar guia
 * de un transfer cancelado o que aun no se creo.
 */
class InventoryTransferGuideController extends Controller
{
    public function __construct(
        private readonly TransferGuidePdfService $service,
    ) {
    }

    public function pdf(Request $request, InventoryTransfer $inventoryTransfer): Response
    {
        $this->authorizeAccess($inventoryTransfer);

        $bytes = $this->service->renderPdf($inventoryTransfer);
        $filename = sprintf(
            'guia-%s-%s.pdf',
            $inventoryTransfer->document_number ?? $inventoryTransfer->id,
            now()->format('Ymd-His'),
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    public function html(Request $request, InventoryTransfer $inventoryTransfer): Response
    {
        $this->authorizeAccess($inventoryTransfer);

        $html = $this->service->renderHtml($inventoryTransfer);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function authorizeAccess(InventoryTransfer $transfer): void
    {
        // Multi-tenancy: solo el tenant dueno puede generar la guia.
        $tenantManager = app(\App\Support\Tenancy\TenantManager::class);
        $currentTenantId = $tenantManager->current()?->id;
        abort_unless(
            $currentTenantId !== null && (int) $transfer->tenant_id === (int) $currentTenantId,
            404,
            'Traslado no encontrado.',
        );

        $allowed = [
            InventoryTransfer::STATUS_PREPARED,
            InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
            InventoryTransfer::STATUS_DISPATCHED,
            InventoryTransfer::STATUS_COMPLETED,
            InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES,
        ];

        if (! in_array($transfer->status, $allowed, true)) {
            abort(404, 'No se puede generar la guia en el estado actual del traslado.');
        }
    }
}