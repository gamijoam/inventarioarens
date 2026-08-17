<?php

namespace Tests\Feature\Infrastructure;

use App\Http\Middleware\IdempotencyKey;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IdempotencyKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_is_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $key = 'shared-key';
        $middleware = app(IdempotencyKey::class);

        app(TenantManager::class)->set($tenantA);
        $first = $middleware->handle(
            $this->request($key),
            fn () => new JsonResponse(['tenant_id' => $tenantA->id], 201),
        );

        app(TenantManager::class)->set($tenantB);
        $second = $middleware->handle(
            $this->request($key),
            fn () => new JsonResponse(['tenant_id' => $tenantB->id], 201),
        );

        $this->assertSame($tenantA->id, $this->responseJson($first)->json('tenant_id'));
        $this->assertSame($tenantB->id, $this->responseJson($second)->json('tenant_id'));
        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }

    public function test_duplicate_reservation_is_returned_as_in_flight_without_executing_the_action(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Race', 'slug' => 'empresa-race']);
        app(TenantManager::class)->set($tenant);
        $key = 'race-key';
        $request = $this->request($key);
        $requestHash = hash('sha256', (string) $request->getContent());
        $injected = false;
        $executions = 0;

        DB::listen(function ($query) use (&$injected, $key, $requestHash, $tenant): void {
            if ($injected || ! str_contains(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'idempotency_keys')) {
                return;
            }

            $injected = true;
            DB::table('idempotency_keys')->insert([
                'tenant_id' => $tenant->id,
                'key' => $key,
                'method' => 'POST',
                'path' => '/api/idempotency-test',
                'request_hash' => $requestHash,
                'response_status' => 0,
                'response_body' => null,
                'expires_at' => now()->addHours(IdempotencyKey::TTL_HOURS),
                'created_at' => now(),
            ]);
        });

        $response = app(IdempotencyKey::class)->handle($request, function () use (&$executions): JsonResponse {
            $executions++;

            return new JsonResponse(['executed' => true], 201);
        });

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, $executions);
        $this->assertTrue($injected);
    }

    public function test_exception_releases_in_flight_reservation_for_a_later_retry(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Retry', 'slug' => 'empresa-retry']);
        app(TenantManager::class)->set($tenant);
        $middleware = app(IdempotencyKey::class);
        $request = $this->request('retry-after-error');

        $this->expectException(HttpException::class);

        try {
            $middleware->handle($request, function (): never {
                throw new HttpException(503, 'Servicio temporalmente no disponible.');
            });
        } finally {
            $this->assertDatabaseMissing('idempotency_keys', [
                'tenant_id' => $tenant->id,
                'key' => 'retry-after-error',
            ]);
        }
    }

    public function test_rejects_an_idempotency_key_longer_than_the_database_column(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Long Key', 'slug' => 'empresa-long-key']);
        app(TenantManager::class)->set($tenant);
        $executions = 0;

        $response = app(IdempotencyKey::class)->handle(
            $this->request(str_repeat('k', 192)),
            function () use (&$executions): JsonResponse {
                $executions++;

                return new JsonResponse(['executed' => true], 201);
            },
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, $executions);
    }

    private function request(string $key): Request
    {
        $request = Request::create(
            '/api/idempotency-test',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['value' => 'same'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set(IdempotencyKey::HEADER, $key);

        return $request;
    }

    private function responseJson($response): TestResponse
    {
        return TestResponse::fromBaseResponse($response);
    }
}
