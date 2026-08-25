<?php

namespace App\Support\Capabilities;

class BaseCapabilities
{
    public const REQUIRED = [
        'dashboard',
        'catalog',
        'inventory',
        'customers',
        'suppliers',
    ];

    public const DEFAULT_NEW = self::REQUIRED;

    public const ALL = [
        'dashboard',
        'catalog',
        'inventory',
        'customers',
        'suppliers',
        'sales',
        'purchases',
        'pos',
        'cash_register',
        'finance',
        'reports',
        'promotions',
        'commissions',
        'warranties',
        'workshop',
        'intercompany',
        'inventory_transfers',
        'data_import',
        'quotations',
        'printing',
        'telegram',
        'offline_sync',
    ];

    public static function definitions(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'description' => 'Resumen operativo de la empresa.'],
            ['key' => 'catalog', 'label' => 'Catalogo', 'description' => 'Productos, precios y clasificacion.'],
            ['key' => 'inventory', 'label' => 'Inventario', 'description' => 'Stock, almacenes, movimientos y conteos.'],
            ['key' => 'customers', 'label' => 'Clientes', 'description' => 'Clientes y grupos de clientes.'],
            ['key' => 'suppliers', 'label' => 'Proveedores', 'description' => 'Proveedores y datos de compras.'],
            ['key' => 'sales', 'label' => 'Ventas', 'description' => 'Ventas administrativas y devoluciones.'],
            ['key' => 'purchases', 'label' => 'Compras', 'description' => 'Compras, recepciones y devoluciones.'],
            ['key' => 'pos', 'label' => 'POS', 'description' => 'Venta de mostrador y checkout.'],
            ['key' => 'cash_register', 'label' => 'Caja', 'description' => 'Cajas, sesiones y movimientos de efectivo.'],
            ['key' => 'finance', 'label' => 'Finanzas', 'description' => 'CxC, CxP, recibos y ajustes financieros.'],
            ['key' => 'reports', 'label' => 'Reportes', 'description' => 'Reportes operativos y gerenciales.'],
            ['key' => 'promotions', 'label' => 'Promociones', 'description' => 'Promociones y reglas comerciales.'],
            ['key' => 'commissions', 'label' => 'Comisiones', 'description' => 'Control y liquidacion de comisiones.'],
            ['key' => 'warranties', 'label' => 'Garantias', 'description' => 'Politicas y reclamos de garantia.'],
            ['key' => 'workshop', 'label' => 'Taller', 'description' => 'Ordenes de servicio y reparaciones.'],
            ['key' => 'intercompany', 'label' => 'Interempresa', 'description' => 'Solicitudes y catalogo entre empresas.'],
            ['key' => 'inventory_transfers', 'label' => 'Traslados', 'description' => 'Traslados entre almacenes.'],
            ['key' => 'data_import', 'label' => 'Importacion', 'description' => 'Importaciones masivas de datos.'],
            ['key' => 'quotations', 'label' => 'Cotizaciones', 'description' => 'Cotizaciones y conversion a venta.'],
            ['key' => 'printing', 'label' => 'Impresion', 'description' => 'Tickets, perfiles y estaciones de impresion.'],
            ['key' => 'telegram', 'label' => 'Telegram', 'description' => 'Bot y alertas operativas.'],
            ['key' => 'offline_sync', 'label' => 'Sync local', 'description' => 'Operacion local y sincronizacion nube.'],
        ];
    }

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::ALL, true);
    }
}
