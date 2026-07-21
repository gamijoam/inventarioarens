<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imagenes propias de productos (galeria multi-imagen, Nivel 2 — pickeatracenamiento de URL-only).
 *
 * Columnas clave:
 * - uuid: identificador global unico (usado en URLs publicas y cache local).
 * - storage_path: ruta relativa dentro del disk (ej: products/1/2026/07/abc-uuid.webp).
 *   Cada tenant tiene su prefijo para no leakear archivos entre empresas.
 * - sha256: deduplicacion + verificacion post-download en sync. Permite reusar la misma
 *   imagen si el user sube el mismo archivo dos veces.
 * - sort + is_primary: orden de la galeria. is_primary=true marca la imagen que se muestra
 *   en listas/POS cuando solo se ve una.
 * - soft delete via deleted_at: el sync replica la baja primero (soft en local + cloud),
 *   despues un job diario limpia los archivos huerfanos del storage (>30 dias).
 *
 * Las FKs son compuestas (tenant_id, parent_id) porque ambos lados son tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // UNIQUE(tenant_id, id): requerido para que product_image_variants
            // y otras tablas hijas puedan hacer FK compuesta a (tenant_id, id).
            $table->unique(['tenant_id', 'id'], 'product_images_tenant_id_id_unique');
            $table->foreignId('product_id');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('storage_path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('original_name')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('alt', 255)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_primary')->default(false);

            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            // FK compuesta a products (tenant_id, id) — ambos lados tenant-scoped.
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();

            // Indice principal para "imagenes de un producto ordenadas".
            $table->index(['tenant_id', 'product_id', 'sort']);
            // Indice secundario para deduplicacion por sha256 (por tenant).
            $table->index(['tenant_id', 'sha256']);
            // Indice para soft-deletes por tenant.
            $table->index(['tenant_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
