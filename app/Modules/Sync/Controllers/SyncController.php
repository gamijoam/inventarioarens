<?php

namespace App\Modules\Sync\Controllers;

use App\Modules\Sync\Requests\AcknowledgeSyncEventRequest;
use App\Modules\Sync\Requests\PullSyncEventsRequest;
use App\Modules\Sync\Requests\PushSyncEventsRequest;
use App\Modules\Sync\Requests\RegisterSyncNodeRequest;
use App\Modules\Sync\Services\SyncTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class SyncController extends Controller
{
    public function __construct(private readonly SyncTransportService $sync)
    {
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
}
