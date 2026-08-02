<?php

namespace App\Modules\AccessControl\Services;

use App\Support\Permissions\BasePermissions;

class PermissionCatalogService
{
    /**
     * Mapa estatico de verbos canonicos a su label en espanol.
     * Usado por la UI para traducir sales.create -> "Crear".
     */
    private const VERB_LABELS = [
        'view' => 'Ver',
        'create' => 'Crear',
        'update' => 'Actualizar',
        'delete' => 'Eliminar',
        'manage' => 'Gestionar',
        'approve' => 'Aprobar',
        'cancel' => 'Cancelar',
        'void' => 'Anular',
        'pay' => 'Pagar',
        'collect' => 'Cobrar',
        'open' => 'Abrir',
        'close' => 'Cerrar',
        'move' => 'Mover',
        'checkout' => 'Procesar venta',
        'prepare' => 'Preparar',
        'offer' => 'Proponer envío',
        'dispatch' => 'Despachar',
        'receive' => 'Recibir',
        'resolve' => 'Resolver',
        'deliver' => 'Entregar',
        'review' => 'Revisar',
        'adjust' => 'Ajustar',
        'transfer' => 'Transferir',
        'configure' => 'Configurar',
        'attach' => 'Asociar',
        'detach' => 'Desasociar',
        'respond' => 'Responder',
        'issue' => 'Emitir',
        'upload' => 'Subir',
        'export' => 'Exportar',
        'use' => 'Usar',
    ];

    private const SUBJECT_LABELS = [
        'products' => 'productos',
        'branches' => 'sucursales',
        'warehouses' => 'almacenes',
        'customers' => 'clientes',
        'suppliers' => 'proveedores',
        'currency' => 'moneda y tasas',
        'inventory' => 'inventario',
        'product_entries' => 'entradas de producto',
        'product_exits' => 'salidas de producto',
        'inventory_transfers' => 'traslados',
        'inventory_transfer_requests' => 'solicitudes de traslado',
        'purchases' => 'compras',
        'purchase_returns' => 'devoluciones de compra',
        'accounts_payable' => 'cuentas por pagar',
        'accounts_receivable' => 'cuentas por cobrar',
        'payment_receipts' => 'recibos de pago',
        'payment_methods' => 'métodos de pago',
        'financial_adjustments' => 'ajustes financieros',
        'finance_reports' => 'reportes financieros',
        'sales' => 'ventas',
        'sales_returns' => 'devoluciones de venta',
        'pos' => 'POS',
        'printing' => 'impresión',
        'cash_register' => 'caja',
        'reports' => 'reportes',
        'kardex' => 'Kardex',
        'warranty_policies' => 'políticas de garantía',
        'warranties' => 'garantías',
        'users' => 'usuarios',
        'roles' => 'roles',
        'tenants' => 'empresas',
        'settings' => 'configuración',
        'sync' => 'sincronización',
        'ai' => 'inteligencia artificial',
        'data_import' => 'importación de datos',
        'finance' => 'costos',
    ];

    private const NESTED_SUBJECT_LABELS = [
        'image' => 'imágenes de productos',
        'manual_movements' => 'movimientos manuales',
        'payment_requests' => 'solicitudes de pago',
        'sales' => 'ventas detalladas',
        'cash' => 'caja y POS',
        'inventory' => 'inventario',
        'movements' => 'movimientos',
        'costs' => 'costos',
        'master' => 'administración maestra',
        'group' => 'organizaciones',
        'users' => 'usuarios',
    ];

