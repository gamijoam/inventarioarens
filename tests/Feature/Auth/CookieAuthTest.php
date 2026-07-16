<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Auth\Services\CookieIssuer;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cubre el contrato de auth via cookies httpOnly.
 *
 * Ver docs/AUTH_COOKIE_API.md para el flujo completo.
 *
 * IMPORTANTE para los tests: Laravel testing solo envia las defaultCookies
 * en requests JSON si primero llamas $this->withCredentials(). Sin esa
 * llamada, json()/getJson() ignoran los cookies aunque los setees con
 * withCookie(). Esto es un quirk del MakesHttpRequests trait de Laravel.
 *
 * Casos cubiertos:
 *   - Login emite cookie httpOnly cuando el request parece SPA.
 *   - Login NO emite cookie cuando trae Bearer header (es un cliente API).
 *   - /me acepta cookie httpOnly para autenticar.
 *   - Cookie requiere X-Requested-With: XMLHttpRequest (CSRF protection).
 *   - Cookie requiere Origin matching app.url.
 *   - Logout limpia la cookie.
 *   - Logout limpia cookie solo si el request estaba autenticado via cookie.
 *   - Switch-tenant rota la cookie cuando el user esta via cookie.
 *   - El bearer token sigue funcionando (compat con sync worker).
 */
class CookieAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_emits_httpOnly_cookie_when_request_looks_like_spa(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ])
            ->assertCreated();

        $cookies = $response->headers->getCookies();
        $authCookie = collect($cookies)->first(
            fn ($c) => $c->getName() === CookieIssuer::COOKIE_NAME
        );

        $this->assertNotNull($authCookie, 'Cookie auth_token no emitida.');
        $this->assertTrue($authCookie->isHttpOnly(), 'Cookie no es httpOnly.');
        $this->assertSame('lax', $authCookie->getSameSite(), 'SameSite incorrecto.');
        $this->assertNotEmpty($authCookie->getValue(), 'Cookie sin valor.');
    }

    public function test_login_does_not_emit_cookie_when_request_uses_bearer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $response = $this
            ->withHeader('Authorization', 'Bearer some-token-that-doesnt-matter-here')
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ]);

        $cookies = $response->headers->getCookies();
        $authCookie = collect($cookies)->first(
            fn ($c) => $c->getName() === CookieIssuer::COOKIE_NAME
        );

        $this->assertNull($authCookie, 'Bearer clients no deberian recibir cookie.');
    }

    public function test_me_authenticates_via_cookie(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalogo', ['products.view']);
        $token = $this->loginToken($tenant, $user);

        // Simulamos al navegador: solo cookie, sin Bearer header.
        // withCredentials() es OBLIGATORIO para que json() envie los default cookies.
        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', config('app.url'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_cookie_request_without_x_requested_with_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        // Cookie + sin X-Requested-With = CSRF bloqueado.
        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertStatus(403);
    }

    public function test_cookie_request_with_wrong_origin_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', 'https://evil.example.com')
            ->getJson('/api/auth/me')
            ->assertStatus(403);
    }

    public function test_cookie_request_with_origin_in_allowlist_is_accepted(): void
    {
        // Caso tipico: dev usa Vite en :5173 mientras APP_URL es :8000 (o :80).
        // Ambos origins deben estar en APP_ALLOWED_ORIGINS_FOR_CSRF.
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        // Patcheamos config para el test.
        config([
            'app.allowed_origins_for_csrf' => [
                'http://localhost:5173',  // Vite dev (browser origin)
                'http://localhost',       // APP_URL fallback
            ],
        ]);

        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', 'http://localhost:5173')  // ← Vite origin
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_cookie_origin_check_normalizes_ports(): void
    {
        // El check debe comparar scheme + host + port exactamente.
        // http://localhost:5173 != http://localhost (puertos diferentes).
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        config([
            'app.allowed_origins_for_csrf' => ['http://localhost'],
        ]);

        // Browser envia :5173 pero la allowlist solo tiene :80 (default).
        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/auth/me')
            ->assertStatus(403);  // blocked por mismatch de puerto
    }

    /**
     * @test
     * Bug fix: cuando Vite proxy reenvia /api/* al backend Laravel en dev,
     * el navegador NO envia header `Origin` (porque la peticion es
     * same-origin desde el punto de vista del browser). El backend no
     * debe rechazar estas requests con 403 porque confiamos en
     * `X-Requested-With: XMLHttpRequest` (que NO se puede setear desde
     * un <form> HTML).
     */
    public function test_cookie_request_without_origin_header_is_accepted_for_same_origin(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        // Caso real del navegador via Vite dev proxy:
        //   - Cookie httpOnly presente
        //   - X-Requested-With: XMLHttpRequest presente
        //   - Origin AUSENTE (porque browser lo omite en same-origin requests
        //     cuando el proxy reenvia a otro puerto sin reescribir Host)
        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            // intencionalmente sin Origin/Referer
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    /**
     * @test
     * Si el navegador envia un Origin y este NO esta en la allowlist,
     * la peticion cookie-auth debe ser rechazada (CSRF cross-origin
     * protection sigue activa para peticiones que SI declaran origin).
     */
    public function test_cookie_request_with_disallowed_origin_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', 'https://attacker.example.com')
            ->getJson('/api/auth/me')
            ->assertStatus(403);
    }

    public function test_bearer_still_works_without_csrf_headers(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        // Bearer puro: sin X-Requested-With, sin Origin, sin cookie.
        // Debe pasar porque no hay riesgo CSRF (el token no se envia auto).
        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_logout_clears_cookie_when_authenticated_via_cookie(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        $response = $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/logout')
            ->assertOk();

        $cookies = $response->headers->getCookies();
        $authCookie = collect($cookies)->first(
            fn ($c) => $c->getName() === CookieIssuer::COOKIE_NAME
        );

        $this->assertNotNull($authCookie, 'Logout deberia emitir Set-Cookie para limpiar.');
        $this->assertSame(0, $authCookie->getExpiresTime(), 'Cookie clear deberia tener expires=0.');

        // El token en DB tambien se revoco.
        $tokenModel = AuthToken::where('token_hash', hash('sha256', $token))->first();
        $this->assertNotNull($tokenModel);
        $this->assertNotNull($tokenModel->revoked_at);
    }

    public function test_logout_via_bearer_does_not_clear_cookie(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $cookies = $response->headers->getCookies();
        $authCookie = collect($cookies)->first(
            fn ($c) => $c->getName() === CookieIssuer::COOKIE_NAME
        );

        $this->assertNull($authCookie, 'Bearer clients no deberian recibir Set-Cookie clear.');
    }

    public function test_switch_tenant_rotates_cookie_when_authenticated_via_cookie(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $this->grantRole($tenantA, $user, 'Empresa A', ['products.view']);
        $this->grantRole($tenantB, $user, 'Empresa B', ['pos.view']);
        $tokenA = $this->loginToken($tenantA, $user);

        $response = $this->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $tokenA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/switch-tenant', [
                'tenant_slug' => $tenantB->slug,
            ])
            ->assertCreated();

        $cookies = $response->headers->getCookies();
        $authCookie = collect($cookies)->first(
            fn ($c) => $c->getName() === CookieIssuer::COOKIE_NAME
        );

        // La rotacion limpia la cookie vieja (Set-Cookie con expires=0)
        // y emite la nueva. Symfony conserva solo la ultima en getCookies()
        // (con el mismo nombre+path+domain), pero la limpieza se hace
        // primero asi que el navegador interpreta ambas directivas.
        $this->assertNotNull($authCookie, 'Switch-tenant deberia emitir la nueva cookie.');
        $this->assertGreaterThan(0, $authCookie->getExpiresTime(), 'La nueva cookie deberia tener expires > 0.');

        $newToken = $response->json('data.token');
        $this->assertSame($newToken, $authCookie->getValue(), 'El valor de la cookie debe coincidir con el nuevo token.');
    }

    public function test_me_without_any_token_returns_401(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);

        $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): Role
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->syncRoles($role);

        return $role;
    }

    private function loginToken(Tenant $tenant, User $user): string
    {
        return $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->json('data.token');
    }
}