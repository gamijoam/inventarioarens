<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenants_endpoint_throttles_after_5_attempts_per_minute_per_ip_and_email(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/tenants', [
                'email' => 'test@example.test',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request {$i} should not be throttled");
        }

        $sixth = $this->postJson('/api/auth/tenants', [
            'email' => 'test@example.test',
        ]);

        $sixth->assertStatus(429);
        $sixth->assertJsonPath('message', 'Demasiados intentos de autenticación. Por favor intente en 1 minuto.');
    }

    public function test_login_endpoint_throttles_after_5_attempts_per_minute_per_ip_and_email(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Throttle', 'slug' => 'tienda-throttle']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/auth/login', [
                    'email' => 'wrong@example.test',
                    'password' => 'wrong-password',
                ]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request {$i} should not be throttled");
        }

        $sixth = $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'wrong@example.test',
                'password' => 'wrong-password',
            ]);

        $sixth->assertStatus(429);
    }

    public function test_different_emails_have_separate_rate_limit_buckets(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/tenants', [
                'email' => "user{$i}@example.test",
            ]);
        }

        $response = $this->postJson('/api/auth/tenants', [
            'email' => 'freshuser@example.test',
        ]);

        $this->assertNotEquals(429, $response->getStatusCode(), 'Email diferente debe tener su propio bucket de rate limit');
    }

    public function test_throttle_lets_a_sixth_request_through_after_window_expires(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/tenants', ['email' => 'test@example.test']);
        }

        $blocked = $this->postJson('/api/auth/tenants', ['email' => 'test@example.test']);
        $blocked->assertStatus(429);

        $cache = app('cache')->store('array');
        $cache->flush();

        $afterReset = $this->postJson('/api/auth/tenants', ['email' => 'test@example.test']);
        $this->assertNotEquals(429, $afterReset->getStatusCode(), 'Despues de flush de cache el throttle debe permitir requests');
    }
}