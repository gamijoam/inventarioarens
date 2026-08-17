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
use App\Modules\Printing\Services\PrinterServer;
use App\Modules\Promotions\Models\SalePromotionApplication;
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

    public function test_plain_ticket_identifies_combo_and_invoice_promotion(): void
    {
        $ticket = app(PrinterServer::class)->buildPlainTicket([
            'profile' => ['paper_width_mm' => 80],
            'tenant' => ['name' => 'Empresa A', 'slug' => 'empresa-a'],
            'pos_order' => ['id' => 10],
            'promotions' => [
                ['scope' => 'combo', 'promotion_name' => 'Combo Accesorios'],
                ['scope' => 'invoice', 'promotion_name' => 'Descuento factura'],
            ],
            'items' => [[
                'product_name' => 'Audifonos',
                'quantity' => 1,
                'unit_price' => 90,
                'total' => 90,
                'promotion_labels' => ['COMBO: Combo Accesorios'],
            ]],
            'totals' => ['total_base_amount' => 90, 'total_local_amount' => 0, 'paid_base_amount' => 90],
        ]);

        $this->assertStringContainsString('COMBO: Combo Accesorios', $ticket);
        $this->assertStringContainsString('PROMOCION: Descuento factura', $ticket);
    }

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
        $this->useTenant($tenant);
        SalePromotionApplication::create([
            'sale_id' => $order->sale_id,
            'promotion_id' => null,
            'slot' => 'combo:print-test',
            'scope' => 'combo',
            'status' => SalePromotionApplication::STATUS_VALIDATED,
            'promotion_name' => 'Combo Impreso',
            'benefit_type' => 'fixed_bundle_price',
            'base_before_amount' => 100,
            'local_before_amount' => 0,
            'base_adjustment_amount' => 10,
            'local_adjustment_amount' => 0,
            'base_after_amount' => 90,
            'local_after_amount' => 0,
        ]);

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
        $snapshot = PrintJob::findOrFail($digitalJobId)->payload_snapshot;
        $this->assertSame('combo', $snapshot['promotions'][0]['scope']);
        $this->assertSame('Combo Impreso', $snapshot['promotions'][0]['promotion_name']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/printing/jobs/{$digitalJobId}/ticket.html")
            ->assertOk()
            ->assertSee('Ticket POS #'.$order->id, false)
            ->assertSee('COMBO: Combo Impreso', false)
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

    public function test_profile_preview_pdf_uses_profile_payload_without_unique_name_conflict(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage', 'printing.digital']);
        $profile = $this->profile($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/profiles/preview.pdf', [
                'name' => $profile->name,
                'paper_width_mm' => 58,
                'characters_per_line' => 32,
                'show_payment_rate' => false,
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_user_can_update_printer_station(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage']);
        $profile = $this->profile($tenant);
        $station = $this->station($tenant, $branch, $register, $profile);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/printing/stations/{$station->id}", [
                'name' => 'Mostrador actualizado',
                'output_mode' => PrinterStation::OUTPUT_BOTH,
                'printer_name' => 'EPSON-TM-T20',
                'digital_directory' => 'Desktop\\TicketsDemo',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mostrador actualizado')
            ->assertJsonPath('data.output_mode', PrinterStation::OUTPUT_BOTH)
            ->assertJsonPath('data.printer_name', 'EPSON-TM-T20')
            ->assertJsonPath('data.digital_directory', 'Desktop\\TicketsDemo');
    }

    public function test_network_station_requires_host_and_port(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage']);
        $profile = $this->profile($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/stations', [
                'print_profile_id' => $profile->id,
                'name' => 'Red',
                'code' => 'RED-01',
                'output_mode' => PrinterStation::OUTPUT_THERMAL,
                'printer_type' => PrinterStation::PRINTER_NETWORK,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['network_host', 'network_port']);
    }

    public function test_network_station_can_be_created_with_host(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage']);
        $profile = $this->profile($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/stations', [
                'print_profile_id' => $profile->id,
                'name' => 'Termica Red',
                'code' => 'RED-02',
                'output_mode' => PrinterStation::OUTPUT_THERMAL,
                'printer_type' => PrinterStation::PRINTER_NETWORK,
                'network_host' => '192.168.1.50',
                'network_port' => 9100,
            ])
            ->assertCreated()
            ->assertJsonPath('data.printer_type', PrinterStation::PRINTER_NETWORK)
            ->assertJsonPath('data.network_host', '192.168.1.50')
            ->assertJsonPath('data.network_port', 9100);
    }

    public function test_windows_thermal_station_requires_printer_name(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador', ['printing.view', 'printing.manage']);
        $profile = $this->profile($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/printing/stations', [
                'print_profile_id' => $profile->id,
                'name' => 'Termica',
                'code' => 'TERM-01',
                'output_mode' => PrinterStation::OUTPUT_THERMAL,
                'printer_type' => PrinterStation::PRINTER_WINDOWS,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['printer_name']);
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
