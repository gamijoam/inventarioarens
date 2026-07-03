<?php

use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->date('issued_at')->nullable()->after('document_number');
            $table->date('due_date')->nullable()->after('issued_at');
            $table->decimal('received_base_amount', 18, 4)->default(0)->after('total_local_amount');
            $table->decimal('received_local_amount', 18, 4)->default(0)->after('received_base_amount');
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->decimal('received_quantity', 18, 4)->default(0)->after('quantity');
        });

        DB::table('purchase_orders')
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->orderBy('id')
            ->each(function (object $purchase): void {
                DB::table('purchase_orders')
                    ->where('id', $purchase->id)
                    ->update([
                        'received_base_amount' => $purchase->total_base_amount,
                        'received_local_amount' => $purchase->total_local_amount,
                    ]);

                DB::table('purchase_items')
                    ->where('purchase_order_id', $purchase->id)
                    ->update([
                        'received_quantity' => DB::raw('quantity'),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropColumn('received_quantity');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'issued_at',
                'due_date',
                'received_base_amount',
                'received_local_amount',
            ]);
        });
    }
};