    /**
     * Mapa de modulos con label legible en espanol.
     */
    private const MODULE_LABELS = [
        'accounts_payable' => 'Cuentas por Pagar',
        'accounts_receivable' => 'Cuentas por Cobrar',
        'ai' => 'Inteligencia Artificial',
        'branches' => 'Sucursales',
        'cash_register' => 'Caja',
        'currency' => 'Moneda y Tasas',
        'customers' => 'Clientes',
        'data_import' => 'Importacion de Datos',
        'finance' => 'Costos',
        'finance_reports' => 'Reportes Financieros',
        'financial_adjustments' => 'Ajustes Financieros',
        'inventory' => 'Inventario',
        'inventory_transfer_requests' => 'Solicitudes de Traslado',
        'inventory_transfers' => 'Traslados',
        'kardex' => 'Kardex',
        'payment_methods' => 'Metodos de Pago',
        'payment_receipts' => 'Recibos de Pago',
        'pos' => 'Punto de Venta',
        'printing' => 'Impresion',
        'product_entries' => 'Entradas de Producto',
        'product_exits' => 'Salidas de Producto',
        'products' => 'Productos',
        'purchase_returns' => 'Devoluciones de Compra',
        'purchases' => 'Compras',
        'reports' => 'Reportes',
        'roles' => 'Perfiles y Roles',
        'sales' => 'Ventas',
        'sales_returns' => 'Devoluciones de Venta',
        'settings' => 'Configuracion',
        'suppliers' => 'Proveedores',
        'sync' => 'Sincronizacion',
        'tenants' => 'Empresas',
        'users' => 'Usuarios',
        'warehouses' => 'Almacenes',
        'warranties' => 'Garantias',
        'warranty_policies' => 'Politicas de Garantia',
    ];

    /**
     * Devuelve el catalogo completo en formato ARBOL navegable.
     */
    public function tree(): array
    {
        $modules = [];

        foreach (BasePermissions::PERMISSIONS as $permission) {
            $parts = explode('.', $permission);
            $module = $parts[0];
            $verb = $parts[count($parts) - 1] ?? null;

            if ($verb === null) {
                continue;
            }

            if (! isset($modules[$module])) {
                $modules[$module] = [
                    'module' => $module,
                    'label' => self::MODULE_LABELS[$module] ?? ucfirst(str_replace('_', ' ', $module)),
                    'actions' => [],
                ];
            }

            $action = [
                'verb' => $verb,
                'label' => $this->actionLabel($permission, $module, $verb, $parts),
                'permission' => $permission,
            ];

            // Acciones marcadas como "danger" (operaciones destructivas)
            if (in_array($verb, ['delete', 'cancel', 'void', 'detach'], true)) {
                $action['danger'] = 'high';
            }

            $modules[$module]['actions'][] = $action;
        }

        // Ordenar modulos y actions por nombre
        usort($modules, fn ($a, $b) => strcmp($a['label'], $b['label']));
        foreach ($modules as &$module) {
            usort($module['actions'], fn ($a, $b) => strcmp($a['verb'], $b['verb']));
            $module['verb_count'] = count($module['actions']);
        }

        $verbCatalog = [];
        foreach (self::VERB_LABELS as $name => $label) {
            $verbCatalog[] = ['name' => $name, 'label' => $label];
        }

        return [
            'modules' => array_values($modules),
            'verbs' => $verbCatalog,
            'total_permissions' => count(BasePermissions::PERMISSIONS),
            'total_modules' => count($modules),
        ];
    }

    /**
     * Genera una etiqueta comprensible incluso para permisos jerárquicos nuevos.
     */
    private function actionLabel(string $permission, string $module, string $verb, array $parts): string
    {
        $verbLabel = self::VERB_LABELS[$verb] ?? ucfirst(str_replace('_', ' ', $verb));
        $subject = count($parts) > 2
            ? (self::NESTED_SUBJECT_LABELS[implode('_', array_slice($parts, 1, -1))]
                ?? ucfirst(str_replace('_', ' ', implode(' ', array_slice($parts, 1, -1)))))
            : (self::SUBJECT_LABELS[$module] ?? ucfirst(str_replace('_', ' ', $module)));

        if ($permission === 'pos.checkout') {
            return 'Cobrar en POS';
        }

        if ($permission === 'inventory_transfer_requests.offer') {
            return 'Proponer envío';
        }

        return $verbLabel.' '.$subject;
    }
}
