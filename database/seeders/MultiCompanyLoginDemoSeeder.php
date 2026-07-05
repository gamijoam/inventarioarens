<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MultiCompanyLoginDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function (): void {
            $this->seedGroup([
                'manager_email' => 'gerente.caracas@demo.test',
                'manager_name' => 'Gerente Caracas',
                'cashier_email' => 'cajero.caracas@demo.test',
                'cashier_name' => 'Cajero Caracas',
                'companies' => [
                    [
                        'name' => 'Demo Caracas Norte',
                        'slug' => 'demo-caracas-norte',
                        'branch_name' => 'Principal Caracas Norte',
                        'branch_code' => 'CCN',
                        'warehouse_name' => 'Almacen Caracas Norte',
                        'warehouse_code' => 'CCN-01',
                        'products' => [
                            ['name' => 'Nevera Ejecutiva Caracas Norte', 'sku' => 'NEV-CCN-01', 'price' => 420, 'currency' => Product::CURRENCY_USD, 'stock' => 2],
                            ['name' => 'Cable USB-C Caracas Norte', 'sku' => 'USB-CCN-01', 'price' => 6, 'currency' => Product::CURRENCY_USD, 'stock' => 30],
                        ],
                    ],
                    [
                        'name' => 'Demo Caracas Este',
                        'slug' => 'demo-caracas-este',
                        'branch_name' => 'Principal Caracas Este',
                        'branch_code' => 'CCE',
                        'warehouse_name' => 'Almacen Caracas Este',
                        'warehouse_code' => 'CCE-01',
                        'products' => [
                            ['name' => 'Samsung A06 Caracas Este', 'sku' => 'SAM-CCE-01', 'price' => 115, 'currency' => Product::CURRENCY_USD, 'stock' => 8],
                            ['name' => 'Audifonos Caracas Este', 'sku' => 'AUD-CCE-01', 'price' => 18, 'currency' => Product::CURRENCY_USD, 'stock' => 15],
                        ],
                    ],
                ],
            ]);

            $this->seedGroup([
                'manager_email' => 'gerente.valencia@demo.test',
                'manager_name' => 'Gerente Valencia',
                'cashier_email' => 'cajero.valencia@demo.test',
                'cashier_name' => 'Cajero Valencia',
                'companies' => [
                    [
                        'name' => 'Demo Valencia Centro',
                        'slug' => 'demo-valencia-centro',
                        'branch_name' => 'Principal Valencia Centro',
                        'branch_code' => 'VLC',
                        'warehouse_name' => 'Almacen Valencia Centro',
                        'warehouse_code' => 'VLC-01',
                        'products' => [
                            ['name' => 'Laptop Oficina Valencia Centro', 'sku' => 'LAP-VLC-01', 'price' => 650, 'currency' => Product::CURRENCY_USD, 'stock' => 3],
                            ['name' => 'Mouse Inalambrico Valencia Centro', 'sku' => 'MOU-VLC-01', 'price' => 12, 'currency' => Product::CURRENCY_USD, 'stock' => 25],
                        ],
                    ],
                    [
                        'name' => 'Demo Valencia Norte',
                        'slug' => 'demo-valencia-norte',
                        'branch_name' => 'Principal Valencia Norte',
                        'branch_code' => 'VLN',
                        'warehouse_name' => 'Almacen Valencia Norte',
                        'warehouse_code' => 'VLN-01',
                        'products' => [
                            ['name' => 'iPhone 11 Valencia Norte', 'sku' => 'IPH-VLN-01', 'price' => 280, 'currency' => Product::CURRENCY_USD, 'stock' => 4],
                            ['name' => 'Cargador Rapido Valencia Norte', 'sku' => 'CRG-VLN-01', 'price' => 14, 'currency' => Product::CURRENCY_USD, 'stock' => 18],
                        ],
                    ],
                ],
            ]);
        });

        app(TenantManager::class)->clear();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedGroup(array $group): void
    {
        $manager = $this->user($group['manager_name'], $group['manager_email']);
        $cashier = $this->user($group['cashier_name'], $group['cashier_email']);

        foreach ($group['companies'] as $company) {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => $company['slug']],
                ['name' => $company['name'], 'status' => 'active', 'plan' => 'demo']
            );

            $this->useTenant($tenant);
            $this->attachUser($tenant, $manager, 'Gerente');
            $this->attachUser($tenant, $cashier, 'Vendedor');

            $branch = Branch::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $company['branch_code']],
                ['name' => $company['branch_name'], 'status' => Branch::STATUS_ACTIVE]
            );

            $warehouse = Warehouse::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $company['warehouse_code']],
                ['branch_id' => $branch->id, 'name' => $company['warehouse_name'], 'status' => Warehouse::STATUS_ACTIVE]
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
                    'source' => 'Demo multiempresa',
                ],
                [
                    'rate' => 500,
                    'effective_at' => now(),
                    'is_active' => true,
                ]
            );

            foreach ($company['products'] as $productData) {
                $product = Product::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => $productData['sku']],
                    [
                        'name' => $productData['name'],
                        'tracking_type' => Product::TRACKING_QUANTITY,
                        'base_price' => $productData['price'],
                        'sale_currency' => $productData['currency'],
                        'sale_exchange_rate_type_id' => $rateType->id,
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
                        'quantity_available' => $productData['stock'],
                        'quantity_reserved' => 0,
                        'quantity_damaged' => 0,
                    ]
                );
            }

            $managerRegister = $this->cashRegister($tenant, $branch, "Caja Gerente {$company['branch_code']}", "GER-{$company['branch_code']}");
            $cashierRegister = $this->cashRegister($tenant, $branch, "Caja Cajero {$company['branch_code']}", "CAJ-{$company['branch_code']}");

            $this->openCashRegister($tenant, $branch, $managerRegister, $manager);
            $this->openCashRegister($tenant, $branch, $cashierRegister, $cashier);
        }
    }

    private function user(string $name, string $email): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make(self::PASSWORD)]
        );
    }

    private function attachUser(Tenant $tenant, User $user, string $roleName): void
    {
        $tenant->users()->syncWithoutDetaching([
            $user->id => ['status' => 'active'],
        ]);

        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions(BasePermissions::ROLE_PERMISSIONS[$roleName]);
        $user->assignRole($role);
    }

    private function cashRegister(Tenant $tenant, Branch $branch, string $name, string $code): CashRegister
    {
        $this->useTenant($tenant);

        return CashRegister::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'branch_id' => $branch->id,
                'name' => $name,
                'status' => CashRegister::STATUS_ACTIVE,
                'notes' => 'Caja fisica demo para pruebas multiempresa.',
            ]
        );
    }

    private function openCashRegister(Tenant $tenant, Branch $branch, CashRegister $cashRegister, User $user): void
    {
        $this->useTenant($tenant);

        $session = CashRegisterSession::query()
            ->where('branch_id', $branch->id)
            ->where('cashier_id', $user->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->first();

        if ($session) {
            if (! $session->cash_register_id) {
                $physicalRegisterIsFree = ! CashRegisterSession::query()
                    ->where('cash_register_id', $cashRegister->id)
                    ->where('status', CashRegisterSession::STATUS_OPEN)
                    ->whereKeyNot($session->id)
                    ->exists();

                if ($physicalRegisterIsFree) {
                    $session->update(['cash_register_id' => $cashRegister->id]);
                }
            }

            return;
        }

        CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 0,
            'opening_local_amount' => 0,
            'expected_base_amount' => 0,
            'expected_local_amount' => 0,
            'opened_at' => now(),
            'notes' => 'Caja abierta por datos demo multiempresa.',
        ]);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
