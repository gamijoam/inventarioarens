<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\ReportZService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Printing\Models\PrinterStation;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Printing\Services\PosTicketPrintService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrintTicketLogoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Branch $branch;

    private CashRegister $cashRegister;

    private PrinterStation $station;

    private PrintProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Avilacar Repuestos', 'slug' => 'avilacar-repuestos']);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);

        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        $role = Role::findOrCreate('Admin POS', 'web');
        $role->syncPermissions([
            'printing.view',
            'printing.manage',
            'printing.print',
            'printing.digital',
            'settings.manage',
        ]);
        $this->user->assignRole($role);

        $this->branch = Branch::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sucursal Principal',
            'code' => 'SUC-01',
            'is_active' => true,
        ]);

        $this->cashRegister = CashRegister::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Caja 1',
            'code' => 'CAJA-01',
            'is_active' => true,
        ]);

        $this->profile = PrintProfile::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS 80mm',
            'paper_width_mm' => 80,
            'characters_per_line' => 48,
            'header_text' => 'Bienvenido a Repuestos Avilacar',
            'footer_text' => 'Gracias por su preferencia.',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->station = PrinterStation::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_id' => $this->cashRegister->id,
            'print_profile_id' => $this->profile->id,
            'name' => 'Estacion Caja 1',
            'code' => 'STATION-01',
            'printer_name' => 'POS-80',
            'output_mode' => PrinterStation::OUTPUT_DIGITAL,
            'printer_type' => PrinterStation::PRINTER_WINDOWS,
            'digital_directory' => 'Desktop\\Tickets',
            'is_active' => true,
        ]);
    }

    private function createPaidOrder(): PosOrder
    {
        $session = CashRegisterSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_id' => $this->cashRegister->id,
            'cashier_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 0,
            'expected_base_amount' => 0,
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'status' => Sale::STATUS_CONFIRMED,
            'created_by' => $this->user->id,
            'total_base_amount' => 25.0,
            'total_local_amount' => 25000,
            'confirmed_at' => now(),
        ]);

        $order = PosOrder::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'cash_register_session_id' => $session->id,
            'cashier_id' => $this->user->id,
            'status' => PosOrder::STATUS_PAID,
            'customer_name' => 'Consumidor Final',
            'total_base_amount' => 25.0,
            'total_local_amount' => 25000,
            'paid_base_amount' => 25.0,
            'paid_local_amount' => 25000,
            'opened_at' => now(),
            'paid_at' => now(),
            'closed_at' => now(),
        ]);

        PosPayment::create([
            'tenant_id' => $this->tenant->id,
            'pos_order_id' => $order->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => 'USD',
            'amount' => 25.0,
            'amount_base' => 25.0,
            'amount_local' => 25000,
            'exchange_rate_type_code' => 'BCV',
            'exchange_rate' => 1000,
            'reference' => 'REF-123',
            'status' => PosPayment::STATUS_CAPTURED,
        ]);

        return $order;
    }

    public function test_pos_ticket_renders_logo_when_present(): void
    {
        Storage::fake('public');
        $logoFile = UploadedFile::fake()->image('logo.png', 200, 100);
        $path = $logoFile->storeAs("tenants/{$this->tenant->id}", 'logo.png', 'public');
        $logoUrl = '/storage/'.$path;

        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'razon_social' => 'Avilacar Repuestos C.A.',
                    'rif' => 'J-12345678-0',
                    'logo_url' => $logoUrl,
                    'show_on' => ['sale_ticket' => true],
                ],
            ],
        ]);

        $order = $this->createPaidOrder();

        $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'printer_station_id' => $this->station->id,
            ])
            ->assertCreated();

        $job = PrintJob::query()->where('output', PrintJob::OUTPUT_DIGITAL)->latest('id')->firstOrFail();

        $response = $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/printing/jobs/{$job->id}/ticket.html");

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('class="logo-container"', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('Avilacar Repuestos C.A.', $html);
        $this->assertStringContainsString('J-12345678-0', $html);

        $pdfResponse = $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/printing/jobs/{$job->id}/ticket.pdf");

        $pdfResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotEmpty($pdfResponse->getContent());
    }

    public function test_pos_ticket_does_not_render_logo_when_absent(): void
    {
        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'razon_social' => 'Avilacar Repuestos C.A.',
                    'rif' => 'J-12345678-0',
                    'logo_url' => null,
                    'show_on' => ['sale_ticket' => true],
                ],
            ],
        ]);

        $order = $this->createPaidOrder();

        $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'printer_station_id' => $this->station->id,
            ])
            ->assertCreated();

        $job = PrintJob::query()->where('output', PrintJob::OUTPUT_DIGITAL)->latest('id')->firstOrFail();

        $response = $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/printing/jobs/{$job->id}/ticket.html");

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('class="logo-container"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Avilacar Repuestos C.A.', $html);
    }

    public function test_pos_ticket_does_not_render_logo_when_show_company_is_disabled(): void
    {
        Storage::fake('public');
        $logoFile = UploadedFile::fake()->image('logo.png', 200, 100);
        $path = $logoFile->storeAs("tenants/{$this->tenant->id}", 'logo.png', 'public');
        $logoUrl = '/storage/'.$path;

        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'razon_social' => 'Avilacar Repuestos C.A.',
                    'rif' => 'J-12345678-0',
                    'logo_url' => $logoUrl,
                    'show_on' => ['sale_ticket' => false],
                ],
            ],
        ]);

        $order = $this->createPaidOrder();

        $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/print-jobs", [
                'output' => PrinterStation::OUTPUT_DIGITAL,
                'printer_station_id' => $this->station->id,
            ])
            ->assertCreated();

        $job = PrintJob::query()->where('output', PrintJob::OUTPUT_DIGITAL)->latest('id')->firstOrFail();

        $response = $this
            ->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/printing/jobs/{$job->id}/ticket.html");

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('class="logo-container"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_preview_ticket_renders_logo_when_company_has_logo(): void
    {
        Storage::fake('public');
        $logoFile = UploadedFile::fake()->image('logo.png', 200, 100);
        $path = $logoFile->storeAs("tenants/{$this->tenant->id}", 'logo.png', 'public');
        $logoUrl = '/storage/'.$path;

        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'razon_social' => 'Avilacar Repuestos C.A.',
                    'logo_url' => $logoUrl,
                    'show_on' => ['sale_ticket' => true],
                ],
            ],
        ]);

        $service = app(PosTicketPrintService::class);
        $html = $service->renderPreviewHtml($this->profile);

        $this->assertStringContainsString('class="logo-container"', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_preview_ticket_does_not_render_logo_when_company_has_no_logo(): void
    {
        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'logo_url' => null,
                ],
            ],
        ]);

        $service = app(PosTicketPrintService::class);
        $html = $service->renderPreviewHtml($this->profile);

        $this->assertStringNotContainsString('class="logo-container"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_report_z_ticket_renders_logo_when_present(): void
    {
        Storage::fake('public');
        $logoFile = UploadedFile::fake()->image('logo.png', 200, 100);
        $path = $logoFile->storeAs("tenants/{$this->tenant->id}", 'logo.png', 'public');
        $logoUrl = '/storage/'.$path;

        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'razon_social' => 'Avilacar Repuestos C.A.',
                    'rif' => 'J-12345678-0',
                    'logo_url' => $logoUrl,
                    'show_on' => ['report_z' => true],
                ],
            ],
        ]);

        $session = CashRegisterSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_id' => $this->cashRegister->id,
            'cashier_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'z_number' => 1,
            'z_emitted_at' => now(),
            'opening_balance_base' => 100,
            'opening_balance_local' => 50000,
        ]);

        $service = app(ReportZService::class);
        $html = $service->renderHtml($session);

        $this->assertStringContainsString('class="logo-container"', $html);
        $this->assertStringContainsString('<img', $html);

        $pdf = $service->renderPdf($session);
        $this->assertNotEmpty($pdf);
    }

    public function test_report_z_ticket_does_not_render_logo_when_absent(): void
    {
        TenantSetting::updateOrCreate(['tenant_id' => $this->tenant->id], [
            'tenant_id' => $this->tenant->id,
            'settings' => [
                'company' => [
                    'razon_social' => 'Avilacar Repuestos C.A.',
                    'logo_url' => null,
                    'show_on' => ['report_z' => true],
                ],
            ],
        ]);

        $session = CashRegisterSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_id' => $this->cashRegister->id,
            'cashier_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'z_number' => 2,
            'z_emitted_at' => now(),
            'opening_balance_base' => 100,
            'opening_balance_local' => 50000,
        ]);

        $service = app(ReportZService::class);
        $html = $service->renderHtml($session);

        $this->assertStringNotContainsString('class="logo-container"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
