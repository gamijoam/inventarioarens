<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommissionEntryApiTest extends TestCase
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

    public function test_user_only_sees_own_commissions_and_mature_entries_become_available(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenant);
        $seller = $this->user($tenant, 'seller@example.test', ['commissions.view_own']);
        $other = $this->user($tenant, 'other@example.test', ['commissions.view_own']);
        $saleItem = $this->saleItem($tenant, $seller);
        $mine = $this->entry($saleItem, $seller, 3, now()->subDay());
        $this->entry($saleItem, $other, 2, now()->addDay());

        $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/commissions/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.status', CommissionEntry::STATUS_AVAILABLE)
            ->assertJsonPath('summary.total_base_amount', '3.0000')
            ->assertJsonPath('summary.available_base_amount', '3.0000');
    }

    public function test_manager_with_view_all_sees_every_beneficiary_but_other_tenant_never_leaks(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenantA);
        $manager = $this->user($tenantA, 'manager@example.test', ['commissions.view_all']);
        $seller = $this->user($tenantA, 'seller@example.test', ['commissions.view_own']);
        $this->entry($this->saleItem($tenantA, $manager), $seller, 5, now());

        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        app(TenantManager::class)->set($tenantB);
        $foreign = $this->user($tenantB, 'foreign@example.test', ['commissions.view_own']);
        $this->entry($this->saleItem($tenantB, $foreign), $foreign, 99, now());

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/commissions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.beneficiary.email', 'seller@example.test')
            ->assertJsonPath('summary.total_base_amount', '5.0000');
    }

    private function user(Tenant $tenant, string $email, array $permissions): User
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create(['email' => $email]);
        $tenant->users()->attach($user->id, ['status' => 'active']);
        $role = Role::findOrCreate('Role '.$email, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function saleItem(Tenant $tenant, User $creator): SaleItem
    {
        app(TenantManager::class)->set($tenant);
        $branch = Branch::create(['code' => 'BR-'.$tenant->id, 'name' => 'Principal', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.$tenant->id, 'name' => 'Almacen', 'status' => 'active']);
        $product = Product::create(['sku' => 'SKU-'.$tenant->id, 'name' => 'Producto', 'sale_currency' => 'USD', 'sale_price' => 100]);
        $sale = Sale::create(['status' => Sale::STATUS_CONFIRMED, 'created_by' => $creator->id, 'total_base_amount' => 100, 'total_local_amount' => 0, 'confirmed_at' => now()]);

        return SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => 100,
            'total_amount' => 100,
            'base_unit_price' => 100,
            'base_total_amount' => 100,
        ]);
    }

    private function entry(SaleItem $item, User $beneficiary, float $amount, $availableAt): CommissionEntry
    {
        return CommissionEntry::create([
            'entry_uuid' => (string) Str::uuid(),
            'sale_id' => $item->sale_id,
            'sale_item_id' => $item->id,
            'beneficiary_user_id' => $beneficiary->id,
            'beneficiary_role' => 'seller',
            'entry_type' => 'earning',
            'plan_name_snapshot' => 'Plan historico',
            'percentage_snapshot' => $amount,
            'sale_currency' => 'USD',
            'source_amount' => 100,
            'eligible_base_amount' => 100,
            'commission_base_amount' => $amount,
            'status' => CommissionEntry::STATUS_PENDING,
            'earned_at' => now(),
            'available_at' => $availableAt,
        ]);
    }
}
