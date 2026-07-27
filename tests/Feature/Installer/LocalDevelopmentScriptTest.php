<?php

namespace Tests\Feature\Installer;

use Tests\TestCase;

class LocalDevelopmentScriptTest extends TestCase
{
    public function test_linux_local_script_forces_http_safe_cookie_settings_and_shared_ports(): void
    {
        $script = file_get_contents(base_path('scripts/run-local-sqlite.sh'));

        $this->assertStringContainsString('export APP_ENV=local', $script);
        $this->assertStringContainsString('export SESSION_SECURE_COOKIE=false', $script);
        $this->assertStringContainsString('export VITE_API_BASE_URL="http://127.0.0.1:${API_PORT}/api"', $script);
        $this->assertStringContainsString('php artisan serve --host=127.0.0.1 --port="$API_PORT"', $script);
        $this->assertStringContainsString('pnpm --dir frontend dev --host 127.0.0.1 --port="$FRONTEND_PORT"', $script);
    }
}
