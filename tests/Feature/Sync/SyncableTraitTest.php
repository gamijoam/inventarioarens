<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Sync\Syncable;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests del trait Syncable: emision automatica de eventos de sync por el
 * ciclo de vida del modelo (created/updated/deleted) sin llamadas manuales.
 */
class SyncableTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSyncableDummyTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('syncable_dummies');
        parent::tearDown();
    }

    private function createSyncableDummyTable(): void
    {
        Schema::create('syncable_dummies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    private function tenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Syncable Tenant', 'slug' => 'syncable-tenant']);
        app(TenantManager::class)->set($tenant);

        return $tenant;
    }

    private function bindOutboxSpy(): array
    {
        $spy = new class
        {
            public array $calls = [];

            public function __call(string $name, array $arguments): void
            {
                $this->calls[] = ['method' => $name, 'args' => $arguments];
            }
        };

        $this->app->instance(SyncCatalogOutboxService::class, $spy);

        return [$spy, []];
    }

    private function callsFor(string $method): array
    {
        $instance = app(SyncCatalogOutboxService::class);

        return array_values(array_filter(
            (array) ($instance->calls ?? []),
            fn (array $call): bool => $call['method'] === $method
        ));
    }

    public function test_created_emits_event_automatically(): void
    {
        $this->tenant();
        $this->bindOutboxSpy();

        SyncableDummy::create(['name' => 'Primero']);

        $this->assertCount(1, $this->callsFor('syncableDummyCreated'));
    }

    public function test_updated_emits_event_automatically(): void
    {
        $this->tenant();
        $this->bindOutboxSpy();
        $dummy = SyncableDummy::create(['name' => 'Uno']);

        $dummy->update(['name' => 'Dos']);

        $this->assertCount(1, $this->callsFor('syncableDummyUpdated'));
    }

    public function test_deleted_emits_event_automatically(): void
    {
        $this->tenant();
        $this->bindOutboxSpy();
        $dummy = SyncableDummy::create(['name' => 'Uno']);

        $dummy->delete();

        $this->assertCount(1, $this->callsFor('syncableDummyDeleted'));
    }

    public function test_suspended_blocks_emission(): void
    {
        $this->tenant();
        $this->bindOutboxSpy();

        SyncableDummy::syncableSuspended(function (): void {
            SyncableDummy::create(['name' => 'Silencioso']);
        });

        $this->assertCount(0, $this->callsFor('syncableDummyCreated'));
    }

    public function test_suspended_depth_nests_correctly(): void
    {
        $this->tenant();
        $this->bindOutboxSpy();

        SyncableDummy::syncableSuspended(function (): void {
            SyncableDummy::create(['name' => 'Nivel 1']);
            SyncableDummy::syncableSuspended(function (): void {
                SyncableDummy::create(['name' => 'Nivel 2']);
            });
        });
        SyncableDummy::create(['name' => 'Activo']);

        $this->assertCount(1, $this->callsFor('syncableDummyCreated'));
    }

    public function test_no_method_means_no_emission(): void
    {
        $this->tenant();
        $this->bindOutboxSpy();

        // El modelo no define syncOutboxMethod para 'restored' -> null (no emite).
        $dummy = new SyncableDummy;
        $this->assertNull($dummy->syncOutboxMethod('restored'));
        $this->assertCount(0, $this->callsFor('syncableDummyUnknown'));
    }
}

class SyncableDummy extends Model
{
    use Syncable;

    protected $table = 'syncable_dummies';

    protected $fillable = ['tenant_id', 'name'];

    public function syncOutboxMethod(string $action): ?string
    {
        return match ($action) {
            'created' => 'syncableDummyCreated',
            'updated' => 'syncableDummyUpdated',
            'deleted' => 'syncableDummyDeleted',
            default => null,
        };
    }
}
