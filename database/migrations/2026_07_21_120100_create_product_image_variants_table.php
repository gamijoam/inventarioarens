<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes de una ProductImage (original 2048px, medium 800x800, thumb 200x200).
 *
 * Generadas server-side con GD en el upload. Todas WebP (~30% mas chico que JPG
 * a igual calidad). El path apunta a un archivo dentro del mismo disk `product-images`
 * (subdirectorio `variants/{variant}/`).
 *
 * Cada ProductImage tiene 3 filas aqui (excepto imagenes legacy pre-Nivel-2 que solo
 * tienen la original; las URL accessors caen a la original si la variante falta).
 *
 * Se replica via sync evento `product.image.{uploaded,updated}` (mismo evento que
 * la imagen padre, las variantes van en el mismo payload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_image_id');
            $table->string('variant', 32); // original | medium | thumb
            $table->string('storage_path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_image_id', 'variant'], 'product_image_variants_unique');
            $table->foreign(['tenant_id', 'product_image_id'])
                ->references(['tenant_id', 'id'])
                ->on('product_images')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_variants');
    }
};
