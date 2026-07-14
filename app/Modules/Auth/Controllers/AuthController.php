<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Middleware\AuthenticateApiToken;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\PlatformLoginRequest;
use App\Modules\Auth\Requests\SwitchTenantRequest;
use App\Modules\Auth\Requests\TenantLookupRequest;
use App\Modules\Auth\Resources\AuthSessionResource;
use App\Modules\Auth\Resources\PlatformSessionResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\CookieIssuer;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CookieIssuer $cookies,
    ) {
    }

    public function tenants(TenantLookupRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->auth->availableTenants(
                $request->validated('email')
            ),
        ]);
    }

    /**
     * Login emite cookie httpOnly si el request es "web-like"
     * (X-Requested-With: XMLHttpRequest, sin Authorization Bearer header).
     *
     * Deteccion:
     *   - Si el request trae `Authorization: Bearer` -> es un cliente API
     *     clasico (sync worker, Postman, scripts PHP) -> SOLO devuelve token en body.
     *   - Si NO trae Bearer -> es el frontend SPA -> emite cookie httpOnly
     *     Y devuelve token en body (para que el SPA tenga el token tambien
     *     en caso de necesitarlo, aunque lo normal es no tocarlo).
     *
     * Esta doble estrategia permite que sync worker y Postman sigan usando
     * Bearer (sin cambios) mientras el frontend usa cookies (mas seguro).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $session = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            app(TenantManager::class)->require(),
            $request
        );

        $response = response()->json([
            'data' => array_merge(
                AuthSessionResource::make($session)->resolve($request),
                [
                    'token' => $session['token'],
                    'token_type' => $session['token_type'],
                    'expires_at' => $session['expires_at'],
                ]
            ),
        ], Response::HTTP_CREATED);

        if ($this->shouldIssueCookie($request)) {
            $this->cookies->issueAuthToken($response, $session['token']);
        }

        return $response;
    }

    public function platformLogin(PlatformLoginRequest $request): JsonResponse
    {
        $session = $this->auth->platformLogin(
            $request->validated('email'),
            $request->validated('password'),
            $request
        );

        $response = response()->json([
            'data' => array_merge(
                PlatformSessionResource::make($session)->resolve($request),
                [
                    'token' => $session['token'],
                    'token_type' => $session['token_type'],
                    'expires_at' => $session['expires_at'],
                ]
            ),
        ], Response::HTTP_CREATED);

        if ($this->shouldIssueCookie($request)) {
            $this->cookies->issueAuthToken($response, $session['token']);
        }

        return $response;
    }

    public function me(Request $request): AuthSessionResource
    {
        $tenant = $request->user()?->isPlatformAdmin()
            ? null
            : app(TenantManager::class)->current();

        return AuthSessionResource::make(
            $this->auth->currentSession(
                $request->user(),
                $tenant
            )
        );
    }

    /**
     * Switch-tenant rota la cookie si el user estaba autenticado via cookie.
     * El token Bearer (si lo hay) sigue funcionando tal cual, pero el backend
     * tambien emite un nuevo token en el body para que clientes que SÍ lo
     * usan puedan actualizarlo.
     */
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

        $response = response()->json([
            'data' => array_merge(
                AuthSessionResource::make($session)->resolve($request),
                [
                    'token' => $session['token'],
                    'token_type' => $session['token_type'],
                    'expires_at' => $session['expires_at'],
                ]
            ),
        ], Response::HTTP_CREATED);

        $authSource = $request->attributes->get('auth_token_source');
        if ($authSource === AuthenticateApiToken::SOURCE_COOKIE) {
            // El user estaba autenticado via cookie -> rotar la cookie
            // (limpia la anterior + emite nueva con el token del nuevo tenant).
            $this->cookies->rotateAuthToken($response, $session['token']);
        } elseif ($this->shouldIssueCookie($request)) {
            // Si el request no trae Bearer pero califica como SPA, emitir cookie.
            $this->cookies->issueAuthToken($response, $session['token']);
        }

        return $response;
    }

    /**
     * Logout limpia la cookie (si existe) y revoca el token en DB.
     * El frontend SPA siempre hace logout via cookie; el sync worker via Bearer.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->revokeCurrentToken($request->attributes->get('auth_token'));

        $response = response()->json(['data' => ['revoked' => true]]);

        $authSource = $request->attributes->get('auth_token_source');
        if ($authSource === AuthenticateApiToken::SOURCE_COOKIE) {
            $this->cookies->clearAuthToken($response);
        }

        return $response;
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $count = $this->auth->revokeAllTenantTokens(
            $request->user(),
            app(TenantManager::class)->require()
        );

        $response = response()->json(['data' => ['revoked_tokens' => $count]]);

        $authSource = $request->attributes->get('auth_token_source');
        if ($authSource === AuthenticateApiToken::SOURCE_COOKIE) {
            $this->cookies->clearAuthToken($response);
        }

        return $response;
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

    /**
     * Determina si el request actual debe recibir cookie httpOnly.
     *
     * Reglas:
     *   - Si el request trae `Authorization: Bearer` -> NO (es un cliente API).
     *   - Si el request trae `X-Requested-With: XMLHttpRequest` -> SI (es el SPA).
     *   - Si el Origin coincide con APP_URL -> SI (es el SPA, ya validado por el cliente).
     *   - Cualquier otro caso -> NO (defensa en profundidad: requests "raros" no reciben cookie).
     */
    private function shouldIssueCookie(Request $request): bool
    {
        if ($request->bearerToken()) {
            return false;
        }

        if ($request->header('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        $appUrl = (string) config('app.url', '');
        if ($appUrl !== '') {
            $expectedOrigin = parse_url($appUrl, PHP_URL_SCHEME).'://'.parse_url($appUrl, PHP_URL_HOST);
            $origin = $request->header('Origin');
            if ($origin !== null && str_starts_with((string) $origin, $expectedOrigin)) {
                return true;
            }
        }

        return false;
    }
}