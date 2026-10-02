<?php

namespace App\Modules\Offline\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfflineController extends Controller
{
    public function exportDatabase(Request $request): BinaryFileResponse|JsonResponse
    {
        $slug = $request->query('slug') 
            ?? $request->header('X-Tenant') 
            ?? optional($request->user()?->tenants()->first())->slug
            ?? 'superleopard';

        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) {
            return response()->json([
                'status' => 'error',
                'message' => "Tenant no encontrado: $slug",
            ], 404);
        }

        $sqlitePath = storage_path('app/offline_packages/' . $tenant->slug . '/database.sqlite');
        $needsGeneration = !file_exists($sqlitePath) 
            || $request->query('fresh') === '1' 
            || (time() - filemtime($sqlitePath) > 7200);

        if ($needsGeneration) {
            $scriptPath = base_path('scripts/export_full_tenant_sqlite.php');
            exec("php " . escapeshellarg($scriptPath) . " " . escapeshellarg($tenant->slug) . " > /dev/null 2>&1");
        }

        if (!file_exists($sqlitePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo generar la base de datos offline para la empresa.',
            ], 500);
        }

        return response()->download($sqlitePath, 'database.sqlite', [
            'Content-Type' => 'application/x-sqlite3',
        ]);
    }

    public function exportConfig(Request $request): JsonResponse
    {
        $slug = $request->query('slug') 
            ?? $request->header('X-Tenant') 
            ?? optional($request->user()?->tenants()->first())->slug
            ?? 'superleopard';

        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) {
            return response()->json([
                'status' => 'error',
                'message' => "Tenant no encontrado: $slug",
            ], 404);
        }

        $cloudUrl = $request->getSchemeAndHttpHost();

        $configData = [
            'tenant_slug'   => $tenant->slug,
            'company_name'  => $tenant->name,
            'cloud_url'     => $cloudUrl,
            'proxy_port'    => 8787,
            'backend_port'  => 8788,
            'offline_mode'  => true,
        ];

        return response()->json($configData, 200, [
            'Content-Disposition' => 'attachment; filename="config.json"',
        ]);
    }

    public function exportPackage(Request $request): BinaryFileResponse|JsonResponse
    {
        $slug = $request->query('slug') 
            ?? $request->header('X-Tenant') 
            ?? optional($request->user()?->tenants()->first())->slug
            ?? 'superleopard';

        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) {
            return response()->json([
                'status' => 'error',
                'message' => "Tenant no encontrado: $slug",
            ], 404);
        }

        $zipPath = storage_path('app/offline_packages/paquete_offline_' . $tenant->slug . '.zip');

        // If package doesn't exist or is older than 2 hours, generate it
        if (!file_exists($zipPath) || (time() - filemtime($zipPath) > 7200)) {
            $scriptPath = base_path('scripts/export_offline_package.php');
            exec("php " . escapeshellarg($scriptPath) . " " . escapeshellarg($tenant->slug) . " > /dev/null 2>&1");
        }

        if (!file_exists($zipPath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo generar el archivo de paquete offline.',
            ], 500);
        }

        return response()->download($zipPath, 'paquete_offline_' . $tenant->slug . '.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function uploadSales(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_slug' => 'required|string',
            'sales'       => 'required|array',
        ]);

        $tenant = Tenant::where('slug', $payload['tenant_slug'])->first();
        if (!$tenant) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tenant no encontrado: ' . $payload['tenant_slug'],
            ], 404);
        }

        $sales = $payload['sales'];
        $syncedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($sales as $saleData) {
                // Check if local code already recorded to avoid duplicates
                $exists = DB::table('audit_logs')
                    ->where('tenant_id', $tenant->id)
                    ->where('action', 'offline.sale.uploaded')
                    ->where('entity_type', $saleData['local_code'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Deduct stock in PostgreSQL
                if (!empty($saleData['items'])) {
                    foreach ($saleData['items'] as $item) {
                        $productId = (int)$item['product_id'];
                        $qty = (float)$item['quantity'];

                        DB::table('stock_balances')
                            ->where('tenant_id', $tenant->id)
                            ->where('product_id', $productId)
                            ->decrement('quantity_available', $qty);
                    }
                }

                // Record audit log for tracking
                DB::table('audit_logs')->insert([
                    'tenant_id'   => $tenant->id,
                    'user_id'     => null,
                    'action'      => 'offline.sale.uploaded',
                    'entity_type' => $saleData['local_code'],
                    'entity_id'   => 0,
                    'new_values'  => json_encode($saleData),
                    'ip_address'  => $request->ip(),
                    'user_agent'  => 'SistemaInventario-Go/1.0',
                    'created_at'  => now(),
                ]);

                $syncedCount++;
            }

            DB::commit();

            return response()->json([
                'status'       => 'success',
                'message'      => "$syncedCount ventas procesadas en la nube",
                'synced_count' => $syncedCount,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Error guardando ventas: ' . $e->getMessage(),
            ], 500);
        }
    }
}
