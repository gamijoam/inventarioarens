<?php

namespace App\Modules\Printing\Controllers;

use App\Modules\Printing\Models\PrintConnector;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Requests\AcknowledgePrintConnectorJobRequest;
use App\Modules\Printing\Requests\CreatePrintConnectorPairingCodeRequest;
use App\Modules\Printing\Requests\ListPrintConnectorJobsRequest;
use App\Modules\Printing\Requests\RegisterPrintConnectorRequest;
use App\Modules\Printing\Resources\PrintConnectorJobResource;
use App\Modules\Printing\Resources\PrintConnectorResource;
use App\Modules\Printing\Services\PosTicketPrintService;
use App\Modules\Printing\Services\PrintConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PrintConnectorController extends Controller
{
    public function __construct(private readonly PrintConnectorService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('printing.view'), Response::HTTP_FORBIDDEN);

        return PrintConnectorResource::collection($this->service->connectors());
    }

    public function createPairingCode(CreatePrintConnectorPairingCodeRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->createPairingCode($request->user()),
        ], Response::HTTP_CREATED);
    }

    public function revoke(Request $request, PrintConnector $printConnector): PrintConnectorResource
    {
        abort_unless($request->user()?->can('printing.manage'), Response::HTTP_FORBIDDEN);

        return PrintConnectorResource::make($this->service->revoke($printConnector));
    }

    public function register(RegisterPrintConnectorRequest $request): JsonResponse
    {
        $result = $this->service->register($request->validated());

        return response()->json([
            'data' => [
                'connector' => PrintConnectorResource::make($result['connector'])->toArray($request),
                'token' => $result['token'],
                'token_expires_at' => $result['token_expires_at'],
            ],
        ], Response::HTTP_CREATED);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $connector = $this->connector($request);

        return response()->json([
            'data' => ['connector' => PrintConnectorResource::make($this->service->heartbeat($connector))],
        ]);
    }

    public function ticketPdf(Request $request, string $jobUuid, PosTicketPrintService $ticketService): Response
    {
        $connector = $this->connector($request);
        $job = PrintJob::query()
            ->where('uuid', $jobUuid)
            ->where('print_connector_id', $connector->id)
            ->firstOrFail();
        $bytes = $ticketService->renderPdf($job);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"Ticket-{$job->pos_order_id}-{$job->id}.pdf\"",
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    public function jobs(ListPrintConnectorJobsRequest $request): AnonymousResourceCollection
    {
        return PrintConnectorJobResource::collection(
            $this->service->availableJobs($this->connector($request), (int) ($request->validated('limit') ?? 20)),
        );
    }

    public function claim(Request $request, string $jobUuid): JsonResponse
    {
        $result = $this->service->claim($this->connector($request), $jobUuid);

        return response()->json([
            'data' => [
                'claim_token' => $result['claim_token'],
                'job' => PrintConnectorJobResource::make($result['job']),
            ],
        ]);
    }

    public function acknowledge(AcknowledgePrintConnectorJobRequest $request, string $jobUuid): PrintConnectorJobResource
    {
        return PrintConnectorJobResource::make($this->service->acknowledge(
            $this->connector($request),
            $jobUuid,
            $request->validated('claim_token'),
            $request->safe()->except('claim_token'),
        ));
    }

    private function connector(Request $request): PrintConnector
    {
        /** @var PrintConnector $connector */
        $connector = $request->attributes->get('print_connector');

        return $connector;
    }
}
