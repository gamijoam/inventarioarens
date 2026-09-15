/**
 * Configuración de visibilidad y personalización de columnas en la tabla de inventario.
 *
 * Permite alternar qué columnas se visualizan (por ejemplo: ocultar SKU y mostrar Código de barras,
 * mostrar solo Producto y Stock, o activar solo listas de precio).
 * La configuración es persistente tanto a nivel local (localStorage) como en backend (tenant_settings.ui_preferences).
 */

export interface InventoryTableColumnsVisibility {
  image: boolean;
  sku: boolean;
  barcode: boolean;
  name: boolean; // Obligatorio / siempre true
  brand: boolean;
  categories: boolean;
  tracking_type: boolean;
  stock: boolean;
  cost_price: boolean;
  base_price: boolean;
  profit: boolean;
  price_list: boolean;
  is_active: boolean;
}

/**
 * Configuración estándar predeterminada:
 * SKU visible, código oculto, nombre, tipo, stock, precio base y listas de precios activas.
 */
export const DEFAULT_INVENTORY_TABLE_COLUMNS: InventoryTableColumnsVisibility = {
  image: true,
  sku: true,
  barcode: false,
  name: true,
  brand: false,
  categories: false,
  tracking_type: true,
  stock: true,
  cost_price: false,
  base_price: true,
  profit: false,
  price_list: true,
  is_active: true,
};

/**
 * Preset "Solo Producto y Stock":
 * Diseñado para consultas rápidas de inventario / conteo físico.
 */
export const PRODUCT_AND_STOCK_COLUMNS: InventoryTableColumnsVisibility = {
  image: false,
  sku: false,
  barcode: false,
  name: true,
  brand: false,
  categories: false,
  tracking_type: false,
  stock: true,
  cost_price: false,
  base_price: false,
  profit: false,
  price_list: false,
  is_active: false,
};

/**
 * Preset "Mostrador / Código":
 * Oculta el SKU interno y prioriza el Código escaneable junto al precio de venta.
 */
export const BARCODE_COUNTER_COLUMNS: InventoryTableColumnsVisibility = {
  image: false,
  sku: false,
  barcode: true,
  name: true,
  brand: false,
  categories: false,
  tracking_type: false,
  stock: true,
  cost_price: false,
  base_price: true,
  profit: false,
  price_list: false,
  is_active: true,
};

/**
 * Preset "Solo lista de precio":
 * Muestra la lista de precios predeterminada / selector y oculta el precio base suelto.
 */
export const SINGLE_PRICE_LIST_COLUMNS: InventoryTableColumnsVisibility = {
  image: false,
  sku: true,
  barcode: false,
  name: true,
  brand: false,
  categories: false,
  tracking_type: false,
  stock: true,
  cost_price: false,
  base_price: false,
  profit: false,
  price_list: true,
  is_active: true,
};

/**
 * Preset "Completo":
 * Muestra todas las columnas disponibles para auditoría o administración detallada.
 */
export const ALL_INVENTORY_TABLE_COLUMNS: InventoryTableColumnsVisibility = {
  image: true,
  sku: true,
  barcode: true,
  name: true,
  brand: true,
  categories: true,
  tracking_type: true,
  stock: true,
  cost_price: true,
  base_price: true,
  profit: true,
  price_list: true,
  is_active: true,
};

export interface ColumnPreset {
  id: string;
  name: string;
  description: string;
  columns: InventoryTableColumnsVisibility;
}

export const INVENTORY_COLUMN_PRESETS: ColumnPreset[] = [
  {
    id: 'default',
    name: 'Estándar',
    description: 'SKU, nombre, tipo, stock, precio base y listas.',
    columns: DEFAULT_INVENTORY_TABLE_COLUMNS,
  },
  {
    id: 'product-stock',
    name: 'Solo Producto y Stock',
    description: 'Vista ultra-simplificada: solo nombre del producto y stock disponible.',
    columns: PRODUCT_AND_STOCK_COLUMNS,
  },
  {
    id: 'barcode-counter',
    name: 'Mostrador / Código',
    description: 'Oculta SKU y muestra Código + precio de venta.',
    columns: BARCODE_COUNTER_COLUMNS,
  },
  {
    id: 'price-list-only',
    name: 'Solo lista de precio',
    description: 'Muestra lista de precio predeterminada y oculta precio base individual.',
    columns: SINGLE_PRICE_LIST_COLUMNS,
  },
  {
    id: 'all',
    name: 'Completo',
    description: 'Muestra todas las columnas disponibles.',
    columns: ALL_INVENTORY_TABLE_COLUMNS,
  },
];

