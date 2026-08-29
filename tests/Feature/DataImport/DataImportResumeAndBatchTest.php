<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Importers\ProductPriceImporter;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Models\DataImportEntity;
use App\Modules\DataImport\Models\DataImportRow;
use App\Modules\DataImport\Services\DataImportService;
use App\Modules\DataImport\Support\ImportStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DataImportResumeAndBatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['status' => 'active']);
    }

    public function test_running_entity_twice_skips_rows_already_imported(): void
    {
        $session = $this->makeSession();
        $csv = $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\nNORTE,Sucursal Norte,inactive\n");
        $this->service()->uploadFile($session, 'branches', $csv);

        $this->service()->runEntity($session, 'branches', $this->admin);
        $this->assertDatabaseCount('branches', 2);
        $this->assertSame(2, DataImportRow::query()->where('status', ImportStatus::ROW_OK)->count());

        // Re-upload del mismo archivo y re-ejecucion: no debe crear duplicados.
        $this->service()->uploadFile($session, 'branches', $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\nNORTE,Sucursal Norte,inactive\n"));
        $this->service()->runEntity($session, 'branches', $this->admin);

        $this->assertDatabaseCount('branches', 2);
        $this->assertSame(2, DataImportRow::query()->where('status', ImportStatus::ROW_SKIPPED)->count());
        $this->assertSame(4, DataImportRow::query()->count());
    }

    public function test_resume_processes_new_rows_but_skips_already_imported_ones(): void
    {
        $session = $this->makeSession();
        $first = $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\n");
        $this->service()->uploadFile($session, 'branches', $first);
        $this->service()->runEntity($session, 'branches', $this->admin);
        $this->assertDatabaseHas('branches', ['code' => 'MAIN']);

        // El archivo ahora tiene MAIN (ya importado) + OESTE (nuevo).
        $second = $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\nOESTE,Sucursal Oeste,active\n");
        $this->service()->uploadFile($session, 'branches', $second);
        $this->service()->runEntity($session, 'branches', $this->admin);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseHas('branches', ['code' => 'OESTE']);

        $skipped = DataImportRow::query()->where('status', ImportStatus::ROW_SKIPPED)->get();
        $this->assertCount(1, $skipped);
        $this->assertSame('MAIN', $skipped->first()->natural_key);
        $this->assertSame(2, DataImportRow::query()->where('status', ImportStatus::ROW_OK)->count());
    }

    public function test_data_import_rows_are_inserted_in_batches(): void
    {
        $session = $this->makeSession();
        $content = "code,name,status\n";
        for ($i = 1; $i <= 1100; $i++) {
            $content .= "B{$i},Sucursal {$i},active\n";
        }
        $this->service()->uploadFile($session, 'branches', $this->tempCsv($content));

        $insertStatements = [];
        DB::listen(function ($query) use (&$insertStatements): void {
            if (str_contains($query->sql, 'insert into "data_import_rows"')) {
                $insertStatements[] = $query->sql;
            }
        });

        $this->service()->runEntity($session, 'branches', $this->admin);

        // 1100 filas en lotes de 500 = 3 inserts, no 1100.
        $this->assertLessThanOrEqual(4, count($insertStatements));
        $this->assertSame(1100, DataImportRow::query()->count());
        $this->assertSame(1100, Branch::query()->count());
    }

    public function test_product_price_importer_exposes_its_natural_key(): void
    {
        $importer = new ProductPriceImporter;

        $this->assertSame(
            'sku-1:DETAL',
            $importer->naturalKey(['sku' => 'sku-1', 'list_code' => 'detal']),
        );
        $this->assertSame(
            ':',
            $importer->naturalKey(['sku' => null, 'list_code' => null]),
        );
    }

    public function test_reported_counts_reflect_only_the_last_run(): void
    {
        $session = $this->makeSession();
        $csv = $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\n");
        $this->service()->uploadFile($session, 'branches', $csv);
        $this->service()->runEntity($session, 'branches', $this->admin);

        $this->service()->uploadFile($session, 'branches', $this->tempCsv("code,name,status\nMAIN,Sucursal Principal,active\n"));
        $this->service()->runEntity($session, 'branches', $this->admin);

        $entity = DataImportEntity::query()
            ->where('data_import_id', $session->id)
            ->where('entity', 'branches')
            ->firstOrFail();

        $this->assertSame(1, $entity->total_rows);
        $this->assertSame(1, $entity->skipped_rows);
        $this->assertSame(0, $entity->succeeded_rows);
        $this->assertSame(ImportStatus::ENTITY_COMPLETED, $entity->status);
    }

    private function service(): DataImportService
    {
        return app(DataImportService::class);
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
}
