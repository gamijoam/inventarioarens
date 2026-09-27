<?php

namespace Tests\Unit\Support;

use App\Support\Realtime\WsHub;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WsHubTest extends TestCase
{
    public function test_publish_sends_post_request_to_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true, 'delivered' => 2], 200),
        ]);

        $result = WsHub::publish('tenant:1', 'stock.updated', ['product_id' => 10, 'qty' => 5]);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === 'tenant:1'
                && $request['event'] === 'stock.updated'
                && $request['data']['product_id'] === 10;
        });
    }

    public function test_publish_tenant_formats_channel_properly(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true, 'delivered' => 1], 200),
        ]);

        $result = WsHub::publishTenant(4, 'rate.updated', ['rate' => 45.5]);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request['channel'] === 'tenant:4'
                && $request['event'] === 'rate.updated';
        });
    }

    public function test_publish_returns_false_and_does_not_throw_on_server_failure(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['error' => 'Internal error'], 500),
        ]);

        $result = WsHub::publish('global', 'alert', ['msg' => 'System maintenance']);

        $this->assertFalse($result);
    }
}
