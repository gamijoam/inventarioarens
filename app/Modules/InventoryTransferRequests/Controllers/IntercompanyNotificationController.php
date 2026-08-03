<?php

namespace App\Modules\InventoryTransferRequests\Controllers;

use App\Modules\InventoryTransferRequests\Models\IntercompanyNotification;
use App\Modules\InventoryTransferRequests\Models\IntercompanyNotificationRead;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Resources\IntercompanyNotificationResource;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IntercompanyNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InventoryTransferRequest::class);
        $tenantId = app(TenantManager::class)->require()->id;
        $userId = $request->user()->id;

        return IntercompanyNotificationResource::collection(
            IntercompanyNotification::query()
                ->where('tenant_id', $tenantId)
                ->withExists(['reads as is_read' => fn ($query) => $query->where('user_id', $userId)])
                ->latest('occurred_at')
                ->paginate(min(max($request->integer('per_page', 15), 1), 50))
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', InventoryTransferRequest::class);
        $tenantId = app(TenantManager::class)->require()->id;
        $userId = $request->user()->id;

        $count = IntercompanyNotification::query()
            ->where('tenant_id', $tenantId)
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $userId))
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        Gate::authorize('viewAny', InventoryTransferRequest::class);
        $tenantId = app(TenantManager::class)->require()->id;
        $item = IntercompanyNotification::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($notification);

        IntercompanyNotificationRead::query()->updateOrCreate(
            ['notification_id' => $item->id, 'user_id' => $request->user()->id],
            ['tenant_id' => $tenantId, 'read_at' => now()]
        );

        return response()->json(['data' => ['read' => true]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', InventoryTransferRequest::class);
        $tenantId = app(TenantManager::class)->require()->id;
        $userId = $request->user()->id;
        $now = now();
        $rows = IntercompanyNotification::query()
            ->where('tenant_id', $tenantId)
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $userId))
            ->pluck('id')
            ->map(fn (int $id) => [
                'tenant_id' => $tenantId,
                'notification_id' => $id,
                'user_id' => $userId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        if ($rows !== []) {
            DB::table('intercompany_notification_reads')->upsert(
                $rows,
                ['notification_id', 'user_id'],
                ['read_at', 'updated_at']
            );
        }

        return response()->json(['data' => ['read' => count($rows)]]);
    }
}
