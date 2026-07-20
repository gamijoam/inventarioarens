<?php

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Warehouses\Models\Warehouse;
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
        $tenantManager = app(\App\Support\Tenancy\TenantManager::class);
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
            $query->where('serial_number', 'like', $search . '%');
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
}