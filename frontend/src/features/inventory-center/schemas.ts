/**
 * Schemas Zod + tipos para el modulo de inventario completo.
 * Reflejan los endpoints del backend documentados en:
 *   - docs/INVENTORY_CATALOG_API.md
 *   - docs/INVENTORY_ALERTS_API.md
 *   - docs/INVENTORY_PHASE3.md
 */
import { z } from 'zod';

// =====================================================================
// Marcas, Categorias, Tags
// =====================================================================

export const BrandSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  description: z.string().nullable().optional(),
  is_active: z.boolean(),
  products_count: z.number().int().nonnegative().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});
export type Brand = z.infer<typeof BrandSchema>;

// CategorySchema es recursivo. Usamos z.lazy con interface explicito.
export interface Category {
  id: number;
  parent_id?: number | null;
  name: string;
  slug: string;
  description?: string | null;
  sort_order?: number;
  is_active: boolean;
  full_path?: string;
  parent?: { id: number; name: string; slug: string } | null;
  children?: Category[];
  products_count?: number;
}

export const CategorySchema: z.ZodType<Category> = z.lazy(() =>
  z.object({
    id: z.number().int().positive(),
    parent_id: z.number().int().nullable().optional(),
    name: z.string(),
    slug: z.string(),
    description: z.string().nullable().optional(),
    sort_order: z.number().int().optional(),
    is_active: z.boolean(),
    full_path: z.string().optional(),
    parent: z
      .object({ id: z.number(), name: z.string(), slug: z.string() })
      .nullable()
      .optional(),
    children: z.array(CategorySchema).optional(),
    products_count: z.number().int().nonnegative().optional(),
  }),
);

// Para el tree (recursivo) la forma util para el frontend es:
// { id, label, children? }.

export const TagSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  color: z.string().nullable().optional(),
  products_count: z.number().int().nonnegative().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});
export type Tag = z.infer<typeof TagSchema>;

// =====================================================================
// Stock status
// =====================================================================

export const StockStatusEnum = z.enum(['out', 'critical', 'low', 'available', 'overstock']);
export type StockStatus = z.infer<typeof StockStatusEnum>;

export const ProductStockStatusSchema = z.object({
  product_id: z.number().int().positive(),
  product_name: z.string(),
  sku: z.string(),
  available: z.number(),
  reserved: z.number().default(0),
  damaged: z.number().default(0),
  physical: z.number(),
  min_stock: z.number().nullable().optional(),
  max_stock: z.number().nullable().optional(),
  reorder_quantity: z.number().nullable().optional(),
  suggested_purchase: z.number().nullable().optional(),
  status: StockStatusEnum,
  status_label: z.string(),
  has_min_stock: z.boolean(),
  has_max_stock: z.boolean(),
});
export type ProductStockStatus = z.infer<typeof ProductStockStatusSchema>;

export const AlertsSummarySchema = z.object({
  out_count: z.number().int().nonnegative(),
  low_count: z.number().int().nonnegative(),
  with_min_stock_count: z.number().int().nonnegative(),
  fallback_threshold: z.number(),
});
export type AlertsSummary = z.infer<typeof AlertsSummarySchema>;

export const ReorderSuggestionSchema = z.object({
  product_id: z.number().int().positive(),
  product_name: z.string(),
  sku: z.string(),
  available: z.number(),
  reserved: z.number().default(0),
  min_stock: z.number().nullable().optional(),
  max_stock: z.number().nullable().optional(),
  reorder_quantity: z.number().nullable().optional(),
  suggested_purchase: z.number().nullable().optional(),
  status: StockStatusEnum,
  status_label: z.string(),
  gap_to_min: z.number(),
});
export type ReorderSuggestion = z.infer<typeof ReorderSuggestionSchema>;

