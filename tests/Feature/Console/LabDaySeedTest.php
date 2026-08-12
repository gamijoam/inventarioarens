<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Products\Models\Product;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabDaySeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_lab_day_prepares_a_gerente_lab_tenant_with_two_warehouses(): void
    {
        $this->artisan('lab:day', [
            '--tenants' => 3,
            '--products' => 10,
            '--password' => 'labday-password-2026',
            '--prefix' => 'labday',
            '--force' => true,
            '--seed-only' => true,
        ])->assertSuccessful();

        $tenant = Tenant::query()->where('slug', 'labday-01')->firstOrFail();

        $this->assertSame(2, Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count());
        $this->assertSame(1, Supplier::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count());

        $user = User::query()->where('email', 'labday-01@loadtest.local')->firstOrFail();
        setPermissionsTeamId($tenant->id);
        $this->assertTrue(
            $user->hasRole('Gerente'),
            'El usuario del lab debe tener rol Gerente para ejecutar compras, traslados y devoluciones.'
        );

        $this->assertDatabaseHas('cash_register_sessions', [
            'tenant_id' => $tenant->id,
            'status' => CashRegisterSession::STATUS_OPEN,
        ]);
        $this->assertSame(12, Product::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count());
    }

    public function test_lab_day_requires_explicit_confirmation(): void
    {
        $this->artisan('lab:day', [
            '--tenants' => 3,
            '--products' => 10,
            '--password' => 'labday-password-2026',
        ])->expectsOutputToContain('--force')
            ->assertFailed();
    }

    public function test_lab_day_rejects_production_without_allow_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('lab:day', [
            '--tenants' => 3,
            '--products' => 10,
            '--password' => 'labday-password-2026',
            '--force' => true,
        ])->expectsOutputToContain('--allow-production')
            ->assertFailed();
    }

    public function test_lab_day_rejects_short_password(): void
    {
        $this->artisan('lab:day', [
            '--tenants' => 3,
            '--products' => 10,
            '--password' => 'short',
            '--force' => true,
        ])->assertFailed();
    }

    public function test_lab_day_can_run_the_full_business_cycle_in_test_mode(): void
    {
        $this->artisan('lab:day', [
            '--tenants' => 3,
            '--products' => 10,
            '--password' => 'labday-password-2026',
            '--prefix' => 'labday',
            '--sales' => 2,
            '--force' => true,
            '--dry-run' => true,
        ])->assertSuccessful();
    }
}
