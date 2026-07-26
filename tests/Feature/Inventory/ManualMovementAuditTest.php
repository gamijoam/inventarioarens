<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use App\Modules\Branches\Models\Branch;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Products\Models\Product;
use App\Models\User;

class ManualMovementAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Tenant Audit',
            'slug' => 'tenant-audit',
        ]);

        app(TenantManager::class)->set($tenant);
    }

    public function test_approved_movement_stores_approval_audit_data(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Audit', 'sku' => 'AUD-001']);

        $user = User::factory()->create();

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'internal_consumption',
            'reason' => 'Auditoria',
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $this->assertNotNull($movement->approved_at);
        $this->assertNotNull($movement->approved_by);
    }

    public function test_rejected_movement_stores_rejection_audit_data(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Audit', 'sku' => 'AUD-002']);

        $user = User::factory()->create();

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'loss',
            'reason' => 'Perdida',
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => 'No autorizado',
        ]);

        $this->assertSame('No autorizado', $movement->rejection_reason);
        $this->assertNotNull($movement->rejected_at);
    }
}
