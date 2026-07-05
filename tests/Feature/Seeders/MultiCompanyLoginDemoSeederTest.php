<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\MultiCompanyLoginDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCompanyLoginDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_emails_have_two_isolated_companies_with_products_and_open_cash_registers(): void
    {
        $this->seed(MultiCompanyLoginDemoSeeder::class);

        $expected = [
            'gerente.caracas@demo.test' => ['demo-caracas-este', 'demo-caracas-norte'],
            'cajero.caracas@demo.test' => ['demo-caracas-este', 'demo-caracas-norte'],
            'gerente.valencia@demo.test' => ['demo-valencia-centro', 'demo-valencia-norte'],
            'cajero.valencia@demo.test' => ['demo-valencia-centro', 'demo-valencia-norte'],
        ];

        foreach ($expected as $email => $slugs) {
            $this
                ->postJson('/api/auth/tenants', ['email' => $email])
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.slug', $slugs[0])
                ->assertJsonPath('data.1.slug', $slugs[1]);
        }

        foreach (array_unique(array_merge(...array_values($expected))) as $slug) {
            $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

            $this->assertSame(2, Product::query()->where('tenant_id', $tenant->id)->count());
            $this->assertSame(2, StockBalance::query()->where('tenant_id', $tenant->id)->count());
            $this->assertSame(2, CashRegister::query()->where('tenant_id', $tenant->id)->count());
            $this->assertSame(2, CashRegisterSession::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', CashRegisterSession::STATUS_OPEN)
                ->whereNotNull('cash_register_id')
                ->count());
        }

        $caracasProductNames = Product::query()
            ->where('tenant_id', Tenant::query()->where('slug', 'demo-caracas-norte')->value('id'))
            ->pluck('name')
            ->all();

        $this->assertContains('Nevera Ejecutiva Caracas Norte', $caracasProductNames);
        $this->assertNotContains('Laptop Oficina Valencia Centro', $caracasProductNames);
    }

    public function test_seeder_can_run_more_than_once_without_duplicating_main_demo_data(): void
    {
        $this->seed(MultiCompanyLoginDemoSeeder::class);
        $this->seed(MultiCompanyLoginDemoSeeder::class);

        $this->assertSame(4, Tenant::query()->whereIn('slug', [
            'demo-caracas-norte',
            'demo-caracas-este',
            'demo-valencia-centro',
            'demo-valencia-norte',
        ])->count());
        $this->assertSame(8, Product::query()->count());
        $this->assertSame(8, StockBalance::query()->count());
        $this->assertSame(8, CashRegister::query()->count());

        $this->assertSame(2, User::query()
            ->where('email', 'gerente.caracas@demo.test')
            ->firstOrFail()
            ->tenants()
            ->wherePivot('status', 'active')
            ->count());
    }
}
