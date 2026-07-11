<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;

class JsonLoggingChannelTest extends TestCase
{
    public function test_json_channel_is_defined_in_config_logging(): void
    {
        $config = require base_path('config/logging.php');

        $this->assertArrayHasKey('json', $config['channels']);
        $this->assertSame('monolog', $config['channels']['json']['driver']);
        $this->assertSame(\Monolog\Handler\StreamHandler::class, $config['channels']['json']['handler']);
        $this->assertSame(\Monolog\Formatter\JsonFormatter::class, $config['channels']['json']['formatter']);
    }

    public function test_json_channel_path_resolves_to_storage_logs(): void
    {
        $config = require base_path('config/logging.php');

        $this->assertStringEndsWith(
            'laravel.json',
            $config['channels']['json']['handler_with']['stream']
        );
    }

    public function test_json_channel_uses_default_log_level_from_env(): void
    {
        $config = require base_path('config/logging.php');

        $this->assertArrayHasKey('level', $config['channels']['json']);
    }

    public function test_json_channel_processor_uses_psr_log_message_format(): void
    {
        $config = require base_path('config/logging.php');

        $this->assertContains(
            \Monolog\Processor\PsrLogMessageProcessor::class,
            $config['channels']['json']['processors']
        );
    }

    public function test_json_channel_is_listed_in_default_stack_compatible(): void
    {
        $config = require base_path('config/logging.php');

        $this->assertArrayHasKey('json', $config['channels']);
        $this->assertTrue(
            in_array('json', array_keys($config['channels'])),
            'json debe ser un channel valido para incluir en LOG_STACK'
        );
    }
}