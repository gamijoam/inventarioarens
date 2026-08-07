<?php

namespace App\Console\Commands;

use App\Support\Permissions\BasePermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class InstallLocalSqliteCommand extends Command
{
    protected $signature = 'local:install-sqlite
        {--database= : SQLite file path, relative to the project root or absolute}
        {--seed : Run the default database seeder after migrations}';

    protected $description = 'Create or migrate the local SQLite database without changing the application environment';

    public function handle(): int
    {
        $database = (string) ($this->option('database') ?: env('DB_DATABASE', storage_path('app/inventario.sqlite')));
        $database = $this->resolveDatabasePath($database);
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        if ($database === ':memory:') {
            $this->error('The local SQLite installer requires a file path.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($database));
        if (! File::exists($database)) {
            File::put($database, '');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'database.connections.sqlite.busy_timeout' => 5000,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.synchronous' => 'NORMAL',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);
        DB::purge('sqlite');

        try {
            $this->call('migrate', ['--database' => 'sqlite', '--force' => true]);
            $this->ensureBasePermissions();
        } finally {
            DB::purge('sqlite');
            config([
                'database.default' => $originalDefault,
                'database.connections.sqlite' => $originalSqlite,
            ]);
        }

        $this->info("Local SQLite database ready: {$database}");
        $this->line('This command does not modify .env. Set DB_CONNECTION=sqlite for application requests.');

        return self::SUCCESS;
    }

    /**
     * Inserts every permission declared in BasePermissions::PERMISSIONS if it
     * does not exist yet. Idempotent (uses findOrCreate) so it is safe to
     * invoke on every supervisor startup and after each app upgrade that
     * adds new permissions to the catalogue.
     *
     * Without this call, the local supervisor PHP runtime ships an empty
     * `permissions` table (migrations only seed schema, not data) and the
     * sidebar / route guards silently hide every feature that requires a
     * permission added after the database was first created.
     */
    private function ensureBasePermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function resolveDatabasePath(string $database): string
    {
        if ($database === ':memory:' || str_starts_with($database, DIRECTORY_SEPARATOR)) {
            return $database;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $database) === 1) {
            return $database;
        }

        return base_path($database);
    }
}
