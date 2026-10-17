<?php

namespace App\Modules\Offline\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class OfflineSnapshotExporter
{
    private const EXCLUDED_TABLES = [
        'auth_tokens',
        'sessions',
        'password_reset_tokens',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'job_batches',
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_refresh_tokens',
        'sync_entity_mappings',
        'sync_pairing_codes',
        'sync_tenant_mappings',
        'migrations',
    ];

    private const RELATED_UNSCOPED_TABLES = [
        'inventory_transfer_requests',
        'inventory_transfer_request_items',
        'inventory_transfer_request_guides',
        'inventory_transfer_request_guide_items',
    ];

    public function build(Tenant $tenant, string $snapshotId): array
    {
        if (! Str::isUuid($snapshotId)) {
            throw new RuntimeException('Identificador de exportacion invalido.');
        }

        $store = app(OfflineSnapshotStore::class);
        $root = $store->root();
        File::ensureDirectoryExists($root, 0700, true);

        $work = $root.DIRECTORY_SEPARATOR.'.work-'.$snapshotId;
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work, 0700, true);

        $databasePath = $work.DIRECTORY_SEPARATOR.'database.sqlite';
        $configPath = $work.DIRECTORY_SEPARATOR.'config.json';
        $imagesPath = $work.DIRECTORY_SEPARATOR.'images';
        File::ensureDirectoryExists($imagesPath, 0700, true);

