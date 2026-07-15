<?php

namespace App\Modules\AccessControl\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Lista los ultimos cambios del audit_log que afectan a un user
 * (cambios de roles, status, overrides, scopes).
 *
 * Filtra: entity_type IN (User, TenantUser pivot) + entity_id = user.id
 * o user_id = user.id en new_values/old_values.
 */
class UserAuditController extends Controller
{
    public function index(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        abort_unless($request->user()?->can('users.view'), 403);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, 404, 'El usuario no pertenece a esta empresa.');

        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        // Buscar logs donde:
        // 1) entity_type = User y entity_id = user.id, o
        // 2) entity_type = tenant_user pivot y entity_id = user.id, o
        // 3) el user_id del log es el user.
        $logs = DB::table('audit_logs')
            ->where(function ($q) use ($user) {
                $q->where(function ($q2) use ($user) {
                    $q2->where('entity_type', User::class)->where('entity_id', $user->id);
                })->orWhere(function ($q2) use ($user) {
                    $q2->where('entity_type', 'tenant_user')->where('entity_id', $user->id);
                })->orWhere('user_id', $user->id);
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'user_id', 'ip_address', 'created_at']);

        return response()->json([
            'data' => $logs->map(fn ($l) => [
                'id' => (int) $l->id,
                'action' => $l->action,
                'entity_type' => $l->entity_type,
                'entity_id' => (int) $l->entity_id,
                'old_values' => $l->old_values ? json_decode((string) $l->old_values, true) : null,
                'new_values' => $l->new_values ? json_decode((string) $l->new_values, true) : null,
                'user_id' => $l->user_id ? (int) $l->user_id : null,
                'ip_address' => $l->ip_address,
                'created_at' => $l->created_at,
            ])->values()->all(),
            'total' => $logs->count(),
        ]);
    }
}