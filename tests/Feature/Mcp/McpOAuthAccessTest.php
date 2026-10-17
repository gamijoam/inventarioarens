<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class McpOAuthAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_configured_platform_admin_can_start_the_oauth_login(): void
    {
        User::create([
            'name' => 'MCP Admin',
            'email' => 'admin@balanzapro.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => true,
        ]);
        User::create([
            'name' => 'Other Admin',
            'email' => 'other@balanzapro.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => true,
        ]);

        $this->post('/mcp/login', [
            'email' => 'other@balanzapro.com',
            'password' => 'secret123',
        ])->assertSessionHasErrors('email');

        $this->post('/mcp/login', [
            'email' => 'admin@balanzapro.com',
            'password' => 'secret123',
        ])->assertRedirect(route('spa.root'));

        $this->assertAuthenticatedAs(User::query()->where('email', 'admin@balanzapro.com')->first());
    }

    public function test_mcp_endpoint_is_public(): void
    {
        $this->postJson('/mcp', [])->assertStatus(400);
    }

    public function test_oauth_authorization_state_uses_php_session_serialization(): void
    {
        $this->assertSame('php', config('session.serialization'));
    }

    public function test_oauth_metadata_is_not_exposed_for_the_public_mcp(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertNotFound();
    }

    public function test_oauth_authorization_view_does_not_close_the_oauth_window(): void
    {
        $view = file_get_contents(resource_path('views/mcp/authorize.blade.php'));

        $this->assertStringNotContainsString('window.close()', $view);
    }
}
