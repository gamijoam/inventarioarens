<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosTicketPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_with_pos_view_can_preview_a_paid_order_ticket(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        // Un cajero comun: no tiene permisos de impresion.
        $this->grantRole($tenant, $user, 'Cajero', ['pos.view']);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);
        $this->useTenant($tenant);
        PrintProfile::create([
            'name' => 'POS 80mm',
            'paper_width_mm' => 80,
            'characters_per_line' => 48,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/pos/orders/{$order->id}/ticket-preview")
            ->assertOk();

        $html = (string) $response->json('data.html');
        $this->assertStringContainsString('Ticket POS #'.$order->id, $html);
        $this->assertStringContainsString('Documento no fiscal', $html);
        $this->assertSame(80, (int) $response->json('data.paper_width_mm'));
    }

    public function test_preview_requires_pos_view_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Sin permisos', []);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/pos/orders/{$order->id}/ticket-preview")
            ->assertForbidden();
    }

    public function test_preview_does_not_cross_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenantA);
        $register = $this->cashRegister($tenantA, $branch);
        $userA = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $userA, 'Cajero A', ['pos.view']);
        $order = $this->paidPosOrder($tenantA, $userA, $branch, $register);

        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantB, $userB, 'Cajero B', ['pos.view']);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/pos/orders/{$order->id}/ticket-preview")
            ->assertNotFound();
    }

    public function test_preview_rejects_unpaid_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.view']);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);
        $order->update(['status' => PosOrder::STATUS_OPEN]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/pos/orders/{$order->id}/ticket-preview")
            ->assertUnprocessable();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function branch(Tenant $tenant): Branch
    {
        $this->useTenant($tenant);

        return Branch::create(['name' => 'Principal', 'code' => 'MAIN-'.uniqid()]);
    }

    private function cashRegister(Tenant $tenant, Branch $branch): CashRegister
    {
        $this->useTenant($tenant);

        return CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Mostrador',
            'code' => 'MOST-'.uniqid(),
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
    }

    private function paidPosOrder(Tenant $tenant, User $user, Branch $branch, CashRegister $register): PosOrder
    {
        $this->useTenant($tenant);

        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 0,
            'expected_base_amount' => 0,
            'opened_at' => now(),
        ]);
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'created_by' => $user->id,
            'total_base_amount' => 12.5,
            'total_local_amount' => 12500,
            'confirmed_at' => now(),
        ]);
        $order = PosOrder::create([
            'sale_id' => $sale->id,
            'cash_register_session_id' => $session->id,
            'cashier_id' => $user->id,
            'status' => PosOrder::STATUS_PAID,
            'customer_name' => 'Consumidor Final',
            'total_base_amount' => 12.5,
            'total_local_amount' => 12500,
            'paid_base_amount' => 12.5,
            'paid_local_amount' => 12500,
            'opened_at' => now(),
            'paid_at' => now(),
            'closed_at' => now(),
        ]);
        PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => 'USD',
            'amount' => 12.5,
            'amount_base' => 12.5,
            'amount_local' => 12500,
            'exchange_rate_type_code' => 'BCV',
            'exchange_rate' => 1000,
            'reference' => 'REF-123',
            'status' => PosPayment::STATUS_CAPTURED,
        ]);

        return $order;
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    public function test_ticket_preview_displays_date_in_business_timezone_not_utc(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.view']);

        $order = $this->paidPosOrder($tenant, $user, $branch, $register);
        // 11:27 UTC equivale a 07:27 AM en Venezuela
        $order->update(['paid_at' => '2026-09-21 11:27:00']);

        $this->useTenant($tenant);
        PrintProfile::create([
            'name' => 'POS 80mm',
            'paper_width_mm' => 80,
            'characters_per_line' => 48,
            'is_default' => true,
            'is_active' => true,
            'show_paid_at' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/pos/orders/{$order->id}/ticket-preview")
            ->assertOk();

        $html = (string) $response->json('data.html');
        $this->assertStringContainsString('21/09/2026 07:27 AM', $html);
        $this->assertStringNotContainsString('11:27:00', $html);
    }
}
