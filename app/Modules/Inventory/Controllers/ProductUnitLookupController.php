<?php

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints de lectura para ProductUnits (usado por el IMEI scanner del
 * modulo de traslados en el frontend). El user Owner del grupo o
 * Administrador puede listar las unidades serializadas disponibles
 * de un almacen para usarlas en crear/preparar/recibir traslados.
 *
 * - GET /api/inventory-centers/products/{product}/units
 *     Query params:
 *       - warehouse_id (required): ID del almacen.
 *       - status (optional, default 'available'): available|reserved|sold|removed.
 *       - search (optional): prefijo de serial_number para filtrar.
 *       - limit (optional, default 100, max 500).
 *
 * Multi-tenancy: el warehouse debe pertenecer al tenant actual. Las
 * ProductUnits devueltas son solo de ese warehouse.
 */
class ProductUnitLookupController extends Controller
{
    /**
     * Lista ProductUnits de un producto en un almacen, con filtros
     * opcionales por status y prefijo de serial. Solo lectura.
     */
    public function index(Request $request, int $product): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id');
        if (! is_numeric($warehouseId)) {
            return response()->json([
                'message' => 'El parametro warehouse_id es requerido.',
            ], 422);
        }

        // Multi-tenancy: el warehouse debe pertenecer al tenant actual.
        $tenantManager = app(TenantManager::class);
        $tenantId = $tenantManager->require()->id;
        $warehouse = Warehouse::query()
            ->where('id', (int) $warehouseId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $warehouse) {
            return response()->json([
                'message' => 'Almacen no encontrado en el tenant actual.',
            ], 404);
        }

        // Validar que el producto pertenece al tenant.
        $productExists = DB::table('products')
            ->where('id', $product)
            ->where('tenant_id', $tenantId)
            ->exists();
        if (! $productExists) {
            return response()->json([
                'message' => 'Producto no encontrado en el tenant actual.',
            ], 404);
        }

        $status = $request->query('status', 'available');
        $search = trim((string) $request->query('search', ''));
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        $query = ProductUnit::query()
            ->withoutGlobalScopes()
            ->where('product_id', $product)
            ->where('warehouse_id', $warehouse->id);

        if (in_array($status, ['available', 'reserved', 'sold', 'removed'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where('serial_number', 'like', $search.'%');
        }

        $units = $query
            ->orderBy('serial_number')
            ->limit($limit)
            ->get(['id', 'product_id', 'warehouse_id', 'serial_type', 'serial_number', 'status']);

        return response()->json([
            'data' => $units->map(fn (ProductUnit $u) => [
                'id' => $u->id,
                'product_id' => $u->product_id,
                'warehouse_id' => $u->warehouse_id,
                'serial_type' => $u->serial_type,
                'serial_number' => $u->serial_number,
                'status' => $u->status,
            ])->all(),
            'meta' => [
                'total' => $units->count(),
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Lookup exacto por (warehouse_id, serial_type, serial_number).
     *
     * Pensado para scanners de barcode del POS: el cajero escanea un
     * IMEI/serial, este endpoint valida que la unidad existe, pertenece
     * al tenant activo, y al warehouse seleccionado. Devuelve 200 con la
     * unidad (incluso si esta sold/removed, para que el POS distinga entre
     * "no existe" vs "ya no esta disponible") o 404 si no se encontro.
     *
     * Multi-tenancy estricto: el warehouse y la unit deben pertenecer
     * al tenant actual. El POS valida antes de aceptar unidades en el
     * carrito, sin confiar en IDs autoincrementales que no son identidad
     * valida entre nodos.
     *
     * Query params:
     *   - warehouse_id (required)
     *   - serial (required): serial_number exacto a buscar
     *   - serial_type (optional, default 'imei'): imei | serial
     */
    public function lookup(Request $request): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id');
        $serial = trim((string) $request->query('serial', ''));
        $serialType = strtolower((string) $request->query('serial_type', 'imei'));

        if (! is_numeric($warehouseId)) {
            return response()->json([
                'message' => 'El parametro warehouse_id es requerido.',
            ], 422);
        }

        if ($serial === '') {
            return response()->json([
                'message' => 'El parametro serial es requerido.',
            ], 422);
        }

        if (! in_array($serialType, ['imei', 'serial'], true)) {
            return response()->json([
                'message' => 'El parametro serial_type debe ser imei o serial.',
            ], 422);
        }

        $tenantManager = app(TenantManager::class);
        $tenantId = $tenantManager->require()->id;

        $warehouse = Warehouse::query()
            ->where('id', (int) $warehouseId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $warehouse) {
            return response()->json([
                'message' => 'Almacen no encontrado en el tenant actual.',
            ], 404);
        }

        $unit = ProductUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouse->id)
            ->where('serial_type', $serialType)
            ->where('serial_number', $serial)
            ->first(['id', 'product_id', 'warehouse_id', 'serial_type', 'serial_number', 'status']);

        if (! $unit) {
            return response()->json([
                'message' => "No se encontro la unidad {$serialType}={$serial} en el almacen.",
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $unit->id,
                'product_id' => $unit->product_id,
                'warehouse_id' => $unit->warehouse_id,
                'serial_type' => $unit->serial_type,
                'serial_number' => $unit->serial_number,
                'status' => $unit->status,
            ],
        ]);
    }
}
