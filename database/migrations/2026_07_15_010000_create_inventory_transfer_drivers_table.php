<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla inventory_transfer_drivers: 1:1 con InventoryTransfer.
 * Almacena los datos del transportista (driver) y su firma digital
 * (URL a la imagen de la firma capturada en el celular del receptor
 * o transportista). El transportista NO necesita user en el sistema.
 *
 * Campos:
 * - inventory_transfer_id: FK 1:1 (UNIQUE) a inventory_transfers.
 * - name, document_number, phone: datos del transportista.
 * - vehicle_plate, carrier_company: contexto del vehiculo y empresa.
 * - picked_up_at, delivered_at: timestamps de la operacion fisica.
 * - signed_by_driver_at, signature_driver_url: firma del transportista.
 * - signed_by_receiver_at, signature_receiver_url: firma del receptor.
 * - notes: observaciones libres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->foreignId('inventory_transfer_id')
                ->unique()
                ->constrained('inventory_transfers')
                ->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('document_number', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('carrier_company', 150)->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('signed_by_driver_at')->nullable();
            $table->string('signature_driver_url', 500)->nullable();
            $table->timestamp('signed_by_receiver_at')->nullable();
            $table->string('signature_receiver_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_drivers');
    }
};
