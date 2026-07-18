<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Printing\Models\PrinterStation;
use App\Modules\Printing\Models\PrintJob;
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

class PrintingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_configure_profile_station_and_generate_pos_ticket(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage', 'printing.print', 'printing.digital']);

        $profileId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/profiles', [
                'name' => 'POS 80mm',
                'paper_width_mm' => 80,
                'characters_per_line' => 48,
                'header_text' => 'Mi tienda',
                'footer_text' => 'Gracias',
                'show_warranty_summary' => true,
                'copies' => 1,
                'is_default' => true,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.paper_width_mm', 80)
            ->json('data.id');

        $stationId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/stations', [
                'branch_id' => $branch->id,
                'cash_register_id' => $register->id,
                'print_profile_id' => $profileId,
                'name' => 'Mostrador',
                'code' => 'MOSTRADOR',
                'output_mode' => PrinterStation::OUTPUT_BOTH,
                'printer_type' => PrinterStation::PRINTER_WINDOWS,
                'printer_name' => 'POS-80',
                'digital_directory' => 'Desktop\\Tickets',
                'save_html_copy' => true,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.output_mode', PrinterStation::OUTPUT_BOTH)
            ->json('data.id');

        $order = $this->paidPosOrder($tenant, $user, $branch, $register);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_BOTH,
                'printer_station_id' => $stationId,
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $digitalJobId = collect($response->json('data'))->firstWhere('output', PrintJob::OUTPUT_DIGITAL)['id'];

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/printing/jobs/{$digitalJobId}/ticket.html")
            ->assertOk()
            ->assertSee('Ticket POS #'.$order->id, false)
            ->assertSee('Documento no fiscal', false);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/printing/jobs/{$digitalJobId}/ticket.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_copy_requires_reprint_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['printing.print', 'printing.digital']);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'copy' => true,
            ])
            ->assertForbidden();
    }

    public function test_ticket_profile_visibility_options_hide_sections_in_html(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage', 'printing.print', 'printing.digital']);

        $profile = $this->profile($tenant);
        $profile->update([
            'show_total_local' => false,
            'show_payment_rate' => false,
            'show_payment_reference' => false,
            'show_cash_register' => false,
            'show_branch' => false,
            'show_customer' => false,
            'show_non_fiscal_text' => false,
        ]);
        $station = $this->station($tenant, $branch, $register, $profile);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);

        $jobId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'printer_station_id' => $station->id,
            ])
            ->assertCreated()
            ->json('data.0.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/printing/jobs/{$jobId}/ticket.html")
            ->assertOk()
            ->assertSee('Ticket POS #'.$order->id, false)
            ->assertDontSee('Total VES', false)
            ->assertDontSee('BCV @', false)
            ->assertDontSee('Ref:', false)
            ->assertDontSee('Caja:', false)
            ->assertDontSee('Sucursal:', false)
            ->assertDontSee('Cliente:', false)
            ->assertDontSee('Documento no fiscal', false);
    }

    public function test_printing_resources_do_not_cross_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Admin A', ['printing.view', 'printing.manage']);
        $this->grantRole($tenantB, $userB, 'Admin B', ['printing.view', 'printing.manage']);
        $profileA = $this->profile($tenantA);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/printing/profiles')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->patchJson("/api/printing/profiles/{$profileA->id}", ['name' => 'Nope'])
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

    private function branch(Tenant $tenant): Branch
    {
        $this->useTenant($tenant);

        return Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
    }

    private function cashRegister(Tenant $tenant, Branch $branch): CashRegister
    {
        $this->useTenant($tenant);

        return CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Mostrador',
            'code' => 'MOSTRADOR',
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

    private function profile(Tenant $tenant): PrintProfile
    {
        $this->useTenant($tenant);

        return PrintProfile::create([
            'name' => 'POS 58mm',
            'paper_width_mm' => 58,
            'characters_per_line' => 32,
            'show_warranty_summary' => true,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function station(Tenant $tenant, Branch $branch, CashRegister $register, PrintProfile $profile): PrinterStation
    {
        $this->useTenant($tenant);

        return PrinterStation::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'print_profile_id' => $profile->id,
            'name' => 'Mostrador',
            'code' => 'MOSTRADOR-TEST',
            'output_mode' => PrinterStation::OUTPUT_DIGITAL,
            'printer_type' => PrinterStation::PRINTER_WINDOWS,
            'digital_directory' => 'Desktop\\Tickets',
            'is_active' => true,
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
