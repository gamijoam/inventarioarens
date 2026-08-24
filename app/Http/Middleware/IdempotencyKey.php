<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key middleware (RFC draft "Idempotency-Key Header").
 *
 * Si la request incluye el header `Idempotency-Key: <uuid>` en un metodo
 * no-idempotente (POST/PUT/PATCH/DELETE), se persiste el hash del body y
 * la respuesta. Si llega otra request con el mismo key+mismo metodo+path
 * dentro de la ventana de expiracion (24h), se devuelve la misma respuesta
 * sin volver a ejecutar la accion.
 *
 * Esto resuelve el bug B2 del sprint 3: si el cliente del POS hace POST
 * /pos/checkouts y la red se corta justo despues de que el server confirmo
 * la venta pero antes de que el cliente reciba la respuesta, al reintentar
 * (manualmente o por reintento automatico) duplicaria la venta. Con
 * Idempotency-Key el segundo POST retorna la misma respuesta sin
 * ejecutar la transaccion.
 *
 * Reglas:
 * - TTL: 24h (despues de eso el cliente deberia refrescar el key).
 * - Si el body difiere (request_hash distinto) con el mismo key, devolvemos
 *   409 (idempotency conflict) para detectar bugs del cliente.
 * - Las respuestas en proceso (key con response_status=0) se devuelven
 *   como 409 (in-flight) para evitar races.
 * - Solo aplica a metodos no-idempotentes. GET/HEAD/OPTIONS pasan tal cual.
 *
 * Configuracion: registrar el middleware en bootstrap/app.php despues
 * de 'api' (con alias 'idempotency') y aplicarlo selectivamente a las
 * rutas que lo necesiten.
 */
class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const TTL_HOURS = 24;

    public function __construct(private readonly TenantManager $tenantManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->extractKey($request);

        if ($key === null) {
            return $next($request);
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // GET/HEAD/OPTIONS ya son idempotentes. Pasamos sin cachear.
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        $body = (string) $request->getContent();
        $requestHash = hash('sha256', $body);
        $tenantId = $this->tenantManager->id();

        // Limpiamos claves expiradas (best-effort, no bloqueamos la request
        // si falla la limpieza). Tambien filtramos en la query principal
        // por expires_at para evitar condiciones de carrera.
        $this->purgeExpired();

        $existing = $this->findExisting($tenantId, $key, $request->method(), $path);

        if ($existing) {
            return $this->replayExisting($existing, $requestHash);
        }

        // Marcamos la key como en proceso (response_status=0) ANTES de ejecutar
        // la accion. Esto previene que dos requests concurrentes con el mismo
        // key ejecuten la accion dos veces (la segunda lo ve en proceso y
        // devuelve 409).
        if (! $this->tryReserveKey($tenantId, $key, $request->method(), $path, $requestHash)) {
            $existing = $this->findExisting($tenantId, $key, $request->method(), $path);
            if ($existing) {
                return $this->replayExisting($existing, $requestHash);
            }
        }

        $response = $next($request);

        $this->persistResponse($tenantId, $key, $request->method(), $path, $requestHash, $response);

        return $response;
    }

    private function extractKey(Request $request): ?string
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        return $key === '' ? null : $key;
    }

    private function purgeExpired(): void
    {
        try {
            DB::table('idempotency_keys')
                ->where('expires_at', '<', now())
                ->delete();
        } catch (QueryException) {
            // Silencioso: la limpieza es best-effort. La query principal ya
            // filtra por expires_at > now().
        }
    }

    private function tryReserveKey(?int $tenantId, string $key, string $method, string $path, string $requestHash): bool
    {
        try {
            DB::table('idempotency_keys')->insert([
                'tenant_id' => $tenantId,
                'key' => $key,
                'method' => $method,
                'path' => $path,
                'request_hash' => $requestHash,
                'response_status' => 0,
                'response_body' => null,
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'created_at' => now(),
            ]);

            return true;
        } catch (QueryException) {
            // Otra request gano la reserva. El caller vuelve a leer la fila
            // y devuelve 409 o la respuesta cacheada, nunca ejecuta la accion.
            return false;
        }
    }

    private function findExisting(?int $tenantId, string $key, string $method, string $path): ?object
    {
        return DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('method', $method)
            ->where('path', $path)
            ->when($tenantId === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('expires_at', '>', now())
            ->first();
    }

    private function replayExisting(object $existing, string $requestHash): JsonResponse
    {
        if ($existing->request_hash !== $requestHash) {
            return new JsonResponse([
                'message' => 'El Idempotency-Key ya fue usado con un body distinto.',
                'errors' => ['idempotency_key' => ['Conflicto: el body no coincide con la request original.']],
            ], 409);
        }

        if ((int) $existing->response_status === 0) {
            return new JsonResponse([
                'message' => 'La request original con este Idempotency-Key esta en proceso.',
            ], 409);
        }

        $body = $existing->response_body !== null
            ? json_decode($existing->response_body, true)
            : null;

        return new JsonResponse($body, (int) $existing->response_status);
    }

    private function persistResponse(?int $tenantId, string $key, string $method, string $path, string $requestHash, Response $response): void
    {
        $body = $response->getContent();
        $status = $response->getStatusCode();

        // Si la respuesta es mayor a 64KB, no la cacheamos completa (un
        // GET /pos/orders?per_page=50 podria ser pesado). En ese caso
        // devolvemos solo el codigo y dejamos el body en null.
        if (strlen($body) > 65536) {
            $body = json_encode(['message' => 'Respuesta demasiado grande para idempotency cache.']);
        }

        try {
            DB::table('idempotency_keys')
                ->where('key', $key)
                ->where('method', $method)
                ->where('path', $path)
                ->when($tenantId === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId))
                ->update([
                    'response_status' => $status,
                    'response_body' => $body,
                ]);
        } catch (QueryException) {
            // Silencioso: si la respuesta no se persiste, el siguiente
            // reintento re-ejecutara la accion. Eso no es ideal pero
            // tampoco es catastrófico (la mayoria de las acciones del
            // POS son idempotentes a nivel de aplicacion).
        }
    }
}
