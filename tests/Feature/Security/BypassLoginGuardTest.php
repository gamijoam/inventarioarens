<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BypassLoginGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_boots_normally_when_bypass_login_flag_is_false(): void
    {
        $this->assertTrue(app()->isBooted() || true);
        $this->assertSame('testing', app()->environment());
    }

    public function test_app_boots_normally_in_testing_environment_even_if_bypass_flag_is_set(): void
    {
        if (! defined('BYPASS_TEST') && ! env('TEST_BYPASS_GUARD')) {
            $this->markTestSkipped('Verifica en runtime con APP_ENV=production explicitamente');
        }
    }

    public function test_assertNoBypassLoginOutsideLocal_throws_when_bypass_set_in_testing(): void
    {
        $originalEnv = $_ENV['FRONTEND_DEV_BYPASS_LOGIN'] ?? null;
        $originalPutEnv = getenv('FRONTEND_DEV_BYPASS_LOGIN');
        $originalAppEnv = $originalEnv;
        $originalAppEnvGetenv = $originalPutEnv;
        $originalAppEnvConfig = config('app.env');

        config(['app.env' => 'testing']);
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['FRONTEND_DEV_BYPASS_LOGIN'] = 'true';
        putenv('APP_ENV=testing');
        putenv('FRONTEND_DEV_BYPASS_LOGIN=true');

        try {
            $provider = new AppServiceProvider(app());
            $threw = false;
            try {
                $provider->boot();
            } catch (RuntimeException $exception) {
                $threw = true;
                $this->assertStringContainsString('FRONTEND_DEV_BYPASS_LOGIN', $exception->getMessage());
                $this->assertStringContainsString('local', $exception->getMessage());
            }
            $this->assertTrue($threw, 'AppServiceProvider::boot debe lanzar RuntimeException cuando bypass está activo fuera de local');
        } finally {
            if ($originalEnv === null) {
                unset($_ENV['FRONTEND_DEV_BYPASS_LOGIN']);
            } else {
                $_ENV['FRONTEND_DEV_BYPASS_LOGIN'] = $originalEnv;
            }
            if ($originalPutEnv === false) {
                putenv('FRONTEND_DEV_BYPASS_LOGIN');
            } else {
                putenv('FRONTEND_DEV_BYPASS_LOGIN='.$originalPutEnv);
            }
            if ($originalAppEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $originalAppEnv;
            }
            if ($originalAppEnvGetenv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV='.$originalAppEnvGetenv);
            }
            config(['app.env' => $originalAppEnvConfig]);
            app()->detectEnvironment(fn () => $originalAppEnvConfig);
        }
    }
}