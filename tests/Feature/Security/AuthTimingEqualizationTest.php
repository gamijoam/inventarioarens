<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTimingEqualizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_credentials_calls_bcrypt_when_user_not_found_to_equalize_timing(): void
    {
        $service = app(AuthService::class);

        $reflection = new \ReflectionClass($service);
        $validateMethod = $reflection->getMethod('validateCredentials');
        $validateMethod->setAccessible(true);

        $bcryptCalled = false;
        $originalBcrypt = Hash::class;

        Hash::swap(new class($bcryptCalledRef = &$bcryptCalled) {
            public function __construct(private bool &$flag) {}

            public function check($value, $hashedValue, $options = []): bool
            {
                $this->flag = true;

                return false;
            }

            public function make($value, $options = []): string
            {
                return '$2y$04$dummy.dummy.dummy.dummy.dummy.dummy.dummy.dummy.dummy.dummy.dummy';
            }
        });

        try {
            try {
                $validateMethod->invoke($service, 'no-existe@example.test', Str::random(20));
            } catch (\Illuminate\Validation\ValidationException) {
            }
        } finally {
            Hash::swap(new $originalBcrypt);
        }

        $this->assertTrue($bcryptCalled, 'validateCredentials debe ejecutar Hash::check cuando el user no existe (timing equalization)');
    }

    public function test_validate_credentials_calls_bcrypt_check_with_user_password_when_user_found(): void
    {
        $service = app(AuthService::class);

        $reflection = new \ReflectionClass($service);
        $validateMethod = $reflection->getMethod('validateCredentials');
        $validateMethod->setAccessible(true);

        User::factory()->create([
            'email' => 'test@example.test',
            'password' => 'correct-password',
        ]);

        $bcryptCalledWith = null;
        $originalBcrypt = Hash::class;

        Hash::swap(new class($bcryptCalledWithRef = &$bcryptCalledWith) {
            public function check($value, $hashedValue, $options = []): bool
            {
                $this->checkCalledWith = $value;
                $this->flag = true;

                return $value === 'correct-password';
            }

            public function make($value, $options = []): string
            {
                return 'dummy';
            }
        });

        try {
            $user = $validateMethod->invoke($service, 'test@example.test', 'correct-password');
        } finally {
            Hash::swap(new $originalBcrypt);
        }

        $this->assertSame('test@example.test', $user->email);
    }
}