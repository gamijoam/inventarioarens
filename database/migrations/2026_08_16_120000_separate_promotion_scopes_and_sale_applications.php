<?php

use App\Modules\Promotions\Models\Promotion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('scope')->default(Promotion::SCOPE_PRODUCT_OFFER);
            $table->boolean('allows_combos')->default(false);
            $table->index(['tenant_id', 'scope', 'is_active'], 'promotions_tenant_scope_active_index');
        });

        DB::table('promotions')
            ->whereIn('benefit_type', [Promotion::BENEFIT_FIXED_BUNDLE_PRICE, Promotion::BENEFIT_BUY_X_GET_Y])
            ->update(['scope' => Promotion::SCOPE_COMBO]);

        DB::table('promotions')
            ->whereIn('benefit_type', [Promotion::BENEFIT_PERCENT_DISCOUNT, Promotion::BENEFIT_FIXED_DISCOUNT])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('promotion_items')
                    ->whereColumn('promotion_items.promotion_id', 'promotions.id')
                    ->whereColumn('promotion_items.tenant_id', 'promotions.tenant_id');
            })
            ->update(['scope' => Promotion::SCOPE_INVOICE]);

        DB::table('promotions')
            ->whereIn('benefit_type', [Promotion::BENEFIT_PERCENT_DISCOUNT, Promotion::BENEFIT_FIXED_DISCOUNT])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('promotion_items')
                    ->whereColumn('promotion_items.promotion_id', 'promotions.id')
                    ->whereColumn('promotion_items.tenant_id', 'promotions.tenant_id');
            })
            ->update(['scope' => Promotion::SCOPE_LEGACY_PRODUCT_DISCOUNT]);

        Schema::create('sale_promotion_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id');
            $table->foreignId('promotion_id')->nullable();
            $table->string('slot');
            $table->string('scope');
            $table->string('status');
            $table->string('instance_uuid')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('promotion_code')->nullable();
            $table->string('promotion_name');
            $table->string('benefit_type');
            $table->string('payment_currency', 3)->default(Promotion::PAYMENT_CURRENCY_ANY);
            $table->decimal('price_usd', 18, 4)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('discount_amount_usd', 18, 4)->nullable();
            $table->json('conditions_snapshot')->nullable();
            $table->decimal('base_before_amount', 18, 4);
            $table->decimal('local_before_amount', 18, 4);
            $table->decimal('base_adjustment_amount', 18, 4);
            $table->decimal('local_adjustment_amount', 18, 4);
            $table->decimal('base_after_amount', 18, 4);
            $table->decimal('local_after_amount', 18, 4);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sale_id', 'slot']);
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'promotion_id'])
                ->references(['tenant_id', 'id'])
                ->on('promotions')
                ->nullOnDelete();
            $table->index(['tenant_id', 'scope', 'status']);
        });

        Schema::create('sale_promotion_application_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_promotion_application_id');
            $table->foreignId('sale_item_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('base_before_amount', 18, 4);
            $table->decimal('local_before_amount', 18, 4);
            $table->decimal('base_adjustment_amount', 18, 4);
            $table->decimal('local_adjustment_amount', 18, 4);
            $table->decimal('base_after_amount', 18, 4);
            $table->decimal('local_after_amount', 18, 4);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(
                ['tenant_id', 'sale_promotion_application_id', 'sale_item_id'],
                'sale_promotion_application_items_unique'
            );
            $table->foreign(['tenant_id', 'sale_promotion_application_id'])
                ->references(['tenant_id', 'id'])
                ->on('sale_promotion_applications')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sale_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('sale_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_promotion_application_items');
        Schema::dropIfExists('sale_promotion_applications');

        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropIndex('promotions_tenant_scope_active_index');
            $table->dropColumn(['scope', 'allows_combos']);
        });
    }
};
