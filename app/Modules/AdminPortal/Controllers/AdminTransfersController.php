<?php

namespace App\Modules\AdminPortal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AdminPortal\Requests\AdminTransferActionRequest;
use App\Modules\AdminPortal\Requests\AdminTransferListRequest;
use App\Modules\AdminPortal\Services\AdminTransferService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Requests\CancelInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\DispatchInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\PrepareInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\ReceiveInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\ResolveInventoryTransferRequest;
use App\Modules\InventoryTransfers\Resources\InventoryTransferResource;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminTransfersController extends Controller
{
    public function index(AdminTransferListRequest $request, AdminTransferService $transfers): JsonResponse|StreamedResponse
    {
        if ($request->wantsCsvExport()) {
            $export = $transfers->export($request->filters());

            return response()->streamDownload(function () use ($export): void {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $export['headers']);

                foreach ($export['rows'] as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            }, $export['filename'], [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->json([
            'data' => $transfers->index($request->filters()),
        ]);
    }

    public function summary(AdminTransferListRequest $request, AdminTransferService $transfers): JsonResponse
    {
        return response()->json([
            'data' => $transfers->summary($request->filters()),
        ]);
    }

    public function show(AdminTransferActionRequest $request, InventoryTransfer $inventoryTransfer, AdminTransferService $transfers): JsonResponse
    {
        return response()->json([
            'data' => $transfers->detail($inventoryTransfer),
        ]);
    }

    public function prepare(
        PrepareInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        Gate::authorize('prepare', $inventoryTransfer);

        return $this->respondWithTransfer(
            $service->prepare($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function dispatch(
        DispatchInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        Gate::authorize('dispatch', $inventoryTransfer);

        return $this->respondWithTransfer(
            $service->dispatch($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function receive(
        ReceiveInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        Gate::authorize('receive', $inventoryTransfer);

        return $this->respondWithTransfer(
            $service->receive($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function cancel(
        CancelInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        Gate::authorize('cancel', $inventoryTransfer);

        return $this->respondWithTransfer(
            $service->cancel($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function resolveDifferences(
        ResolveInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        Gate::authorize('resolveDifferences', $inventoryTransfer);

        return $this->respondWithTransfer(
            $service->resolveDifferences($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    private function authorizeAdmin($request): void
    {
        if (! $request->user()?->can('inventory_transfers.admin')) {
            abort(403, 'Acceso restringido al portal administrativo de traslados.');
        }
    }

    private function respondWithTransfer(InventoryTransfer $transfer): JsonResponse
    {
        return response()->json([
            'data' => InventoryTransferResource::make(
                $transfer->load(['fromWarehouse', 'toWarehouse', 'guide', 'items.product', 'canceller', 'resolver'])
            ),
        ]);
    }
}
