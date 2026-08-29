<?php

namespace Tests\Feature\CashRegister;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReportZTest extends TestCase
{
    use RefreshDatabase;

    public function test_close_assigns_consecutive_z_number_per_register(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();

        $first = $this->openAndClose($tenant, $register, $user, 40);
        $second = $this->openAndClose($tenant, $register, $user, 20);

        $this->assertSame(1, (int) $first->z_number);
        $this->assertSame(2, (int) $second->z_number);
        $this->assertNotNull($first->z_emitted_at);
    }

    public function test_z_number_is_isolated_between_registers(): void
    {
        [$tenant, $branch, $registerA, $user] = $this->fixture();
        $registerB = $this->cashRegister($tenant, $branch, 'Caja B', 'CJ-B');

        $a = $this->openAndClose($tenant, $registerA, $user, 40);
        $b = $this->openAndClose($tenant, $registerB, $user, 20);

        $this->assertSame(1, (int) $a->z_number);
        $this->assertSame(1, (int) $b->z_number);
    }

    public function test_report_z_json_returns_totals_and_payment_breakdown(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();
        $session = $this->openSession($tenant, $register, $user);
        $this->seedPaidOrder($tenant, $session, $user, 40);
        $this->closeViaService($tenant, $session, $user, 40);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/cash-register/sessions/{$session->id}/report-z")
            ->assertOk()
            ->assertJsonPath('data.z_number', 1)
            ->assertJsonPath('data.totals.orders_count', 1)
            ->assertJsonPath('data.totals.paid_base_amount', 40)
            ->assertJsonPath('data.payments.0.method', PosPayment::METHOD_CASH)
            ->assertJsonPath('data.payments.0.amount_base', 40);
    }

    public function test_report_z_pdf_returns_pdf(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();
        $session = $this->openAndClose($tenant, $register, $user, 0);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/cash-register/sessions/{$session->id}/report-z.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_report_z_ticket_html_contains_expected_content(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();
        $session = $this->openAndClose($tenant, $register, $user, 40);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/cash-register/sessions/{$session->id}/report-z.ticket.html")
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('REPORTE Z', $html);
        $this->assertStringContainsString('Z #1', $html);
        $this->assertStringContainsString('Total USD', $html);
    }

    public function test_report_z_requires_closed_session(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();
        $session = $this->openSession($tenant, $register, $user);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/cash-register/sessions/{$session->id}/report-z")
            ->assertStatus(422);
    }

    public function test_report_z_requires_permission(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();
        $session = $this->openAndClose($tenant, $register, $user, 40);

        $viewer = $this->userInTenant($tenant);

        $this
            ->actingAs($viewer)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/cash-register/sessions/{$session->id}/report-z")
            ->assertForbidden();
    }

    public function test_report_z_does_not_cross_tenants(): void
    {
        [$tenant, $branch, $register, $user] = $this->fixture();
        $session = $this->openAndClose($tenant, $register, $user, 40);

        $otherTenant = Tenant::create(['name' => 'Otra', 'slug' => 'otra-empresa']);
        $otherUser = $this->userInTenant($otherTenant);
        $this->grantRole($otherTenant, $otherUser, 'Gerente', ['cash_register.view', 'reports.cash.view']);

        $this
            ->actingAs($otherUser)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson("/api/cash-register/sessions/{$session->id}/report-z")
            ->assertNotFound();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Z', 'slug' => 'empresa-z']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch, 'Caja Principal', 'CJ-1');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente', ['cash_register.open', 'cash_register.close', 'cash_register.view']);

        return [$tenant, $branch, $register, $user];
    }

    private function openSession(Tenant $tenant, CashRegister $register, User $user): CashRegisterSession
    {
        $sessionId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $register->branch_id,
                'cash_register_id' => $register->id,
                'opening_amount' => 0,
            ])
            ->assertCreated()
            ->json('data.id');

        return CashRegisterSession::query()->findOrFail($sessionId);
    }

    private function openAndClose(Tenant $tenant, CashRegister $register, User $user, float $paid): CashRegisterSession
    {
        $session = $this->openSession($tenant, $register, $user);
        if ($paid > 0) {
            $this->seedPaidOrder($tenant, $session, $user, $paid);
        }
        $this->closeViaService($tenant, $session, $user, $paid);

        return $session->refresh();
    }

    private function closeViaService(Tenant $tenant, CashRegisterSession $session, User $user, float $counted): void
    {
        $this->useTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/cash-register/sessions/{$session->id}/close", [
                'counted_base_amount' => $counted,
                'counted_local_amount' => 0,
                'counted_cash_usd' => $counted,
                'counted_cash_ves' => 0,
                'closing_notes' => 'Cierre de prueba del Reporte Z.',
            ])
            ->assertOk();
    }

    private function seedPaidOrder(Tenant $tenant, CashRegisterSession $session, User $user, float $amount): void
    {
        $this->useTenant($tenant);

        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $amount,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        $order = PosOrder::create([
            'sale_id' => $sale->id,
            'cash_register_session_id' => $session->id,
            'cashier_id' => $user->id,
            'status' => PosOrder::STATUS_PAID,
            'customer_name' => 'Consumidor Final',
            'total_base_amount' => $amount,
            'total_local_amount' => 0,
            'paid_base_amount' => $amount,
            'paid_local_amount' => 0,
            'opened_at' => now(),
            'paid_at' => now(),
        ]);

        PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => Product::CURRENCY_USD,
            'amount' => $amount,
            'amount_base' => $amount,
            'amount_local' => 0,
            'status' => PosPayment::STATUS_CAPTURED,
        ]);
    }

    private function branch(Tenant $tenant, string $suffix = 'MAIN'): Branch
    {
        $this->useTenant($tenant);

        return Branch::create([
            'name' => "Sucursal {$suffix}",
            'code' => "BR-Z-{$suffix}",
        ]);
    }

    private function cashRegister(Tenant $tenant, Branch $branch, string $name, string $code): CashRegister
    {
        $this->useTenant($tenant);

        return CashRegister::create([
            'branch_id' => $branch->id,
            'name' => $name,
            'code' => $code,
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
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
}
