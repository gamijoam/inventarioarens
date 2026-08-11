<?php

namespace Tests\Feature\TelegramBot;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumen_only_sent_at_configured_time(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);
        TelegramBotUser::create([
            'tenant_id' => $tenant->id,
            'telegram_chat_id' => '111111111',
            'name' => 'A',
            'is_active' => true,
        ]);
        TenantSetting::where('tenant_id', $tenant->id)
            ->update(['settings' => json_encode(['telegram' => ['enabled' => true, 'report_time' => now()->format('H:i')]])]);

        Http::fake(['https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);

        $exit = Artisan::call('telegram:alerts', ['--type' => 'resumen']);

        $this->assertSame(0, $exit);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_resumen_not_sent_outside_configured_time(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);
        TelegramBotUser::create([
            'tenant_id' => $tenant->id,
            'telegram_chat_id' => '111111111',
            'name' => 'A',
            'is_active' => true,
        ]);
        $wrongHour = now()->subHours(2)->format('H:i');
        TenantSetting::where('tenant_id', $tenant->id)
            ->update(['settings' => json_encode(['telegram' => ['enabled' => true, 'report_time' => $wrongHour]])]);

        Http::fake();

        Artisan::call('telegram:alerts', ['--type' => 'resumen']);

        Http::assertNothingSent();
    }

    public function test_stock_alert_sent_when_enabled(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);
        app(TenantManager::class)->set($tenant);
        $branch = Branch::create(['name' => 'B', 'code' => 'B-AL']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'W', 'code' => 'W-AL']);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Bajo',
            'sku' => 'BAJO-001',
            'tracking_type' => 'quantity',
            'min_stock' => 3,
            'sale_currency' => 'USD',
            'is_active' => true,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_available' => 1,
        ]);
        TelegramBotUser::create([
            'tenant_id' => $tenant->id,
            'telegram_chat_id' => '222222222',
            'name' => 'A',
            'is_active' => true,
        ]);
        TenantSetting::where('tenant_id', $tenant->id)
            ->update(['settings' => json_encode(['telegram' => ['enabled' => true, 'low_stock_alerts' => true, 'low_stock_threshold' => 3]])]);

        Http::fake(['https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);

        Artisan::call('telegram:alerts', ['--type' => 'stock']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_stock_alert_not_sent_when_disabled(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);
        TelegramBotUser::create([
            'tenant_id' => $tenant->id,
            'telegram_chat_id' => '333333333',
            'name' => 'A',
            'is_active' => true,
        ]);
        TenantSetting::where('tenant_id', $tenant->id)
            ->update(['settings' => json_encode(['telegram' => ['enabled' => true, 'low_stock_alerts' => false]])]);

        Http::fake();

        Artisan::call('telegram:alerts', ['--type' => 'stock']);

        Http::assertNothingSent();
    }
}