export const ReorderSuggestionsResponseSchema = z.object({
  data: z.array(ReorderSuggestionSchema),
  summary: z.object({
    total_suggestions: z.number().int().nonnegative(),
    critical_count: z.number().int().nonnegative(),
    low_count: z.number().int().nonnegative(),
    out_count: z.number().int().nonnegative(),
  }),
});
export type ReorderSuggestionsResponse = z.infer<typeof ReorderSuggestionsResponseSchema>;

// =====================================================================
// Producto (form schemas)
// =====================================================================

export const TRACKING_TYPES = ['quantity', 'serialized'] as const;
export const UNITS_OF_MEASURE = ['unit', 'kg', 'lt', 'm'] as const;
export const SALE_CURRENCIES = ['USD', 'VES'] as const;

const trimmedString = (max: number) =>
  z
    .string()
    .max(max)
    .transform((s) => s.trim())
    .refine((s) => s.length > 0, 'Requerido');

const optionalTrimmedString = (max: number) =>
  z
    .string()
    .max(max)
    .transform((s) => s.trim())
    .optional()
    .or(z.literal('').transform(() => undefined));

const optionalNumber = (min = 0) =>
  z.preprocess(
    (v) => (v === '' || v == null ? undefined : Number(v)),
    z.number().min(min).optional(),
  );

export const StoreProductSchema = z
  .object({
    name: trimmedString(255),
    description: optionalTrimmedString(1000),
    long_description: optionalTrimmedString(50000),
    sku: optionalTrimmedString(255),
    barcode: optionalTrimmedString(255),
    image_url: z
      .string()
      .max(500)
      .transform((s) => s.trim())
      .refine((s) => s === '' || /^https?:\/\//.test(s) || s.startsWith('/'), {
        message: 'URL invalida',
      })
      .optional()
      .or(z.literal('').transform(() => undefined)),
    tracking_type: z.enum(TRACKING_TYPES).default('quantity'),
    unit_of_measure: z.enum(UNITS_OF_MEASURE).default('unit'),
    track_stock: z.boolean().default(true),
    brand_id: optionalNumber(1),
    category_ids: z.array(z.number().int().positive()).default([]),
    tag_ids: z.array(z.number().int().positive()).default([]),
    base_price: optionalNumber(0),
    sale_currency: z.enum(SALE_CURRENCIES).default('USD'),
    sale_exchange_rate_type_id: optionalNumber(1),
    min_stock: optionalNumber(0),
    max_stock: optionalNumber(0),
    reorder_quantity: optionalNumber(0),
    warranty_policy_id: optionalNumber(1),
    is_active: z.boolean().default(true),
  })
  .superRefine((data, ctx) => {
    // Validaciones cross-field.
    if (data.max_stock != null && data.min_stock != null && data.max_stock < data.min_stock) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['max_stock'],
        message: 'max_stock debe ser >= min_stock',
      });
    }
    if (
      data.reorder_quantity != null &&
      data.min_stock != null &&
      data.max_stock != null &&
      data.reorder_quantity > data.max_stock - data.min_stock
    ) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['reorder_quantity'],
        message: 'reorder_quantity no puede superar (max_stock - min_stock)',
      });
    }
  })
  .transform((data) => {
    // Limpia campos vacios opcionales para no enviar strings vacios al backend.
    const cleaned: Record<string, unknown> = { ...data };
    for (const key of [
      'description',
      'long_description',
      'sku',
      'barcode',
      'image_url',
      'brand_id',
      'base_price',
      'sale_exchange_rate_type_id',
      'min_stock',
      'max_stock',
      'reorder_quantity',
      'warranty_policy_id',
    ]) {
      if (cleaned[key] === '' || cleaned[key] === undefined) {
        delete cleaned[key];
      }
    }
    return cleaned;
  });
export type StoreProductInput = z.input<typeof StoreProductSchema>;
export type StoreProductValues = z.output<typeof StoreProductSchema>;

