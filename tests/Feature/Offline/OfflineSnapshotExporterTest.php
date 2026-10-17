<?php

namespace Tests\Feature\Offline;

use App\Models\User;
use App\Modules\Offline\Services\OfflineSnapshotExporter;
use App\Modules\Offline\Services\OfflineSnapshotStore;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class OfflineSnapshotExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_only_the_selected_tenant_and_writes_a_verified_manifest(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = User::factory()->create();
        $userA->tenants()->attach($tenantA, ['status' => 'active']);
        $userB = User::factory()->create();
        $userB->tenants()->attach($tenantB, ['status' => 'active']);
        $this->seed(RolesAndPermissionsSeeder::class);
        setPermissionsTeamId($tenantA->id);
        $userA->assignRole('Owner');
        setPermissionsTeamId($tenantB->id);
        $userB->assignRole('Owner');

        $snapshotId = (string) Str::uuid();
        $store = app(OfflineSnapshotStore::class);
        $store->create($snapshotId, $tenantA->id, $userA->id);

        try {
            $result = app(OfflineSnapshotExporter::class)->build($tenantA, $snapshotId);
            $archivePath = $store->archivePath($snapshotId);
            $this->assertFileExists($archivePath);
            $this->assertGreaterThan(0, $result['size_bytes']);
            $this->assertSame(hash_file('sha256', $archivePath), $result['sha256']);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archivePath) === true);
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $this->assertSame('balanzapro-offline-package', $manifest['format']);
            $this->assertSame($tenantA->slug, $manifest['tenant_slug']);
            $this->assertArrayHasKey('database.sqlite', $manifest['files']);
            $this->assertSame('complete', $manifest['access_control']['status']);

            $databaseBytes = $zip->getFromName('database.sqlite');
            $this->assertIsString($databaseBytes);
            $temporaryDatabase = tempnam(sys_get_temp_dir(), 'offline-snapshot-');
            file_put_contents($temporaryDatabase, $databaseBytes);
            $database = new \PDO('sqlite:'.$temporaryDatabase);
            $rows = $database->query('SELECT slug FROM tenants ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains($tenantA->slug, $rows);
            $this->assertNotContains($tenantB->slug, $rows);
            $permissionCount = (int) $database->query("SELECT COUNT(*) FROM permissions WHERE guard_name = 'web'")->fetchColumn();
            $rolePermissionCount = (int) $database->query(
                'SELECT COUNT(*) FROM role_has_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = '.(int) $tenantA->id
            )->fetchColumn();
            $userRoleCount = (int) $database->query('SELECT COUNT(*) FROM model_has_roles')->fetchColumn();
            $this->assertGreaterThanOrEqual(count(BasePermissions::PERMISSIONS), $permissionCount);
            $this->assertSame($manifest['access_control']['permissions_count'], $permissionCount);
            $this->assertSame($manifest['access_control']['role_permission_assignments'], $rolePermissionCount);
            $this->assertGreaterThan(0, $rolePermissionCount);
            $this->assertSame(1, $userRoleCount);
            $this->assertSame('ok', $database->query('PRAGMA integrity_check')->fetchColumn());
            $zip->close();
            @unlink($temporaryDatabase);
        } finally {
            @unlink($store->archivePath($snapshotId));
            @unlink($store->metadataPath($snapshotId));
        }
    }
}
