<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Bandera explicita para distinguir un grupo raiz de una empresa standalone.
            // Antes se inferia de `parent_id IS NULL`, pero el codigo de registro
            // standalone tambien creaba con parent_id NULL, generando confusion
            // entre "grupo" y "empresa suelta".
            $table->boolean('is_group')->default(false)->after('parent_id');
            $table->index(['is_group', 'status'], 'tenants_is_group_status_index');
        });

        // Backfill: todos los tenants existentes con parent_id = NULL son grupos
        // (los creo el platform admin via POST /api/master/groups o el registro
        // legacy que los trataba como root).
        DB::table('tenants')
            ->whereNull('parent_id')
            ->update(['is_group' => true]);

        // Garantia de consistencia: si un tenant tiene parent_id != NULL,
        // NO puede ser grupo.
        DB::table('tenants')
            ->whereNotNull('parent_id')
            ->update(['is_group' => false]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('tenants_is_group_status_index');
            $table->dropColumn('is_group');
        });
    }
};