// Update es como Store pero todos los campos son opcionales.
// StoreProductSchema es un ZodEffects (por el .transform + superRefine), asi que
// redefinimos el schema base sin el transform para poder hacer .partial().
const StoreProductBaseSchema = z.object({
  name: trimmedString(255),
  description: optionalTrimmedString(1000),
  long_description: optionalTrimmedString(50000),
  sku: optionalTrimmedString(255),
  barcode: optionalTrimmedString(255),
  image_url: z
    .string()
    .max(500)
    .transform((s) => s.trim())
    .refine((s) => s === '' || /^https?:\/\//.test(s) || s.startsWith('/'), {
      message: 'URL invalida',
    })
    .optional()
    .or(z.literal('').transform(() => undefined)),
  tracking_type: z.enum(TRACKING_TYPES).default('quantity'),
  unit_of_measure: z.enum(UNITS_OF_MEASURE).default('unit'),
  track_stock: z.boolean().default(true),
  brand_id: optionalNumber(1),
  category_ids: z.array(z.number().int().positive()).default([]),
  tag_ids: z.array(z.number().int().positive()).default([]),
  base_price: optionalNumber(0),
  sale_currency: z.enum(SALE_CURRENCIES).default('USD'),
  sale_exchange_rate_type_id: optionalNumber(1),
  min_stock: optionalNumber(0),
  max_stock: optionalNumber(0),
  reorder_quantity: optionalNumber(0),
  warranty_policy_id: optionalNumber(1),
  is_active: z.boolean().default(true),
});

export const UpdateProductSchema = StoreProductBaseSchema.partial();
export type UpdateProductInput = z.input<typeof UpdateProductSchema>;

// =====================================================================
// Bulk action
// =====================================================================

export const BULK_ACTIONS = [
  'activate',
  'deactivate',
  'assign_warranty_policy',
  'assign_exchange_rate_type',
  'fill_missing_price_list',
  'update_price_list',
] as const;
export type BulkAction = (typeof BULK_ACTIONS)[number];

export const PRICE_STRATEGIES = ['base_price', 'fixed_price', 'percent_over_base'] as const;
export type PriceStrategy = (typeof PRICE_STRATEGIES)[number];

export const BulkActionSchema = z
  .object({
    product_ids: z.array(z.number().int().positive()).min(1).max(200),
    action: z.enum(BULK_ACTIONS),
    payload: z
      .object({
        warranty_policy_id: z.number().int().positive().optional(),
        sale_exchange_rate_type_id: z.number().int().positive().optional(),
        price_list_id: z.number().int().positive().optional(),
        strategy: z.enum(PRICE_STRATEGIES).optional(),
        price: optionalNumber(0),
        percent: optionalNumber(-99),
        currency: z.enum(SALE_CURRENCIES).optional(),
      })
      .optional()
      .default({}),
  })
  .superRefine((data, ctx) => {
    if (data.action === 'assign_warranty_policy' && !data.payload?.warranty_policy_id) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['payload', 'warranty_policy_id'],
        message: 'Selecciona la politica de garantia a asignar.',
      });
    }
    if (data.action === 'assign_exchange_rate_type' && !data.payload?.sale_exchange_rate_type_id) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['payload', 'sale_exchange_rate_type_id'],
        message: 'Selecciona el tipo de tasa a asignar.',
      });
    }
    if (
      (data.action === 'fill_missing_price_list' || data.action === 'update_price_list') &&
      !data.payload?.price_list_id
    ) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['payload', 'price_list_id'],
        message: 'Selecciona la lista de precio.',
      });
    }
  });
export type BulkActionInput = z.input<typeof BulkActionSchema>;

// =====================================================================
// Producto (response)
// =====================================================================

// CategorySchema es ZodType (lazy) y no expone .pick().
// Redefinimos los sub-schemas inline para evitar el problema.
const ProductBrandRefSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
});
const ProductCategoryRefSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  full_path: z.string().optional(),
});
const ProductTagRefSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  color: z.string().nullable().optional(),
});

