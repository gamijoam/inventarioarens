<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerCreditTransaction;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreditAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_credit_issue_returns_the_original_transaction(): void
    {
        [$customer, $user] = $this->fixture();
        $service = app(CustomerCreditService::class);
        $payload = [
            'currency' => Product::CURRENCY_USD,
            'amount' => 100,
            'amount_base' => 100,
            'amount_local' => 0,
            'operation_key' => 'return:1',
        ];

        $first = $service->issue($customer, $user, $payload);
        $second = $service->issue($customer, $user, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerCreditTransaction::query()->count());
    }

    public function test_retrying_credit_application_returns_the_original_transaction(): void
    {
        [$customer, $user] = $this->fixture();
        $service = app(CustomerCreditService::class);
        $service->issue($customer, $user, [
            'currency' => Product::CURRENCY_USD,
            'amount' => 100,
            'amount_base' => 100,
            'amount_local' => 0,
            'operation_key' => 'return:2',
        ]);

        $payload = [
            'currency' => Product::CURRENCY_USD,
            'amount' => 60,
            'amount_base' => 60,
            'amount_local' => 0,
            'operation_key' => 'payment:1',
        ];
        $first = $service->apply($customer, $user, $payload);
        $second = $service->apply($customer, $user, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, CustomerCreditTransaction::query()->count());
        $this->assertSame(40.0, $service->availableBase($customer));
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant Credit Atomic', 'slug' => 'tenant-credit-atomic']);
        app(TenantManager::class)->set($tenant);

        $customer = Customer::create([
            'name' => 'Cliente Crédito',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => 'V-100',
            'is_generic' => false,
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return [$customer, $user];
    }
}
