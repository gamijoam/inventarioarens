<?php

namespace Tests\Feature\Local;

use App\Support\Permissions\BasePermissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InstallLocalSqliteCommandTest extends TestCase
{
    public function test_installer_creates_and_migrates_a_sqlite_file_without_changing_environment(): void
    {
        $database = storage_path('framework/testing-local.sqlite');
        File::delete($database);
        File::delete($database.'-shm');
        File::delete($database.'-wal');

        $originalConnection = config('database.default');

        $exitCode = Artisan::call('local:install-sqlite', ['--database' => $database]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($database);
        $this->assertSame($originalConnection, config('database.default'));
        $connection = new \PDO('sqlite:'.$database);
        $this->assertGreaterThan(0, (int) $connection->query('SELECT COUNT(*) FROM migrations')->fetchColumn());

        $this->assertSame('wal', strtolower((string) $connection->query('PRAGMA journal_mode')->fetchColumn()));

        File::delete($database);
        File::delete($database.'-shm');
        File::delete($database.'-wal');
    }

    public function test_installer_rejects_in_memory_database(): void
    {
        $this->assertSame(1, Artisan::call('local:install-sqlite', ['--database' => ':memory:']));
    }

    public function test_installer_seeds_base_permissions_catalog_on_fresh_install(): void
    {
        $database = storage_path('framework/testing-local-perms.sqlite');
        File::delete($database);
        File::delete($database.'-shm');
        File::delete($database.'-wal');

        $exitCode = Artisan::call('local:install-sqlite', ['--database' => $database]);

        $this->assertSame(0, $exitCode);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
        ]);
        \DB::purge('sqlite');

        $catalog = BasePermissions::PERMISSIONS;
        $this->assertNotEmpty($catalog);

        $missing = [];
        foreach ($catalog as $permission) {
            if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
                $missing[] = $permission;
            }
        }

        $this->assertEmpty(
            $missing,
            'local:install-sqlite should seed every permission in BasePermissions::PERMISSIONS. Missing: '.implode(', ', $missing)
        );

        File::delete($database);
        File::delete($database.'-shm');
        File::delete($database.'-wal');
    }

    public function test_installer_seeding_permissions_is_idempotent_across_runs(): void
    {
        $database = storage_path('framework/testing-local-idem.sqlite');
        File::delete($database);
        File::delete($database.'-shm');
        File::delete($database.'-wal');

        Artisan::call('local:install-sqlite', ['--database' => $database]);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
        ]);
        \DB::purge('sqlite');
        $firstCount = Permission::query()->count();

        \DB::purge('sqlite');
        config(['database.default' => 'testing']);
        \DB::purge('testing');

        Artisan::call('local:install-sqlite', ['--database' => $database]);

        \DB::purge('sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
        ]);
        \DB::purge('sqlite');
        $secondCount = Permission::query()->count();

        $this->assertSame(
            $firstCount,
            $secondCount,
            'Running local:install-sqlite twice must not duplicate permissions.'
        );
        $this->assertGreaterThan(
            0,
            $firstCount,
            'Permissions must exist after the first install.'
        );

        File::delete($database);
        File::delete($database.'-shm');
        File::delete($database.'-wal');
    }
}