/**
 * Schema del producto en respuesta del backend.
 *
 * IMPORTANTE: los campos decimales (base_price, min_stock, max_stock,
 * reorder_quantity, suggested_purchase, average_cost) son retornados
 * por el backend como NUMEROS (float). Anteriormente estaban tipados como
 * string en este schema, lo que causaba que Zod fallara la validacion y
 * la query de useProducts quedara en estado de error -> inventario vacio.
 *
 * Tambien eran .int() para stock levels, pero el backend devuelve floats.
 *
 * Para robustez: aceptamos z.number() | z.string() (unido) y los normalizamos
 * a number via z.coerce.number() cuando aplique.
 */
export const ProductSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int().positive(),
  name: z.string(),
  sku: z.string().nullable().optional(),
  barcode: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  long_description: z.string().nullable().optional(),
  image_url: z.string().nullable().optional(),
  tracking_type: z.enum(TRACKING_TYPES),
  unit_of_measure: z.enum(UNITS_OF_MEASURE).optional(),
  track_stock: z.boolean().optional(),
  brand_id: z.number().int().nullable().optional(),
  brand: ProductBrandRefSchema.nullable().optional(),
  categories: z.array(ProductCategoryRefSchema).optional(),
  tags: z.array(ProductTagRefSchema).optional(),
  // Decimales: el backend castea a (float). Aceptamos number | string
  // para tolerancia (algunos endpoints legacy pueden enviar string).
  base_price: z.union([z.number(), z.string()]).nullable().optional(),
  sale_currency: z.enum(SALE_CURRENCIES).nullable().optional(),
  sale_exchange_rate_type_id: z.number().int().nullable().optional(),
  sale_exchange_rate_type: z
    .object({ id: z.number(), code: z.string(), name: z.string(), is_default: z.boolean(), is_active: z.boolean() })
    .nullable()
    .optional(),
  // Stock levels: number | string (toleramos ambos), null permitido.
  min_stock: z.union([z.number(), z.string()]).nullable().optional(),
  max_stock: z.union([z.number(), z.string()]).nullable().optional(),
  reorder_quantity: z.union([z.number(), z.string()]).nullable().optional(),
  suggested_purchase: z.union([z.number(), z.string()]).nullable().optional(),
  // Suma de stock_balances.quantity_available del producto. Si el
  // listado se filtro por warehouse_id, este campo refleja SOLO ese
  // almacen. number | string para tolerancia.
  available_stock: z.union([z.number(), z.string()]).nullable().optional(),
  average_cost: z.union([z.number(), z.string()]).nullable().optional(),
  average_cost_visible: z.boolean().optional(),
  warranty_policy_id: z.number().int().nullable().optional(),
  warranty_policy: z
    .object({ id: z.number(), name: z.string(), duration_days: z.number(), coverage_type: z.string() })
    .nullable()
    .optional(),
  can_change_tracking_type: z.boolean().optional(),
  units_count: z.union([z.number(), z.string()]).optional(),
  is_active: z.boolean(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});
export type Product = z.infer<typeof ProductSchema>;

export const PaginatedProductsSchema = z.object({
  data: z.array(ProductSchema),
  meta: z.object({
    current_page: z.number(),
    from: z.number().optional(),
    last_page: z.number(),
    per_page: z.number(),
    to: z.number().optional(),
    total: z.number(),
  }),
  links: z
    .object({
      first: z.string().nullable().optional(),
      last: z.string().nullable().optional(),
      prev: z.string().nullable().optional(),
      next: z.string().nullable().optional(),
    })
    .optional(),
});
export type PaginatedProducts = z.infer<typeof PaginatedProductsSchema>;

// =====================================================================
// Detail (consolidado)
// =====================================================================

