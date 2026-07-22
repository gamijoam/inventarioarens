<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Models\DataImportEntity;
use App\Modules\DataImport\Services\DataImportService;
use App\Modules\DataImport\Support\ImportStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DataImportWizardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('data_import.view', 'web');
        Permission::findOrCreate('data_import.create', 'web');
        Permission::findOrCreate('data_import.execute', 'web');
        Permission::findOrCreate('data_import.delete', 'web');

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
        $this->admin->givePermissionTo(['data_import.view', 'data_import.create', 'data_import.execute']);
    }

    private function makeSession(): DataImport
    {
        return DataImport::create([
            'user_id' => $this->admin->id,
            'status' => ImportStatus::SESSION_PENDING,
        ]);
    }

    private function tempCsv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'test.csv', 'text/csv', null, true);
    }

    public function test_upload_stores_file_and_creates_entity_row(): void
    {
        $session = $this->makeSession();
        $csv = $this->tempCsv("code,name\nMAIN,Sucursal Principal\n");

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/branches/upload", [
                'file' => $csv,
            ]);

        $response->assertOk()
            ->assertJsonPath('entity', 'branches');

        $this->assertDatabaseHas('data_import_entities', [
            'data_import_id' => $session->id,
            'entity' => 'branches',
            'status' => 'pending',
        ]);

        $entityRow = DataImportEntity::query()
            ->where('data_import_id', $session->id)
            ->where('entity', 'branches')
            ->first();
        $this->assertFileExists($entityRow->source_path);
    }

    public function test_upload_rejects_invalid_entity(): void
    {
        $session = $this->makeSession();
        $csv = $this->tempCsv("code,name\nMAIN,Test\n");

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/bad_entity/upload", [
                'file' => $csv,
            ]);

        $response->assertStatus(422);
    }

    public function test_upload_requires_file(): void
    {
        $session = $this->makeSession();

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/branches/upload", []);

        $response->assertStatus(422);
    }

    public function test_run_executes_branches_import_and_returns_summary(): void
    {
        $session = $this->makeSession();
        $csv = $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\nNORTE,Sucursal Norte,inactive\n");
        $this->service()->uploadFile($session, 'branches', $csv);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/branches/run");

        $response->assertOk()
            ->assertJsonPath('entity', 'branches')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.ok', 2)
            ->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseHas('branches', ['code' => 'MAIN']);
        $this->assertDatabaseHas('branches', ['code' => 'NORTE']);
        $this->assertDatabaseHas('data_import_entities', [
            'data_import_id' => $session->id,
            'entity' => 'branches',
            'status' => 'completed',
        ]);
        $this->assertDatabaseCount('data_import_rows', 2);
    }

    public function test_run_records_skipped_and_failed_rows(): void
    {
        $session = $this->makeSession();
        Branch::create(['code' => 'EXISTING', 'name' => 'Old', 'status' => 'active']);

        $csv = $this->tempCsv("code,name\nEXISTING,Will be skipped\n,Sin codigo\nNEW,Nuevo OK\n");
        $this->service()->uploadFile($session, 'branches', $csv);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/branches/run");

        $response->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.ok', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 1);

        $this->assertDatabaseCount('branches', 2);
    }

    public function test_run_requires_uploaded_file_first(): void
    {
        $session = $this->makeSession();

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/branches/run");

        $response->assertStatus(422);
    }

    public function test_report_download_returns_csv(): void
    {
        $session = $this->makeSession();
        $csv = $this->tempCsv("code,name\nMAIN,Sucursal Principal\n");
        $this->service()->uploadFile($session, 'branches', $csv);
        $this->service()->runEntity($session, 'branches', $this->admin);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/import/sessions/{$session->id}/report");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('fila,entidad,estado,clave_natural', $response->getContent());
        $this->assertStringContainsString('1,branches,ok,MAIN', $response->getContent());
    }

    public function test_full_wizard_flow_upload_run_report(): void
    {
        $createResp = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/import/sessions', ['meta' => ['source' => 'test']]);
        $sessionId = $createResp->json('data.id');

        $csv = $this->tempCsv("code,name\nW1,Almacen 1\n");
        $this->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$sessionId}/entities/warehouses/upload", ['file' => $csv])
            ->assertOk();

        $this->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$sessionId}/entities/warehouses/run")
            ->assertOk()
            ->assertJsonPath('summary.failed', 1);

        $this->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/import/sessions/{$sessionId}/report")
            ->assertOk();
    }

    public function test_execute_permission_required_to_run(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $viewer->tenants()->attach($this->tenant->id, ['status' => 'active']);
        setPermissionsTeamId($this->tenant->id);
        $viewer->givePermissionTo(['data_import.view']);

        $session = $this->makeSession();

        $response = $this
            ->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/import/sessions/{$session->id}/entities/branches/run");

        $response->assertForbidden();
    }

    private function service(): DataImportService
    {
        return app(DataImportService::class);
    }
}
