/**
 * Configuración de visibilidad y personalización del Dashboard.
 *
 * Permite seleccionar qué métricas (KPIs) y paneles analíticos visualizar
 * con persistencia local por empresa (tenant-aware).
 */

export interface DashboardVisibility {
  // Métricas principales (tarjetas KPI)
  sales: boolean;
  profit: boolean;
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
  profit: true,
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
        key: 'profit',
        label: 'Ganancia estimada ($)',
        description: 'Utilidad bruta (Ventas confirmadas menos Costo de la mercancía)',
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

/**
 * Retorna las clases de Tailwind de la cuadrícula en función del número de tarjetas KPI visibles,
 * permitiendo que las tarjetas se expandan y llenen proporcionalmente la pantalla.
 */
export function getKpiGridClasses(count: number): string {
  switch (count) {
    case 1:
      return 'grid grid-cols-1 gap-6';
    case 2:
      return 'grid grid-cols-1 md:grid-cols-2 gap-6';
    case 3:
      return 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6';
    case 4:
      return 'grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5';
    case 5:
      return 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4';
    case 6:
      return 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4';
    default:
      // 7 u 8 tarjetas -> 4 columnas en pantallas amplias para 2 filas balanceadas
      return 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-4 gap-4';
  }
}

export interface MetricCardSizeConfig {
  card: string;
  title: string;
  value: string;
  iconBox: string;
  icon: string;
  helper: string;
  stripe: string;
}

/**
 * Escala visualmente los elementos internos de la tarjeta métrica (padding, tamaño de fuente,
 * caja de ícono) cuando hay menos tarjetas en pantalla, haciéndolas mucho más prominentes y legibles.
 */
export function getMetricCardSizeClass(count: number): MetricCardSizeConfig {
  if (count <= 2) {
    return {
      card: 'p-6 sm:p-8 rounded-3xl min-h-[170px] sm:min-h-[195px] flex flex-col justify-between shadow-sm hover:shadow-lg',
      title: 'text-xs sm:text-sm font-bold text-slate-400 uppercase tracking-wider block',
      value: 'mt-2 text-4xl sm:text-5xl lg:text-6xl font-black tabular-nums font-mono tracking-tight block leading-tight',
      iconBox: 'size-14 sm:size-16 rounded-2xl border flex items-center justify-center shrink-0',
      icon: 'size-7 sm:size-8',
      helper: 'mt-4 flex items-center text-xs sm:text-sm text-slate-500 font-medium',
      stripe: 'h-2',
    };
  }
  if (count <= 4) {
    return {
      card: 'p-5 sm:p-6 rounded-2xl min-h-[145px] sm:min-h-[160px] flex flex-col justify-between shadow-sm hover:shadow-md',
      title: 'text-xs sm:text-sm font-bold text-slate-400 uppercase tracking-wider block',
      value: 'mt-1.5 text-3xl sm:text-4xl font-black tabular-nums font-mono tracking-tight block leading-snug',
      iconBox: 'size-12 sm:size-14 rounded-xl border flex items-center justify-center shrink-0',
      icon: 'size-6 sm:size-7',
      helper: 'mt-3 flex items-center text-xs sm:text-sm text-slate-500 font-medium',
      stripe: 'h-1.5',
    };
  }
  if (count <= 6) {
    return {
      card: 'p-4 sm:p-5 rounded-2xl min-h-[130px] sm:min-h-[145px] flex flex-col justify-between shadow-sm hover:shadow-md',
      title: 'text-[11px] sm:text-xs font-bold text-slate-400 uppercase tracking-wider block',
      value: 'mt-1 text-2xl sm:text-3xl font-black tabular-nums font-mono tracking-tight block',
      iconBox: 'size-11 sm:size-12 rounded-xl border flex items-center justify-center shrink-0',
      icon: 'size-5 sm:size-6',
      helper: 'mt-2.5 flex items-center text-xs text-slate-500 font-medium',
      stripe: 'h-1',
    };
  }
  return {
    card: 'p-4 rounded-2xl min-h-[125px] flex flex-col justify-between shadow-sm hover:shadow-md',
    title: 'text-[11px] font-bold text-slate-400 uppercase tracking-wider block',
    value: 'mt-1 text-2xl font-black tabular-nums font-mono tracking-tight block',
    iconBox: 'size-10 rounded-xl border flex items-center justify-center shrink-0',
    icon: 'size-5',
    helper: 'mt-2 flex items-center text-xs text-slate-500 font-medium',
    stripe: 'h-1',
  };
}

