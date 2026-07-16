<?php

namespace App\Modules\Tenancy\Middleware;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el request lo hace un Owner del grupo de la ruta.
 *
 * Uso: Route::middleware(['api.auth', EnsureGroupOwner::class])->prefix('groups/{group}')
 *
 * Aplica tenant resolution (igual que ResolveTenant) pero NO usa el tenant
 * para permisos globales: solo verifica que el user pueda administrar el grupo.
 */
class EnsureGroupOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Authentication required.');

        $group = $this->resolveGroup($request);
        abort_unless($group, 404, 'Group not found.');
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        $isOwner = $user->isOwnerOf($group);
        abort_unless($isOwner, 403, sprintf(
            'User is not an owner. user_id=%d group_id=%d belongsToTenant=%s rolesInGroup=%d',
            $user->id,
            $group->id,
            $user->belongsToTenant($group) ? 'yes' : 'no',
            \DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('model_has_roles.model_type', User::class)->where('model_has_roles.model_id', $user->id)->where('roles.tenant_id', $group->id)->count()
        ));

        $request->attributes->set('group', $group);

        return $next($request);
    }

    private function resolveGroup(Request $request): ?Tenant
    {
        $routeGroup = $request->route('group');
        if ($routeGroup instanceof Tenant) {
            return $routeGroup;
        }

        $value = (string) $routeGroup;
        if ($value === '') {
            return null;
        }

        $query = Tenant::query();
        if (is_numeric($value)) {
            $query->where('id', (int) $value);
        } else {
            $query->where('slug', $value);
        }

        return $query->first();
    }
}