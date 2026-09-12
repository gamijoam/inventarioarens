import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export interface SimpleModeSubItemConfig {
  to: string;
  label: string;
  description: string;
  defaultVisible: boolean;
}

export interface SimpleModeItemConfig {
  to: string;
  label: string;
  description: string;
  section: string;
  defaultVisible: boolean;
  children?: SimpleModeSubItemConfig[];
}

export const AVAILABLE_SIMPLE_MODE_ITEMS: SimpleModeItemConfig[] = [
  // ===== Operación =====
  {
    to: '/dashboard',
    label: 'Tablero',
    description: 'Métricas clave y resumen de ventas del día',
    section: 'Operación',
    defaultVisible: true,
  },
  {
    to: '/pos',
    label: 'Punto de Venta (POS)',
    description: 'Facturación rápida, cobro y apertura/cierre de caja',
    section: 'Operación',
    defaultVisible: true,
  },
  {
    to: '/cash-register',
    label: 'Cajas',
    description: 'Control de cajas chicas, movimientos y arqueos',
    section: 'Operación',
    defaultVisible: false,
  },

  // ===== Ventas =====
  {
    to: '/sales',
    label: 'Ventas',
    description: 'Historial detallado de órdenes, devoluciones y facturas',
    section: 'Ventas',
    defaultVisible: false,
  },
  {
    to: '/quotations',
    label: 'Cotizaciones',
    description: 'Emisión de presupuestos para clientes',
    section: 'Ventas',
    defaultVisible: false,
  },
  {
    to: '/sales-returns',
    label: 'Devoluciones',
    description: 'Devoluciones sobre ventas y notas de crédito',
    section: 'Ventas',
    defaultVisible: false,
  },
  {
    to: '/promotions',
    label: 'Promociones',
    description: 'Descuentos por volumen y promociones',
    section: 'Ventas',
    defaultVisible: false,
  },
  {
    to: '/commissions',
    label: 'Comisiones',
    description: 'Cálculo y pago de comisiones a vendedores',
    section: 'Ventas',
    defaultVisible: false,
  },
  {
    to: '/customers',
    label: 'Clientes',
    description: 'Directorio de clientes y límites de crédito',
    section: 'Ventas',
    defaultVisible: true,
  },

  // ===== Finanzas =====
  {
    to: '/receivables',
    label: 'Cuentas por Cobrar',
    description: 'Saldos pendientes de clientes y cobros',
    section: 'Finanzas',
    defaultVisible: false,
  },
  {
    to: '/payables',
    label: 'Cuentas por Pagar',
    description: 'Deudas con proveedores y programación de pagos',
    section: 'Finanzas',
    defaultVisible: false,
  },
  {
    to: '/payment-methods',
    label: 'Métodos de Pago',
    description: 'Configuración de bancos, pago móvil y monedas',
    section: 'Finanzas',
    defaultVisible: false,
  },
  {
    to: '/suppliers',
    label: 'Proveedores',
    description: 'Gestión y directorio de proveedores',
    section: 'Finanzas',
    defaultVisible: false,
  },

  // ===== Inventario =====
  {
    to: '/inventory',
    label: 'Inventario',
    description: 'Catálogo de repuestos, stock, precios, marcas y tasas',
    section: 'Inventario',
    defaultVisible: true,
    children: [
      {
        to: '/inventory',
        label: 'Productos',
        description: 'Catálogo de productos, existencias y precios de venta',
        defaultVisible: true,
      },
      {
        to: '/inventory/catalogs',
        label: 'Catálogos',
        description: 'Categorías, marcas y clasificaciones de repuestos',
        defaultVisible: true,
      },
      {
        to: '/inventory/currency',
        label: 'Tipos de tasa',
        description: 'Tasas de cambio de divisas (BCV, Paralelo, Tienda)',
        defaultVisible: true,
      },
      {
        to: '/inventory/manual-movements',
        label: 'Movimientos manuales',
        description: 'Ajustes de inventario, entradas, salidas y kardex',
        defaultVisible: false,
      },
      {
        to: '/inventory/admin',
        label: 'Administración',
        description: 'Operaciones masivas y herramientas administrativas',
        defaultVisible: false,
      },
    ],
  },
  {
    to: '/purchases',
    label: 'Compras',
    description: 'Órdenes de compra y recepción de mercancía',
    section: 'Inventario',
    defaultVisible: false,
  },
  {
    to: '/transfers',
    label: 'Traslados',
    description: 'Movimientos de inventario entre almacenes',
    section: 'Inventario',
    defaultVisible: false,
  },
  {
    to: '/inventory-transfer-requests',
    label: 'Solicitudes inter-empresa',
    description: 'Transferencias entre diferentes empresas del grupo',
    section: 'Inventario',
    defaultVisible: false,
  },
  {
    to: '/warranties',
    label: 'Garantías',
    description: 'Recepción y seguimiento de garantías de repuestos',
    section: 'Inventario',
    defaultVisible: false,
  },
  {
    to: '/workshop',
    label: 'Taller',
    description: 'Órdenes de servicio y reparación mecánica',
    section: 'Inventario',
    defaultVisible: false,
  },

  // ===== Analítica =====
  {
    to: '/reports',
    label: 'Reportes',
    description: 'Reportes de ventas, caja y cierre de turno',
    section: 'Analítica',
    defaultVisible: true,
  },
  {
    to: '/import',
    label: 'Importar datos',
    description: 'Importación masiva de productos y listas desde Excel',
    section: 'Analítica',
    defaultVisible: false,
  },
  {
    to: '/printing',
    label: 'Impresión',
    description: 'Conectores de ticketera y formatos de impresión térmica',
    section: 'Analítica',
    defaultVisible: false,
  },

  // ===== Acceso y Configuración =====
  {
    to: '/users',
    label: 'Usuarios y Acceso',
    description: 'Gestión de usuarios, roles y permisos de acceso',
    section: 'Configuración',
    defaultVisible: true,
    children: [
      {
        to: '/users',
        label: 'Usuarios',
        description: 'Directorio de usuarios y asignación de credenciales',
        defaultVisible: true,
      },
      {
        to: '/access/roles',
        label: 'Roles y Permisos',
        description: 'Matriz de roles y permisos del personal',
        defaultVisible: false,
      },
      {
        to: '/access/permissions',
        label: 'Catálogo de permisos',
        description: 'Definición técnica de capacidades del sistema',
        defaultVisible: false,
      },
      {
        to: '/access/groups',
        label: 'Organizaciones',
        description: 'Gestión y control de empresas del grupo',
        defaultVisible: false,
      },
    ],
  },
  {
    to: '/settings/company',
    label: 'Configuración',
    description: 'Datos de la empresa, modo fácil y ajustes generales',
    section: 'Configuración',
    defaultVisible: true,
    children: [
      {
        to: '/settings/company',
        label: 'Empresa',
        description: 'Datos fiscales, logo y datos de contacto de la tienda',
        defaultVisible: true,
      },
      {
        to: '/settings/simple-mode',
        label: 'Modo Fácil',
        description: 'Personalización de menús y submódulos visibles',
        defaultVisible: true,
      },
      {
        to: '/settings/telegram',
        label: 'Telegram',
        description: 'Alertas y notificaciones automatizadas al bot',
        defaultVisible: false,
      },
      {
        to: '/settings/capabilities',
        label: 'Capacidades y módulos',
        description: 'Control de módulos contratados en el SaaS',
        defaultVisible: false,
      },
    ],
  },
];

