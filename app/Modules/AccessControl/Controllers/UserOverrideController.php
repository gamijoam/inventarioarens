<?php

namespace App\Modules\AccessControl\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Requests\ReplaceUserOverridesRequest;
use App\Modules\AccessControl\Services\CapabilityResolver;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class UserOverrideController extends Controller
{
    public function __construct(private readonly CapabilityResolver $resolver) {}

    public function index(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        abort_unless($request->user()?->can('users.view'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);

        $overrides = DB::table('user_permission_overrides')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->orderBy('permission')
            ->get(['permission', 'effect', 'created_at', 'updated_at']);

        $extras = $overrides->where('effect', 'allow')->pluck('permission')->values()->all();
        $denied = $overrides->where('effect', 'deny')->pluck('permission')->values()->all();

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'items' => $overrides->map(fn ($o) => [
                    'permission' => $o->permission,
                    'effect' => $o->effect,
                    'created_at' => $o->created_at,
                    'updated_at' => $o->updated_at,
                ])->values()->all(),
                'extra_count' => count($extras),
                'deny_count' => count($denied),
                'extras' => $extras,
                'denied' => $denied,
            ],
        ]);
    }

    public function update(ReplaceUserOverridesRequest $request, Tenant $tenant, User $user): Response
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);

        $this->resolver->replaceOverrides($user, $request->validated('items'), $request->user());

        return response()->noContent();
    }

    public function destroy(Request $request, Tenant $tenant, User $user, string $permission): Response
    {
        abort_unless($request->user()?->can('users.update'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);
        $this->resolver->removeOverride($user, $permission, $request->user());

        return response()->noContent();
    }

    public function effectivePermissions(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        abort_unless($request->user()?->can('users.view'), Response::HTTP_FORBIDDEN);
        abort_unless((int) $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists() === 1, Response::HTTP_NOT_FOUND, 'El usuario no pertenece a esta empresa.');

        $this->ensureTenantContext($tenant);

        return response()->json([
            'data' => $this->resolver->resolveFor($user),
        ]);
    }

    private function ensureTenantContext(Tenant $tenant): void
    {
        // Forzar que el TenantManager refleje el tenant que viene en la URL.
        app(TenantManager::class)->set($tenant);
    }
}
