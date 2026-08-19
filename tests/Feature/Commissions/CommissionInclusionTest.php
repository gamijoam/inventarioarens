<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPlan;
use App\Modules\Commissions\Models\CommissionPlanAssignment;
use App\Modules\Commissions\Services\CommissionLedgerService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommissionInclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_default_to_include_combos_and_discounts(): void
    {
        [$tenant] = $this->fixture();

        $plan = CommissionPlan::create([
            'name' => 'Default',
            'beneficiary_role' => CommissionPlan::ROLE_SELLER,
            'percentage' => 10,
            'conversion_policy' => CommissionPlan::CONVERSION_SALE_SNAPSHOT,
            'credit_policy' => CommissionPlan::CREDIT_SALE_CONFIRMATION,
            'is_active' => true,
        ]);

        $this->assertTrue((bool) $plan->include_combos);
        $this->assertTrue((bool) $plan->include_discounts);
    }

    public function test_plan_commissions_discounted_line_by_default(): void
    {
        [$tenant] = $this->fixture();
        $plan = $this->plan($tenant, true, true);
        [$seller, $order] = $this->seedPaidOrder($tenant, $plan, [
            'discount_base_amount' => 20,
        ]);

        $this->record($order);

        $entry = $this->entryFor($order, $plan, $seller);
        $this->assertNotNull($entry);
        $this->assertSame(8.0, (float) $entry->commission_base_amount);
    }

    public function test_plan_skips_discounted_line_when_include_discounts_false(): void
    {
        [$tenant] = $this->fixture();
        $plan = $this->plan($tenant, true, false);
        [$seller, $order] = $this->seedPaidOrder($tenant, $plan, [
            'discount_base_amount' => 20,
        ]);

        $this->record($order);

        $this->assertNull($this->entryFor($order, $plan, $seller));
    }

    public function test_plan_skips_combo_line_when_include_combos_false(): void
    {
        [$tenant] = $this->fixture();
        $plan = $this->plan($tenant, false, true);
        [$seller, $order] = $this->seedPaidOrder($tenant, $plan, [], true);

        $this->record($order);

        $this->assertNull($this->entryFor($order, $plan, $seller));
    }

    public function test_plan_still_commissions_normal_line_when_both_disabled(): void
    {
        [$tenant] = $this->fixture();
        $plan = $this->plan($tenant, false, false);
        [$seller, $order] = $this->seedPaidOrder($tenant, $plan, []);

        $this->record($order);

        $entry = $this->entryFor($order, $plan, $seller);
        $this->assertNotNull($entry);
        $this->assertSame(10.0, (float) $entry->commission_base_amount);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Comisiones', 'slug' => 'comisiones']);
        $this->useTenant($tenant);

        return [$tenant];
    }

    private function plan(Tenant $tenant, bool $includeCombos, bool $includeDiscounts): CommissionPlan
    {
        $this->useTenant($tenant);

        return CommissionPlan::create([
            'name' => 'Plan '.uniqid(),
            'beneficiary_role' => CommissionPlan::ROLE_SELLER,
            'percentage' => 10,
            'conversion_policy' => CommissionPlan::CONVERSION_SALE_SNAPSHOT,
            'credit_policy' => CommissionPlan::CREDIT_SALE_CONFIRMATION,
            'include_combos' => $includeCombos,
            'include_discounts' => $includeDiscounts,
            'is_active' => true,
        ]);
    }

    private function seedPaidOrder(Tenant $tenant, CommissionPlan $plan, array $itemOverrides, bool $combo = false): array
    {
        $this->useTenant($tenant);

        $seller = User::factory()->create();
        $seller->tenants()->attach($tenant, ['status' => 'active']);

        CommissionPlanAssignment::create([
            'commission_plan_id' => $plan->id,
            'user_id' => $seller->id,
            'is_active' => true,
        ]);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-1']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-1']);
        $product = Product::create([
            'name' => 'Producto',
            'sku' => 'SKU-'.uniqid(),
            'tracking_type' => 'quantity',
            'base_price' => 100,
            'sale_currency' => 'USD',
        ]);

        $promotionId = null;
        if ($combo) {
            $promotionId = Promotion::create([
                'name' => 'Combo Test',
                'benefit_type' => Promotion::BENEFIT_FIXED_BUNDLE_PRICE,
                'scope' => Promotion::SCOPE_COMBO,
                'price_currency' => 'USD',
                'is_active' => true,
            ])->id;
        }

        $baseTotal = 100.0;
        $discountBase = (float) ($itemOverrides['discount_base_amount'] ?? 0);
        $netBase = round($baseTotal - $discountBase, 4);

        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $netBase,
            'total_local_amount' => 0,
            'created_by' => $seller->id,
            'confirmed_at' => now(),
        ]);

        $order = PosOrder::create([
            'sale_id' => $sale->id,
            'status' => PosOrder::STATUS_PAID,
            'seller_id' => $seller->id,
            'cashier_id' => $seller->id,
            'paid_base_amount' => $netBase,
            'paid_local_amount' => 0,
            'paid_at' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => $netBase,
            'total_amount' => $netBase,
            'base_unit_price' => 100,
            'base_total_amount' => $netBase,
            'discount_amount' => (float) ($itemOverrides['discount_amount'] ?? $discountBase),
            'discount_base_amount' => $discountBase,
            'promotion_id' => $promotionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$seller, $order];
    }

    private function record(PosOrder $order): void
    {
        $this->useTenant($order->tenant);
        app(CommissionLedgerService::class)->recordPaidOrder($order);
    }

    private function entryFor(PosOrder $order, CommissionPlan $plan, User $seller): ?CommissionEntry
    {
        return CommissionEntry::query()
            ->where('commission_plan_id', $plan->id)
            ->where('pos_order_id', $order->id)
            ->where('beneficiary_user_id', $seller->id)
            ->where('entry_type', CommissionEntry::TYPE_EARNING)
            ->first();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
