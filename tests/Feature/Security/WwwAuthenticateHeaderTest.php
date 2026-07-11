<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class WwwAuthenticateHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401_with_www_authenticate_header(): void
    {
        $response = $this->getJson('/api/customers');

        $response->assertStatus(401);
        $this->assertEquals(
            'Bearer realm="api"',
            $response->headers->get('WWW-Authenticate'),
            'Debe incluir WWW-Authenticate: Bearer realm="api" en respuestas 401'
        );
    }

    public function test_invalid_token_returns_401_with_www_authenticate_header_with_error(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-12345')
            ->withHeader('X-Tenant', 'demo')
            ->getJson('/api/customers');

        $response->assertStatus(401);
        $this->assertEquals(
            'Bearer realm="api", error="invalid_token"',
            $response->headers->get('WWW-Authenticate')
        );
    }

    public function test_token_belonging_to_other_tenant_returns_403_with_tenant_mismatch_error(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $user = User::factory()->create();
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);

        $plainToken = Str::random(60);
        AuthToken::create([
            'tenant_id' => $tenantA->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->withHeader('X-Tenant', 'tenant-b')
            ->getJson('/api/customers');

        $response->assertStatus(403);
        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $wwwAuth);
        $this->assertStringContainsString('tenant_mismatch', $wwwAuth);
    }

    public function test_user_not_active_in_tenant_returns_403_with_specific_error(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant X', 'slug' => 'tenant-x']);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'inactive']);

        $plainToken = Str::random(60);
        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->withHeader('X-Tenant', 'tenant-x')
            ->getJson('/api/customers');

        $response->assertStatus(403);
        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $wwwAuth);
        $this->assertStringContainsString('user_not_in_tenant', $wwwAuth);
    }
}