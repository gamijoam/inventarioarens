<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPlan;
use App\Modules\Commissions\Models\CommissionPlanAssignment;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Workshop\Models\ServiceOrder;
use App\Modules\Workshop\Services\ServiceOrderService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato de la comision del tecnico del Taller (Fase 2):
 * al entregar una orden de servicio se registra comision sobre la mano de obra
 * para el tecnico asignado, segun el plan de comision con rol 'technician'.
 */
class TechnicianCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_plan_earns_commission_on_service_order_delivery(): void
    {
        [$tenant, $technician, $warehouse, $user] = $this->scaffold();
        $plan = $this->plan($tenant, $technician, 10);

        $order = $this->deliveredOrder($tenant, $technician, $warehouse, $user, labor: 100);

        $this->assertDatabaseHas('commission_entries', [
            'tenant_id' => $tenant->id,
            'commission_plan_id' => $plan->id,
            'service_order_id' => $order->id,
            'beneficiary_user_id' => $technician->id,
            'beneficiary_role' => CommissionPlan::ROLE_TECHNICIAN,
            'entry_type' => CommissionEntry::TYPE_EARNING,
            'commission_base_amount' => '10.0000',
            'status' => CommissionEntry::STATUS_PENDING,
        ]);
    }

    public function test_technician_without_active_assignment_gets_no_commission(): void
    {
        [$tenant, $technician, $warehouse, $user] = $this->scaffold();
        // Plan de rol technician pero SIN assignment para el tecnico.
        CommissionPlan::create([
            'name' => 'Comision Tecnico',
            'beneficiary_role' => CommissionPlan::ROLE_TECHNICIAN,
            'percentage' => 10,
            'conversion_policy' => CommissionPlan::CONVERSION_SALE_SNAPSHOT,
            'credit_policy' => CommissionPlan::CREDIT_SALE_CONFIRMATION,
            'maturation_days' => 0,
            'is_active' => true,
        ]);

        $order = $this->deliveredOrder($tenant, $technician, $warehouse, $user, labor: 100);

        $this->assertDatabaseMissing('commission_entries', [
            'tenant_id' => $tenant->id,
            'service_order_id' => $order->id,
        ]);
    }

    public function test_commission_base_is_labor_only_ignoring_parts(): void
    {
        [$tenant, $technician, $warehouse, $user] = $this->scaffold();
        $plan = $this->plan($tenant, $technician, 10);

        $product = Product::create([
            'name' => 'Pieza Tecnico',
            'sku' => 'PIEZA-TEC',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 50,
            'last_purchase_cost' => 20,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: 5,
            unitCost: 20,
            createdBy: $user,
            reason: 'Stock tecnico',
        );

        $order = $this->deliveredOrder($tenant, $technician, $warehouse, $user, labor: 100, parts: [[$product, 2]]);

        // Mano de obra 100 -> comision 10 (las piezas no suman a la base).
        $this->assertDatabaseHas('commission_entries', [
            'tenant_id' => $tenant->id,
            'service_order_id' => $order->id,
            'commission_base_amount' => '10.0000',
        ]);
    }

    public function test_inactive_plan_does_not_earn_commission(): void
    {
        [$tenant, $technician, $warehouse, $user] = $this->scaffold();
        $plan = $this->plan($tenant, $technician, 10);
        $plan->update(['is_active' => false]);

        $order = $this->deliveredOrder($tenant, $technician, $warehouse, $user, labor: 100);

        $this->assertDatabaseMissing('commission_entries', [
            'tenant_id' => $tenant->id,
            'service_order_id' => $order->id,
        ]);
    }

    public function test_order_without_technician_gets_no_commission(): void
    {
        [$tenant, $technician, $warehouse, $user] = $this->scaffold();
        $this->plan($tenant, $technician, 10);

        $order = $this->deliveredOrder($tenant, null, $warehouse, $user, labor: 100);

        $this->assertDatabaseMissing('commission_entries', [
            'tenant_id' => $tenant->id,
            'service_order_id' => $order->id,
        ]);
    }

    // ---- Helpers ----

    private function plan(Tenant $tenant, User $technician, float $percentage): CommissionPlan
    {
        $plan = CommissionPlan::create([
            'name' => 'Comision Tecnico',
            'beneficiary_role' => CommissionPlan::ROLE_TECHNICIAN,
            'percentage' => $percentage,
            'conversion_policy' => CommissionPlan::CONVERSION_SALE_SNAPSHOT,
            'credit_policy' => CommissionPlan::CREDIT_SALE_CONFIRMATION,
            'maturation_days' => 0,
            'is_active' => true,
        ]);
        CommissionPlanAssignment::create([
            'commission_plan_id' => $plan->id,
            'user_id' => $technician->id,
            'is_active' => true,
        ]);

        return $plan;
    }

    private function deliveredOrder(Tenant $tenant, ?User $technician, Warehouse $warehouse, User $user, float $labor, array $parts = []): ServiceOrder
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $service = app(ServiceOrderService::class);

        $order = $service->create($user, [
            'type' => ServiceOrder::TYPE_REPAIR,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'Cliente',
            'device_description' => 'Equipo',
        ]);

        $service->diagnose($order, $user, [
            'diagnosis' => 'Diagnostico',
            'labor_base_amount' => $labor,
        ]);

        if ($technician) {
            $service->assignTechnician($order, $user, [
                'technician_id' => $technician->id,
                'warehouse_id' => $warehouse->id,
            ]);
        }

        foreach ($parts as [$product, $quantity]) {
            $service->addPart($order, $user, [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return $service->complete($order, $user);
    }

    private function scaffold(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Taller', 'slug' => 'empresa-taller-com']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal Taller', 'code' => 'BR-TALLER']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Taller', 'code' => 'WH-TALLER']);
        $technician = User::factory()->create();
        $technician->tenants()->attach($tenant, ['status' => 'active']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return [$tenant, $technician, $warehouse, $user];
    }
}
