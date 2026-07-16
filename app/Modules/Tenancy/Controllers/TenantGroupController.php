<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\StoreTenantGroupRequest;
use App\Modules\Tenancy\Resources\GroupResource;
use App\Modules\Tenancy\Resources\TenantResource;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Endpoints para que un user autenticado (no platform admin) pueda crear
 * SU PROPIO grupo + su primera empresa en una sola transaccion.
 *
 * Pensado para el flujo "self-serve": el owner real se registra y queda
 * con:
 *  - 1 grupo raiz (is_group=true) del cual es Owner.
 *  - 1 empresa inicial (spinoff) del cual es Administrador.
 *
 * A partir de ahi puede crear mas empresas hijas via
 * POST /api/tenant-groups/{group}/tenants.
 */
class TenantGroupController extends Controller
{
    public function __construct(private readonly TenantRegistrationService $service)
    {
    }

    public function store(StoreTenantGroupRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $this->service->registerGroupWithInitialTenant(
            $request->validated(),
            $user,
        );

        return response()->json([
            'data' => [
                'group' => GroupResource::make($result['group']),
                'tenant' => TenantResource::make($result['tenant']),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Lista los grupos donde el user es Owner (no solo member).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $groups = Tenant::query()
            ->groups()
            ->whereHas('users', function ($q) use ($user): void {
                // Filtramos por la columna del pivote 'tenant_user.status' directamente,
                // porque wherePivot() sin argumentos generaba `where "pivot" = ?` que no existe
                // como columna en la tabla pivote.
                $q->where('users.id', $user->id)->where('tenant_user.status', 'active');
            })
            // Ademas del attach activo, el user debe tener el rol 'Owner' del grupo
            // (model_has_roles + roles.tenant_id = tenants.id).
            ->whereIn('id', function ($sub) use ($user): void {
                $sub->select('roles.tenant_id')
                    ->from('roles')
                    ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->where('model_has_roles.model_type', User::class)
                    ->where('model_has_roles.model_id', $user->id)
                    ->where('roles.name', 'Owner')
                    ->whereNotNull('roles.tenant_id');
            })
            ->withCount(['children', 'users'])
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'domain' => $g->domain,
                'plan' => $g->plan,
                'status' => $g->status,
                'children_count' => (int) $g->children_count,
                'users_count' => (int) $g->users_count,
                'is_owner' => $user->isOwnerOf($g),
            ]);

        return response()->json(['data' => $groups]);
    }

    /**
     * Spinoffs (empresas hijas) de un grupo.
     *
     * Politica: cualquier miembro activo del grupo (no necesariamente Owner).
     * Esto permite que admins de empresa vean el resto de empresas del holding.
     */
    public function spinoffs(Request $request, Tenant $group): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        abort_unless($user->belongsToTenant($group), 403, 'User is not a member of this group.');

        $spinoffs = Tenant::query()
            ->spinoffs()
            ->where('parent_id', $group->id)
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'domain' => $t->domain,
                'plan' => $t->plan,
                'status' => $t->status,
                'users_count' => (int) $t->users_count,
            ]);

        return response()->json(['data' => $spinoffs]);
    }
}