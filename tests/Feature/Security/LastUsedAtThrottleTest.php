<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LastUsedAtThrottleTest extends TestCase
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

    private function makeTenantAndUserWithToken(): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Throttle', 'slug' => 'tienda-throttle']);
        $user = User::factory()->create(['email' => 'throttle@example.test', 'password' => 'Valid1234!']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $plainToken = \Illuminate\Support\Str::random(60);
        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $user, $plainToken];
    }

    public function test_consecutive_requests_within_5_minutes_do_not_update_last_used_at(): void
    {
        [$tenant, $user, $token] = $this->makeTenantAndUserWithToken();

        $originalTimestamp = AuthToken::query()->where('user_id', $user->id)->first()->last_used_at;

        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('X-Tenant', $tenant->slug)
                ->getJson('/api/auth/me')
                ->assertOk();
        }

        $currentTimestamp = AuthToken::query()->where('user_id', $user->id)->first()->last_used_at;

        $this->assertEquals(
            $originalTimestamp?->toIso8601String(),
            $currentTimestamp?->toIso8601String(),
            'last_used_at NO debe actualizarse en requests consecutivos dentro de 5 minutos'
        );
    }

    public function test_request_after_5_minutes_updates_last_used_at(): void
    {
        [$tenant, $user, $token] = $this->makeTenantAndUserWithToken();

        AuthToken::query()->where('user_id', $user->id)->update([
            'last_used_at' => now()->subMinutes(10),
        ]);

        $staleTimestamp = AuthToken::query()->where('user_id', $user->id)->first()->last_used_at;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertOk();

        $currentTimestamp = AuthToken::query()->where('user_id', $user->id)->first()->last_used_at;

        $this->assertNotEquals(
            $staleTimestamp?->toIso8601String(),
            $currentTimestamp?->toIso8601String(),
            'last_used_at SI debe actualizarse despues de 5 minutos'
        );
        $this->assertTrue($currentTimestamp->gt(now()->subMinutes(1)));
    }
}