export const ProductStockSchema = z.object({
  warehouse_id: z.number().int().positive(),
  warehouse_code: z.string(),
  warehouse_name: z.string(),
  quantity: z.union([z.string(), z.number()]),
  reserved: z.union([z.string(), z.number()]).nullable().optional(),
  damaged: z.union([z.string(), z.number()]).nullable().optional(),
});
export type ProductStock = z.infer<typeof ProductStockSchema>;

export const ProductSerialSchema = z.object({
  id: z.number(),
  serial_number: z.string(),
  serial_type: z.string(),
  status: z.string(),
  warehouse_id: z.number().nullable().optional(),
  warehouse_name: z.string().nullable().optional(),
});
export type ProductSerial = z.infer<typeof ProductSerialSchema>;

export const ProductMovementSchema = z.object({
  id: z.number(),
  warehouse_id: z.number().nullable().optional(),
  warehouse_name: z.string().nullable().optional(),
  type: z.string(),
  quantity: z.union([z.string(), z.number()]),
  unit_cost: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  created_at: z.string(),
  user_name: z.string().nullable().optional(),
});
export type ProductMovement = z.infer<typeof ProductMovementSchema>;

/**
 * Schema del precio por lista (price_list_id) de un producto.
 * Shape real del backend (verificado 2026-07-14):
 * {
 *   "id": number,
 *   "tenant_id": number,
 *   "product_id": number,
 *   "price_list_id": number,
 *   "price_list": { id, name, code, is_default, is_active },
 *   "price": number,
 *   "currency": "USD" | "VES",
 *   "exchange_rate_type_id": number | null,
 *   "exchange_rate_type": object | null,
 *   "is_active": bool,
 *   "created_at": string,
 *   "updated_at": string,
 * }
 */
export const ProductPriceSchema = z
  .object({
    id: z.number(),
    tenant_id: z.number().int().optional(),
    product_id: z.number().int().optional(),
    price_list_id: z.number().int(),
    // Aceptamos ambas formas: price_list anidado (nuevo) o planos (legacy).
    price_list: z
      .object({
        id: z.number().int(),
        name: z.string(),
        code: z.string(),
        is_default: z.boolean().optional(),
        is_active: z.boolean().optional(),
      })
      .optional(),
    price_list_code: z.string().optional(),
    price_list_name: z.string().optional(),
    // El backend retorna `price` (number). Aceptamos `amount` (string) por
    // compatibilidad legacy. Normalizamos a `amount: string`.
    price: z.union([z.number(), z.string()]).optional(),
    amount: z.union([z.number(), z.string()]).optional(),
    currency: z.enum(SALE_CURRENCIES),
    exchange_rate: z.union([z.number(), z.string()]).nullable().optional(),
    exchange_rate_type_id: z.number().int().nullable().optional(),
    exchange_rate_type: z.unknown().nullable().optional(),
    is_active: z.boolean().optional(),
    created_at: z.string().optional(),
    updated_at: z.string().optional(),
  })
  .transform((p) => ({
    id: p.id,
    price_list_id: p.price_list_id,
    price_list_code:
      p.price_list?.code ?? p.price_list_code ?? '',
    price_list_name:
      p.price_list?.name ?? p.price_list_name ?? '',
    amount: String(p.price ?? p.amount ?? ''),
    currency: p.currency,
    exchange_rate: p.exchange_rate ?? null,
  }));
export type ProductPrice = z.infer<typeof ProductPriceSchema>;

export const ProductDetailSchema = z.object({
  product: ProductSchema,
  stock_by_warehouse: z.array(ProductStockSchema),
  serials: z.array(ProductSerialSchema),
  recent_movements: z.array(ProductMovementSchema),
  prices: z.array(ProductPriceSchema),
});
export type ProductDetail = z.infer<typeof ProductDetailSchema>;

// =====================================================================
// Lookups auxiliares (form schemas)
// =====================================================================

export const WarrantyPolicySchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  duration_days: z.number().int().optional(),
  coverage_type: z.string().optional(),
  conditions: z.string().nullable().optional(),
  is_active: z.boolean().optional(),
});
export type WarrantyPolicy = z.infer<typeof WarrantyPolicySchema>;

