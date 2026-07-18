<?php

namespace App\Modules\Printing\Controllers;

use App\Modules\Printing\Models\PrinterStation;
use App\Modules\Printing\Requests\StorePrinterStationRequest;
use App\Modules\Printing\Requests\UpdatePrinterStationRequest;
use App\Modules\Printing\Resources\PrinterStationResource;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PrinterStationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('printing.view'), Response::HTTP_FORBIDDEN);

        return PrinterStationResource::collection(
            PrinterStation::query()
                ->with(['profile', 'branch', 'cashRegister'])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StorePrinterStationRequest $request): JsonResponse
    {
        $station = PrinterStation::create($this->normalize($request->validated()))
            ->refresh()
            ->load(['profile', 'branch', 'cashRegister']);

        return PrinterStationResource::make($station)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePrinterStationRequest $request, PrinterStation $printerStation): PrinterStationResource
    {
        $this->ensureTenantResource($printerStation);

        $printerStation->update($this->normalize($request->validated()));

        return PrinterStationResource::make($printerStation->refresh()->load(['profile', 'branch', 'cashRegister']));
    }

    public function destroy(Request $request, PrinterStation $printerStation): Response
    {
        abort_unless($request->user()?->can('printing.manage'), Response::HTTP_FORBIDDEN);
        $this->ensureTenantResource($printerStation);

        $printerStation->update(['is_active' => false]);

        return response()->noContent();
    }

    private function normalize(array $data): array
    {
        if (isset($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }

        return $data;
    }

    private function ensureTenantResource(PrinterStation $station): void
    {
        abort_unless($station->tenant_id === app(TenantManager::class)->require()->id, Response::HTTP_NOT_FOUND);
    }
}
