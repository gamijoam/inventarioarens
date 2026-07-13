<?php

namespace Tests\Unit\AccessControl;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScopeResolverUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_no_scope_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $resolver = app(ScopeResolver::class);

        $this->assertNull($resolver->branchIdsFor($user));
        $this->assertNull($resolver->warehouseIdsFor($user));
        $this->assertNull($resolver->customerGroupIdsFor($user));
        $this->assertNull($resolver->vendorOfGroupIdsFor($user));
    }

    public function test_returns_array_when_branches_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $b1 = \App\Modules\Branches\Models\Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        $b2 = \App\Modules\Branches\Models\Branch::create(['name' => 'B2', 'code' => 'B2', 'tenant_id' => $tenant->id]);

        DB::table('user_branch_scopes')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b1->id, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b2->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $this->assertSame([$b1->id, $b2->id], $resolver->branchIdsFor($user));
    }

    public function test_returns_array_when_warehouses_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $b = \App\Modules\Branches\Models\Branch::create(['name' => 'B', 'code' => 'B', 'tenant_id' => $tenant->id]);
        $w = \App\Modules\Warehouses\Models\Warehouse::create(['branch_id' => $b->id, 'name' => 'W', 'code' => 'W', 'tenant_id' => $tenant->id]);

        DB::table('user_warehouse_scopes')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'warehouse_id' => $w->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $this->assertSame([$w->id], $resolver->warehouseIdsFor($user));
    }

    public function test_returns_array_when_customer_groups_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $cg = \App\Modules\Customers\Models\CustomerGroup::create([
            'tenant_id' => $tenant->id, 'code' => 'CG1', 'name' => 'Retail',
        ]);

        DB::table('user_customer_group_scopes')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'customer_group_id' => $cg->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $this->assertSame([$cg->id], $resolver->customerGroupIdsFor($user));
    }

    public function test_returns_array_when_vendor_of_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $cg = \App\Modules\Customers\Models\CustomerGroup::create([
            'tenant_id' => $tenant->id, 'code' => 'CG-VEND', 'name' => 'Vendor Group',
        ]);

        DB::table('user_vendor_assignments')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'customer_group_id' => $cg->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $this->assertSame([$cg->id], $resolver->vendorOfGroupIdsFor($user));
    }

    public function test_status_for_returns_none_when_unset(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);

        $resolver = app(ScopeResolver::class);
        $this->assertSame(ScopeResolver::SCOPE_NONE, $resolver->statusFor($user));
    }

    public function test_status_for_returns_restrict_when_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $b = \App\Modules\Branches\Models\Branch::create(['name' => 'B', 'code' => 'B', 'tenant_id' => $tenant->id]);

        DB::table('user_branch_scopes')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $this->assertSame(ScopeResolver::SCOPE_RESTRICT, $resolver->statusFor($user));
    }

    public function test_apply_scope_returns_query_intact_when_no_scope(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);

        $resolver = app(ScopeResolver::class);

        // Crear un query real y verificar que NO se le agrega whereIn.
        $query = \App\Modules\Branches\Models\Branch::query();
        $result = $resolver->applyBranchScope($query, $user, 'id');

        // Sin scope: el query no se modifico, retorna la misma instancia.
        $this->assertSame($query, $result);
    }

    public function test_apply_scope_adds_where_in_when_assigned(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $b = \App\Modules\Branches\Models\Branch::create(['name' => 'B', 'code' => 'B', 'tenant_id' => $tenant->id]);

        DB::table('user_branch_scopes')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $query = \App\Modules\Branches\Models\Branch::query();
        $result = $resolver->applyBranchScope($query, $user, 'id');

        // Con scope: el query tiene un whereIn. Verifico que el SQL lo incluye.
        $sql = $result->toSql();
        $this->assertStringContainsString('"id" in', $sql);
    }

    public function test_replace_scope_is_idempotent(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUserWithTenant($tenant);
        $b1 = \App\Modules\Branches\Models\Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        $b2 = \App\Modules\Branches\Models\Branch::create(['name' => 'B2', 'code' => 'B2', 'tenant_id' => $tenant->id]);

        DB::table('user_branch_scopes')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b1->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolver = app(ScopeResolver::class);
        $resolver->replaceScope($user, \App\Modules\AccessControl\Models\UserBranchScope::class, 'branch_id', [$b2->id], $user);

        $this->assertSame([$b2->id], $resolver->branchIdsFor($user));
    }

    private function createTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-' . \Illuminate\Support\Str::random(6),
            'status' => 'active',
        ]);
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        return $tenant;
    }

    private function createUserWithTenant(Tenant $tenant): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        return $user;
    }
}