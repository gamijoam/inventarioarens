<?php

namespace Tests\Feature\PaymentReceipts;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Customers\Models\Customer;
use App\Modules\PaymentReceipts\Models\PaymentReceipt;
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

class PaymentReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_collection_creates_payment_receipt_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente A', ['payment_receipts.view', 'accounts_receivable.collect']);
        $account = $this->receivableAccount($tenant, $user, 120);

        $payment = app(AccountsReceivableService::class)->registerPayment($account, $user, [
            'payment_currency' => Product::CURRENCY_USD,
            'amount' => 40,
            'method' => 'transferencia',
            'reference' => 'COBRO-REC-001',
        ]);

        $this->assertDatabaseHas('payment_receipts', [
            'tenant_id' => $tenant->id,
            'receipt_number' => 'REC-000001',
            'type' => PaymentReceipt::TYPE_CUSTOMER_COLLECTION,
            'status' => PaymentReceipt::STATUS_ISSUED,
            'source_type' => $payment::class,
            'source_id' => $payment->id,
            'accounts_receivable_payment_id' => $payment->id,
            'party_type' => 'customer',
            'party_name' => 'Cliente REC',
            'payment_currency' => Product::CURRENCY_USD,
            'amount_base' => '40.0000',
            'method' => 'transferencia',
            'reference' => 'COBRO-REC-001',
        ]);

        $receipt = PaymentReceipt::query()->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/payment-receipts/{$receipt->id}")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', 'REC-000001')
            ->assertJsonPath('data.party_name', 'Cliente REC');
    }

    public function test_supplier_payment_creates_payment_receipt_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente A', ['payment_receipts.view', 'accounts_payable.pay']);
        $account = $this->payableAccount($tenant, $user, 80);

        $payment = app(AccountsPayableService::class)->registerPayment($account, $user, [
            'payment_currency' => PurchaseOrder::CURRENCY_USD,
            'amount' => 25,
            'method' => 'transferencia',
            'reference' => 'PAGO-REC-001',
        ]);

        $this->assertDatabaseHas('payment_receipts', [
            'tenant_id' => $tenant->id,
            'receipt_number' => 'REC-000001',
            'type' => PaymentReceipt::TYPE_SUPPLIER_PAYMENT,
            'source_type' => $payment::class,
            'source_id' => $payment->id,
            'accounts_payable_payment_id' => $payment->id,
            'party_type' => 'supplier',
            'party_name' => 'Proveedor REC',
            'amount_base' => '25.0000',
        ]);
    }

    public function test_void_receipt_does_not_reverse_original_payment_or_account(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente A', [
            'payment_receipts.view',
            'payment_receipts.void',
            'accounts_receivable.collect',
        ]);
        $account = $this->receivableAccount($tenant, $user, 100);

        $payment = app(AccountsReceivableService::class)->registerPayment($account, $user, [
            'payment_currency' => Product::CURRENCY_USD,
            'amount' => 30,
        ]);
        $receipt = PaymentReceipt::query()->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/payment-receipts/{$receipt->id}/void", [
                'reason' => 'Error de impresion del comprobante.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PaymentReceipt::STATUS_VOIDED)
            ->assertJsonPath('data.void_reason', 'Error de impresion del comprobante.');

        $this->assertDatabaseHas('accounts_receivable_payments', [
            'tenant_id' => $tenant->id,
            'id' => $payment->id,
            'amount_base' => '30.0000',
        ]);
        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'collected_base_amount' => '30.0000',
            'balance_base_amount' => '70.0000',
        ]);
    }

    public function test_payment_receipts_do_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Gerente A', ['payment_receipts.view', 'accounts_receivable.collect']);
        $this->grantRole($tenantB, $userB, 'Gerente B', ['payment_receipts.view', 'accounts_receivable.collect']);
        $accountA = $this->receivableAccount($tenantA, $userA, 50);
        $accountB = $this->receivableAccount($tenantB, $userB, 50);

        $this->useTenant($tenantA);
        app(AccountsReceivableService::class)->registerPayment($accountA, $userA, [
            'payment_currency' => Product::CURRENCY_USD,
            'amount' => 10,
            'reference' => 'COBRO-A',
        ]);
        $this->useTenant($tenantB);
        app(AccountsReceivableService::class)->registerPayment($accountB, $userB, [
            'payment_currency' => Product::CURRENCY_USD,
            'amount' => 15,
            'reference' => 'COBRO-B',
        ]);

        $foreignReceipt = PaymentReceipt::withoutGlobalScopes()
            ->where('tenant_id', $tenantB->id)
            ->firstOrFail();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/payment-receipts')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'COBRO-A')
            ->assertJsonMissing(['reference' => 'COBRO-B']);

        $response = $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/payment-receipts/{$foreignReceipt->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_payment_receipt_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $creator = $this->userInTenant($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $creator, 'Cobrador', ['accounts_receivable.collect']);
        $account = $this->receivableAccount($tenant, $creator, 50);

        app(AccountsReceivableService::class)->registerPayment($account, $creator, [
            'payment_currency' => Product::CURRENCY_USD,
            'amount' => 10,
        ]);

        $receipt = PaymentReceipt::query()->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/payment-receipts')
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/payment-receipts/{$receipt->id}/void", [
                'reason' => 'Sin permiso.',
            ])
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
            'name' => 'Cliente REC',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => "V-REC-{$tenant->id}",
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
            'document_number' => "VENTA-REC-{$tenant->id}",
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
            'name' => 'Proveedor REC',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => "J-REC-{$tenant->id}",
        ]);
        $purchase = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'document_number' => "COMPRA-REC-{$tenant->id}",
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
