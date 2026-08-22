<?php

namespace App\Modules\Sync\Controllers;

use App\Modules\Sync\Requests\AcknowledgeSyncEventRequest;
use App\Modules\Sync\Requests\CreateSyncGroupPairingCodeRequest;
use App\Modules\Sync\Requests\CreateSyncPairingCodeRequest;
use App\Modules\Sync\Requests\IssueSyncTokenRequest;
use App\Modules\Sync\Requests\PreviewSyncPairingCodeRequest;
use App\Modules\Sync\Requests\PullSyncEventsRequest;
use App\Modules\Sync\Requests\PushSyncEventsRequest;
use App\Modules\Sync\Requests\RedeemSyncPairingCodeRequest;
use App\Modules\Sync\Requests\RegisterSyncNodeRequest;
use App\Modules\Sync\Requests\StartSyncBootstrapRequest;
use App\Modules\Sync\Requests\SyncReadinessRequest;
use App\Modules\Sync\Requests\UploadSyncImageRequest;
use App\Modules\Sync\Services\SyncBootstrapService;
use App\Modules\Sync\Services\SyncImageService;
use App\Modules\Sync\Services\SyncPairingService;
use App\Modules\Sync\Services\SyncReadinessService;
use App\Modules\Sync\Services\SyncTokenService;
use App\Modules\Sync\Services\SyncTransportService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncTransportService $sync,
        private readonly SyncReadinessService $readiness,
        private readonly SyncTokenService $tokens,
        private readonly SyncPairingService $pairing,
        private readonly SyncBootstrapService $bootstrap,
        private readonly SyncImageService $images,
        private readonly TenantManager $tenancy,
    ) {}

    public function registerNode(RegisterSyncNodeRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->sync->registerNode($request->validated()),
        ], Response::HTTP_CREATED);
    }

    public function push(PushSyncEventsRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->sync->pushEvents(
                $request->validated('events'),
                $request->validated('origin_node_code')
            ),
        ], Response::HTTP_ACCEPTED);
    }

    public function pull(PullSyncEventsRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->sync->pullEvents(
                $request->validated('node_code'),
                (int) ($request->validated('limit') ?? 50)
            ),
        ]);
    }

    public function acknowledge(AcknowledgeSyncEventRequest $request, string $eventUuid): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->sync->acknowledge(
                $eventUuid,
                $request->validated('node_code'),
                $request->validated('status') ?? 'applied',
                $request->validated('error')
            ),
        ]);
    }

    public function startBootstrap(StartSyncBootstrapRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->bootstrap->start(
                $this->tenancy->require(),
                $request->validated(),
            ),
        ], Response::HTTP_CREATED);
    }

    public function completeBootstrap(Request $request, string $sessionToken): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->bootstrap->complete(
                $this->tenancy->require(),
                $sessionToken,
            ),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorizeTransport($request);

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
        $this->authorizeTransport($request);

        $data = $request->validated();

        return response()->json([
            'data' => $this->readiness->mark($data['installation_code'], $data),
        ]);
    }

    public function issueToken(IssueSyncTokenRequest $request): JsonResponse
    {
        $tenant = $this->tenancy->current();
        $user = $request->user();

        abort_unless($tenant && $user && $user->belongsToTenant($tenant), Response::HTTP_FORBIDDEN, 'No tienes acceso a esta empresa.');

        $data = $request->validated();
        $name = $data['name'] ?? ('sync-worker-'.$request->ip());
        $days = (int) ($data['days'] ?? 90);

        return response()->json([
            'data' => $this->tokens->issue(
                tenant: $tenant,
                user: $user,
                name: $name,
                days: $days,
                ipAddress: (string) $request->ip(),
                userAgent: $request->userAgent(),
            ),
        ], Response::HTTP_CREATED);
    }

    private function authorizeTransport(Request $request): void
    {
        abort_unless($request->user()?->can('sync.transport'), Response::HTTP_FORBIDDEN);
    }

    public function createPairingCode(CreateSyncPairingCodeRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->pairing->create(
                $this->tenancy->require(),
                $request->user(),
                $request->validated(),
            ),
        ], Response::HTTP_CREATED);
    }

    public function createGroupPairingCode(CreateSyncGroupPairingCodeRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->pairing->createGroup(
                $this->tenancy->require(),
                $request->user(),
                $request->validated(),
            ),
        ], Response::HTTP_CREATED);
    }

    public function redeemPairingCode(RedeemSyncPairingCodeRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->pairing->redeem(
                $request->validated(),
                (string) $request->ip(),
                $request->userAgent(),
            ),
        ], Response::HTTP_CREATED);
    }

    public function previewPairingCode(PreviewSyncPairingCodeRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        return response()->json([
            'data' => $this->pairing->preview($request->validated('code')),
        ]);
    }

    /**
     * Recibe el binario de una imagen que un nodo local sube para publicarla
     * en la nube. El local emite luego el evento product.image.uploaded con la
     * cloud_url resultante (base de la nube).
     */
    public function uploadImage(UploadSyncImageRequest $request): JsonResponse
    {
        $this->authorizeTransport($request);

        $result = $this->images->storeFromNode(
            $this->tenancy->require(),
            $request->validated(),
            $request->file('image'),
        );

        return response()->json(['data' => $result], Response::HTTP_CREATED);
    }
}
