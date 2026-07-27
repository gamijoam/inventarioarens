<?php

namespace Tests\Feature\Local;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallLocalSqliteCommandTest extends TestCase
{
    use RefreshDatabase;

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
}
