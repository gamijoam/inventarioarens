<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Printing\Services\PosTicketPrintService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrintPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);

        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $role = Role::findOrCreate('Admin Print', 'web');
        $role->syncPermissions(['printing.view', 'printing.manage', 'printing.digital']);
        $this->user->assignRole($role);
    }

    public function test_preview_html_shows_active_sections_and_hides_disabled_ones(): void
    {
        $profile = PrintProfile::create([
            'name' => 'POS 80mm',
            'paper_width_mm' => 80,
            'characters_per_line' => 48,
            'header_text' => 'Mi tienda',
            'footer_text' => 'Gracias',
            'logo_text' => 'LOGO TEST',
            'show_tenant_slug' => true,
            'show_sale_number' => true,
            'show_paid_at' => true,
            'show_cashier' => true,
            'show_cash_register' => true,
            'show_branch' => true,
            'show_customer' => true,
            'show_item_sku' => true,
            'show_item_serials' => true,
            'show_warranty_summary' => true,
            'show_total_local' => true,
            'show_payment_rate' => true,
            'show_payment_reference' => true,
            'show_non_fiscal_text' => true,
            'is_default' => true,
            'is_active' => true,
        ]);

        $html = app(PosTicketPrintService::class)->renderPreviewHtml($profile);

        // Datos del ticket (activos).
        $this->assertStringContainsString('LOGO TEST', $html);
        $this->assertStringContainsString('Mi tienda', $html);
        $this->assertStringContainsString('empresa-a', $html);
        $this->assertStringContainsString('Venta #PRUEBA', $html);
        $this->assertStringContainsString('Cajero: Cajero Demo', $html);
        $this->assertStringContainsString('Caja: Mostrador 1', $html);
        $this->assertStringContainsString('Sucursal: Sucursal Principal', $html);
        $this->assertStringContainsString('Cliente: Cliente de prueba', $html);
        // Items.
        $this->assertStringContainsString('DEMO-21-CCS', $html);
        $this->assertStringContainsString('IMEI/Serial: IMEI-DEMO-001', $html);
        $this->assertStringContainsString('Garantia: Accesorios 7 dias', $html);
        // Pagos.
        $this->assertStringContainsString('Total VES', $html);
        $this->assertStringContainsString('BCV @', $html);
        $this->assertStringContainsString('Ref: REF-DEMO', $html);
        // Pie.
        $this->assertStringContainsString('Gracias', $html);
        $this->assertStringContainsString('Documento no fiscal', $html);
    }

    public function test_preview_html_respects_disabled_toggles(): void
    {
        $profile = PrintProfile::create([
            'name' => 'POS 80mm',
            'paper_width_mm' => 80,
            'characters_per_line' => 48,
            'show_tenant_slug' => false,
            'show_sale_number' => false,
            'show_paid_at' => false,
            'show_cashier' => false,
            'show_cash_register' => false,
            'show_branch' => false,
            'show_customer' => false,
            'show_item_sku' => false,
            'show_item_serials' => false,
            'show_warranty_summary' => false,
            'show_total_local' => false,
            'show_payment_rate' => false,
            'show_payment_reference' => false,
            'show_non_fiscal_text' => false,
            'is_default' => true,
            'is_active' => true,
        ]);

        $html = app(PosTicketPrintService::class)->renderPreviewHtml($profile);

        $this->assertStringNotContainsString('empresa-a', $html);
        $this->assertStringNotContainsString('Venta #', $html);
        $this->assertStringNotContainsString('Cajero:', $html);
        $this->assertStringNotContainsString('Caja:', $html);
        $this->assertStringNotContainsString('Sucursal:', $html);
        $this->assertStringNotContainsString('Cliente:', $html);
        $this->assertStringNotContainsString('DEMO-21-CCS', $html);
        $this->assertStringNotContainsString('IMEI/Serial', $html);
        $this->assertStringNotContainsString('Garantia:', $html);
        $this->assertStringNotContainsString('Total VES', $html);
        $this->assertStringNotContainsString('BCV @', $html);
        $this->assertStringNotContainsString('Ref:', $html);
        $this->assertStringNotContainsString('Documento no fiscal', $html);
    }

    public function test_preview_pdf_endpoint_returns_valid_pdf(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/printing/profiles/preview.pdf', [
                'name' => 'Preview',
                'paper_width_mm' => 58,
                'characters_per_line' => 32,
                'show_payment_rate' => true,
                'show_total_local' => true,
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_preview_endpoint_requires_printing_manage_or_digital(): void
    {
        $other = User::factory()->create();
        $other->tenants()->attach($this->tenant, ['status' => 'active']);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $role = Role::findOrCreate('Sin Print', 'web');
        $role->syncPermissions(['printing.view']);
        $other->assignRole($role);

        $this->actingAs($other)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/printing/profiles/preview.pdf', [
                'name' => 'Preview',
                'paper_width_mm' => 80,
                'characters_per_line' => 48,
            ])
            ->assertForbidden();
    }
}
