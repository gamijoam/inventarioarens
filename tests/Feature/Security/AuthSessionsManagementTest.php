<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSessionsManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithTokens(int $tokenCount, ?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::create(['name' => 'Tienda Sesiones', 'slug' => 'tienda-sesiones']);
        $user = User::factory()->create(['email' => 'sessions@example.test', 'password' => 'Valid1234!']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $tokens = [];
        for ($i = 0; $i < $tokenCount; $i++) {
            $plain = Str::random(60);
            $tokens[] = AuthToken::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => "device-{$i}",
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addDays(30),
                'last_used_at' => now()->subMinutes($i * 10),
                'ip_address' => "192.168.1.{$i}",
                'user_agent' => "DeviceAgent/{$i}",
                'created_at' => now()->subDays($i),
                'updated_at' => now(),
            ]);
        }

        return [$tenant, $user, $tokens];
    }

    public function test_list_sessions_returns_active_tokens_for_user(): void
    {
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(3);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/sessions');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertSame('device-0', $response->json('data.0.name'));
    }

    public function test_list_sessions_does_not_include_revoked_tokens(): void
    {
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(3);
        AuthToken::query()->whereKey($tokens[1]->id)->update(['revoked_at' => now()]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/sessions');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_sessions_only_returns_current_user_tokens(): void
    {
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(2);
        $otherUser = User::factory()->create();
        $otherUser->tenants()->attach($tenant, ['status' => 'active']);
        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $otherUser->id,
            'name' => 'other-device',
            'token_hash' => hash('sha256', Str::random(60)),
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'OtherAgent',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/sessions');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_revoke_session_by_id(): void
    {
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(2);
        $currentToken = $tokens[0];

        $response = $this->withHeader('X-Tenant', $tenant->slug)
            ->actingAs($user)
            ->deleteJson("/api/auth/sessions/{$tokens[1]->id}");

        $response->assertOk();
        $this->assertDatabaseHas('auth_tokens', [
            'id' => $tokens[1]->id,
            'revoked_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_revoke_any_session_including_current_via_id(): void
    {
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(2);
        $currentToken = $tokens[0];

        $response = $this->withHeader('X-Tenant', $tenant->slug)
            ->actingAs($user)
            ->deleteJson("/api/auth/sessions/{$currentToken->id}");

        $response->assertOk();
        $this->assertDatabaseHas('auth_tokens', [
            'id' => $currentToken->id,
            'revoked_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_cannot_revoke_other_users_token(): void
    {
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(1);

        $otherUser = User::factory()->create();
        $otherUser->tenants()->attach($tenant, ['status' => 'active']);
        $otherToken = AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $otherUser->id,
            'name' => 'other-device',
            'token_hash' => hash('sha256', Str::random(60)),
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant', $tenant->slug)
            ->actingAs($user)
            ->deleteJson("/api/auth/sessions/{$otherToken->id}");

        $response->assertStatus(404);
    }

    public function test_cannot_revoke_token_from_other_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        [$tenant, $user, $tokens] = $this->makeUserWithTokens(1, $tenantA);

        $user->tenants()->attach($tenantB, ['status' => 'active']);

        $response = $this->withHeader('X-Tenant', $tenantB->slug)
            ->actingAs($user)
            ->deleteJson("/api/auth/sessions/{$tokens[0]->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('auth_tokens', [
            'id' => $tokens[0]->id,
            'revoked_at' => null,
        ]);
    }
}