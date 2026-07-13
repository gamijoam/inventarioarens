<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\PlatformLoginRequest;
use App\Modules\Auth\Requests\SwitchTenantRequest;
use App\Modules\Auth\Requests\TenantLookupRequest;
use App\Modules\Auth\Resources\AuthSessionResource;
use App\Modules\Auth\Resources\PlatformSessionResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Tenancy\Models\Tenant;
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
                $request->validated('email')
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

    public function platformLogin(PlatformLoginRequest $request): JsonResponse
    {
        $session = $this->auth->platformLogin(
            $request->validated('email'),
            $request->validated('password'),
            $request
        );

        return response()->json([
            'data' => array_merge(
                PlatformSessionResource::make($session)->resolve($request),
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

    public function switchTenant(SwitchTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::query()
            ->where('slug', $request->validated('tenant_slug'))
            ->firstOrFail();

        $session = $this->auth->switchTenant(
            $request->user(),
            $tenant,
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

    public function sessions(Request $request): JsonResponse
    {
        $tenant = app(TenantManager::class)->require();
        $user = $request->user();
        $currentToken = $request->attributes->get('auth_token');

        $sessions = \App\Modules\Auth\Models\AuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (\App\Modules\Auth\Models\AuthToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_current' => $currentToken && (int) $currentToken->id === (int) $token->id,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $tenant = app(TenantManager::class)->require();
        $user = $request->user();

        $token = \App\Modules\Auth\Models\AuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereKey($tokenId)
            ->firstOrFail();

        $this->auth->revokeCurrentToken($token);

        return response()->json(['data' => ['revoked' => true, 'token_id' => $token->id]]);
    }
}
