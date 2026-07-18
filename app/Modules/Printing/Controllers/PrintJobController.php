<?php

namespace App\Modules\Printing\Controllers;

use App\Modules\POS\Models\PosOrder;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Requests\StorePosPrintJobRequest;
use App\Modules\Printing\Requests\UpdatePrintJobStatusRequest;
use App\Modules\Printing\Resources\PrintJobResource;
use App\Modules\Printing\Services\PosTicketPrintService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PrintJobController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('printing.view'), Response::HTTP_FORBIDDEN);

        return PrintJobResource::collection(
            PrintJob::query()
                ->with(['station.profile', 'profile'])
                ->when($request->integer('pos_order_id'), fn ($query, $id) => $query->where('pos_order_id', $id))
                ->latest()
                ->paginate((int) $request->input('per_page', 25))
        );
    }

    public function storeForPos(StorePosPrintJobRequest $request, PosOrder $posOrder, PosTicketPrintService $service): JsonResponse
    {
        $jobs = $service->createJobs($posOrder, $request->user(), $request->validated());

        return PrintJobResource::collection(collect($jobs)->map->load(['station.profile', 'profile']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function html(Request $request, PrintJob $printJob, PosTicketPrintService $service): Response
    {
        abort_unless($request->user()?->can('printing.view') || $request->user()?->can('printing.print'), Response::HTTP_FORBIDDEN);
        $this->ensureTenantResource($printJob);

        return response($service->renderHtml($printJob), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function pdf(Request $request, PrintJob $printJob, PosTicketPrintService $service): Response
    {
        abort_unless($request->user()?->can('printing.digital') || $request->user()?->can('printing.print'), Response::HTTP_FORBIDDEN);
        $this->ensureTenantResource($printJob);

        $bytes = $service->renderPdf($printJob);
        $filename = sprintf('Ticket-%s-%s.pdf', $printJob->pos_order_id, now()->format('Ymd-His'));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    public function status(UpdatePrintJobStatusRequest $request, PrintJob $printJob, PosTicketPrintService $service): PrintJobResource
    {
        $this->ensureTenantResource($printJob);

        return PrintJobResource::make($service->markStatus($printJob, $request->validated()));
    }

    private function ensureTenantResource(PrintJob $job): void
    {
        abort_unless($job->tenant_id === app(TenantManager::class)->require()->id, Response::HTTP_NOT_FOUND);
    }
}
