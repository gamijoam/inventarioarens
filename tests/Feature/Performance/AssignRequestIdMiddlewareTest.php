<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;

class AssignRequestIdMiddlewareTest extends TestCase
{
    public function test_request_id_header_is_set_in_response(): void
    {
        $response = $this->getJson('/api/customers');

        $response->assertStatus(401);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_request_id_is_uuid_format(): void
    {
        $response = $this->getJson('/api/customers');

        $requestId = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $requestId,
            'X-Request-Id debe ser un UUID cuando no se envia en el request'
        );
    }

    public function test_request_id_preserves_client_supplied_value(): void
    {
        $clientId = 'client-correlation-abc-123';

        $response = $this->withHeader('X-Request-Id', $clientId)
            ->getJson('/api/customers');

        $this->assertSame(
            $clientId,
            $response->headers->get('X-Request-Id'),
            'X-Request-Id del cliente debe preservarse (sin caracteres especiales)'
        );
    }

    public function test_request_id_rejects_malicious_input_and_generates_new_one(): void
    {
        $malicious = "abc; DROP TABLE users; --";

        $response = $this->withHeader('X-Request-Id', $malicious)
            ->getJson('/api/customers');

        $newId = $response->headers->get('X-Request-Id');
        $this->assertNotSame($malicious, $newId, 'X-Request-Id malicioso debe ser ignorado y generar uno nuevo');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $newId
        );
    }

    public function test_request_id_rejects_too_long_input(): void
    {
        $tooLong = str_repeat('a', 200);

        $response = $this->withHeader('X-Request-Id', $tooLong)
            ->getJson('/api/customers');

        $this->assertNotSame($tooLong, $response->headers->get('X-Request-Id'));
    }

    public function test_request_id_is_unique_per_request(): void
    {
        $response1 = $this->getJson('/api/customers');
        $response2 = $this->getJson('/api/customers');

        $id1 = $response1->headers->get('X-Request-Id');
        $id2 = $response2->headers->get('X-Request-Id');

        $this->assertNotEmpty($id1);
        $this->assertNotEmpty($id2);
        $this->assertNotSame($id1, $id2, 'Cada request debe tener un ID unico');
    }

    public function test_request_id_is_attached_to_log_context(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('shareContext')
            ->once()
            ->with(\Mockery::on(function ($context) {
                return isset($context['request_id'])
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $context['request_id']) === 1
                    && array_key_exists('tenant_id', $context)
                    && array_key_exists('user_id', $context);
            }));

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('http_request', \Mockery::any());

        $this->getJson('/api/customers');
    }
}