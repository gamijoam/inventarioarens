<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_api_response_includes_security_headers(): void
    {
        $response = $this->getJson('/api/customers');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
    }

    public function test_api_response_includes_strict_csp(): void
    {
        $response = $this->getJson('/api/customers');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_hsts_header_only_present_on_https(): void
    {
        $httpsRequest = \Illuminate\Http\Request::create('/api/customers', 'GET');
        $httpsRequest->server->set('HTTPS', 'on');
        $httpsResponse = $this->handleRequest($httpsRequest);
        $this->assertNotNull($httpsResponse->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('max-age=63072000', $httpsResponse->headers->get('Strict-Transport-Security'));

        $httpRequest = \Illuminate\Http\Request::create('/api/customers', 'GET');
        $httpResponse = $this->handleRequest($httpRequest);
        $this->assertNull($httpResponse->headers->get('Strict-Transport-Security'));
    }

    public function test_unauthenticated_error_response_includes_security_headers(): void
    {
        $response = $this->getJson('/api/customers');

        $response->assertStatus(401);
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_web_welcome_page_includes_web_csp(): void
    {
        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_web_welcome_page_in_local_environment_allows_vite_hmr(): void
    {
        $this->app['env'] = 'local';
        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('localhost:5173', $csp);
    }

    private function handleRequest(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        return $kernel->handle($request);
    }
}