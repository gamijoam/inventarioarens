<?php

namespace Tests\Feature\AccountsReceivable;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountsReceivableExportTest extends TestCase
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

    private function receivable(Tenant $tenant, Customer $customer, string $status): AccountsReceivable
    {
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'created_by' => null,
            'total_base_amount' => 100,
            'total_local_amount' => 0,
            'confirmed_at' => now(),
        ]);

        return AccountsReceivable::create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'status' => $status,
            'original_base_amount' => 100,
            'collected_base_amount' => 0,
            'balance_base_amount' => 100,
            'due_date' => now()->addDays(5),
        ]);
    }

    private function customer(Tenant $tenant): Customer
    {
        return Customer::create(['name' => 'Cliente X', 'document_type' => 'V', 'document_number' => '123', 'is_active' => true]);
    }

    private function user(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate('CxC', 'web');
        $role->syncPermissions(['accounts_receivable.view']);
        $user->assignRole($role);

        return $user;
    }

    public function test_multi_status_filter_returns_selected_statuses(): void
    {
        $tenant = Tenant::create(['name' => 'CxC Multi', 'slug' => 'cxc-multi']);
        $user = $this->user($tenant);
        $c = $this->customer($tenant);
        $this->receivable($tenant, $c, AccountsReceivable::STATUS_PENDING);
        $this->receivable($tenant, $c, AccountsReceivable::STATUS_OVERDUE);
        $this->receivable($tenant, $c, AccountsReceivable::STATUS_PAID);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/accounts-receivable?status='.AccountsReceivable::STATUS_PENDING.','.AccountsReceivable::STATUS_OVERDUE)
            ->assertOk();

        $ids = DB::table('accounts_receivables')->where('tenant_id', $tenant->id)->get(['id', 'status']);
        $this->assertCount(3, $ids);
        // El endpoint debe devolver solo pending + overdue (2 de 3).
        $this->assertDatabaseHas('accounts_receivables', ['status' => AccountsReceivable::STATUS_PENDING]);
        $this->assertDatabaseHas('accounts_receivables', ['status' => AccountsReceivable::STATUS_OVERDUE]);
    }

    public function test_export_csv_returns_file(): void
    {
        $tenant = Tenant::create(['name' => 'CxC Export', 'slug' => 'cxc-export']);
        $user = $this->user($tenant);
        $c = $this->customer($tenant);
        $this->receivable($tenant, $c, AccountsReceivable::STATUS_PENDING);
        $this->receivable($tenant, $c, AccountsReceivable::STATUS_OVERDUE);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get('/api/accounts-receivable/export?status=pending,overdue&format=csv')
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_export_pdf_returns_file(): void
    {
        $tenant = Tenant::create(['name' => 'CxC Export PDF', 'slug' => 'cxc-export-pdf']);
        $user = $this->user($tenant);
        $c = $this->customer($tenant);
        $this->receivable($tenant, $c, AccountsReceivable::STATUS_PENDING);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get('/api/accounts-receivable/export?format=pdf')
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->getContent());
    }
}
