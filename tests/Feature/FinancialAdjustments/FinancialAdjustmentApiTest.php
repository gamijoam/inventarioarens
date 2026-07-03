<?php

namespace Tests\Feature\FinancialAdjustments;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Customers\Models\Customer;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\Products\Models\Product;
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

class FinancialAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_apply_customer_credit_adjustment_to_receivable_account(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente A', ['financial_adjustments.create', 'financial_adjustments.view']);
        $account = $this->receivableAccount($tenant, $user, 100);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/financial-adjustments', [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $account->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 15,
                'reason' => 'Descuento posterior a la venta',
                'notes' => 'Ajuste autorizado por gerencia.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'AJF-000001')
            ->assertJsonPath('data.amount_base', '15.0000');

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsReceivable::STATUS_PARTIAL,
            'adjusted_base_amount' => '15.0000',
            'balance_base_amount' => '85.0000',
        ]);
    }

    public function test_user_can_apply_supplier_credit_adjustment_to_payable_account(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente A', ['financial_adjustments.create', 'financial_adjustments.view']);
        $account = $this->payableAccount($tenant, $user, 80);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/financial-adjustments', [
                'account_type' => FinancialAdjustment::ACCOUNT_PAYABLE,
                'account_id' => $account->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 20,
                'reason' => 'Nota de credito del proveedor',
            ])
            ->assertCreated()
            ->assertJsonPath('data.account_type', FinancialAdjustment::ACCOUNT_PAYABLE);

        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsPayable::STATUS_PARTIAL,
            'adjusted_base_amount' => '20.0000',
            'balance_base_amount' => '60.0000',
        ]);
    }

    public function test_financial_adjustment_cannot_exceed_pending_balance(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente A', ['financial_adjustments.create']);
        $account = $this->receivableAccount($tenant, $user, 30);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/financial-adjustments', [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $account->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 31,
                'reason' => 'Ajuste invalido',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_financial_adjustments_do_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Gerente A', ['financial_adjustments.create', 'financial_adjustments.view']);
        $this->grantRole($tenantB, $userB, 'Gerente B', ['financial_adjustments.create', 'financial_adjustments.view']);
        $accountA = $this->receivableAccount($tenantA, $userA, 50);
        $accountB = $this->receivableAccount($tenantB, $userB, 50);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/financial-adjustments', [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $accountA->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 5,
                'reason' => 'Ajuste Empresa A',
            ])
            ->assertCreated();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/financial-adjustments', [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $accountB->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 7,
                'reason' => 'Ajuste Empresa B',
            ])
            ->assertCreated();

        $foreignAdjustment = FinancialAdjustment::withoutGlobalScopes()
            ->where('tenant_id', $tenantB->id)
            ->firstOrFail();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/financial-adjustments')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'Ajuste Empresa A')
            ->assertJsonMissing(['reason' => 'Ajuste Empresa B']);

        $response = $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/financial-adjustments/{$foreignAdjustment->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_financial_adjustment_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $creator = $this->userInTenant($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $creator, 'Gerente A', ['financial_adjustments.create']);
        $account = $this->receivableAccount($tenant, $creator, 50);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/financial-adjustments', [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $account->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 5,
                'reason' => 'Sin permiso',
            ])
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/financial-adjustments')
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

    private function receivableAccount(Tenant $tenant, User $user, float $amount): AccountsReceivable
    {
        $this->useTenant($tenant);

        $customer = Customer::create([
            'name' => "Cliente AJF {$tenant->id}",
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => "V-AJF-{$tenant->id}",
        ]);
        $sale = Sale::create([
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $amount,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        return AccountsReceivable::create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'status' => AccountsReceivable::STATUS_PENDING,
            'document_number' => "VENTA-AJF-{$tenant->id}",
            'currency' => Product::CURRENCY_USD,
            'original_base_amount' => $amount,
            'original_local_amount' => 0,
            'balance_base_amount' => $amount,
            'balance_local_amount' => 0,
            'opened_at' => now(),
        ]);
    }

    private function payableAccount(Tenant $tenant, User $user, float $amount): AccountsPayable
    {
        $this->useTenant($tenant);

        $supplier = Supplier::create([
            'name' => "Proveedor AJF {$tenant->id}",
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => "J-AJF-{$tenant->id}",
        ]);
        $purchase = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'document_number' => "COMPRA-AJF-{$tenant->id}",
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => $amount,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'received_at' => now(),
        ]);

        return AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchase->id,
            'status' => AccountsPayable::STATUS_PENDING,
            'document_number' => $purchase->document_number,
            'currency' => PurchaseOrder::CURRENCY_USD,
            'original_base_amount' => $amount,
            'original_local_amount' => 0,
            'balance_base_amount' => $amount,
            'balance_local_amount' => 0,
            'opened_at' => now(),
        ]);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

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
