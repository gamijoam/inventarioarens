<?php

namespace App\Modules\Sync\Commands;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Sync\Models\SyncNode;
use App\Modules\Sync\Services\SyncTokenService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea (si no existe) todo lo necesario para sincronizar un tenant
 * y emite el token en un solo comando. Pensado para operadores
 * que no quieren ejecutar 4-5 comandos artisan a mano cada vez que
 * agregan un tenant al SaaS.
 *
 * Crea si no existe:
 *   - Tenant (slug + name).
 *   - User (gabo@gabo.com por default, is_platform_admin=true).
 *   - Pivot tenant_user con status=active.
 *   - Sync node del tipo local.
 *
 * Siempre:
 *   - Emite un nuevo sync token de 365 dias.
 *
 * Uso (en el VPS):
 *   php artisan sync:ensure-and-token grupo-prueba
 *   php artisan sync:ensure-and-token mi-empresa --user=admin@local --node-name=POS-01
 */
class EnsureAndTokenCommand extends Command
{
    protected $signature = 'sync:ensure-and-token
        {tenant : Slug del tenant}
        {--user=gabo@gabo.com : Email del usuario que autorizara la sincronizacion}
        {--node-name=Local-Node : Nombre visible del nodo local}
        {--node-code= : Codigo unico del nodo (default: basado en el host)}
        {--user-password= : Password del user (solo si se crea nuevo)}
        {--days=365 : Vigencia del token}';

    protected $description = 'Crea tenant/user/node (si no existen), los vincula, y emite un sync token en un solo comando. Ideal para setup inicial de un tenant.';

    public function handle(): int
    {
        $tenantSlug = Str::lower((string) $this->argument('tenant'));
        $userEmail = Str::lower((string) $this->option('user'));
        $nodeName = (string) $this->option('node-name');
        $days = max(1, (int) $this->option('days'));
        $nodeCodeOpt = $this->option('node-code');

        // 1. Tenant (crear si no existe).
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        $tenantCreated = false;
        if (! $tenant) {
            $name = Str::title(str_replace(['-', '_'], ' ', $tenantSlug));
            $tenant = Tenant::query()->create([
                'slug' => $tenantSlug,
                'name' => $name,
                'status' => 'active',
            ]);
            $tenantCreated = true;
            $this->info("[NEW] Tenant creado: {$tenant->slug} (id={$tenant->id}, name='{$tenant->name}')");
        } else {
            $this->line("[OK] Tenant existente: {$tenant->slug} (id={$tenant->id})");
        }

        // 2. User (crear si no existe).
        $user = User::query()->where('email', $userEmail)->first();
        $userCreated = false;
        if (! $user) {
            $password = (string) ($this->option('user-password') ?: Str::random(24));
            $user = User::query()->create([
                'email' => $userEmail,
                'name' => Str::title(Str::before($userEmail, '@')),
                'password' => Hash::make($password),
                'is_platform_admin' => true,
            ]);
            $userCreated = true;
            $this->info("[NEW] User creado: {$user->email} (id={$user->id}, password='{$password}')");
        } else {
            $this->line("[OK] User existente: {$user->email} (id={$user->id})");
        }

        // 3. Pivot tenant_user.
        $alreadyAttached = $tenant->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();
        if (! $alreadyAttached) {
            $tenant->users()->attach($user, ['status' => 'active']);
            $this->info("[NEW] Pivot tenant_user creado: tenant={$tenant->slug}, user={$user->email}, status=active");
        } else {
            $this->line("[OK] Pivot tenant_user existente");
        }

        // 4. Sync node (crear si no existe).
        $nodeCode = $nodeCodeOpt ?: $this->resolveNodeCode($tenantSlug);
        $node = SyncNode::query()
            ->where('tenant_id', $tenant->id)
            ->where('code', $nodeCode)
            ->first();
        $nodeCreated = false;
        if (! $node) {
            $node = SyncNode::query()->create([
                'tenant_id' => $tenant->id,
                'code' => $nodeCode,
                'name' => $nodeName,
                'type' => 'local',
                'status' => 'active',
                'metadata' => [
                    'created_via' => 'sync:ensure-and-token',
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            $nodeCreated = true;
            $this->info("[NEW] SyncNode creado: {$node->code} (id={$node->id}, type=local)");
        } else {
            $this->line("[OK] SyncNode existente: {$node->code} (id={$node->id})");
        }

        // 5. Rotar tokens existentes (idempotente + siempre devuelve plain fresh).
        // Si hay un token valido para (tenant, user, name), lo revocamos
        // (rotacion OAuth-style) y emitimos uno nuevo. Esto evita acumular
        // tokens viejos en la BD y siempre muestra el plain al user.
        $existing = AuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('name', $nodeName)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();
        foreach ($existing as $old) {
            $old->update(['revoked_at' => now()]);
        }
        if ($existing->count() > 0) {
            $this->info("[ROTATE] Revocados {$existing->count()} tokens anteriores para (tenant={$tenant->slug}, user={$user->email}, name={$nodeName})");
        }

        // 5b. Emitir token nuevo.
        $session = app(SyncTokenService::class)->issue(
            tenant: $tenant,
            user: $user,
            name: $nodeName,
            days: $days,
            ipAddress: 'cli',
            userAgent: 'sync:ensure-and-token',
        );
        $this->info("[NEW] Token emitido (vence en {$days} dias, id={$session['id']})");

        // 6. Output estructurado.
        $this->newLine();
        $this->line('========================================================');
        $this->line('SYNC TOKEN para tenant "' . $tenant->slug . '"');
        $this->line('========================================================');
        $this->line('  Tenant ID:    ' . $tenant->id);
        $this->line('  Tenant slug:  ' . $tenant->slug);
        $this->line('  User ID:      ' . $user->id);
        $this->line('  User email:   ' . $user->email);
        $this->line('  Node ID:      ' . $node->id);
        $this->line('  Node code:    ' . $node->code);
        $this->line('  Token ID:     ' . $session['id']);
        $this->newLine();
        $this->line('  TOKEN=' . $session['token']);
        $this->newLine();
        $this->warn('  Copia este token AHORA. No se vuelve a mostrar.');
        $this->line('  Para usar en el .env del local:');
        $this->line('    SYNC_CLOUD_URL=https://app.miinventariofacil.com/api');
        $this->line('    SYNC_CLOUD_TOKEN=' . $session['token']);
        $this->line('========================================================');

        return self::SUCCESS;
    }

    /**
     * Resuelve el codigo del node: usa --node-code si se paso,
     * sino genera uno deterministico basado en el slug.
     */
    private function resolveNodeCode(string $tenantSlug): string
    {
        $host = (string) (gethostname() ?: 'unknown');
        $hostSlug = Str::slug($host, '_');

        return strtoupper("LOCAL-{$tenantSlug}-{$hostSlug}");
    }
}