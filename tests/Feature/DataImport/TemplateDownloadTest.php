<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TemplateDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('data_import.view', 'web');

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['status' => 'active']);
        setPermissionsTeamId($this->tenant->id);
        $this->admin->givePermissionTo(['data_import.view']);
    }

    public function test_template_for_branches_returns_csv_with_headers_and_examples(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/branches');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringStartsWith('code,name,status', $content);
        $this->assertStringContainsString('PRINCIPAL,Sucursal Principal,active', $content);
        $this->assertStringContainsString('NORTE,Sucursal Norte,active', $content);
    }

    public function test_template_for_warehouses_includes_tenant_branch_values(): void
    {
        Branch::create(['code' => 'PRINCIPAL', 'name' => 'Principal', 'status' => 'active']);
        Branch::create(['code' => 'NORTE', 'name' => 'Norte', 'status' => 'active']);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/warehouses');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('PRINCIPAL,Almacen Principal,PRINCIPAL,active', $content);
        $this->assertStringContainsString('NORTE', $content);
    }

    public function test_template_rejects_invalid_entity(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/bad_entity');

        $response->assertStatus(422);
    }

    public function test_template_for_products_includes_separator_aware_json_column(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/price_lists');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('payment_method_codes;prices', $content);
    }
}
