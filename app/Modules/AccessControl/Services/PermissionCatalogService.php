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
        'finance_reports' => 'Reportes Financieros',
        'financial_adjustments' => 'Ajustes Financieros',
        'inventory' => 'Inventario',
        'inventory_transfer_requests' => 'Solicitudes de Traslado',
        'inventory_transfers' => 'Traslados',
        'kardex' => 'Kardex',
        'payment_methods' => 'Metodos de Pago',
        'payment_receipts' => 'Recibos de Pago',
        'pos' => 'Punto de Venta',
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
            $verb = $parts[1] ?? null;

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
                'label' => self::VERB_LABELS[$verb] ?? ucfirst($verb),
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
}