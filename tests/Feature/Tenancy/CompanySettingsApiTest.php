<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferGuide;
use App\Modules\InventoryTransfers\Services\TransferGuidePdfService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Printing\Models\PrinterStation;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompanySettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_admin_can_save_company_info_and_it_round_trips(): void
    {
        $tenant = Tenant::create(['name' => 'Comercial Arens C.A.', 'slug' => 'comercial-arens']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['settings.manage']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-settings', [
                'settings' => [
                    'company' => [
                        'razon_social' => 'Comercial Arens, C.A.',
                        'rif' => 'J-12345678-9',
                        'domicilio_fiscal' => 'Av. Principal, Local 5',
                        'ciudad' => 'Caracas',
                        'estado' => 'Distrito Capital',
                        'telefono' => '+58 212 555 0101',
                        'correo' => 'info@comercialarens.com',
                        'website' => 'https://comercialarens.com',
                        'regimen' => 'Contribuyente formal',
                        'show_on' => ['sale_ticket' => false, 'guide' => true, 'report_z' => false],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.company.rif', 'J-12345678-9')
            ->assertJsonPath('data.settings.company.show_on.sale_ticket', false)
            ->assertJsonPath('data.settings.company.show_on.guide', true);

        $stored = DB::table('tenant_settings')->where('tenant_id', $tenant->id)->value('settings');
        $storedCompany = json_decode((string) $stored, true)['company'];
        $this->assertSame('Comercial Arens, C.A.', $storedCompany['razon_social']);
        $this->assertSame('J-12345678-9', $storedCompany['rif']);
    }

    public function test_company_defaults_returned_when_not_configured(): void
    {
        $tenant = Tenant::create(['name' => 'Sin Datos', 'slug' => 'sin-datos']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/tenant-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.company.rif', null)
            ->assertJsonPath('data.settings.company.show_on.sale_ticket', true)
            ->assertJsonPath('data.settings.company.show_on.guide', true)
            ->assertJsonPath('data.settings.company.show_on.report_z', true);

        $this->assertSame(CompanySettings::DEFAULTS, CompanySettings::getForTenant($tenant));
    }

    public function test_member_without_settings_manage_cannot_save_company(): void
    {
        $tenant = Tenant::create(['name' => 'Restringida', 'slug' => 'restringida']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-settings', [
                'settings' => ['company' => ['rif' => 'J-00000000-1']],
            ])
            ->assertStatus(403);
    }

    public function test_company_info_appears_on_pos_ticket_when_enabled(): void
    {
        $tenant = Tenant::create(['name' => 'Ticket SRL', 'slug' => 'ticket-srl']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin Ticket', [
            'printing.manage',
            'printing.print',
            'printing.view',
            'printing.digital',
            'settings.manage',
        ]);
        $this->configureCompany($tenant, [
            'razon_social' => 'Ticket, S.R.L.',
            'rif' => 'J-98765432-1',
            'domicilio_fiscal' => 'Calle 9, Edif. Central',
            'show_on' => ['sale_ticket' => true, 'guide' => false, 'report_z' => true],
        ]);

        $profile = $this->profile($tenant);
        $station = $this->station($tenant, $branch, $register, $profile);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'printer_station_id' => $station->id,
            ])
            ->assertCreated();

        $digitalJobId = PrintJob::query()->where('output', PrintJob::OUTPUT_DIGITAL)->latest('id')->value('id');
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/printing/jobs/{$digitalJobId}/ticket.html")
            ->assertOk()
            ->assertSee('J-98765432-1', false)
            ->assertSee('Ticket, S.R.L.', false);
    }

    public function test_company_info_hidden_on_pos_ticket_when_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'Ticket Oculto', 'slug' => 'ticket-oculto']);
        $branch = $this->branch($tenant);
        $register = $this->cashRegister($tenant, $branch);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin Ticket Oculto', [
            'printing.manage',
            'printing.print',
            'printing.view',
            'printing.digital',
            'settings.manage',
        ]);
        $this->configureCompany($tenant, [
            'razon_social' => 'Ticket Oculto SRL',
            'rif' => 'J-55555555-5',
            'show_on' => ['sale_ticket' => false],
        ]);

        $profile = $this->profile($tenant);
        $station = $this->station($tenant, $branch, $register, $profile);
        $order = $this->paidPosOrder($tenant, $user, $branch, $register);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'printer_station_id' => $station->id,
            ])
            ->assertCreated();

        $digitalJobId = PrintJob::query()->where('output', PrintJob::OUTPUT_DIGITAL)->latest('id')->value('id');
        $html = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/printing/jobs/{$digitalJobId}/ticket.html")
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('J-55555555-5', $html);
    }

    public function test_company_info_appears_on_transfer_guide_when_enabled(): void
    {
        $tenant = Tenant::create(['name' => 'Guias C.A.', 'slug' => 'guias-ca']);
        $this->configureCompany($tenant, [
            'razon_social' => 'Guias C.A.',
            'rif' => 'J-11111111-1',
            'show_on' => ['guide' => true],
        ]);
        $transfer = $this->completedTransfer($tenant);

        $html = app(TransferGuidePdfService::class)->renderHtml($transfer);
        $this->assertStringContainsString('J-11111111-1', $html);
        $this->assertStringContainsString('Guias C.A.', $html);
    }

    public function test_company_info_hidden_on_transfer_guide_when_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'Guias Ocultas', 'slug' => 'guias-ocultas']);
        $this->configureCompany($tenant, [
            'razon_social' => 'Guias Ocultas C.A.',
            'rif' => 'J-22222222-2',
            'show_on' => ['guide' => false],
        ]);
        $transfer = $this->completedTransfer($tenant);

        $html = app(TransferGuidePdfService::class)->renderHtml($transfer);
        $this->assertStringNotContainsString('J-22222222-2', $html);
    }

    private function configureCompany(Tenant $tenant, array $company): void
    {
        DB::table('tenant_settings')
            ->updateOrInsert(
                ['tenant_id' => $tenant->id],
                ['settings' => json_encode(['company' => array_replace_recursive(CompanySettings::DEFAULTS, $company)])],
            );
    }

    private function completedTransfer(Tenant $tenant): InventoryTransfer
    {
        $this->useTenant($tenant);
        $branch = Branch::create(['name' => 'Sucursal Guia', 'code' => 'BR-GUIA']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'ORIGEN']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'DESTINO']);
        $user = $this->userInTenant($tenant);

        $transfer = InventoryTransfer::create([
            'sequence' => 1,
            'document_number' => 'TRF-000001',
            'guide_number' => 'GUIA-000001',
            'type' => InventoryTransfer::TYPE_INTERNAL,
            'validation_mode' => InventoryTransfer::VALIDATION_SIMPLE,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'status' => InventoryTransfer::STATUS_COMPLETED,
            'created_by' => $user->id,
            'processed_at' => now(),
        ]);

        InventoryTransferGuide::create([
            'inventory_transfer_id' => $transfer->id,
            'guide_number' => $transfer->guide_number,
            'status' => InventoryTransferGuide::STATUS_COMPLETED,
            'issued_at' => now(),
        ]);

        return $transfer;
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
}
