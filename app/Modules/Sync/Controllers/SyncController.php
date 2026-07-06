<?php

namespace App\Modules\Sync\Controllers;

use App\Modules\Sync\Requests\AcknowledgeSyncEventRequest;
use App\Modules\Sync\Requests\PullSyncEventsRequest;
use App\Modules\Sync\Requests\PushSyncEventsRequest;
use App\Modules\Sync\Requests\RegisterSyncNodeRequest;
use App\Modules\Sync\Requests\SyncReadinessRequest;
use App\Modules\Sync\Services\SyncReadinessService;
use App\Modules\Sync\Services\SyncTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncTransportService $sync,
        private readonly SyncReadinessService $readiness,
    ) {
    }

    public function registerNode(RegisterSyncNodeRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sync->registerNode($request->validated()),
        ], Response::HTTP_CREATED);
    }

    public function push(PushSyncEventsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sync->pushEvents(
                $request->validated('events'),
                $request->validated('origin_node_code')
            ),
        ], Response::HTTP_ACCEPTED);
    }

    public function pull(PullSyncEventsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sync->pullEvents(
                $request->validated('node_code'),
                (int) ($request->validated('limit') ?? 50)
            ),
        ]);
    }

    public function acknowledge(AcknowledgeSyncEventRequest $request, string $eventUuid): JsonResponse
    {
        return response()->json([
            'data' => $this->sync->acknowledge(
                $eventUuid,
                $request->validated('node_code'),
                $request->validated('status') ?? 'applied',
                $request->validated('error')
            ),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sync->status($request->query('node_code')),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_code' => ['required', 'string', 'max:80'],
        ]);

        return response()->json([
            'data' => $this->readiness->get($data['installation_code']),
        ]);
    }

    public function markReadiness(SyncReadinessRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->readiness->mark($data['installation_code'], $data),
        ]);
    }
}