export const ExchangeRateTypeSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  is_default: z.boolean().optional(),
  is_active: z.boolean().optional(),
});
export type ExchangeRateType = z.infer<typeof ExchangeRateTypeSchema>;

export const WarehouseSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  branch_id: z.number().int().nullable().optional(),
  branch_name: z.string().nullable().optional(),
  status: z.string().optional(),
  is_active: z.boolean().optional(),
});
export type Warehouse = z.infer<typeof WarehouseSchema>;

export const PriceListSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  description: z.string().nullable().optional(),
  is_default: z.boolean().optional(),
  is_active: z.boolean(),
  sort_order: z.number().int().optional(),
  payment_method_ids: z.array(z.number().int()).optional(),
});
export type PriceList = z.infer<typeof PriceListSchema>;

export const BranchSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  status: z.string().optional(),
  is_active: z.boolean().optional(),
});
export type Branch = z.infer<typeof BranchSchema>;

// =====================================================================
// Exchange rate types (catalog: BCV, Paralelo, etc.)
// =====================================================================

export const EXCHANGE_RATE_CURRENCIES = ['USD', 'EUR', 'VES'] as const;

export const StoreExchangeRateTypeSchema = z
  .object({
    code: z
      .string()
      .max(50)
      .transform((s) => s.trim().toUpperCase())
      .refine((s) => s.length > 0, 'El codigo es obligatorio.'),
    name: z
      .string()
      .max(255)
      .transform((s) => s.trim())
      .refine((s) => s.length > 0, 'El nombre es obligatorio.'),
    is_default: z.boolean().optional(),
    is_active: z.boolean().optional(),
  })
  .transform((data) => ({
    ...data,
    is_default: data.is_default ?? false,
    is_active: data.is_active ?? true,
  }));
export type StoreExchangeRateTypeValues = z.output<typeof StoreExchangeRateTypeSchema>;

// =====================================================================
// Exchange rates (historico: BCV en fecha X, Paralelo en fecha Y, etc.)
// =====================================================================

export const StoreExchangeRateSchema = z
  .object({
    exchange_rate_type_id: z.coerce.number().int().positive(),
    base_currency: z.string().default('USD'),
    quote_currency: z.string().default('VES'),
    rate: z.coerce.number().positive(),
    effective_at: z
      .string()
      .min(1, 'La fecha efectiva es obligatoria.'),
    source: z.string().optional(),
    is_active: z.boolean().optional(),
  })
  .transform((data) => ({
    ...data,
    base_currency: data.base_currency ?? 'USD',
    quote_currency: data.quote_currency ?? 'VES',
    source: data.source ?? 'manual',
    is_active: data.is_active ?? true,
  }));
export type StoreExchangeRateValues = z.output<typeof StoreExchangeRateSchema>;

// =====================================================================
// Sucursales (Branches) -- prerequisito de Warehouses
// =====================================================================

export const StoreBranchSchema = z
  .object({
    name: z
      .string()
      .max(255)
      .transform((s) => s.trim())
      .refine((s) => s.length > 0, 'El nombre es obligatorio.'),
    code: z
      .string()
      .max(50)
      .transform((s) => s.trim().toUpperCase())
      .refine((s) => s.length > 0, 'El codigo es obligatorio.'),
    status: z.enum(['active', 'inactive']).default('active'),
  })
  .transform((data) => ({
    ...data,
    status: data.status ?? 'active',
  }));
export type StoreBranchValues = z.output<typeof StoreBranchSchema>;

// =====================================================================
// Almacenes (Warehouses)
// =====================================================================

