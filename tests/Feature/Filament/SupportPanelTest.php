<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SupportPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_open_support_dashboard_and_read_only_tools(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get('/soporte')
            ->assertOk()
            ->assertSee('Resumen de soporte');

        foreach ([
            '/soporte/tenants',
            '/soporte/users',
            '/soporte/sync-nodes',
            '/soporte/sync-inboxes',
            '/soporte/sync-outboxes',
        ] as $path) {
            $this->get($path)->assertOk();
        }

        $this->assertFalse(Route::has('filament.soporte.resources.tenants.create'));
        $this->assertFalse(Route::has('filament.soporte.resources.users.edit'));
        $this->assertFalse(Route::has('filament.soporte.resources.sync-nodes.delete'));
    }

    public function test_regular_users_cannot_open_the_support_panel(): void
    {
        $user = User::factory()->create([
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/soporte')
            ->assertForbidden();
    }
}
