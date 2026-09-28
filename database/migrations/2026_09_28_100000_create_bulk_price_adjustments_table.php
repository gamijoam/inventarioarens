<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_price_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('target')->default('base_price'); // 'base_price' or 'price_list'
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('adjustment_type'); // 'percentage_increase', 'percentage_decrease', 'fixed_amount', 'markup_on_cost'
            $table->decimal('adjustment_value', 12, 4);
            $table->string('rounding')->default('none'); // 'none', 'round_99', 'round_int'
            $table->json('filters')->nullable();
            $table->integer('items_count')->default(0);
            $table->json('snapshots')->nullable(); // array of [{product_id, old_price, new_price}]
            $table->string('status')->default('applied'); // 'applied', 'reverted'
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_price_adjustments');
    }
};