export const StoreWarehouseSchema = z
  .object({
    branch_id: z.coerce.number().int().positive('La sucursal es obligatoria.'),
    name: z
      .string()
      .max(255)
      .transform((s) => s.trim())
      .refine((s) => s.length > 0, 'El nombre es obligatorio.'),
    code: z
      .string()
      .max(50)
      .transform((s) => s.trim().toUpperCase())
      .refine((s) => s.length > 0, 'El codigo es obligatorio.'),
    status: z.enum(['active', 'inactive']).default('active'),
  })
  .transform((data) => ({
    ...data,
    status: data.status ?? 'active',
  }));
export type StoreWarehouseValues = z.output<typeof StoreWarehouseSchema>;

// =====================================================================
// Politicas de garantia
// =====================================================================

export const WARRANTY_COVERAGE_TYPES = ['store', 'manufacturer', 'none'] as const;
export type WarrantyCoverageType = (typeof WARRANTY_COVERAGE_TYPES)[number];

export const WARRANTY_COVERAGE_LABELS: Record<WarrantyCoverageType, string> = {
  store: 'Tienda',
  manufacturer: 'Fabricante',
  none: 'Sin garantia',
};

export const StoreWarrantyPolicySchema = z
  .object({
    name: z
      .string()
      .min(1, 'El nombre es obligatorio.')
      .max(150),
    duration_days: z.coerce
      .number()
      .int('Debe ser un numero entero.')
      .min(0, 'No puede ser negativo.')
      .max(3650, 'Maximo 10 anos.'),
    coverage_type: z.enum(WARRANTY_COVERAGE_TYPES, {
      errorMap: () => ({ message: 'Tipo de cobertura invalido.' }),
    }),
    conditions: z
      .string()
      .max(2000)
      .optional()
      .transform((s) => (s?.trim() ? s.trim() : null)),
    is_active: z.boolean().default(true),
  })
  .transform((data) => ({
    ...data,
    is_active: data.is_active ?? true,
  }));
export type StoreWarrantyPolicyValues = z.output<typeof StoreWarrantyPolicySchema>;

// =====================================================================
// Listas de precios (Price Lists)
// =====================================================================

export const StorePriceListSchema = z
  .object({
    name: z
      .string()
      .min(1, 'El nombre es obligatorio.')
      .max(255),
    code: z
      .string()
      .min(1, 'El codigo es obligatorio.')
      .max(50)
      .transform((s) => s.trim().toUpperCase()),
    description: z
      .string()
      .max(1000)
      .optional()
      .transform((s) => (s?.trim() ? s.trim() : null)),
    is_default: z.boolean().optional(),
    is_active: z.boolean().default(true),
    sort_order: z.coerce.number().int().min(0).default(0),
    payment_method_ids: z.array(z.coerce.number().int().positive()).optional(),
  })
  .transform((data) => ({
    ...data,
    is_default: data.is_default ?? false,
    is_active: data.is_active ?? true,
    sort_order: data.sort_order ?? 0,
    payment_method_ids: data.payment_method_ids ?? [],
  }));
export type StorePriceListValues = z.output<typeof StorePriceListSchema>;

// =====================================================================
// Filter schemas (listado)
// =====================================================================

export const InventoryFiltersSchema = z.object({
  search: z.string().default(''),
  tracking_type: z.enum(['all', 'quantity', 'serialized']).default('all'),
  stock_status: z.enum(['all', 'available', 'low', 'critical', 'out', 'overstock']).default('all'),
  active_status: z.enum(['all', 'active', 'inactive']).default('active'),
  brand_id: z.coerce.number().int().positive().optional(),
  category_id: z.coerce.number().int().positive().optional(),
  tag_id: z.coerce.number().int().positive().optional(),
  warehouse_id: z.coerce.number().int().positive().optional(),
  low_stock_threshold: z.coerce.number().min(0).max(999999).optional(),
  page: z.coerce.number().int().min(1).default(1),
  per_page: z.coerce.number().int().min(1).max(50).default(25),
});
export type InventoryFilters = z.infer<typeof InventoryFiltersSchema>;