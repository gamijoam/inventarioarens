import { describe, it, expect, beforeEach } from 'vitest';
import {
  DEFAULT_INVENTORY_TABLE_COLUMNS,
  PRODUCT_AND_STOCK_COLUMNS,
  BARCODE_COUNTER_COLUMNS,
  SINGLE_PRICE_LIST_COLUMNS,
  ALL_INVENTORY_TABLE_COLUMNS,
  INVENTORY_COLUMN_PRESETS,
  INVENTORY_COLUMN_DEFINITIONS,
  getStoredInventoryColumnsVisibility,
  saveStoredInventoryColumnsVisibility,
  resetStoredInventoryColumnsVisibility,
} from '../inventoryColumnsConfig';

describe('inventoryColumnsConfig', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  describe('Presets', () => {
    it('DEFAULT preset shows essential columns with SKU and hides Barcode and Cost price', () => {
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.name).toBe(true);
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.sku).toBe(true);
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.barcode).toBe(false);
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.cost_price).toBe(false);
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.stock).toBe(true);
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.base_price).toBe(true);
      expect(DEFAULT_INVENTORY_TABLE_COLUMNS.price_list).toBe(true);
    });

    it('PRODUCT_AND_STOCK_COLUMNS preset only keeps name and stock', () => {
      expect(PRODUCT_AND_STOCK_COLUMNS.name).toBe(true);
      expect(PRODUCT_AND_STOCK_COLUMNS.stock).toBe(true);
      expect(PRODUCT_AND_STOCK_COLUMNS.sku).toBe(false);
      expect(PRODUCT_AND_STOCK_COLUMNS.barcode).toBe(false);
      expect(PRODUCT_AND_STOCK_COLUMNS.cost_price).toBe(false);
      expect(PRODUCT_AND_STOCK_COLUMNS.base_price).toBe(false);
      expect(PRODUCT_AND_STOCK_COLUMNS.price_list).toBe(false);
      expect(PRODUCT_AND_STOCK_COLUMNS.image).toBe(false);
      expect(PRODUCT_AND_STOCK_COLUMNS.is_active).toBe(false);
    });

    it('BARCODE_COUNTER_COLUMNS preset hides SKU and shows Barcode', () => {
      expect(BARCODE_COUNTER_COLUMNS.sku).toBe(false);
      expect(BARCODE_COUNTER_COLUMNS.barcode).toBe(true);
      expect(BARCODE_COUNTER_COLUMNS.cost_price).toBe(false);
      expect(BARCODE_COUNTER_COLUMNS.name).toBe(true);
      expect(BARCODE_COUNTER_COLUMNS.stock).toBe(true);
      expect(BARCODE_COUNTER_COLUMNS.base_price).toBe(true);
      expect(BARCODE_COUNTER_COLUMNS.price_list).toBe(false);
    });

    it('SINGLE_PRICE_LIST_COLUMNS shows price_list and hides base_price and cost_price', () => {
      expect(SINGLE_PRICE_LIST_COLUMNS.name).toBe(true);
      expect(SINGLE_PRICE_LIST_COLUMNS.stock).toBe(true);
      expect(SINGLE_PRICE_LIST_COLUMNS.cost_price).toBe(false);
      expect(SINGLE_PRICE_LIST_COLUMNS.base_price).toBe(false);
      expect(SINGLE_PRICE_LIST_COLUMNS.price_list).toBe(true);
    });

    it('ALL_INVENTORY_TABLE_COLUMNS enables all columns including cost_price', () => {
      expect(ALL_INVENTORY_TABLE_COLUMNS.cost_price).toBe(true);
      Object.values(ALL_INVENTORY_TABLE_COLUMNS).forEach((val) => {
        expect(val).toBe(true);
      });
    });

    it('defines 5 clear presets', () => {
      expect(INVENTORY_COLUMN_PRESETS).toHaveLength(5);
      const ids = INVENTORY_COLUMN_PRESETS.map((p) => p.id);
      expect(ids).toContain('default');
      expect(ids).toContain('product-stock');
      expect(ids).toContain('barcode-counter');
      expect(ids).toContain('price-list-only');
      expect(ids).toContain('all');

      const barcodePreset = INVENTORY_COLUMN_PRESETS.find((p) => p.id === 'barcode-counter');
      expect(barcodePreset?.name).toBe('Mostrador / Código');
    });

    it('INVENTORY_COLUMN_DEFINITIONS marks name as required and has barcode as Código', () => {
      const nameDef = INVENTORY_COLUMN_DEFINITIONS.find((d) => d.key === 'name');
      expect(nameDef).toBeDefined();
      expect(nameDef?.required).toBe(true);

      const barcodeDef = INVENTORY_COLUMN_DEFINITIONS.find((d) => d.key === 'barcode');
      expect(barcodeDef).toBeDefined();
      expect(barcodeDef?.label).toBe('Código');

      const costDef = INVENTORY_COLUMN_DEFINITIONS.find((d) => d.key === 'cost_price');
      expect(costDef).toBeDefined();
      expect(costDef?.label).toBe('Precio costo');
    });
  });

  describe('Storage persistence helpers', () => {
    it('returns DEFAULT_INVENTORY_TABLE_COLUMNS when storage is empty', () => {
      const result = getStoredInventoryColumnsVisibility(1);
      expect(result).toEqual(DEFAULT_INVENTORY_TABLE_COLUMNS);
    });

    it('saves and recovers visibility per tenantId', () => {
      saveStoredInventoryColumnsVisibility(BARCODE_COUNTER_COLUMNS, 42);
      const recovered = getStoredInventoryColumnsVisibility(42);
      expect(recovered.barcode).toBe(true);
      expect(recovered.sku).toBe(false);

      // Other tenant has defaults
      const otherTenant = getStoredInventoryColumnsVisibility(99);
      expect(otherTenant.barcode).toBe(false);
      expect(otherTenant.sku).toBe(true);
    });

    it('always forces name column to true even if stored as false', () => {
      saveStoredInventoryColumnsVisibility({ ...BARCODE_COUNTER_COLUMNS, name: false }, 10);
      const recovered = getStoredInventoryColumnsVisibility(10);
      expect(recovered.name).toBe(true);
    });

    it('resets stored visibility to default', () => {
      saveStoredInventoryColumnsVisibility(PRODUCT_AND_STOCK_COLUMNS, 5);
      expect(getStoredInventoryColumnsVisibility(5).sku).toBe(false);

      resetStoredInventoryColumnsVisibility(5);
      expect(getStoredInventoryColumnsVisibility(5).sku).toBe(true);
    });
  });
});
