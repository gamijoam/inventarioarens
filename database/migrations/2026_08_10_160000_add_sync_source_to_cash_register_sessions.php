<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega sync_source_* a cash_register_sessions.
 *
 * La sesion de caja necesita una identidad natural entre nodos (local<->nube)
 * para que el applier la aplique de forma idempotente. Los IDs autoincrementales
 * locales no son identidad valida en el otro nodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'cash_register_sessions_sync_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->dropUnique('cash_register_sessions_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });
    }
};
