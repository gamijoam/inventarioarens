<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);
        $request->headers->set('X-Request-Id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
            'tenant_id' => app(TenantManager::class)->current()?->id,
            'user_id' => $request->user()?->id,
        ]);

        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } finally {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $context = [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response?->getStatusCode() ?? 0,
                'elapsed_ms' => $elapsedMs,
            ];

            if ($request->is('oauth/authorize') && $response?->isRedirection()) {
                $location = parse_url((string) $response->headers->get('Location'));
                $context['redirect_destination'] = ($location['host'] ?? '').($location['path'] ?? '');
            }

            Log::info('http_request', $context);
        }

        $response?->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $existing = trim((string) $request->headers->get('X-Request-Id'));
        if ($existing !== '' && strlen($existing) <= 100 && preg_match('/^[A-Za-z0-9_\-:.]+$/', $existing)) {
            return $existing;
        }

        return (string) Str::uuid();
    }
}
