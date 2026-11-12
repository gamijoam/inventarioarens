<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad de sincronizacion del saldo a favor: cada movimiento replicado se
 * inserta una sola vez en el nodo destino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_transactions', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable();
            $table->unsignedBigInteger('sync_source_id')->nullable();
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'customer_credit_sync_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_transactions', function (Blueprint $table): void {
            $table->dropUnique('customer_credit_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });
    }
};
