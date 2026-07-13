<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_login_returns_token_and_user_when_credentials_are_valid(): void
    {
        User::create([
            'name' => 'SaaS Admin',
            'email' => 'saas@example.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => true,
        ]);

        $response = $this->postJson('/api/auth/platform-login', [
            'email' => 'saas@example.com',
            'password' => 'secret123',
            'device_name' => 'unit-test',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'expires_at',
                'user' => ['id', 'name', 'email', 'is_platform_admin'],
                'tenant',
                'roles',
                'permissions',
            ],
        ]);
        $this->assertNull($response->json('data.tenant'));
        $this->assertTrue($response->json('data.user.is_platform_admin'));
        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_platform_login_rejects_non_platform_admin(): void
    {
        User::create([
            'name' => 'Normal User',
            'email' => 'normal@example.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => false,
        ]);

        $response = $this->postJson('/api/auth/platform-login', [
            'email' => 'normal@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_platform_login_rejects_invalid_password(): void
    {
        User::create([
            'name' => 'SaaS Admin',
            'email' => 'saas@example.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => true,
        ]);

        $response = $this->postJson('/api/auth/platform-login', [
            'email' => 'saas@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_platform_login_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/platform-login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_platform_token_works_on_master_endpoints_without_tenant_header(): void
    {
        $admin = User::create([
            'name' => 'SaaS Admin',
            'email' => 'saas@example.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => true,
        ]);

        $login = $this->postJson('/api/auth/platform-login', [
            'email' => 'saas@example.com',
            'password' => 'secret123',
        ])->assertCreated();

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/master/admins')
            ->assertOk()
            ->assertJsonFragment(['email' => $admin->email]);
    }

    public function test_platform_token_works_on_auth_me_without_tenant(): void
    {
        User::create([
            'name' => 'SaaS Admin',
            'email' => 'saas@example.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => true,
        ]);

        $login = $this->postJson('/api/auth/platform-login', [
            'email' => 'saas@example.com',
            'password' => 'secret123',
        ])->assertCreated();

        $token = $login->json('data.token');

        $response = $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk();

        $response->assertJsonPath('data.user.is_platform_admin', true);
        $response->assertJsonPath('data.tenant', null);
    }
}