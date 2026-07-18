<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OperationalReportsDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(MultiCompanyLoginDemoSeeder::class);
        $this->call(DemoDataSeeder::class);

        DB::transaction(function (): void {
            $reportsGroup = $this->group('Grupo Demo Reportes', 'grupo-demo-reportes');
            $multiCompanyGroup = $this->group('Grupo Demo Multiempresa', 'grupo-demo-multiempresa');

            $this->attachCompanies($reportsGroup, ['demo-caracas', 'demo-valencia']);
            $this->attachCompanies($multiCompanyGroup, [
                'demo-caracas-norte',
                'demo-caracas-este',
                'demo-valencia-centro',
                'demo-valencia-norte',
            ]);

            $owner = $this->user('Owner Demo Reportes', 'owner.reportes@demo.test');
            $platformAdmin = $this->user('SaaS Master Demo', 'saas.master.demo@demo.test', true);

            $this->attachUser($reportsGroup, $owner, 'Owner');
            $this->attachUser($multiCompanyGroup, $owner, 'Owner');

            foreach (['demo-caracas', 'demo-valencia'] as $slug) {
                $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

                $this->attachUser($tenant, $owner, 'Owner');
                $this->attachUser($tenant, $this->user('Admin Demo Operativo', 'admin.operativo@demo.test'), 'Administrador');
                $this->attachUser($tenant, $this->user('Gerente Demo Reportes', 'gerente.reportes@demo.test'), 'Gerente');
                $this->attachUser($tenant, $this->user('Vendedor Demo POS', 'vendedor.pos@demo.test'), 'Vendedor');
                $this->attachUser($tenant, $this->user('Almacen Demo', 'almacen.demo@demo.test'), 'Almacen');
                $this->attachUser($tenant, $this->user('Auditor Demo', 'auditor.demo@demo.test'), 'Auditor');

                $this->useTenant($tenant);
                $this->paymentMethods();
            }

            foreach (['demo-caracas-norte', 'demo-caracas-este', 'demo-valencia-centro', 'demo-valencia-norte'] as $slug) {
                $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
                $this->attachUser($tenant, $owner, 'Owner');
                $this->useTenant($tenant);
                $this->paymentMethods();
            }

            $platformAdmin->forceFill(['is_platform_admin' => true])->save();
        });

        app(TenantManager::class)->clear();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Demo operativo creado.');
        $this->command?->line('Password comun: password');
        $this->command?->line('Owner: owner.reportes@demo.test');
        $this->command?->line('Admin: admin.operativo@demo.test');
        $this->command?->line('Gerente: gerente.reportes@demo.test');
        $this->command?->line('Vendedor: vendedor.pos@demo.test');
        $this->command?->line('Almacen: almacen.demo@demo.test');
        $this->command?->line('Auditor: auditor.demo@demo.test');
        $this->command?->line('SaaS Master: saas.master.demo@demo.test');
        $this->command?->line('Tenants principales para reportes: demo-caracas, demo-valencia.');
    }

    private function group(string $name, string $slug): Tenant
    {
        return Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'status' => 'active',
                'plan' => 'demo',
                'parent_id' => null,
                'is_group' => true,
            ]
        );
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function attachCompanies(Tenant $group, array $slugs): void
    {
        Tenant::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->each(fn (Tenant $tenant) => $tenant->update([
                'parent_id' => $group->id,
                'is_group' => false,
                'status' => 'active',
                'plan' => $tenant->plan ?: 'demo',
            ]));
    }

    private function user(string $name, string $email, bool $platformAdmin = false): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'is_platform_admin' => $platformAdmin,
            ]
        );

        $user->forceFill([
            'name' => $name,
            'password' => Hash::make(self::PASSWORD),
            'is_platform_admin' => $platformAdmin || $user->isPlatformAdmin(),
        ])->save();

        return $user;
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

    private function paymentMethods(): void
    {
        $methods = [
            ['name' => 'USD Efectivo', 'code' => 'USD_CASH', 'method' => PosPayment::METHOD_CASH, 'currency_mode' => PaymentMethod::CURRENCY_USD, 'requires_reference' => false, 'sort_order' => 10],
            ['name' => 'VES Efectivo', 'code' => 'VES_CASH', 'method' => PosPayment::METHOD_CASH, 'currency_mode' => PaymentMethod::CURRENCY_VES, 'requires_reference' => false, 'sort_order' => 20],
            ['name' => 'Pago Movil Banco Venezuela', 'code' => 'PM_BDV', 'method' => PosPayment::METHOD_MOBILE_PAYMENT, 'currency_mode' => PaymentMethod::CURRENCY_VES, 'requires_reference' => true, 'sort_order' => 30],
            ['name' => 'Transferencia Banesco', 'code' => 'TRF_BANESCO', 'method' => PosPayment::METHOD_TRANSFER, 'currency_mode' => PaymentMethod::CURRENCY_VES, 'requires_reference' => true, 'sort_order' => 40],
            ['name' => 'Tarjeta Punto', 'code' => 'CARD_POS', 'method' => PosPayment::METHOD_CARD, 'currency_mode' => PaymentMethod::CURRENCY_VES, 'requires_reference' => true, 'sort_order' => 50],
            ['name' => 'Zelle', 'code' => 'ZELLE', 'method' => PosPayment::METHOD_ZELLE, 'currency_mode' => PaymentMethod::CURRENCY_USD, 'requires_reference' => true, 'sort_order' => 60],
            ['name' => 'Financiadora', 'code' => 'FINANCING', 'method' => PosPayment::METHOD_EXTERNAL_FINANCING, 'currency_mode' => PaymentMethod::CURRENCY_USD, 'requires_reference' => true, 'sort_order' => 70],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $method['code']],
                $method + ['is_active' => true]
            );
        }
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
