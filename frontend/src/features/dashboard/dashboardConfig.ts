/**
 * Configuración de visibilidad y personalización del Dashboard.
 *
 * Permite seleccionar qué métricas (KPIs) y paneles analíticos visualizar
 * con persistencia local por empresa (tenant-aware).
 */

export interface DashboardVisibility {
  // Métricas principales (tarjetas KPI)
  sales: boolean;
  pos: boolean;
  cash_register: boolean;
  inventory_value: boolean;
  inventory_retail_value: boolean;
  low_stock: boolean;
  receivables: boolean;
  payables: boolean;

  // Secciones / Paneles
  low_stock_table: boolean;
  executive_reading: boolean;
}

export const DEFAULT_DASHBOARD_VISIBILITY: DashboardVisibility = {
  sales: true,
  pos: true,
  cash_register: true,
  inventory_value: true,
  inventory_retail_value: false,
  low_stock: true,
  receivables: true,
  payables: true,
  low_stock_table: true,
  executive_reading: true,
};

export interface DashboardItem {
  key: keyof DashboardVisibility;
  label: string;
  description: string;
}

export interface DashboardSection {
  id: string;
  title: string;
  items: DashboardItem[];
}

export const DASHBOARD_SECTIONS: DashboardSection[] = [
  {
    id: 'kpis',
    title: 'Métricas e Indicadores (KPIs)',
    items: [
      {
        key: 'sales',
        label: 'Ventas confirmadas',
        description: 'Monto total y cantidad de ventas confirmadas en el periodo',
      },
      {
        key: 'pos',
        label: 'POS cobrado',
        description: 'Ventas y tickets pagados en el punto de venta',
      },
      {
        key: 'cash_register',
        label: 'Cajas abiertas',
        description: 'Cantidad de sesiones y turnos de caja activos',
      },
      {
        key: 'inventory_value',
        label: 'Valor del inventario ($)',
        description: 'Capital total reflejado en existencias disponibles en almacenes',
      },
      {
        key: 'inventory_retail_value',
        label: 'Valor del inventario a precio venta ($)',
        description: 'Total de existencias calculado a precio venta al público (PVP)',
      },
      {
        key: 'low_stock',
        label: 'Bajo stock',
        description: 'Cantidad de productos con existencias por debajo del umbral mínimo',
      },
      {
        key: 'receivables',
        label: 'Cuentas por cobrar (CxC)',
        description: 'Saldo pendiente acumulado por cobrar a clientes',
      },
      {
        key: 'payables',
        label: 'Cuentas por pagar (CxP)',
        description: 'Saldo pendiente acumulado por pagar a proveedores',
      },
    ],
  },
  {
    id: 'panels',
    title: 'Secciones y Paneles',
    items: [
      {
        key: 'low_stock_table',
        label: 'Tabla de alertas de inventario',
        description: 'Lista detallada de productos y almacenes con bajo stock',
      },
      {
        key: 'executive_reading',
        label: 'Lectura ejecutiva',
        description: 'Resumen con balance operativo (CxC vs CxP) y venta promedio',
      },
    ],
  },
];

const STORAGE_PREFIX = 'dashboard_visibility';

/**
 * Obtiene la configuración de visibilidad guardada en el navegador para la empresa actual.
 */
export function getStoredDashboardVisibility(tenantId?: number | string | null): DashboardVisibility {
  if (typeof window === 'undefined') {
    return DEFAULT_DASHBOARD_VISIBILITY;
  }
  try {
    const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
    const raw = window.localStorage.getItem(key);
    if (raw) {
      const parsed = JSON.parse(raw);
      return { ...DEFAULT_DASHBOARD_VISIBILITY, ...parsed };
    }
  } catch {
    // Si falla o está en modo privado, usar defaults
  }
  return DEFAULT_DASHBOARD_VISIBILITY;
}

/**
 * Guarda la configuración de visibilidad en el navegador.
 */
export function saveStoredDashboardVisibility(
  visibility: DashboardVisibility,
  tenantId?: number | string | null,
): void {
  if (typeof window === 'undefined') return;
  try {
    const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
    window.localStorage.setItem(key, JSON.stringify(visibility));
  } catch {
    // ignorar errores de cuota o modo privado
  }
}

/**
 * Restablece la configuración de visibilidad a los valores por defecto.
 */
export function resetStoredDashboardVisibility(tenantId?: number | string | null): DashboardVisibility {
  if (typeof window !== 'undefined') {
    try {
      const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
      window.localStorage.removeItem(key);
    } catch {
      // ignorar
    }
  }
  return DEFAULT_DASHBOARD_VISIBILITY;
}