export interface ColumnDefinitionItem {
  key: keyof InventoryTableColumnsVisibility;
  label: string;
  description?: string;
  required?: boolean;
}

export const INVENTORY_COLUMN_DEFINITIONS: ColumnDefinitionItem[] = [
  {
    key: 'name',
    label: 'Nombre del producto',
    required: true,
    description: 'Obligatorio para identificar el producto',
  },
  {
    key: 'sku',
    label: 'SKU (Código interno)',
    description: 'Código de referencia interno del negocio',
  },
  {
    key: 'barcode',
    label: 'Código',
    description: 'Código de barras o referencia escaneable',
  },
  {
    key: 'image',
    label: 'Miniatura / Foto',
    description: 'Imagen principal del producto',
  },
  {
    key: 'brand',
    label: 'Marca',
    description: 'Marca o fabricante asignado',
  },
  {
    key: 'categories',
    label: 'Categorías',
    description: 'Categorías y clasificaciones',
  },
  {
    key: 'tracking_type',
    label: 'Tipo de control',
    description: 'Por cantidad o serializado',
  },
  {
    key: 'stock',
    label: 'Stock disponible',
    description: 'Existencia total o por almacén seleccionado',
  },
  {
    key: 'cost_price',
    label: 'Precio costo',
    description: 'Costo unitario o promedio de adquisición del producto',
  },
  {
    key: 'base_price',
    label: 'Precio base',
    description: 'Precio de venta base en USD/VES',
  },
  {
    key: 'profit',
    label: 'Ganancia',
    description: 'Margen de ganancia estimado (Precio venta - Precio costo)',
  },
  {
    key: 'price_list',
    label: 'Lista de precios',
    description: 'Lista predeterminada y popover con precios configurados',
  },
  {
    key: 'is_active',
    label: 'Estado',
    description: 'Activo o Inactivo',
  },
];

const STORAGE_PREFIX = 'inventory_table_columns';

/**
 * Obtiene la configuración de columnas guardada en el navegador para la empresa actual.
 */
export function getStoredInventoryColumnsVisibility(
  tenantId?: number | string | null,
): InventoryTableColumnsVisibility {
  if (typeof window === 'undefined') {
    return DEFAULT_INVENTORY_TABLE_COLUMNS;
  }
  try {
    const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
    const raw = window.localStorage.getItem(key);
    if (raw) {
      const parsed = JSON.parse(raw);
      return { ...DEFAULT_INVENTORY_TABLE_COLUMNS, ...parsed, name: true };
    }
  } catch {
    // Si falla o está en modo privado, usar defaults
  }
  return DEFAULT_INVENTORY_TABLE_COLUMNS;
}

/**
 * Guarda la configuración de columnas en el navegador.
 */
export function saveStoredInventoryColumnsVisibility(
  columns: InventoryTableColumnsVisibility,
  tenantId?: number | string | null,
): void {
  if (typeof window === 'undefined') return;
  try {
    const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
    window.localStorage.setItem(key, JSON.stringify({ ...columns, name: true }));
  } catch {
    // ignorar errores de cuota o modo privado
  }
}

/**
 * Restablece la configuración de columnas a los valores por defecto.
 */
export function resetStoredInventoryColumnsVisibility(
  tenantId?: number | string | null,
): InventoryTableColumnsVisibility {
  if (typeof window !== 'undefined') {
    try {
      const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
      window.localStorage.removeItem(key);
    } catch {
      // ignorar
    }
  }
  return DEFAULT_INVENTORY_TABLE_COLUMNS;
}
