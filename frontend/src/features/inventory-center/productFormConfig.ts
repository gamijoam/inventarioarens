/**
 * Configuración de visibilidad de campos en el formulario de productos (ProductForm).
 *
 * Permite ocultar campos innecesarios sin eliminarlos a nivel de programación ni de base de datos.
 * Para mostrar u ocultar cualquier campo, simplemente cambia su valor entre true y false.
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
 * Visibilidad simplificada para el formulario de CREACIÓN de productos.
 * Mantiene visibles los campos esenciales para una carga limpia y rápida:
 * - Nombre, SKU, Código de barras, Descripción corta
 * - Marca, Categorías
 * - Tipo de control de stock (cantidad vs serializado)
 * - Precio de venta
 *
 * Los demás campos (tags, límites de stock, recargos automáticos, monedas, garantías, etc.)
 * quedan ocultos por defecto, pero accesibles mediante el botón "+ Mostrar campos adicionales".
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
