<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\AlertHistory;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\AlertHistoryService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bootstrap(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'name' => 'A', 'email' => 'a@t.test', 'password' => bcrypt('secret'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        return [$tenant, $user];
    }

    public function test_record_creates_alert(): void
    {
        [$tenant] = $this->bootstrap();
        $service = app(AlertHistoryService::class);
        $alert = $service->record(
            alertType: 'product.low_stock',
            title: 'Stock bajo',
            message: 'Producto X bajo de minimo',
            subjectType: 'product',
            subjectId: 42,
            severity: AlertHistory::SEVERITY_WARNING,
        );

        $this->assertNotNull($alert);
        $this->assertSame('product.low_stock', $alert->alert_type);
        $this->assertFalse($alert->isDismissed());
    }

    public function test_record_dedupes_within_24h(): void
    {
        [$tenant] = $this->bootstrap();
        $service = app(AlertHistoryService::class);
        $first = $service->record('low_stock', 'T', 'M', 'product', 1);
        $second = $service->record('low_stock', 'T', 'M', 'product', 1);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_snapshot_alerts_detects_low_stock(): void
    {
        [$tenant] = $this->bootstrap();
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id, 'name' => 'W1', 'code' => 'W1',
        ]);

        $p = Product::create([
            'name' => 'P', 'sku' => 'P-1',
            'tracking_type' => 'quantity',
            'min_stock' => 10,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $p->id,
            'quantity_available' => 3,
        ]);

        $count = app(AlertHistoryService::class)->snapshotAlerts($tenant->id);
        $this->assertSame(1, $count);

        $alert = AlertHistory::where('subject_type', 'product')
            ->where('subject_id', $p->id)
            ->first();
        $this->assertNotNull($alert);
        $this->assertSame('product.low_stock', $alert->alert_type);
    }

    public function test_dismiss_marks_as_dismissed(): void
    {
        [$tenant, $user] = $this->bootstrap();
        $service = app(AlertHistoryService::class);
        $alert = $service->record('low_stock', 'T', 'M', 'product', 1);

        $alert->update(['dismissed_at' => now(), 'dismissed_by' => $user->id]);
        $alert->refresh();
        $this->assertTrue($alert->isDismissed());
    }
}
