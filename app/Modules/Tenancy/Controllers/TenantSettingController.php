<?php

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuracion por empresa (tenant). La fila de settings se crea
 * automaticamente al registrar el tenant.
 *
 * Solo el Owner del grupo o el Admin de la empresa pueden modificar su
 * configuracion. El acceso se controla por el tenant resuelto en el
 * middleware `tenant`.
 */
class TenantSettingController extends Controller
{
    public function __construct(private readonly SyncCatalogOutboxService $syncCatalog) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = app(TenantManager::class)->require();
        $this->authorizeManage($request, $tenant);

        $setting = $tenant->setting
            ?: TenantSetting::firstOrCreate(['tenant_id' => $tenant->id]);

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'settings' => $this->mergeCompanyInto($tenant, $this->mergeWhitelistInto($tenant, $setting->settings ?? [])),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = app(TenantManager::class)->require();
        $this->authorizeManage($request, $tenant);

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.telegram' => ['sometimes', 'array'],
            'settings.company' => ['sometimes', 'array'],
            'settings.company.razon_social' => ['nullable', 'string', 'max:255'],
            'settings.company.rif' => ['nullable', 'string', 'max:30'],
            'settings.company.domicilio_fiscal' => ['nullable', 'string', 'max:500'],
            'settings.company.ciudad' => ['nullable', 'string', 'max:120'],
            'settings.company.estado' => ['nullable', 'string', 'max:120'],
            'settings.company.telefono' => ['nullable', 'string', 'max:40'],
            'settings.company.correo' => ['nullable', 'email', 'max:150'],
            'settings.company.website' => ['nullable', 'string', 'max:150'],
            'settings.company.regimen' => ['nullable', 'string', 'max:80'],
            'settings.company.show_on' => ['sometimes', 'array'],
            'settings.company.show_on.sale_ticket' => ['sometimes', 'boolean'],
            'settings.company.show_on.guide' => ['sometimes', 'boolean'],
            'settings.company.show_on.report_z' => ['sometimes', 'boolean'],
            'settings.company.show_on.quotation' => ['sometimes', 'boolean'],
        ]);

        $setting = $tenant->setting
            ?: TenantSetting::firstOrCreate(['tenant_id' => $tenant->id]);

        $current = $setting->settings ?? [];
        $incoming = $data['settings'] ?? [];

        // La informacion de la empresa es legal/fiscal: solo administradores
        // (Owner/Admin con permiso settings.manage) pueden editarla.
        if (array_key_exists('company', $incoming) && ! $request->user()->can('settings.manage')) {
            abort(403, 'Solo el administrador de la empresa puede editar su informacion.');
        }

        // La whitelist de Telegram vive en la tabla telegram_bot_users (no en
        // el JSON) para lookups rapidos por chat_id. La sincronizamos y la
        // quitamos del JSON.
        $whitelist = $incoming['telegram']['whitelist'] ?? null;
        if (is_array($whitelist)) {
            $this->syncTelegramWhitelist($tenant, $whitelist);
            unset($incoming['telegram']['whitelist']);
            if ($incoming['telegram'] === []) {
                unset($incoming['telegram']);
            }
        }

        // Merge profundo por seccion para no pisar secciones que el frontend
        // no envia (solo actualiza la seccion 'telegram' o 'company').
        $merged = array_replace_recursive($current, $incoming);
        $setting->update(['settings' => $merged]);

        // Propaga la seccion company al resto de nodos (VPS -> local) para
        // que los tickets/guias locales reflejen la identidad de la empresa.
        $this->syncCatalog->tenantSettingsUpdated($tenant, $setting->fresh());

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'settings' => $this->mergeCompanyInto($tenant, $this->mergeWhitelistInto($tenant, $setting->fresh()->settings ?? [])),
            ],
        ]);
    }

    /**
     * Reemplaza la lista blanca del tenant en la tabla telegram_bot_users.
     */
    private function syncTelegramWhitelist(Tenant $tenant, array $whitelist): void
    {
        DB::table('telegram_bot_users')
            ->where('tenant_id', $tenant->id)
            ->delete();

        $now = now();
        foreach ($whitelist as $entry) {
            $chatId = trim((string) ($entry['telegram_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }

            DB::table('telegram_bot_users')->insert([
                'tenant_id' => $tenant->id,
                'telegram_chat_id' => $chatId,
                'name' => trim((string) ($entry['name'] ?? '')) ?: null,
                'user_id' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Expone la lista blanca actual en settings.telegram.whitelist para el panel.
     */
    private function mergeWhitelistInto(Tenant $tenant, array $settings): array
    {
        $rows = DB::table('telegram_bot_users')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get(['id', 'name', 'telegram_chat_id']);

        $settings['telegram']['whitelist'] = $rows->map(fn ($row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'telegram_id' => $row->telegram_chat_id,
        ])->values()->all();

        return $settings;
    }

    /**
     * Expone settings.company con defaults completos para el panel.
     */
    private function mergeCompanyInto(Tenant $tenant, array $settings): array
    {
        $settings['company'] = CompanySettings::getForTenant($tenant);

        return $settings;
    }

    /**
     * Valida que el usuario actual pueda administrar el tenant (Owner del
     * grupo o Admin/miembro con rol Owner del tenant actual).
     */
    private function authorizeManage(Request $request, Tenant $tenant): void
    {
        $user = $request->user();

        if (! $user) {
            throw ValidationException::withMessages(['auth' => 'No autenticado.']);
        }

        $canManage = $user->tenants()
            ->whereKey($tenant->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $canManage) {
            abort(403, 'No tienes acceso a la configuracion de esta empresa.');
        }
    }
}
