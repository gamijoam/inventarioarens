<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Informacion legal/fiscal de la empresa (razon social, RIF, domicilio,
 * contacto) almacenada en `tenant_settings.settings.company` y flags de
 * visibilidad por documento (`show_on.sale_ticket|guide|report_z`).
 *
 * Los valores se leen con defaults tipados para que la impresion y el
 * frontend siempre tengan el shape completo.
 */
class CompanySettings
{
    public const DEFAULTS = [
        'razon_social' => null,
        'rif' => null,
        'domicilio_fiscal' => null,
        'ciudad' => null,
        'estado' => null,
        'telefono' => null,
        'correo' => null,
        'website' => null,
        'regimen' => null,
        'show_on' => [
            'sale_ticket' => true,
            'guide' => true,
            'report_z' => true,
        ],
    ];

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Lee la configuracion de la empresa con defaults aplicados.
     */
    public static function getForTenant(Tenant $tenant): array
    {
        $stored = DB::table('tenant_settings')
            ->where('tenant_id', $tenant->id)
            ->value('settings');

        $company = is_string($stored)
            ? (json_decode($stored, true)['company'] ?? [])
            : [];

        return array_replace_recursive(self::DEFAULTS, $company);
    }

    /**
     * Indica si la informacion de la empresa debe mostrarse en un documento.
     */
    public static function shouldShowFor(Tenant $tenant, string $document): bool
    {
        $settings = self::getForTenant($tenant);

        return (bool) ($settings['show_on'][$document] ?? false);
    }
}
