<?php

namespace Tests\Feature\FinanceReports;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Customers\Models\Customer;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_summary_reports_receivables_payables_cash_flow_and_net_balance(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->reportUser($tenant);
        $this->seedFinanceData($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/finance-reports/summary')
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.accounts_receivable.total_balance_base_amount', 150)
            ->assertJsonPath('data.accounts_receivable.partial_count', 1)
            ->assertJsonPath('data.accounts_receivable.overdue_count', 1)
            ->assertJsonPath('data.accounts_payable.total_balance_base_amount', 80)
            ->assertJsonPath('data.accounts_payable.partial_count', 1)
            ->assertJsonPath('data.accounts_payable.overdue_count', 1)
            ->assertJsonPath('data.cash_flow.collections_base_amount', 40)
            ->assertJsonPath('data.cash_flow.supplier_payments_base_amount', 25)
            ->assertJsonPath('data.net_balance_base_amount', 70);
    }

    public function test_finance_reports_filter_receivables_and_payables(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->reportUser($tenant);
        [$customer, $supplier] = $this->seedFinanceData($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/finance-reports/receivables?status=partial&customer_id={$customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Cliente Finanzas')
            ->assertJsonPath('data.0.balance_base_amount', '100.0000');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/finance-reports/payables?status=partial&supplier_id={$supplier->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.supplier_name', 'Proveedor Finanzas')
            ->assertJsonPath('data.0.balance_base_amount', '50.0000');
    }

    public function test_finance_reports_do_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->reportUser($tenantA);
        $this->seedFinanceData($tenantA);
        $this->seedFinanceData($tenantB, 999);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/finance-reports/summary')
            ->assertOk()
            ->assertJsonPath('data.accounts_receivable.total_balance_base_amount', 150)
            ->assertJsonPath('data.accounts_payable.total_balance_base_amount', 80);
    }

    public function test_finance_reports_require_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/finance-reports/summary')
            ->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function seedFinanceData(Tenant $tenant, int $offset = 0): array
    {
        $this->useTenant($tenant);

        $customer = Customer::create([
            'name' => 'Cliente Finanzas',
            'document_type' => 'V',
            'document_number' => (string) (1000 + $offset),
        ]);

        $supplier = Supplier::create([
            'name' => 'Proveedor Finanzas',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => (string) (2000 + $offset),
        ]);

        $saleA = Sale::create([
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 140,
            'confirmed_at' => now(),
        ]);

        $saleB = Sale::create([
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 50,
            'confirmed_at' => now(),
        ]);

        $purchaseA = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => 75,
            'received_at' => now(),
        ]);

        $purchaseB = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => 30,
            'received_at' => now(),
        ]);

        $receivable = AccountsReceivable::create([
            'customer_id' => $customer->id,
            'sale_id' => $saleA->id,
            'status' => AccountsReceivable::STATUS_PARTIAL,
            'document_number' => "CXCA-{$offset}",
            'original_base_amount' => 140,
            'collected_base_amount' => 40,
            'balance_base_amount' => 100,
            'opened_at' => now(),
        ]);

        AccountsReceivable::create([
            'customer_id' => $customer->id,
            'sale_id' => $saleB->id,
            'status' => AccountsReceivable::STATUS_OVERDUE,
            'document_number' => "CXCB-{$offset}",
            'original_base_amount' => 50,
            'balance_base_amount' => 50,
            'due_date' => now()->subDay(),
            'opened_at' => now(),
        ]);

        AccountsReceivablePayment::create([
            'accounts_receivable_id' => $receivable->id,
            'payment_currency' => 'USD',
            'amount' => 40,
            'amount_base' => 40,
            'amount_local' => 0,
            'paid_at' => now(),
        ]);

        $payable = AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseA->id,
            'status' => AccountsPayable::STATUS_PARTIAL,
            'document_number' => "CXPA-{$offset}",
            'original_base_amount' => 75,
            'paid_base_amount' => 25,
            'balance_base_amount' => 50,
            'opened_at' => now(),
        ]);

        AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseB->id,
            'status' => AccountsPayable::STATUS_OVERDUE,
            'document_number' => "CXPB-{$offset}",
            'original_base_amount' => 30,
            'balance_base_amount' => 30,
            'due_date' => now()->subDay(),
            'opened_at' => now(),
        ]);

        AccountsPayablePayment::create([
            'accounts_payable_id' => $payable->id,
            'payment_currency' => 'USD',
            'amount' => 25,
            'amount_base' => 25,
            'amount_local' => 0,
            'paid_at' => now(),
        ]);

        return [$customer, $supplier];
    }

    private function reportUser(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Finanzas', ['finance_reports.view']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
