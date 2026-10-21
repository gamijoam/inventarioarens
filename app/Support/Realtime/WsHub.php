<?php

namespace App\Support\Realtime;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WsHub
{
    /**
     * Publish an event to a specific channel on the WebSocket Hub.
     */
    public static function publish(string $channel, string $event, array $data = []): bool
    {
        $url = config('services.ws_hub.url', 'http://127.0.0.1:16666/publish');
        $key = config('services.ws_hub.key');

        try {
            $request = Http::timeout(1)->asJson();
            if (! empty($key)) {
                $request->withHeader('X-Auth-Key', $key);
            }

            $response = $request->post($url, [
                'channel' => $channel,
                'event' => $event,
                'data' => $data,
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('[WsHub] Failed to publish event', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish a tenant-scoped event (channel: "tenant:{tenant_id}").
     */
    public static function publishTenant(int|string $tenantId, string $event, array $data = []): bool
    {
        return self::publish("tenant:{$tenantId}", $event, $data);
    }
}
