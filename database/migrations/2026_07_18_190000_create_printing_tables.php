<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('paper_width_mm')->default(80);
            $table->unsignedSmallInteger('characters_per_line')->default(48);
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->string('logo_text')->nullable();
            $table->boolean('show_warranty_summary')->default(true);
            $table->boolean('cut_paper')->default(true);
            $table->boolean('open_cash_drawer')->default(false);
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_default']);
        });

        Schema::create('printer_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('cash_register_id')->nullable();
            $table->foreignId('print_profile_id')->constrained('print_profiles')->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('output_mode')->default('digital');
            $table->string('printer_type')->default('windows_printer');
            $table->string('printer_name')->nullable();
            $table->string('network_host')->nullable();
            $table->unsignedInteger('network_port')->nullable();
            $table->string('digital_directory')->nullable();
            $table->boolean('save_html_copy')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['tenant_id', 'cash_register_id'])->references(['tenant_id', 'id'])->on('cash_registers')->nullOnDelete();
            $table->index(['tenant_id', 'output_mode']);
        });

        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('printer_station_id')->nullable()->constrained('printer_stations')->nullOnDelete();
            $table->foreignId('print_profile_id')->nullable()->constrained('print_profiles')->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('pos_order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('output');
            $table->string('status')->default('created');
            $table->boolean('is_copy')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('payload_snapshot');
            $table->string('digital_pdf_path')->nullable();
            $table->string('digital_html_path')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printer_stations');
        Schema::dropIfExists('print_profiles');
    }
};
