<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Services\ExchangeRateActivationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WsHubEventBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_exchange_rate_broadcasts_to_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        app(TenantManager::class)->set($tenant);

        $type = ExchangeRateType::create([
            'tenant_id' => $tenant->id,
            'code' => 'BCV',
            'name' => 'Banco Central de Venezuela',
            'is_default' => true,
        ]);

        $rate = ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $type->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 45.85,
            'effective_at' => now(),
            'is_active' => false,
        ]);

        $service = app(ExchangeRateActivationService::class);
        $service->activate($rate);

        Http::assertSent(function ($request) use ($tenant) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === "tenant:{$tenant->id}"
                && $request['event'] === 'rate.updated'
                && (float) $request['data']['rate'] === 45.85;
        });
    }

    public function test_closing_cash_register_session_broadcasts_to_ws_hub(): void
    {
        Http::fake([
            'http://127.0.0.1:16666/publish' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Cash Tenant', 'slug' => 'cash-tenant']);
        app(TenantManager::class)->set($tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUC-01',
            'name' => 'Principal',
            'address' => 'Av 1',
            'status' => 'active',
        ]);

        $register = CashRegister::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-1',
            'name' => 'Caja 1',
            'status' => CashRegister::STATUS_ACTIVE,
        ]);

        $service = app(CashRegisterService::class);
        $session = $service->open($user, $branch, $register, $user, [
            'opening_cash_usd' => 100,
            'opening_cash_ves' => 0,
        ]);

        $service->close($session, [
            'counted_base_amount' => 100,
            'counted_local_amount' => 0,
            'counted_cash_usd' => 100,
            'counted_cash_ves' => 0,
            'closing_notes' => 'Cierre normal del dia',
        ], $user);

        Http::assertSent(function ($request) use ($tenant, $session) {
            return $request->url() === 'http://127.0.0.1:16666/publish'
                && $request['channel'] === "tenant:{$tenant->id}"
                && $request['event'] === 'cash_register.closed'
                && $request['data']['session_id'] === $session->id;
        });
    }
}