export const DEFAULT_VISIBLE_ROUTES: string[] = AVAILABLE_SIMPLE_MODE_ITEMS.flatMap((item) => {
  const routes: string[] = [];
  if (item.children && item.children.length > 0) {
    const activeChildren = item.children.filter((c) => c.defaultVisible);
    if (activeChildren.length > 0 || item.defaultVisible) {
      routes.push(item.to);
    }
    activeChildren.forEach((c) => {
      if (!routes.includes(c.to)) {
        routes.push(c.to);
      }
    });
  } else if (item.defaultVisible) {
    routes.push(item.to);
  }
  return routes;
});

interface UiModeState {
  isSimpleMode: boolean;
  visibleRoutes: string[];
  toggleSimpleMode: () => void;
  setSimpleMode: (simple: boolean) => void;
  toggleRoute: (route: string) => void;
  toggleModuleGroup: (parentTo: string, enabled: boolean) => void;
  setVisibleRoutes: (routes: string[]) => void;
  resetToDefaults: () => void;
  syncFromPreferences: (prefs: { is_simple_mode?: boolean; visible_routes?: string[] }) => void;
}

export const useUiModeStore = create<UiModeState>()(
  persist(
    (set) => ({
      isSimpleMode: true, // Modo Fácil activo por defecto para Repuestos Avilacar
      visibleRoutes: DEFAULT_VISIBLE_ROUTES,
      toggleSimpleMode: () => set((state) => ({ isSimpleMode: !state.isSimpleMode })),
      setSimpleMode: (simple: boolean) => set({ isSimpleMode: simple }),
      syncFromPreferences: (prefs) =>
        set((state) => ({
          isSimpleMode: prefs.is_simple_mode !== undefined ? prefs.is_simple_mode : state.isSimpleMode,
          visibleRoutes:
            Array.isArray(prefs.visible_routes) && prefs.visible_routes.length > 0
              ? prefs.visible_routes
              : state.visibleRoutes,
        })),
      toggleRoute: (route: string) =>
        set((state) => {
          const exists = state.visibleRoutes.includes(route);
          if (exists) {
            return {
              visibleRoutes: state.visibleRoutes.filter((r) => r !== route),
            };
          } else {
            const routesToAdd = [route];
            for (const item of AVAILABLE_SIMPLE_MODE_ITEMS) {
              if (item.children && item.children.some((c) => c.to === route)) {
                if (!state.visibleRoutes.includes(item.to) && !routesToAdd.includes(item.to)) {
                  routesToAdd.push(item.to);
                }
              }
            }
            return {
              visibleRoutes: [...state.visibleRoutes, ...routesToAdd],
            };
          }
        }),
      toggleModuleGroup: (parentTo: string, enabled: boolean) =>
        set((state) => {
          const item = AVAILABLE_SIMPLE_MODE_ITEMS.find((i) => i.to === parentTo);
          if (!item) {
            if (enabled) {
              return { visibleRoutes: Array.from(new Set([...state.visibleRoutes, parentTo])) };
            }
            return { visibleRoutes: state.visibleRoutes.filter((r) => r !== parentTo) };
          }

          const groupRoutes = [item.to];
          if (item.children) {
            item.children.forEach((c) => {
              if (!groupRoutes.includes(c.to)) {
                groupRoutes.push(c.to);
              }
            });
          }

          if (enabled) {
            return {
              visibleRoutes: Array.from(new Set([...state.visibleRoutes, ...groupRoutes])),
            };
          } else {
            return {
              visibleRoutes: state.visibleRoutes.filter((r) => !groupRoutes.includes(r)),
            };
          }
        }),
      setVisibleRoutes: (routes: string[]) => set({ visibleRoutes: routes }),
      resetToDefaults: () =>
        set({
          isSimpleMode: true,
          visibleRoutes: DEFAULT_VISIBLE_ROUTES,
        }),
    }),
    {
      name: 'sdi-ui-mode',
    },
  ),
);
