<?php

namespace Tests\Feature\AccountsPayable;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountsPayableExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function payable(Tenant $tenant, Supplier $supplier, string $status): AccountsPayable
    {
        $po = PurchaseOrder::create(['status' => 'confirmed', 'document_number' => 'PO-'.uniqid(), 'total_base_amount' => 100, 'total_local_amount' => 0]);

        return AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'status' => $status,
            'original_base_amount' => 100,
            'paid_base_amount' => 0,
            'balance_base_amount' => 100,
            'due_date' => now()->addDays(5),
        ]);
    }

    private function user(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate('CxP', 'web');
        $role->syncPermissions(['accounts_payable.view']);
        $user->assignRole($role);

        return $user;
    }

    public function test_multi_status_filter_and_csv_export(): void
    {
        $tenant = Tenant::create(['name' => 'CxP Multi', 'slug' => 'cxp-multi']);
        $user = $this->user($tenant);
        $supplier = Supplier::create(['name' => 'Proveedor X', 'document_type' => 'J', 'document_number' => 'J-123', 'is_active' => true]);
        $this->payable($tenant, $supplier, AccountsPayable::STATUS_PENDING);
        $this->payable($tenant, $supplier, AccountsPayable::STATUS_OVERDUE);
        $this->payable($tenant, $supplier, AccountsPayable::STATUS_PAID);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/accounts-payable?status='.AccountsPayable::STATUS_PENDING.','.AccountsPayable::STATUS_OVERDUE)
            ->assertOk();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get('/api/accounts-payable/export?status=pending,overdue&format=csv')
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }
}
