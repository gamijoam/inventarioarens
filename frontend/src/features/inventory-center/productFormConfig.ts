/**
 * Configuración de visibilidad de campos en el formulario de productos (ProductForm).
 *
 * Permite ocultar campos innecesarios sin eliminarlos a nivel de programación ni de base de datos.
 * Para mostrar u ocultar cualquier campo, simplemente cambia su valor entre true y false
 * o utiliza el botón "Personalizar campos" en la interfaz.
 */

export interface ProductFormVisibility {
  // 1. Identificación
  name: boolean;
  sku: boolean;
  barcode: boolean;
  image_url: boolean;
  description: boolean;
  long_description: boolean;

  // 2. Catálogos
  brand: boolean;
  categories: boolean;
  tags: boolean;

  // 3. Control de stock
  tracking_type: boolean;
  unit_of_measure: boolean;
  track_stock: boolean;
  min_stock: boolean;
  max_stock: boolean;
  reorder_quantity: boolean;

  // 4. Precios
  pricing_mode: boolean;
  last_purchase_cost: boolean;
  profit_margin: boolean;
  base_price: boolean;
  sale_currency: boolean;
  sale_exchange_rate_type_id: boolean;

  // 5. Garantía y Estado
  warranty_policy_id: boolean;
  is_active: boolean;
}

/**
 * Visibilidad completa (todos los campos visibles).
 * Utilizada por defecto en edición y pruebas completas.
 */
export const FULL_PRODUCT_FORM_VISIBILITY: ProductFormVisibility = {
  name: true,
  sku: true,
  barcode: true,
  image_url: true,
  description: true,
  long_description: true,

  brand: true,
  categories: true,
  tags: true,

  tracking_type: true,
  unit_of_measure: true,
  track_stock: true,
  min_stock: true,
  max_stock: true,
  reorder_quantity: true,

  pricing_mode: true,
  last_purchase_cost: true,
  profit_margin: true,
  base_price: true,
  sale_currency: true,
  sale_exchange_rate_type_id: true,

  warranty_policy_id: true,
  is_active: true,
};

/**
 * Visibilidad simplificada por defecto para el formulario de CREACIÓN de productos.
 * Mantiene visibles los campos esenciales para una carga limpia y rápida:
 * - Nombre, SKU, Código de barras, Descripción corta
 * - Marca, Categorías
 * - Tipo de control de stock (cantidad vs serializado)
 * - Precio de venta
 */
export const CREATE_PRODUCT_FORM_VISIBILITY: ProductFormVisibility = {
  // Identificación
  name: true,
  sku: true,
  barcode: true,
  image_url: false,
  description: true,
  long_description: false,

  // Catálogos
  brand: true,
  categories: true,
  tags: false,

  // Control de stock
  tracking_type: true,
  unit_of_measure: false,
  track_stock: false,
  min_stock: false,
  max_stock: false,
  reorder_quantity: false,

  // Precios
  pricing_mode: false,
  last_purchase_cost: false,
  profit_margin: false,
  base_price: true,
  sale_currency: false,
  sale_exchange_rate_type_id: false,

  // Garantía y Estado
  warranty_policy_id: false,
  is_active: false,
};

/**
 * Definición estructurada de secciones y campos para el diálogo de personalización visual.
 */
export interface FormFieldItem {
  key: keyof ProductFormVisibility;
  label: string;
  description?: string;
  required?: boolean;
}

export interface FormSectionItem {
  id: string;
  title: string;
  fields: FormFieldItem[];
}

export const PRODUCT_FORM_SECTIONS: FormSectionItem[] = [
  {
    id: 'identification',
    title: '1. Identificación',
    fields: [
      { key: 'name', label: 'Nombre', required: true, description: 'Obligatorio en todo producto' },
      { key: 'sku', label: 'SKU (Código interno)' },
      { key: 'barcode', label: 'Código de barras' },
      { key: 'image_url', label: 'URL externa de imagen' },
      { key: 'description', label: 'Descripción corta' },
      { key: 'long_description', label: 'Descripción larga (HTML)' },
    ],
  },
  {
    id: 'catalogs',
    title: '2. Catálogos',
    fields: [
      { key: 'brand', label: 'Marca' },
      { key: 'categories', label: 'Categorías' },
      { key: 'tags', label: 'Tags / Etiquetas' },
    ],
  },
  {
    id: 'stock',
    title: '3. Control de stock',
    fields: [
      { key: 'tracking_type', label: 'Tipo de control (Cantidad / Serializado)' },
      { key: 'unit_of_measure', label: 'Unidad de medida' },
      { key: 'track_stock', label: 'Controlar stock (Switch)' },
      { key: 'min_stock', label: 'Stock mínimo de alerta' },
      { key: 'max_stock', label: 'Stock máximo' },
      { key: 'reorder_quantity', label: 'Cantidad a reordenar' },
    ],
  },
  {
    id: 'pricing',
    title: '4. Precios',
    fields: [
      {
        key: 'base_price',
        label: 'Precio de venta',
        required: true,
        description: 'Obligatorio para facturar y vender en POS/caja',
      },
      { key: 'pricing_mode', label: 'Modo de precio (Automático vs Manual)' },
      { key: 'last_purchase_cost', label: 'Costo unitario' },
      { key: 'profit_margin', label: 'Recargo sobre costo (%)' },
      { key: 'sale_currency', label: 'Moneda de venta (USD / VES)' },
      { key: 'sale_exchange_rate_type_id', label: 'Tipo de tasa asignada' },
    ],
  },
  {
    id: 'warranty_status',
    title: '5. Garantía y Estado',
    fields: [
      { key: 'warranty_policy_id', label: 'Política de garantía' },
      { key: 'is_active', label: 'Producto activo (visible en ventas)' },
    ],
  },
];

const STORAGE_PREFIX = 'product_form_visibility';

/**
 * Obtiene la configuración de visibilidad guardada en el navegador para la empresa actual.
 */
export function getStoredProductFormVisibility(tenantId?: number | string | null): ProductFormVisibility {
  if (typeof window === 'undefined') {
    return CREATE_PRODUCT_FORM_VISIBILITY;
  }
  try {
    const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
    const raw = window.localStorage.getItem(key);
    if (raw) {
      const parsed = JSON.parse(raw);
      return { ...CREATE_PRODUCT_FORM_VISIBILITY, ...parsed, name: true, base_price: true };
    }
  } catch {
    // Si falla o está en modo privado, usar defaults
  }
  return CREATE_PRODUCT_FORM_VISIBILITY;
}

/**
 * Guarda la configuración de visibilidad en el navegador.
 */
export function saveStoredProductFormVisibility(
  visibility: ProductFormVisibility,
  tenantId?: number | string | null,
): void {
  if (typeof window === 'undefined') return;
  try {
    const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
    window.localStorage.setItem(key, JSON.stringify({ ...visibility, name: true, base_price: true }));
  } catch {
    // ignorar errores de cuota o modo privado
  }
}

/**
 * Restablece la configuración de visibilidad a los valores por defecto.
 */
export function resetStoredProductFormVisibility(tenantId?: number | string | null): ProductFormVisibility {
  if (typeof window !== 'undefined') {
    try {
      const key = tenantId ? `${STORAGE_PREFIX}_${tenantId}` : STORAGE_PREFIX;
      window.localStorage.removeItem(key);
    } catch {
      // ignorar
    }
  }
  return CREATE_PRODUCT_FORM_VISIBILITY;
}
