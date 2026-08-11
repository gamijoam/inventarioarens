<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega sync_source_* a purchase_returns y purchase_return_items.
 *
 * La devolucion de compra necesita una identidad natural entre nodos para que
 * el applier la aplique de forma idempotente (local<->nube). Sin estas
 * columnas, el id local no sirve como referencia en el otro nodo.
 *
 * Ademas, purchase_return_items.purchase_item_id pasa a nullable: el applier
 * replica el item de la devolucion sin poder resolver el purchase_item del
 * otro nodo (los items de compra no viajan por sync). El cambio de nullability
 * se hace con DB::statement porque SQLite no soporta ->change() con FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'purchase_returns_sync_source_unique');
        });

        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'purchase_return_items_sync_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->dropUnique('purchase_return_items_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });

        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropUnique('purchase_returns_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });
    }
};
