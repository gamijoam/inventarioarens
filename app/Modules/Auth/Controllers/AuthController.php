<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\TenantLookupRequest;
use App\Modules\Auth\Resources\AuthSessionResource;
use App\Modules\Auth\Services\AuthService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function tenants(TenantLookupRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->auth->availableTenants(
                $request->validated('email'),
                $request->validated('password')
            ),
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $session = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            app(TenantManager::class)->require(),
            $request
        );

        return response()->json([
            'data' => array_merge(
                AuthSessionResource::make($session)->resolve($request),
                [
                    'token' => $session['token'],
                    'token_type' => $session['token_type'],
                    'expires_at' => $session['expires_at'],
                ]
            ),
        ], Response::HTTP_CREATED);
    }

    public function me(Request $request): AuthSessionResource
    {
        return AuthSessionResource::make(
            $this->auth->currentSession(
                $request->user(),
                app(TenantManager::class)->require()
            )
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->revokeCurrentToken($request->attributes->get('auth_token'));

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $count = $this->auth->revokeAllTenantTokens(
            $request->user(),
            app(TenantManager::class)->require()
        );

        return response()->json(['data' => ['revoked_tokens' => $count]]);
    }
}
