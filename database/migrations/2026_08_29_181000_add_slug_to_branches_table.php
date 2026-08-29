<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('code');
        });

        $used = [];
        DB::table('branches')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'name', 'slug'])
            ->each(function (object $branch) use (&$used): void {
                $base = Str::slug((string) $branch->name) ?: 'branch';
                $slug = $base;
                $suffix = 2;
                while (isset($used[$branch->tenant_id][$slug])) {
                    $slug = $base.'-'.$suffix++;
                }

                $used[$branch->tenant_id][$slug] = true;
                DB::table('branches')->where('id', $branch->id)->update(['slug' => $slug]);
            });

        Schema::table('branches', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
