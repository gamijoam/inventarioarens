/**
 * Schemas Zod + tipos para el Centro de Inventario.
 * Reflejan la respuesta de GET /api/inventory-center/* y /api/products.
 */
import { z } from 'zod';

export const ProductSchema = z.object({
  id: z.number().int().positive(),
  sku: z.string(),
  name: z.string(),
  description: z.string().nullable().optional(),
  tracking_type: z.enum(['quantity', 'serialized']),
  base_price: z.string(),
  sale_currency: z.enum(['USD', 'VES']).nullable().optional(),
  is_active: z.boolean(),
  is_sellable: z.boolean().optional(),
  has_warranty: z.boolean().optional(),
  stock_available: z.union([z.string(), z.number()]).nullable().optional(),
  stock_reserved: z.union([z.string(), z.number()]).nullable().optional(),
  stock_damaged: z.union([z.string(), z.number()]).nullable().optional(),
  total_stock: z.union([z.string(), z.number()]).nullable().optional(),
  has_base_price: z.boolean().optional(),
  updated_at: z.string().nullable().optional(),
});

export type Product = z.infer<typeof ProductSchema>;

export const PaginatedProductsSchema = z.object({
  data: z.array(ProductSchema),
  meta: z.object({
    current_page: z.number(),
    per_page: z.number(),
    total: z.number(),
    last_page: z.number(),
  }),
});

export type PaginatedProducts = z.infer<typeof PaginatedProductsSchema>;

export const InventoryFiltersSchema = z.object({
  search: z.string().default(''),
  tracking_type: z.enum(['all', 'quantity', 'serialized']).default('all'),
  stock_status: z.enum(['all', 'available', 'low', 'none']).default('all'),
  status: z.enum(['all', 'active', 'inactive']).default('all'),
  page: z.number().int().positive().default(1),
  per_page: z.number().int().min(10).max(50).default(25),
});

export type InventoryFilters = z.infer<typeof InventoryFiltersSchema>;

export const ProductStockSchema = z.object({
  warehouse_id: z.number(),
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

export const ProductPriceSchema = z.object({
  id: z.number(),
  price_list_id: z.number(),
  price_list_code: z.string(),
  price_list_name: z.string(),
  amount: z.string(),
  currency: z.enum(['USD', 'VES']),
  exchange_rate: z.string().nullable().optional(),
});

export type ProductPrice = z.infer<typeof ProductPriceSchema>;

export const ProductDetailSchema = z.object({
  product: ProductSchema,
  stock_by_warehouse: z.array(ProductStockSchema),
  serials: z.array(ProductSerialSchema),
  recent_movements: z.array(ProductMovementSchema),
  prices: z.array(ProductPriceSchema),
});

export type ProductDetail = z.infer<typeof ProductDetailSchema>;