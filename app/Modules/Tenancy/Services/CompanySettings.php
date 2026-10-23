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
        'logo_url' => null,
        'allow_negative_stock' => false,
        'pos_allow_rate_edit' => false,
        'pos_allow_catalog_view' => true,
        'pos_catalog_stock_edit' => false,
        'catalog_allow_stock_edit' => false,
        'pos_default_view_mode' => 'classic',
        'show_on' => [
            'sale_ticket' => true,
            'guide' => true,
            'report_z' => true,
            'quotation' => true,
        ],
        'warranty_certificate' => [
            'enabled' => true,
            'title' => 'CERTIFICADO DE GARANTÍA OFICIAL',
            'covers' => "• Defectos de fabricación\n• Fallas técnicas internas\n• Componentes defectuosos\n• Mal funcionamiento del equipo en condiciones normales",
            'not_covers' => "• Mal uso, descuido o negligencia\n• Caídas, golpes o impactos\n• Contacto con líquidos o humedad\n• Modificaciones no autorizadas o sellos violados",
            'claim_steps' => "1. Presente este certificado original\n2. Junto al comprobante de pago\n3. El equipo debe estar completo con empaque y accesorios\n4. Acuda a nuestra sede en horario de atención",
            'important_notes' => "• Conserve este documento en buen estado\n• Sin certificado la garantía no aplica\n• Garantía personal e intransferible\n• Válida solo en el local de compra",
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

    /**
     * Indica si la empresa permite facturar/vender sin stock (stock negativo).
     */
    public static function allowsNegativeStock(Tenant $tenant): bool
    {
        $settings = self::getForTenant($tenant);

        return (bool) ($settings['allow_negative_stock'] ?? false);
    }

    /**
     * Indica si el POS permite cambiar la tasa de cambio desde la barra
     * superior (modal de tasa).
     */
    public static function allowsPosRateEdit(Tenant $tenant): bool
    {
        $settings = self::getForTenant($tenant);

        return (bool) ($settings['pos_allow_rate_edit'] ?? false);
    }

    /**
     * Indica si el POS ofrece la vista de catalogo visual (ademas de la
     * vista ticket).</p>
     */
    public static function allowsPosCatalogView(Tenant $tenant): bool
    {
        $settings = self::getForTenant($tenant);

        return (bool) ($settings['pos_allow_catalog_view'] ?? true);
    }

    /**
     * Indica si se permite editar/ajustar el stock de productos directamente
     * desde la vista catalogo y el formulario rapido de creacion.
     */
    public static function allowsCatalogStockEdit(Tenant $tenant): bool
    {
        $settings = self::getForTenant($tenant);

        return (bool) ($settings['catalog_allow_stock_edit'] ?? $settings['pos_catalog_stock_edit'] ?? false);
    }

    public static function allowsPosCatalogStockEdit(Tenant $tenant): bool
    {
        return self::allowsCatalogStockEdit($tenant);
    }

    /**
     * Indica el modo de vista principal del POS configurado por defecto.
     * Retorna 'classic', 'catalog' o 'ticket'.
     */
    public static function posDefaultViewMode(Tenant $tenant): string
    {
        $settings = self::getForTenant($tenant);
        $mode = $settings['pos_default_view_mode'] ?? 'classic';

        return in_array($mode, ['classic', 'catalog', 'ticket'], true) ? $mode : 'classic';
    }

    /**
     * Indica si el certificado de garantia para impresora estandar esta habilitado.
     */
    public static function isWarrantyCertificateEnabled(Tenant $tenant): bool
    {
        $settings = self::getForTenant($tenant);

        return (bool) ($settings['warranty_certificate']['enabled'] ?? true);
    }

    /**
     * Obtiene la configuracion del certificado de garantia.
     */
    public static function warrantyCertificateSettings(Tenant $tenant): array
    {
        $settings = self::getForTenant($tenant);

        return array_replace_recursive(self::DEFAULTS['warranty_certificate'], $settings['warranty_certificate'] ?? []);
    }
}
