<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hace que product_images.uuid sea unico POR TENANT en vez de global.
 *
 * Antes: UNIQUE(uuid) global -> imposible que la misma imagen exista en el
 * grupo y en sus spinoffs (misma BD single-tenant del VPS). Eso bloqueaba la
 * propagacion de imagenes del catalogo compartido a las empresas hijas.
 *
 * Ahora: UNIQUE(tenant_id, uuid) permite que cada tenant tenga la misma
 * imagen (mismo uuid, identidad natural del sync) sin colision.
 *
 * En SQLite el unique de Laravel es un indice nombrado (dropeable con DROP
 * INDEX); en Postgres es una constraint. Ambos se reconstruyen por nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropGlobalUnique();
        $this->backfillDuplicates();
        $this->createPerTenantUnique();
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropUnique('product_images_tenant_uuid_unique');
        });
        Schema::table('product_images', function (Blueprint $table): void {
            $table->unique('uuid', 'product_images_uuid_unique');
        });
    }

    private function dropGlobalUnique(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS product_images_uuid_unique');
        } else {
            Schema::table('product_images', function (Blueprint $table): void {
                $table->dropUnique('product_images_uuid_unique');
            });
        }
    }

    private function createPerTenantUnique(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX product_images_tenant_uuid_unique '
                .'ON product_images (tenant_id, uuid)'
            );
        } else {
            Schema::table('product_images', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'uuid'], 'product_images_tenant_uuid_unique');
            });
        }
    }

    private function backfillDuplicates(): void
    {
        // En caso de que existan duplicados de uuid entre tenants (de
        // versiones previas), conservamos la fila del tenant mas antiguo.
        $dups = DB::table('product_images')
            ->select('uuid')
            ->groupBy('uuid')
            ->havingRaw('COUNT(DISTINCT tenant_id) > 1')
            ->get();

        foreach ($dups as $dup) {
            $ids = DB::table('product_images')
                ->where('uuid', $dup->uuid)
                ->orderBy('id')
                ->pluck('id');
            $ids->shift(); // conservar la primera
            DB::table('product_images')
                ->whereIn('id', $ids->all())
                ->delete();
        }
    }
};
