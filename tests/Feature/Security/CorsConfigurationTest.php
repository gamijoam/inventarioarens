<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_cors_config_file_exists_and_loads(): void
    {
        $this->assertFileExists(base_path('config/cors.php'));

        $config = require base_path('config/cors.php');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('paths', $config);
        $this->assertArrayHasKey('allowed_methods', $config);
        $this->assertArrayHasKey('allowed_origins', $config);
        $this->assertArrayHasKey('allowed_headers', $config);
    }

    public function test_cors_config_protects_api_paths_only(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertContains('api/*', $config['paths']);
    }

    public function test_cors_config_includes_production_origin(): void
    {
        $config = require base_path('config/cors.php');

        $origins = $config['allowed_origins'];
        $hasProd = false;
        foreach ($origins as $origin) {
            if (str_contains((string) $origin, 'app.miinventariofacil.com')) {
                $hasProd = true;
                break;
            }
        }
        $this->assertTrue($hasProd, 'Debe incluir https://app.miinventariofacil.com en allowed_origins');
    }

    public function test_cors_config_supports_credentials_for_sanctum_compatibility(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertTrue($config['supports_credentials'], 'supports_credentials debe ser true para Sanctum/CSRF');
    }

    public function test_cors_config_exposes_rate_limit_headers(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertContains('X-RateLimit-Limit', $config['exposed_headers']);
        $this->assertContains('X-RateLimit-Remaining', $config['exposed_headers']);
    }

    public function test_cors_response_includes_acao_header_for_allowed_origin(): void
    {
        config()->set('cors.allowed_origins', ['https://app.miinventariofacil.com']);

        $response = $this->call('OPTIONS', '/api/customers', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.miinventariofacil.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertEquals(
            'https://app.miinventariofacil.com',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_cors_blocks_disallowed_origin(): void
    {
        config()->set('cors.allowed_origins', ['https://app.miinventariofacil.com']);

        $response = $this->call('OPTIONS', '/api/customers', [], [], [], [
            'HTTP_ORIGIN' => 'https://malicious-site.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $acao = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotEquals('https://malicious-site.example.com', $acao);
    }
}