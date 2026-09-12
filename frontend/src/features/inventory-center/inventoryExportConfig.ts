/**
 * Configuración de columnas y presets para la exportación de inventario en CSV.
 *
 * Permite seleccionar qué columnas se incluyen al exportar el inventario (por ejemplo:
 * solo nombres, nombre + SKU, nombre + código de barras, nombre + stock, etc.).
 * La selección se persiste automáticamente en localStorage por tenant.
 */

export type InventoryExportColumnKey =
  | 'name'
  | 'sku'
  | 'barcode'
  | 'tracking_type'
  | 'sale_currency'
  | 'base_price'
  | 'stock_available'
  | 'stock_reserved'
  | 'stock_damaged'
  | 'stock_status'
  | 'min_stock'
  | 'max_stock'
  | 'average_cost'
  | 'is_active';

export interface InventoryExportColumnDefinition {
  key: InventoryExportColumnKey;
  label: string;
  description: string;
  category: 'basic' | 'stock' | 'pricing';
  required?: boolean;
}

export const INVENTORY_EXPORT_COLUMNS: InventoryExportColumnDefinition[] = [
  // Información básica
  {
    key: 'name',
    label: 'Producto (Nombre)',
    description: 'Nombre comercial del producto',
    category: 'basic',
    required: true,
  },
  {
    key: 'sku',
    label: 'SKU',
    description: 'Código interno de referencia',
    category: 'basic',
  },
  {
    key: 'barcode',
    label: 'Código de barras',
    description: 'Código de barras escaneable',
    category: 'basic',
  },
  {
    key: 'tracking_type',
    label: 'Tipo de control',
    description: 'Por cantidad o Serializado (IMEI)',
    category: 'basic',
  },
  {
    key: 'is_active',
    label: 'Estado activo/inactivo',
    description: 'Indica si el producto está disponible para venta',
    category: 'basic',
  },

  // Stock y Almacén
  {
    key: 'stock_available',
    label: 'Stock disponible',
    description: 'Unidades físicas listas para facturar',
    category: 'stock',
  },
  {
    key: 'stock_reserved',
    label: 'Stock reservado',
    description: 'Unidades en pedidos o apartados',
    category: 'stock',
  },
  {
    key: 'stock_damaged',
    label: 'Stock dañado',
    description: 'Unidades averiadas o defectuosas',
    category: 'stock',
  },
  {
    key: 'stock_status',
    label: 'Estado de stock',
    description: 'Disponible, Stock bajo o Sin stock',
    category: 'stock',
  },
  {
    key: 'min_stock',
    label: 'Stock mínimo',
    description: 'Umbral mínimo de reposición',
    category: 'stock',
  },
  {
    key: 'max_stock',
    label: 'Stock máximo',
    description: 'Límite máximo de almacenamiento',
    category: 'stock',
  },

  // Precios y Costos
  {
    key: 'base_price',
    label: 'Precio base',
    description: 'Precio de venta al público',
    category: 'pricing',
  },
  {
    key: 'sale_currency',
    label: 'Moneda',
    description: 'Moneda de venta (USD / VES)',
    category: 'pricing',
  },
  {
    key: 'average_cost',
    label: 'Costo promedio',
    description: 'Costo unitario promedio ponderado',
    category: 'pricing',
  },
];

export interface InventoryExportPreset {
  id: string;
  label: string;
  description: string;
  columns: InventoryExportColumnKey[];
}

export const INVENTORY_EXPORT_PRESETS: InventoryExportPreset[] = [
  {
    id: 'default',
    label: 'Estándar',
    description: 'Campos principales con stock y precio base.',
    columns: [
      'name',
      'sku',
      'tracking_type',
      'sale_currency',
      'base_price',
      'stock_available',
      'stock_reserved',
      'stock_damaged',
      'stock_status',
    ],
  },
  {
    id: 'only_names',
    label: 'Solo Nombres',
    description: 'Únicamente el nombre de los productos.',
    columns: ['name'],
  },
  {
    id: 'name_and_sku',
    label: 'Nombre y SKU',
    description: 'Nombre del producto y su código interno SKU.',
    columns: ['name', 'sku'],
  },
  {
    id: 'name_and_barcode',
    label: 'Nombre y Código de barras',
    description: 'Nombre y código de barras para escáner o etiquetas.',
    columns: ['name', 'barcode'],
  },
  {
    id: 'name_and_stock',
    label: 'Nombre y Stock',
    description: 'Nombre y cantidad disponible para conteo físico.',
    columns: ['name', 'stock_available'],
  },
  {
    id: 'catalog_price',
    label: 'Lista de mostrador',
    description: 'Nombre, código de barras, precio base y stock disponible.',
    columns: ['name', 'barcode', 'base_price', 'sale_currency', 'stock_available'],
  },
  {
    id: 'all',
    label: 'Todos los campos',
    description: 'Auditoría completa con todas las columnas disponibles.',
    columns: INVENTORY_EXPORT_COLUMNS.map((col) => col.key),
  },
];

export const DEFAULT_EXPORT_COLUMNS: InventoryExportColumnKey[] = [
  'name',
  'sku',
  'tracking_type',
  'sale_currency',
  'base_price',
  'stock_available',
  'stock_reserved',
  'stock_damaged',
  'stock_status',
];

const STORAGE_KEY_PREFIX = 'inventory_export_columns_';

export function getStoredExportColumns(tenantId?: number | string | null): InventoryExportColumnKey[] {
  if (typeof window === 'undefined') return DEFAULT_EXPORT_COLUMNS;
  try {
    const key = `${STORAGE_KEY_PREFIX}${tenantId ?? 'default'}`;
    const raw = window.localStorage.getItem(key);
    if (!raw) return DEFAULT_EXPORT_COLUMNS;
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed) && parsed.length > 0) {
      const validKeys = new Set(INVENTORY_EXPORT_COLUMNS.map((c) => c.key));
      const filtered = parsed.filter((k): k is InventoryExportColumnKey => validKeys.has(k));
      if (!filtered.includes('name')) filtered.unshift('name');
      return filtered.length > 0 ? filtered : DEFAULT_EXPORT_COLUMNS;
    }
    return DEFAULT_EXPORT_COLUMNS;
  } catch {
    return DEFAULT_EXPORT_COLUMNS;
  }
}

export function saveStoredExportColumns(
  columns: InventoryExportColumnKey[],
  tenantId?: number | string | null,
): void {
  if (typeof window === 'undefined') return;
  try {
    const key = `${STORAGE_KEY_PREFIX}${tenantId ?? 'default'}`;
    const cleanColumns = Array.from(new Set(['name', ...columns])) as InventoryExportColumnKey[];
    window.localStorage.setItem(key, JSON.stringify(cleanColumns));
  } catch {
    // ignore
  }
}
