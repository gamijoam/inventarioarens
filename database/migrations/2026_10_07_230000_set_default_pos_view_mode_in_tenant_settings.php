<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $records = DB::table('tenant_settings')->get();

        foreach ($records as $record) {
            $settings = is_string($record->settings)
                ? json_decode($record->settings, true)
                : ($record->settings ?? []);

            if (! is_array($settings)) {
                $settings = [];
            }

            if (! isset($settings['company'])) {
                $settings['company'] = [];
            }

            if (! isset($settings['company']['pos_default_view_mode'])) {
                $settings['company']['pos_default_view_mode'] = 'classic';

                DB::table('tenant_settings')
                    ->where('id', $record->id)
                    ->update([
                        'settings' => json_encode($settings),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Safe no-op
    }
};
