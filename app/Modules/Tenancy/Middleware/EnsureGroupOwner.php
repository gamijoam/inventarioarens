<?php

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGroupOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 403, 'Authenticated user required.');

        $group = $request->route('group');
        if (! $group instanceof Tenant) {
            $query = Tenant::query();
            $value = (string) $request->route('group');
            if (is_numeric($value)) {
                $query->where('id', (int) $value);
            } else {
                $query->where('slug', $value);
            }
            $group = $query->first();
        }

        abort_unless($group, 404, 'Group not found.');
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        abort_unless($group->isOwnedBy($user), 403, 'User is not an owner of this group.');

        $request->attributes->set('group', $group);

        return $next($request);
    }
}