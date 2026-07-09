<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_transfer_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('validation_mode')->default('simple');
            $table->boolean('reserve_on_request')->default(false);
            $table->boolean('require_preparation_checklist')->default(false);
            $table->boolean('require_reception_checklist')->default(false);
            $table->jsonb('settings')->nullable();
            $table->timestamps();
        });

        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->string('validation_mode')->default('simple')->after('type');
            $table->string('guide_number')->nullable()->after('document_number');
            $table->timestamp('requested_at')->nullable()->after('processed_at');
            $table->timestamp('prepared_at')->nullable()->after('requested_at');
            $table->timestamp('dispatched_at')->nullable()->after('prepared_at');
            $table->timestamp('received_at')->nullable()->after('dispatched_at');
            $table->foreignId('prepared_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->after('prepared_by')->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->after('dispatched_by')->constrained('users')->nullOnDelete();

            $table->unique(['tenant_id', 'guide_number']);
            $table->index(['tenant_id', 'validation_mode']);
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->decimal('requested_quantity', 18, 4)->nullable()->after('quantity');
            $table->decimal('prepared_quantity', 18, 4)->nullable()->after('requested_quantity');
            $table->decimal('received_quantity', 18, 4)->nullable()->after('prepared_quantity');
            $table->decimal('difference_quantity', 18, 4)->default(0)->after('received_quantity');
            $table->string('difference_reason')->nullable()->after('difference_quantity');
            $table->text('difference_notes')->nullable()->after('difference_reason');
            $table->jsonb('prepared_product_unit_ids')->nullable()->after('product_unit_ids');
            $table->jsonb('received_product_unit_ids')->nullable()->after('prepared_product_unit_ids');
        });

        Schema::create('inventory_transfer_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_transfer_id');
            $table->string('guide_number');
            $table->string('status')->default('generated');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'inventory_transfer_id']);
            $table->unique(['tenant_id', 'guide_number']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'inventory_transfer_id'], 'itg_transfer_fk')
                ->references(['tenant_id', 'id'])
                ->on('inventory_transfers')
                ->cascadeOnDelete();
        });

        Schema::create('inventory_transfer_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_transfer_id');
            $table->foreignId('inventory_transfer_guide_id')->nullable();
            $table->string('stage');
            $table->string('status')->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'inventory_transfer_id']);
            $table->index(['tenant_id', 'stage', 'status']);
            $table->foreign(['tenant_id', 'inventory_transfer_id'], 'itc_transfer_fk')
                ->references(['tenant_id', 'id'])
                ->on('inventory_transfers')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'inventory_transfer_guide_id'], 'itc_guide_fk')
                ->references(['tenant_id', 'id'])
                ->on('inventory_transfer_guides')
                ->cascadeOnDelete();
        });

        Schema::create('inventory_transfer_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_transfer_checklist_id');
            $table->foreignId('inventory_transfer_item_id');
            $table->foreignId('product_id');
            $table->decimal('expected_quantity', 18, 4);
            $table->decimal('checked_quantity', 18, 4)->default(0);
            $table->decimal('difference_quantity', 18, 4)->default(0);
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('expected_product_unit_ids')->nullable();
            $table->jsonb('checked_product_unit_ids')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'inventory_transfer_checklist_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'inventory_transfer_checklist_id'], 'itci_checklist_fk')
                ->references(['tenant_id', 'id'])
                ->on('inventory_transfer_checklists')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'inventory_transfer_item_id'], 'itci_transfer_item_fk')
                ->references(['tenant_id', 'id'])
                ->on('inventory_transfer_items')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'itci_product_fk')
                ->references(['tenant_id', 'id'])
                ->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_checklist_items');
        Schema::dropIfExists('inventory_transfer_checklists');
        Schema::dropIfExists('inventory_transfer_guides');

        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->dropColumn([
                'requested_quantity',
                'prepared_quantity',
                'received_quantity',
                'difference_quantity',
                'difference_reason',
                'difference_notes',
                'prepared_product_unit_ids',
                'received_product_unit_ids',
            ]);
        });

        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'guide_number']);
            $table->dropIndex(['tenant_id', 'validation_mode']);
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropConstrainedForeignId('dispatched_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn([
                'validation_mode',
                'guide_number',
                'requested_at',
                'prepared_at',
                'dispatched_at',
                'received_at',
            ]);
        });

        Schema::dropIfExists('tenant_transfer_settings');
    }
};
