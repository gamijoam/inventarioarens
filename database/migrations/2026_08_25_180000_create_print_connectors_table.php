<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_connectors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('installation_id', 128);
            $table->string('version', 32)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'installation_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('print_connector_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('print_connector_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'print_connector_id'])
                ->references(['tenant_id', 'id'])
                ->on('print_connectors')
                ->nullOnDelete();
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('print_connector_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('print_connector_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'print_connector_id'])
                ->references(['tenant_id', 'id'])
                ->on('print_connectors')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'print_connector_id', 'revoked_at']);
        });

        Schema::table('printer_stations', function (Blueprint $table): void {
            $table->unsignedBigInteger('print_connector_id')->nullable()->after('tenant_id');
            $table->foreign(['tenant_id', 'print_connector_id'])
                ->references(['tenant_id', 'id'])
                ->on('print_connectors')
                ->nullOnDelete();
            $table->index(['tenant_id', 'print_connector_id']);
        });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('print_connector_id')->nullable()->after('tenant_id');
            $table->string('claim_token_hash', 64)->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->foreign(['tenant_id', 'print_connector_id'])
                ->references(['tenant_id', 'id'])
                ->on('print_connectors')
                ->nullOnDelete();
            $table->index(['tenant_id', 'print_connector_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'print_connector_id']);
            $table->dropIndex(['tenant_id', 'print_connector_id', 'status']);
            $table->dropUnique(['uuid']);
            $table->dropColumn([
                'uuid',
                'print_connector_id',
                'claim_token_hash',
                'claim_expires_at',
                'claimed_at',
            ]);
        });

        Schema::table('printer_stations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'print_connector_id']);
            $table->dropIndex(['tenant_id', 'print_connector_id']);
            $table->dropColumn('print_connector_id');
        });

        Schema::dropIfExists('print_connector_tokens');
        Schema::dropIfExists('print_connector_pairing_codes');
        Schema::dropIfExists('print_connectors');
    }
};
