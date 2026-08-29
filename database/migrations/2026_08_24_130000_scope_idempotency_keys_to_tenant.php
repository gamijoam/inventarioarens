<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('idempotency_keys', 'tenant_id')) {
            Schema::table('idempotency_keys', function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            });
        }

        $indexes = $this->indexNames();

        Schema::table('idempotency_keys', function (Blueprint $table) use ($indexes): void {
            foreach ([
                'idempotency_keys_key_method_path_unique',
                'idempotency_keys_tenant_key_method_path_unique',
            ] as $index) {
                if (in_array($index, $indexes, true)) {
                    $table->dropUnique($index);
                }
            }

            if (! in_array('idempotency_keys_tenant_id_key_method_path_unique', $indexes, true)) {
                $table->unique(['tenant_id', 'key', 'method', 'path']);
            }
        });
    }

    public function down(): void
    {
        $indexes = $this->indexNames();

        Schema::table('idempotency_keys', function (Blueprint $table) use ($indexes): void {
            if (in_array('idempotency_keys_tenant_id_key_method_path_unique', $indexes, true)) {
                $table->dropUnique('idempotency_keys_tenant_id_key_method_path_unique');
            }

            if (! in_array('idempotency_keys_tenant_key_method_path_unique', $indexes, true)) {
                $table->unique(
                    ['tenant_id', 'key', 'method', 'path'],
                    'idempotency_keys_tenant_key_method_path_unique',
                );
            }
        });
    }

    private function indexNames(): array
    {
        return array_column(Schema::getIndexes('idempotency_keys'), 'name');
    }
};
