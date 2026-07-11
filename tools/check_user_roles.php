<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
$user = \App\Models\User::query()->first();
echo "User: {$user->email} (id={$user->id})\n";
echo "Tenant: {$tenant->slug} (id={$tenant->id})\n\n";

echo "--- Roles del user (todos los tenants) ---\n";
$roles = \DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', \App\Models\User::class)->get();
foreach ($roles as $r) {
    $role = \DB::table('roles')->where('id', $r->role_id)->first();
    echo "  role_id={$r->role_id}  name={$role->name}  tenant_id={$r->tenant_id}\n";
}

echo "\n--- Permisos del user (sample) ---\n";
$perms = \DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', \App\Models\User::class)->limit(10)->get();
foreach ($perms as $p) {
    echo "  perm_id={$p->permission_id}  tenant_id={$p->tenant_id}\n";
}
$permCount = \DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', \App\Models\User::class)->where('tenant_id', $tenant->id)->count();
echo "Total permisos del user para tenant {$tenant->id}: {$permCount}\n";

echo "\n--- Permisos disponibles 'inventory_transfers.*' ---\n";
$permsInv = \DB::table('permissions')->where('name', 'like', 'inventory_transfers.%')->get();
foreach ($permsInv as $p) {
    echo "  {$p->id}: {$p->name}\n";
}
