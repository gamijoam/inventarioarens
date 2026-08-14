<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
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

class CommissionSettlementApiTest extends TestCase
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

    public function test_only_available_entries_can_be_approved_and_the_approval_is_audited(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenant);
        $manager = $this->user($tenant, 'manager@example.test', ['commissions.approve']);
        $seller = $this->user($tenant, 'seller@example.test', ['commissions.view_own']);
        $item = $this->saleItem($tenant, $manager);
        $available = $this->entry($item, $seller, 12, CommissionEntry::STATUS_AVAILABLE);
        $pending = $this->entry($item, $seller, 4, CommissionEntry::STATUS_PENDING, now()->addDay());

        $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commissions/approve', ['entry_ids' => [$pending->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_ids');

        $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commissions/approve', ['entry_ids' => [$available->id]])
            ->assertOk()
            ->assertJsonPath('data.0.status', CommissionEntry::STATUS_APPROVED);

        $available->refresh();
        $this->assertSame(CommissionEntry::STATUS_APPROVED, $available->status);
        $this->assertSame($manager->id, $available->approved_by);
        $this->assertNotNull($available->approved_at);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'commission_entries.approved',
        ]);
    }

    public function test_approved_entries_are_paid_once_in_usd_and_grouped_by_beneficiary(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenant);
        $manager = $this->user($tenant, 'manager@example.test', ['commissions.settle']);
        $seller = $this->user($tenant, 'seller@example.test', ['commissions.view_own']);
        $other = $this->user($tenant, 'other@example.test', ['commissions.view_own']);
        $item = $this->saleItem($tenant, $manager);
        $earning = $this->entry($item, $seller, 12, CommissionEntry::STATUS_APPROVED);
        $reversal = $this->entry($item, $seller, -2, CommissionEntry::STATUS_APPROVED, now(), 'reversal');
        $foreignBeneficiary = $this->entry($item, $other, 5, CommissionEntry::STATUS_APPROVED);

        $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commission-settlements', [
                'entry_ids' => [$earning->id, $foreignBeneficiary->id],
                'payment_currency' => 'USD',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_ids');

        $response = $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commission-settlements', [
                'entry_ids' => [$earning->id, $reversal->id],
                'payment_currency' => 'USD',
                'reference' => 'PAGO-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_currency', 'USD')
            ->assertJsonPath('data.total_base_amount', '10.0000')
            ->assertJsonPath('data.payment_amount', '10.0000');

        $settlementId = $response->json('data.id');
        $this->assertDatabaseHas('commission_settlements', [
            'id' => $settlementId,
            'tenant_id' => $tenant->id,
            'beneficiary_user_id' => $seller->id,
            'status' => 'paid',
        ]);
        $itemCount = (int) \DB::table('commission_settlement_items')->where('commission_settlement_id', $settlementId)->count();
        $paidCount = CommissionEntry::query()->whereIn('id', [$earning->id, $reversal->id])->where('status', CommissionEntry::STATUS_PAID)->count();
        $this->assertSame(2, $itemCount, 'Settlement items: '.json_encode(\DB::table('commission_settlement_items')->get()));
        $this->assertSame(2, $paidCount, 'Entry statuses: '.json_encode(\DB::table('commission_entries')->whereIn('id', [$earning->id, $reversal->id])->get()));

        $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commission-settlements', [
                'entry_ids' => [$earning->id],
                'payment_currency' => 'USD',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_ids');
    }

    public function test_ves_payment_freezes_the_selected_rate_and_both_currency_amounts(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenant);
        $manager = $this->user($tenant, 'manager@example.test', ['commissions.settle']);
        $seller = $this->user($tenant, 'seller@example.test', ['commissions.view_own']);
        $entry = $this->entry($this->saleItem($tenant, $manager), $seller, 10, CommissionEntry::STATUS_APPROVED);
        [$type, $rate] = $this->rate($tenant, 'BCV', 60);

        $response = $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commission-settlements', [
                'entry_ids' => [$entry->id],
                'payment_currency' => 'VES',
                'exchange_rate_type_id' => $type->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_base_amount', '10.0000')
            ->assertJsonPath('data.total_local_amount', '600.0000')
            ->assertJsonPath('data.payment_amount', '600.0000')
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.exchange_rate', '60.000000');

        $rate->update(['rate' => 75]);
        $this->assertDatabaseHas('commission_settlements', [
            'id' => $response->json('data.id'),
            'exchange_rate' => 60,
            'total_local_amount' => 600,
        ]);
    }

    public function test_manual_adjustment_is_append_only_requires_a_reason_and_is_exported_safely(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenant);
        $manager = $this->user($tenant, 'manager@example.test', ['commissions.adjust', 'commissions.view_all']);
        $seller = $this->user($tenant, '=seller@example.test', ['commissions.view_own']);

        $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commissions/adjustments', [
                'beneficiary_user_id' => $seller->id,
                'amount_base' => 3.5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $response = $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commissions/adjustments', [
                'beneficiary_user_id' => $seller->id,
                'amount_base' => -3.5,
                'reason' => 'Correccion por diferencia verificada',
            ])
            ->assertCreated()
            ->assertJsonPath('data.entry_type', CommissionEntry::TYPE_ADJUSTMENT)
            ->assertJsonPath('data.commission_base_amount', '-3.5000')
            ->assertJsonPath('data.adjustment_reason', 'Correccion por diferencia verificada');

        $this->assertDatabaseHas('commission_entries', [
            'id' => $response->json('data.id'),
            'sale_id' => null,
            'sale_item_id' => null,
            'created_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'commission_entry.created',
        ]);

        $csv = $this->actingAs($manager)->withHeader('X-Tenant', $tenant->slug)
            ->get('/api/commissions/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Correccion por diferencia verificada', $csv);
        $this->assertStringContainsString("'=seller@example.test", $csv);
    }

    public function test_entries_from_another_tenant_cannot_be_approved_or_paid(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        app(TenantManager::class)->set($tenantA);
        $manager = $this->user($tenantA, 'manager@example.test', ['commissions.approve', 'commissions.settle']);

        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        app(TenantManager::class)->set($tenantB);
        $sellerB = $this->user($tenantB, 'seller-b@example.test', ['commissions.view_own']);
        $entryB = $this->entry($this->saleItem($tenantB, $sellerB), $sellerB, 20, CommissionEntry::STATUS_AVAILABLE);

        app(TenantManager::class)->set($tenantA);
        $this->actingAs($manager)->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/commissions/approve', ['entry_ids' => [$entryB->id]])
            ->assertUnprocessable();
        $this->actingAs($manager)->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/commission-settlements', ['entry_ids' => [$entryB->id], 'payment_currency' => 'USD'])
            ->assertUnprocessable();

        app(TenantManager::class)->set($tenantB);
        $this->assertSame(CommissionEntry::STATUS_AVAILABLE, $entryB->fresh()->status);
    }

    private function user(Tenant $tenant, string $email, array $permissions): User
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create(['email' => $email]);
        $tenant->users()->attach($user->id, ['status' => 'active']);
        $role = Role::findOrCreate('Role '.Str::uuid(), 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function saleItem(Tenant $tenant, User $creator): SaleItem
    {
        app(TenantManager::class)->set($tenant);
        $branch = Branch::create(['code' => 'BR-'.Str::random(6), 'name' => 'Principal', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.Str::random(6), 'name' => 'Almacen', 'status' => 'active']);
        $product = Product::create(['sku' => 'SKU-'.Str::random(6), 'name' => 'Producto', 'sale_currency' => 'USD', 'sale_price' => 100]);
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

    private function entry(SaleItem $item, User $beneficiary, float $amount, string $status, $availableAt = null, string $type = 'earning'): CommissionEntry
    {
        return CommissionEntry::create([
            'entry_uuid' => (string) Str::uuid(),
            'sale_id' => $item->sale_id,
            'sale_item_id' => $item->id,
            'beneficiary_user_id' => $beneficiary->id,
            'beneficiary_role' => 'seller',
            'entry_type' => $type,
            'plan_name_snapshot' => 'Plan historico',
            'percentage_snapshot' => abs($amount),
            'sale_currency' => 'USD',
            'source_amount' => 100,
            'eligible_base_amount' => 100,
            'commission_base_amount' => $amount,
            'status' => $status,
            'earned_at' => now(),
            'available_at' => $availableAt ?? now(),
        ]);
    }

    private function rate(Tenant $tenant, string $code, float $value): array
    {
        app(TenantManager::class)->set($tenant);
        $type = ExchangeRateType::create(['code' => $code, 'name' => "Tasa {$code}", 'is_default' => true, 'is_active' => true]);
        $rate = ExchangeRate::create([
            'exchange_rate_type_id' => $type->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => $value,
            'effective_at' => now(),
            'is_active' => true,
            'source' => 'manual',
        ]);

        return [$type, $rate];
    }
}
