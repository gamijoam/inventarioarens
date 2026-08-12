<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SeedStressLabCommand extends Command
{
    protected $signature = 'stress:seed
        {--tenants=3 : Empresas web-only a preparar (3-20)}
        {--products=100 : Productos por empresa (10-10000)}
        {--prefix=loadtest : Prefijo seguro de los slugs creados}
        {--password=loadtest-password : Clave de los usuarios de carga}
        {--role=vendedor : Rol del usuario de carga (vendedor|gerente)}
        {--warehouses=1 : Almacenes por empresa (1-2)}
        {--supplier : Crea un proveedor de laboratorio por empresa}
        {--force : Confirma la creacion o actualizacion de datos de carga}
        {--allow-production : Permite ejecutarlo en produccion durante una ventana aprobada}';

    protected $description = 'Prepara empresas web-only aisladas para pruebas de carga y multi-tenant.';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Esta accion requiere --force para crear datos de carga.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('En produccion debes confirmar tambien --allow-production.');

            return self::FAILURE;
        }

        $tenantCount = (int) $this->option('tenants');
        $productCount = (int) $this->option('products');
        $prefix = strtolower(trim((string) $this->option('prefix')));
        $password = (string) $this->option('password');
        $role = strtolower(trim((string) $this->option('role')));
        $warehouses = (int) $this->option('warehouses');
        $withSupplier = (bool) $this->option('supplier');

        if (! in_array($role, ['vendedor', 'gerente'], true)) {
            $this->error('El rol debe ser vendedor o gerente.');

            return self::INVALID;
        }

        if ($warehouses < 1 || $warehouses > 2) {
            $this->error('El numero de almacenes debe estar entre 1 y 2.');

            return self::INVALID;
        }

        if ($tenantCount < 3 || $tenantCount > 20) {
            $this->error('El numero de empresas debe estar entre 3 y 20.');

            return self::INVALID;
        }

        if ($productCount < 10 || $productCount > 10000) {
            $this->error('El numero de productos por empresa debe estar entre 10 y 10000.');

            return self::INVALID;
        }

        if (! preg_match('/^[a-z0-9-]{3,30}$/', $prefix)) {
            $this->error('El prefijo solo puede incluir letras minusculas, numeros y guiones.');

            return self::INVALID;
        }

        if (strlen($password) < 12) {
            $this->error('Usa una clave de carga de al menos 12 caracteres.');

            return self::INVALID;
        }

        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function () use ($tenantCount, $productCount, $prefix, $password, $role, $warehouses, $withSupplier): void {
            for ($number = 1; $number <= $tenantCount; $number++) {
                $this->seedTenant($number, $productCount, $prefix, $password, $role, $warehouses, $withSupplier);
            }
        });

        app(TenantManager::class)->clear();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info("Laboratorio listo: {$tenantCount} empresas, {$productCount} productos por empresa.");
        $this->line("Usuarios: {$prefix}-01@loadtest.local hasta {$prefix}-".str_pad((string) $tenantCount, 2, '0', STR_PAD_LEFT).'@loadtest.local');
        $this->line('Estas empresas son web-only: no requieren worker local ni agente de impresion.');

        return self::SUCCESS;
    }

    private function seedTenant(int $number, int $productCount, string $prefix, string $password, string $role, int $warehouses, bool $withSupplier): void
    {
        $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        $slug = "{$prefix}-{$suffix}";

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => "Laboratorio Web {$suffix}",
                'status' => 'active',
                'plan' => 'loadtest',
                'parent_id' => null,
                'is_group' => false,
            ]
        );

        app(TenantManager::class)->set($tenant);

        $user = User::query()->updateOrCreate(
            ['email' => "{$slug}@loadtest.local"],
            [
                'name' => "Operador {$slug}",
                'password' => Hash::make($password),
            ]
        );

        $tenant->users()->syncWithoutDetaching([$user->id => ['status' => 'active']]);
        setPermissionsTeamId($tenant->id);
        $roleName = ucfirst($role);
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions(BasePermissions::ROLE_PERMISSIONS[$roleName]);
        $user->syncRoles([$role]);

        $branch = Branch::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => "LAB-{$suffix}"],
            ['name' => "Sucursal laboratorio {$suffix}", 'status' => Branch::STATUS_ACTIVE]
        );

        $warehouse = Warehouse::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => "LAB-{$suffix}-01"],
            [
                'branch_id' => $branch->id,
                'name' => "Almacen laboratorio {$suffix}",
                'status' => Warehouse::STATUS_ACTIVE,
            ]
        );

        if ($warehouses >= 2) {
            Warehouse::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => "LAB-{$suffix}-02"],
                [
                    'branch_id' => $branch->id,
                    'name' => "Almacen laboratorio {$suffix} 2",
                    'status' => Warehouse::STATUS_ACTIVE,
                ]
            );
        }

        if ($withSupplier) {
            Supplier::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'document_number' => "LAB-SUP-{$suffix}"],
                [
                    'name' => "Proveedor laboratorio {$suffix}",
                    'document_type' => Supplier::DOCUMENT_J,
                    'is_active' => true,
                ]
            );
        }

        $cashRegister = CashRegister::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => "LAB-{$suffix}-POS"],
            [
                'branch_id' => $branch->id,
                'name' => "Caja laboratorio {$suffix}",
                'status' => CashRegister::STATUS_ACTIVE,
            ]
        );

        $rateType = ExchangeRateType::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'BCV'],
            ['name' => 'Banco Central de Venezuela', 'is_default' => true, 'is_active' => true]
        );

        ExchangeRate::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'exchange_rate_type_id' => $rateType->id,
                'base_currency' => ExchangeRate::BASE_USD,
                'quote_currency' => ExchangeRate::QUOTE_VES,
                'source' => 'Laboratorio de carga',
            ],
            ['rate' => 500, 'effective_at' => now(), 'is_active' => true]
        );

        $cashMethod = PaymentMethod::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'LAB-CASH-USD'],
            [
                'name' => 'Efectivo USD laboratorio',
                'method' => 'cash',
                'currency_mode' => PaymentMethod::CURRENCY_USD,
                'requires_reference' => false,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $priceList = PriceList::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'LAB-BASE'],
            [
                'name' => 'Precio laboratorio',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
        PriceList::query()->whereKeyNot($priceList->id)->update(['is_default' => false]);
        $priceList->paymentMethods()->sync([$cashMethod->id => ['tenant_id' => $tenant->id]]);

        for ($productNumber = 1; $productNumber <= $productCount; $productNumber++) {
            $productSuffix = str_pad((string) $productNumber, 4, '0', STR_PAD_LEFT);
            $sku = strtoupper("{$prefix}-{$suffix}-{$productSuffix}");
            $price = 5 + (($productNumber % 30) * 3);

            $isSerialized = $productNumber === $productCount;
            $product = Product::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $sku],
                [
                    'name' => "Producto laboratorio {$suffix} {$productSuffix}",
                    'tracking_type' => $isSerialized ? Product::TRACKING_SERIALIZED : Product::TRACKING_QUANTITY,
                    'base_price' => $price,
                    'sale_currency' => Product::CURRENCY_USD,
                    'sale_exchange_rate_type_id' => $rateType->id,
                    'track_stock' => true,
                    'is_active' => true,
                ]
            );

            StockBalance::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity_available' => $isSerialized ? 100 : 500,
                    'quantity_reserved' => 0,
                    'quantity_damaged' => 0,
                ]
            );

            ProductPrice::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'product_id' => $product->id,
                    'price_list_id' => $priceList->id,
                ],
                [
                    'price' => $price,
                    'currency' => Product::CURRENCY_USD,
                    'exchange_rate_type_id' => $rateType->id,
                    'is_active' => true,
                ]
            );

            if ($isSerialized) {
                $this->seedSerializedUnits($tenant->id, $warehouse->id, $product->id, $suffix);
            }
        }

        $this->seedRaceProducts(
            tenantId: $tenant->id,
            warehouseId: $warehouse->id,
            rateTypeId: $rateType->id,
            priceListId: $priceList->id,
            prefix: $prefix,
            suffix: $suffix,
        );

        CashRegisterSession::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'cash_register_id' => $cashRegister->id,
                'cashier_id' => $user->id,
                'status' => CashRegisterSession::STATUS_OPEN,
            ],
            [
                'branch_id' => $branch->id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_base_amount' => 100,
                'opening_local_amount' => 50000,
            ]
        );

        $this->line("  OK {$slug}: {$productCount} productos, caja POS abierta y usuario {$slug}@loadtest.local");
    }

    private function seedSerializedUnits(int $tenantId, int $warehouseId, int $productId, string $suffix): void
    {
        for ($number = 1; $number <= 100; $number++) {
            ProductUnit::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'serial_number' => "LAB-IMEI-{$suffix}-".str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                ],
                [
                    'warehouse_id' => $warehouseId,
                    'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                    'status' => ProductUnit::STATUS_AVAILABLE,
                    'released_stock_movement_id' => null,
                ]
            );
        }
    }

    private function seedRaceProducts(int $tenantId, int $warehouseId, int $rateTypeId, int $priceListId, string $prefix, string $suffix): void
    {
        foreach ([
            ['sku' => strtoupper("{$prefix}-{$suffix}-RACE-QTY"), 'name' => 'Producto colision cantidad', 'tracking_type' => Product::TRACKING_QUANTITY],
            ['sku' => strtoupper("{$prefix}-{$suffix}-RACE-IMEI"), 'name' => 'Producto colision IMEI', 'tracking_type' => Product::TRACKING_SERIALIZED],
        ] as $definition) {
            $product = Product::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'sku' => $definition['sku']],
                [
                    'name' => $definition['name'],
                    'tracking_type' => $definition['tracking_type'],
                    'base_price' => 25,
                    'sale_currency' => Product::CURRENCY_USD,
                    'sale_exchange_rate_type_id' => $rateTypeId,
                    'track_stock' => true,
                    'is_active' => true,
                ]
            );

            StockBalance::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'product_id' => $product->id],
                ['quantity_available' => 1, 'quantity_reserved' => 0, 'quantity_damaged' => 0]
            );

            ProductPrice::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'product_id' => $product->id, 'price_list_id' => $priceListId],
                ['price' => 25, 'currency' => Product::CURRENCY_USD, 'exchange_rate_type_id' => $rateTypeId, 'is_active' => true]
            );

            if ($definition['tracking_type'] === Product::TRACKING_SERIALIZED) {
                ProductUnit::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'product_id' => $product->id, 'serial_number' => "LAB-RACE-IMEI-{$suffix}"],
                    [
                        'warehouse_id' => $warehouseId,
                        'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                        'status' => ProductUnit::STATUS_AVAILABLE,
                        'released_stock_movement_id' => null,
                    ]
                );
            }
        }
    }
}
