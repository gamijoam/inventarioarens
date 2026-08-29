<?php

namespace App\Modules\CRM\Middleware;

use App\Modules\CRM\Models\CrmApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCrmScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('crm_token');

        if (! $token instanceof CrmApiToken || ! $token->allows($scope)) {
            abort(response()->json([
                'message' => "La credencial CRM no tiene el scope '{$scope}'.",
                'error' => 'insufficient_scope',
                'required_scope' => $scope,
            ], Response::HTTP_FORBIDDEN));
        }

        return $next($request);
    }
}
