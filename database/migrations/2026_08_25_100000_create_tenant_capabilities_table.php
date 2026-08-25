<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('capability', 80);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'capability']);
            $table->index(['tenant_id', 'enabled']);
        });

        $capabilities = [
            'dashboard',
            'catalog',
            'inventory',
            'customers',
            'suppliers',
            'sales',
            'purchases',
            'pos',
            'cash_register',
            'finance',
            'reports',
            'promotions',
            'commissions',
            'warranties',
            'workshop',
            'intercompany',
            'inventory_transfers',
            'data_import',
            'quotations',
            'printing',
            'telegram',
            'offline_sync',
        ];
        $now = now();
        $rows = [];

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ($capabilities as $capability) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'capability' => $capability,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tenant_capabilities')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_capabilities');
    }
};