        try {
            $databaseSnapshot = $this->createTenantDatabase($tenant, $databasePath);
            $includedTables = $databaseSnapshot['tables'];
            $this->copyReferencedImages($includedTables, $snapshotId, $imagesPath, $databasePath);

            $config = [
                'tenant_slug' => $tenant->slug,
                'company_name' => $tenant->name,
                'cloud_url' => rtrim((string) config('app.url'), '/'),
                'proxy_port' => 8787,
                'backend_port' => 8788,
                'offline_mode' => true,
            ];
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

            $files = [
                'database.sqlite' => $databasePath,
                'config.json' => $configPath,
            ];
            foreach (File::allFiles($imagesPath) as $file) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($imagesPath) + 1));
                $files['images/'.$relative] = $file->getPathname();
            }

            $manifestFiles = [];
            foreach ($files as $archiveName => $localPath) {
                $manifestFiles[$archiveName] = [
                    'size_bytes' => filesize($localPath),
                    'sha256' => hash_file('sha256', $localPath),
                ];
            }

            $migration = DB::connection($this->snapshotConnectionName($snapshotId))
                ->table('migrations')->orderByDesc('id')->value('migration');

            $manifest = [
                'format' => 'balanzapro-offline-package',
                'format_version' => 1,
                'tenant_slug' => $tenant->slug,
                'company_name' => $tenant->name,
                'generated_at' => now()->toIso8601String(),
                'schema_version' => $migration,
                'database_integrity' => 'ok',
                'access_control' => $databaseSnapshot['access_control'],
                'files' => $manifestFiles,
            ];

            $manifestPath = $work.DIRECTORY_SEPARATOR.'manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

            $archive = $store->archivePath($snapshotId);
            $temporaryArchive = $archive.'.tmp';
            $zip = new ZipArchive;
            if ($zip->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo ZIP.');
            }

            foreach ($files as $archiveName => $localPath) {
                if (! $zip->addFile($localPath, $archiveName)) {
                    $zip->close();
                    throw new RuntimeException('No se pudo agregar un archivo al paquete.');
                }
            }
            if (! $zip->addFile($manifestPath, 'manifest.json') || ! $zip->close()) {
                throw new RuntimeException('No se pudo cerrar el paquete ZIP.');
            }

            if (! rename($temporaryArchive, $archive)) {
                throw new RuntimeException('No se pudo activar el paquete generado.');
            }

            @chmod($archive, 0600);

            return [
                'file_name' => 'BalanzaPro-datos-'.$tenant->slug.'.zip',
                'size_bytes' => filesize($archive),
                'sha256' => hash_file('sha256', $archive),
            ];
        } finally {
            File::deleteDirectory($work);
            $connection = $this->snapshotConnectionName($snapshotId);
            DB::purge($connection);
            config(['database.connections.'.$connection => null]);
        }
    }

    private function createTenantDatabase(Tenant $tenant, string $databasePath): array
    {
        $originalDefault = DB::getDefaultConnection();
        // The directory name is .work-<uuid>; derive a private connection name from that UUID.
        $connection = $this->snapshotConnectionName(str_replace('.work-', '', basename(dirname($databasePath))));
        $source = $originalDefault;

        File::ensureDirectoryExists(dirname($databasePath), 0700, true);
        config([
            'database.connections.'.$connection => [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'busy_timeout' => 5000,
                'journal_mode' => 'DELETE',
                'synchronous' => 'FULL',
            ],
        ]);
        DB::purge($connection);

        $sourceTransactionOpen = false;
        $sourceDatabase = DB::connection($source);

        try {
            DB::setDefaultConnection($connection);
            $exitCode = Artisan::call('migrate', [
                '--database' => $connection,
                '--force' => true,
                '--no-interaction' => true,
            ]);
            if ($exitCode !== 0) {
                throw new RuntimeException('No se pudo preparar el esquema SQLite local: '.trim(Artisan::output()));
            }

            $sourceDatabase->beginTransaction();
            $sourceTransactionOpen = true;
            if ($sourceDatabase->getDriverName() === 'pgsql') {
                $sourceDatabase->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            $sourceSchema = $sourceDatabase->getSchemaBuilder();
            $targetSchema = DB::connection($connection)->getSchemaBuilder();
            $tables = $this->normalizeTables($sourceSchema->getTableListing());
            $included = [];

            $this->copyRows(
                DB::connection($source)->table('tenants')->where('id', $tenant->id),
                $connection,
                'tenants'
            );
            $included['tenants'] = true;

            foreach ($tables as $table) {
                if (in_array($table, self::EXCLUDED_TABLES, true) || ! $sourceSchema->hasColumn($table, 'tenant_id')) {
                    continue;
                }
                if (! $targetSchema->hasTable($table)) {
                    throw new RuntimeException('El esquema SQLite no contiene la tabla '.$table.'.');
                }

                $this->copyRows(
                    DB::connection($source)->table($table)->where('tenant_id', $tenant->id),
                    $connection,
                    $table
                );
                $included[$table] = true;
            }

            foreach (self::RELATED_UNSCOPED_TABLES as $table) {
                if (! in_array($table, $tables, true) || ! $targetSchema->hasTable($table)) {
                    continue;
                }

                $tenantColumns = array_values(array_filter(
                    $this->columnNames($sourceSchema->getColumns($table)),
                    fn (string $name): bool => str_contains($name, 'tenant_id')
                ));
                if ($tenantColumns === []) {
                    continue;
                }

                $query = DB::connection($source)->table($table)->where(function ($builder) use ($tenantColumns, $tenant): void {
                    foreach ($tenantColumns as $column) {
                        $builder->orWhere($column, $tenant->id);
                    }
                });
                $this->copyRows($query, $connection, $table);
                $included[$table] = true;
            }

            $roleIds = $this->copyTenantAccessControl($tenant, $source, $connection, $sourceSchema, $targetSchema, $included);

            // Copia solo los registros padre realmente referenciados por las filas de la empresa.
            $maxPasses = max(2, count($tables) + 1);
            for ($pass = 0; $pass < $maxPasses; $pass++) {
                $insertedThisPass = 0;
                $includedNow = array_keys($included);

                foreach ($includedNow as $childTable) {
                    if (! $sourceSchema->hasTable($childTable) || ! $targetSchema->hasTable($childTable)) {
                        continue;
                    }

                    foreach ($sourceSchema->getForeignKeys($childTable) as $foreignKey) {
                        $parentTable = $this->normalizeTable((string) $foreignKey['foreign_table']);
                        if (
                            $parentTable === ''
                            || in_array($parentTable, self::EXCLUDED_TABLES, true)
                            || ! $targetSchema->hasTable($parentTable)
                        ) {
                            continue;
                        }

                        $inserted = $this->copyForeignParents(
                            $connection,
                            $source,
                            $sourceSchema,
                            $parentTable,
                            $childTable,
                            $foreignKey,
                            (int) $tenant->id
                        );
                        if ($inserted > 0) {
                            $insertedThisPass += $inserted;
                            $included[$parentTable] = true;
                        }
                    }
                }

                // Request items and guides have no tenant_id; attach only rows whose parent belongs to this tenant.
                $relatedInserted = $this->copyTransferDependents($connection, $source, $sourceSchema, $tables);
                $insertedThisPass += $relatedInserted;
                if ($insertedThisPass === 0) {
                    break;
                }
            }

            $target = DB::connection($connection);
            $target->statement('PRAGMA foreign_keys = ON');
            $integrity = $target->select('PRAGMA integrity_check');
            if (($integrity[0]->integrity_check ?? null) !== 'ok') {
                throw new RuntimeException('La base SQLite generada no paso la verificacion de integridad.');
            }
            $foreignKeyErrors = $target->select('PRAGMA foreign_key_check');
            if ($foreignKeyErrors !== []) {
                throw new RuntimeException('La base SQLite generada contiene referencias incompletas.');
            }

            $accessControl = $this->validateTenantAccessControl($tenant, $source, $connection, $roleIds);

            return [
                'tables' => array_keys($included),
                'access_control' => $accessControl,
            ];
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            if ($sourceTransactionOpen && $sourceDatabase->transactionLevel() > 0) {
                $sourceDatabase->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            DB::purge($connection);
        }
    }

    private function copyTenantAccessControl(Tenant $tenant, string $source, string $connection, $sourceSchema, $targetSchema, array &$included): array
    {
        foreach (['permissions', 'role_has_permissions'] as $table) {
            if (! $sourceSchema->hasTable($table) || ! $targetSchema->hasTable($table)) {
                throw new RuntimeException('El esquema no contiene las tablas de permisos necesarias para el paquete local.');
            }
        }

        $this->copyRows(
            DB::connection($source)->table('permissions')->where('guard_name', 'web'),
            $connection,
            'permissions'
        );
        $included['permissions'] = true;

        $roleIds = DB::connection($source)->table('roles')
            ->where('tenant_id', $tenant->id)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (array_chunk($roleIds, 500) as $roleIdChunk) {
            $this->copyRows(
                DB::connection($source)->table('role_has_permissions')->whereIn('role_id', $roleIdChunk),
                $connection,
                'role_has_permissions'
            );
        }
        $included['role_has_permissions'] = true;

        return $roleIds;
    }

    private function validateTenantAccessControl(Tenant $tenant, string $source, string $connection, array $roleIds): array
    {
        $target = DB::connection($connection);
        $permissionNames = $target->table('permissions')
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();
        $missingPermissions = array_values(array_diff(BasePermissions::PERMISSIONS, $permissionNames));
        if ($missingPermissions !== []) {
            throw new RuntimeException(
                'No se puede preparar el paquete: faltan permisos del catálogo oficial ('
                .implode(', ', array_slice($missingPermissions, 0, 8)).').'
            );
        }

        $roleCount = count($roleIds);
        $sourcePermissionCount = DB::connection($source)->table('permissions')->where('guard_name', 'web')->count();
        $targetPermissionCount = $target->table('permissions')->where('guard_name', 'web')->count();
        $sourceRolePermissionCount = $roleIds === []
            ? 0
            : DB::connection($source)->table('role_has_permissions')->whereIn('role_id', $roleIds)->count();
        $targetRolePermissionCount = $roleIds === []
            ? 0
            : $target->table('role_has_permissions')->whereIn('role_id', $roleIds)->count();

        if ($roleCount === 0 || $targetRolePermissionCount === 0) {
            throw new RuntimeException('No se puede preparar el paquete: la empresa no tiene roles con permisos asignados.');
        }
        if (
            $sourcePermissionCount !== $targetPermissionCount
            || $sourceRolePermissionCount !== $targetRolePermissionCount
        ) {
            throw new RuntimeException('No se pudo conservar completo el catálogo o las asignaciones de permisos de la empresa.');
        }

        $userRoleCount = $target->table('model_has_roles')
            ->where('tenant_id', $tenant->id)
            ->count();

        return [
            'status' => 'complete',
            'permissions_count' => (int) $targetPermissionCount,
            'roles_count' => $roleCount,
            'role_permission_assignments' => (int) $targetRolePermissionCount,
            'user_role_assignments' => (int) $userRoleCount,
        ];
    }

    private function copyForeignParents(
        string $connection,
        string $source,
        $sourceSchema,
        string $parentTable,
        string $childTable,
        array $foreignKey,
        int $tenantId
    ): int {
        $childColumns = $foreignKey['columns'] ?? [];
        $parentColumns = $foreignKey['foreign_columns'] ?? [];
        if ($childColumns === [] || count($childColumns) !== count($parentColumns)) {
            return 0;
        }

        $target = DB::connection($connection);
        $childRows = $target->table($childTable)
            ->select($childColumns)
            ->distinct()
            ->whereNotNull($childColumns[0])
            ->cursor();

        $total = 0;
        $tuples = [];
        foreach ($childRows as $row) {
            $tuple = [];
            $hasNull = false;
            foreach ($childColumns as $index => $column) {
                $value = $row->{$column} ?? null;
                if ($value === null) {
                    $hasNull = true;
                    break;
                }
                $tuple[$parentColumns[$index]] = $value;
            }
            if ($hasNull) {
                continue;
            }
            $tuples[] = $tuple;

            if (count($tuples) >= 100) {
                $total += $this->copyParentTupleBatch($source, $sourceSchema, $parentTable, $connection, $tuples, $tenantId);
                $tuples = [];
            }
        }
        if ($tuples !== []) {
            $total += $this->copyParentTupleBatch($source, $sourceSchema, $parentTable, $connection, $tuples, $tenantId);
        }

        return $total;
    }

    private function copyParentTupleBatch(string $source, $sourceSchema, string $parentTable, string $connection, array $tuples, int $tenantId): int
    {
        $query = DB::connection($source)->table($parentTable)->where(function ($builder) use ($tuples): void {
            foreach ($tuples as $tuple) {
                $builder->orWhere(function ($clause) use ($tuple): void {
                    foreach ($tuple as $column => $value) {
                        $clause->where($column, $value);
                    }
                });
            }
        });

        if ($parentTable !== 'tenants' && $sourceSchema->hasColumn($parentTable, 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return $this->copyRows($query, $connection, $parentTable);
    }

    private function copyTransferDependents(string $connection, string $source, $sourceSchema, array $tables): int
    {
        $targetSchema = DB::connection($connection)->getSchemaBuilder();
        $total = 0;

        foreach (self::RELATED_UNSCOPED_TABLES as $parentTable) {
            if (! in_array($parentTable, $tables, true) || ! $targetSchema->hasTable($parentTable)) {
                continue;
            }
            $parentIds = DB::connection($connection)->table($parentTable)->pluck('id')->all();
            if ($parentIds === []) {
                continue;
            }

            foreach ($tables as $childTable) {
                if (
                    ! str_starts_with($childTable, 'inventory_transfer_request_')
                    || ! in_array($childTable, self::RELATED_UNSCOPED_TABLES, true)
                    || $childTable === $parentTable
                    || ! $sourceSchema->hasTable($childTable)
                    || ! $targetSchema->hasTable($childTable)
                    || $sourceSchema->hasColumn($childTable, 'tenant_id')
                ) {
                    continue;
                }

                foreach ($sourceSchema->getForeignKeys($childTable) as $foreignKey) {
                    if ($this->normalizeTable((string) $foreignKey['foreign_table']) !== $parentTable) {
                        continue;
                    }
                    $childColumns = $foreignKey['columns'] ?? [];
                    $parentColumns = $foreignKey['foreign_columns'] ?? [];
                    if (count($childColumns) !== 1 || count($parentColumns) !== 1) {
                        continue;
                    }

                    foreach (array_chunk($parentIds, 500) as $idChunk) {
                        $query = DB::connection($source)->table($childTable)
                            ->whereIn($childColumns[0], $idChunk);
                        $total += $this->copyRows($query, $connection, $childTable);
                    }
                }
            }
        }

        return $total;
    }

    private function copyRows($query, string $connection, string $table): int
    {
        $count = 0;
        $batch = [];
        foreach ($query->cursor() as $row) {
            $values = (array) $row;
            if ($table === 'users') {
                if (array_key_exists('is_platform_admin', $values)) {
                    $values['is_platform_admin'] = false;
                }
                if (array_key_exists('remember_token', $values)) {
                    $values['remember_token'] = null;
                }
            }
            $batch[] = $values;
            if (count($batch) >= 500) {
                $count += DB::connection($connection)->table($table)->insertOrIgnore($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $count += DB::connection($connection)->table($table)->insertOrIgnore($batch);
        }

        return $count;
    }

    private function copyReferencedImages(array $tables, string $snapshotId, string $imagesPath, string $databasePath): void
    {
        $connection = $this->snapshotConnectionName($snapshotId);
        DB::purge($connection);
        config(['database.connections.'.$connection.'.database' => $databasePath]);
        $targetSchema = DB::connection($connection)->getSchemaBuilder();
        $roots = array_filter([
            realpath(public_path('storage')),
            realpath(storage_path('app/public')),
        ]);
        $seen = [];

        foreach ($tables as $table) {
            if (! $targetSchema->hasTable($table)) {
                continue;
            }
            $columns = $this->columnNames($targetSchema->getColumns($table));
            $imageColumns = array_values(array_filter($columns, fn (string $name): bool => preg_match('/image|photo|logo|attachment|media|file_path/i', $name) === 1));
            if ($imageColumns === []) {
                continue;
            }

            foreach (DB::connection($connection)->table($table)->select($imageColumns)->cursor() as $row) {
                foreach ($imageColumns as $column) {
                    $value = $row->{$column} ?? null;
                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $path = parse_url($value, PHP_URL_PATH);
                    if (! is_string($path)) {
                        continue;
                    }
                    $relative = ltrim($path, '/');
                    if (str_starts_with($relative, 'storage/')) {
                        $relative = substr($relative, strlen('storage/'));
                    } elseif (str_starts_with($relative, 'public/storage/')) {
                        $relative = substr($relative, strlen('public/storage/'));
                    } else {
                        continue;
                    }

                    $relative = str_replace(['\\', "\0"], '/', $relative);
                    if ($relative === '' || str_contains($relative, '../') || str_starts_with($relative, '/')) {
                        continue;
                    }

                    $sourcePath = null;
                    foreach ($roots as $root) {
                        $candidate = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
                        if ($candidate && is_file($candidate) && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
                            $sourcePath = $candidate;
                            break;
                        }
                    }
                    if (! $sourcePath || isset($seen[$relative])) {
                        continue;
                    }

                    $destination = $imagesPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    File::ensureDirectoryExists(dirname($destination), 0700, true);
                    if (copy($sourcePath, $destination)) {
                        $seen[$relative] = true;
                    }
                }
            }
        }
    }

    private function normalizeTables(array $tables): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($table): string => $this->normalizeTable(is_string($table) ? $table : (string) ($table['name'] ?? '')),
            $tables
        ))));
    }

    private function normalizeTable(string $table): string
    {
        $parts = explode('.', $table);

        return trim((string) end($parts), '"');
    }

    private function columnNames(array $columns): array
    {
        return array_values(array_map(
            fn ($column): string => (string) ($column['name'] ?? ''),
            $columns
        ));
    }

    private function snapshotConnectionName(string $snapshotId): string
    {
        return 'offline_snapshot_'.str_replace('-', '', $snapshotId);
    }
}
