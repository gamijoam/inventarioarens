<?php

namespace Tests\Feature\TelegramBot;

use App\Models\User;
use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\TelegramBot\Services\TelegramBotService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TelegramBotTest extends TestCase
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

    public function test_chat_not_in_whitelist_is_ignored(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);

        $service = app(TelegramBotService::class);

        $this->assertNull($service->resolveFromChatId('999999999'));
        $this->assertNull($service->resolveFromChatId(''));
    }

    public function test_inactive_chat_is_ignored(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $this->linkChat($tenant, null, '111111111', 'Juan', false);

        $this->assertNull(app(TelegramBotService::class)->resolveFromChatId('111111111'));
    }

    public function test_admin_of_spinoff_only_sees_its_company(): void
    {
        $group = Tenant::create(['name' => 'Grupo', 'slug' => 'grupo', 'is_group' => true]);
        $spinoff = Tenant::create(['name' => 'Hija', 'slug' => 'hija', 'parent_id' => $group->id, 'is_group' => false]);
        $admin = $this->userWithRole($spinoff, 'Admin', ['products.view']);

        $entry = $this->linkChat($spinoff, $admin->id, '111222333', 'Admin Hija');

        $accessible = app(TelegramBotService::class)->accessibleTenants($entry);

        $this->assertCount(1, $accessible);
        $this->assertSame($spinoff->id, $accessible->first()->id);
    }

    public function test_owner_of_group_sees_group_and_all_spinoffs(): void
    {
        $group = Tenant::create(['name' => 'Grupo', 'slug' => 'grupo', 'is_group' => true]);
        $spinoff1 = Tenant::create(['name' => 'Hija 1', 'slug' => 'hija-1', 'parent_id' => $group->id, 'is_group' => false]);
        $spinoff2 = Tenant::create(['name' => 'Hija 2', 'slug' => 'hija-2', 'parent_id' => $group->id, 'is_group' => false]);
        $owner = $this->userWithRole($group, 'Owner', ['products.view', 'settings.manage']);

        $entry = $this->linkChat($group, $owner->id, '222333444', 'Jefe');

        $accessible = app(TelegramBotService::class)->accessibleTenants($entry);

        $ids = $accessible->pluck('id')->all();
        $this->assertContains($group->id, $ids);
        $this->assertContains($spinoff1->id, $ids);
        $this->assertContains($spinoff2->id, $ids);
        $this->assertCount(3, $ids);
    }

    public function test_platform_admin_sees_all_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b']);
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $entry = $this->linkChat($tenantA, $admin->id, '444555666', 'Master');

        $accessible = app(TelegramBotService::class)->accessibleTenants($entry);

        $ids = $accessible->pluck('id')->all();
        $this->assertContains($tenantA->id, $ids);
        $this->assertContains($tenantB->id, $ids);
    }

    public function test_whitelist_without_user_id_only_sees_its_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b']);
        $entry = $this->linkChat($tenantA, null, '555666777', 'Solo A');

        $accessible = app(TelegramBotService::class)->accessibleTenants($entry);

        $this->assertCount(1, $accessible);
        $this->assertSame($tenantA->id, $accessible->first()->id);
    }

    private function linkChat(Tenant $tenant, ?int $userId, string $chatId, string $name, bool $active = true): TelegramBotUser
    {
        return TelegramBotUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $userId,
            'telegram_chat_id' => $chatId,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function userWithRole(Tenant $tenant, string $roleName, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::create([
            'name' => $roleName,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(
            Permission::query()->whereIn('name', $permissions)->get()
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
