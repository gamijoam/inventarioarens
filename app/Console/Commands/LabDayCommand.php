<?php

namespace App\Console\Commands;

use App\Support\Lab\LabDayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LabDayCommand extends Command
{
    protected $signature = 'lab:day
        {--tenants=3 : Empresas de laboratorio a preparar (3-5)}
        {--products=10 : Productos por empresa (10-200)}
        {--prefix=labday : Prefijo seguro de los slugs creados}
        {--password= : Clave de los usuarios de laboratorio (min 12)}
        {--sales=10 : Ventas POS simuladas por empresa}
        {--base-url=http://127.0.0.1:8000/api : API destino (local o VPS)}
        {--seed-only : Solo prepara los datos del laboratorio, no ejecuta el ciclo}
        {--dry-run : Prepara datos y valida acceso, sin ejecutar ventas/operaciones}
        {--force : Confirma la creacion de datos de laboratorio}
        {--allow-production : Permite ejecutarlo contra la nube durante una ventana aprobada}';

    protected $description = 'Laboratorio de dia simulado: prepara datos desechables y ejecuta un ciclo real de negocio (POS, devolucion, compra, traslado) contra la API.';

    public function handle(LabDayService $service): int
    {
        $tenantCount = (int) $this->option('tenants');
        $productCount = (int) $this->option('products');
        $prefix = strtolower(trim((string) $this->option('prefix')));
        $password = (string) $this->option('password');
        $sales = (int) $this->option('sales');
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $seedOnly = (bool) $this->option('seed-only');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->option('force')) {
            $this->error('Esta accion requiere --force para crear datos de laboratorio.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('En produccion debes confirmar tambien --allow-production.');

            return self::FAILURE;
        }

        if ($tenantCount < 3 || $tenantCount > 5) {
            $this->error('El numero de empresas debe estar entre 3 y 5.');

            return self::INVALID;
        }

        if ($productCount < 10 || $productCount > 200) {
            $this->error('El numero de productos por empresa debe estar entre 10 y 200.');

            return self::INVALID;
        }

        if (! preg_match('/^[a-z0-9-]{3,30}$/', $prefix)) {
            $this->error('El prefijo solo puede incluir letras minusculas, numeros y guiones.');

            return self::INVALID;
        }

        if (strlen($password) < 12) {
            $this->error('Usa una clave de laboratorio de al menos 12 caracteres.');

            return self::INVALID;
        }

        $this->info("Laboratorio de dia simulado ({$tenantCount} empresa(s), {$sales} ventas POS por empresa).");
        $this->line("  Seed: {$prefix}-01..0{$tenantCount} / {$productCount} productos.");
        $this->line("  API:  {$baseUrl}");

        if (app()->environment('production')) {
            $this->line('  NOTA: destino de produccion aprobado. Usa datos desechables '.$prefix.'-*.');
        }

        $this->line('  Preparando datos de laboratorio (rol Gerente, 2 almacenes, proveedor)...');
        $seed = $this->callSilently('stress:seed', [
            '--tenants' => $tenantCount,
            '--products' => $productCount,
            '--prefix' => $prefix,
            '--password' => $password,
            '--role' => 'gerente',
            '--warehouses' => 2,
            '--supplier' => true,
            '--force' => true,
            '--allow-production' => $this->option('allow-production'),
        ]);

        if ($seed !== self::SUCCESS) {
            $this->error('Fallo la preparacion de los datos de laboratorio.');

            return self::FAILURE;
        }

        if ($seedOnly) {
            $this->info('Datos de laboratorio listos (seed-only).');

            return self::SUCCESS;
        }

        $report = [
            'created_at' => now()->toIso8601String(),
            'prefix' => $prefix,
            'base_url' => $baseUrl,
            'tenants' => [],
        ];

        for ($number = 1; $number <= $tenantCount; $number++) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $slug = "{$prefix}-{$suffix}";
            $email = "{$slug}@loadtest.local";

            $config = [
                'base_url' => $baseUrl,
                'tenant' => $slug,
                'email' => $email,
                'password' => $password,
                'sales' => $sales,
                'warehouse_origin' => "LAB-{$suffix}-01",
                'warehouse_destination' => "LAB-{$suffix}-02",
                'supplier_document' => "LAB-SUP-{$suffix}",
            ];

            $this->newLine();
            $this->line("> {$slug} ({$email})");

            try {
                if ($dryRun) {
                    $tenantReport = ['mode' => 'dry-run', 'prepared' => true];
                    $this->line('  dry-run: datos de laboratorio preparados (no se ejecuto el ciclo HTTP).');
                } else {
                    $tenantReport = $service->runDay($config);
                    $this->line('  POS ventas: '.$tenantReport['phases']['sales']['paid'].'/'.$tenantReport['phases']['sales']['attempts'].' pagadas.');
                    $this->line('  Devolucion: '.((($tenantReport['phases']['sales_return']['processed'] ?? false) === true) ? 'OK' : 'no aplica'));
                    $this->line('  Compra recibida: '.($tenantReport['phases']['purchase']['received'] ?? 'n/a'));
                    $this->line('  Traslado completado: '.((($tenantReport['phases']['transfer']['received'] ?? false) === true) ? 'OK' : 'no aplica'));
                }

                $report['tenants'][$slug] = $tenantReport;
            } catch (Throwable $error) {
                $this->error('  FALLO: '.$error->getMessage());
                $report['tenants'][$slug] = ['error' => $error->getMessage()];
            }
        }

        $path = $this->storeReport($prefix, $report);
        $this->newLine();
        $this->info('Reporte del laboratorio: '.$path);

        $failed = collect($report['tenants'])->filter(fn ($tenant) => isset($tenant['error']))->count();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function storeReport(string $prefix, array $report): string
    {
        $directory = 'lab-reports/'.date('Y-m-d');
        $path = $directory.'/'.$prefix.'-'.date('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 'storage/app/'.$path;
    }
}
