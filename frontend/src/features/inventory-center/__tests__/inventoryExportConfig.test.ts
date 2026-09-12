import { describe, it, expect, beforeEach } from 'vitest';
import {
  DEFAULT_EXPORT_COLUMNS,
  INVENTORY_EXPORT_COLUMNS,
  INVENTORY_EXPORT_PRESETS,
  getStoredExportColumns,
  saveStoredExportColumns,
} from '../inventoryExportConfig';

describe('inventoryExportConfig', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  describe('Presets', () => {
    it('DEFAULT_EXPORT_COLUMNS contains standard operational columns', () => {
      expect(DEFAULT_EXPORT_COLUMNS).toContain('name');
      expect(DEFAULT_EXPORT_COLUMNS).toContain('sku');
      expect(DEFAULT_EXPORT_COLUMNS).toContain('stock_available');
      expect(DEFAULT_EXPORT_COLUMNS).toContain('base_price');
      expect(DEFAULT_EXPORT_COLUMNS).toContain('stock_status');
    });

    it('only_names preset only includes the product name', () => {
      const preset = INVENTORY_EXPORT_PRESETS.find((p) => p.id === 'only_names');
      expect(preset).toBeDefined();
      expect(preset?.columns).toEqual(['name']);
    });

    it('name_and_sku preset only includes name and sku', () => {
      const preset = INVENTORY_EXPORT_PRESETS.find((p) => p.id === 'name_and_sku');
      expect(preset).toBeDefined();
      expect(preset?.columns).toEqual(['name', 'sku']);
    });

    it('name_and_barcode preset only includes name and barcode', () => {
      const preset = INVENTORY_EXPORT_PRESETS.find((p) => p.id === 'name_and_barcode');
      expect(preset).toBeDefined();
      expect(preset?.columns).toEqual(['name', 'barcode']);
    });

    it('name_and_stock preset only includes name and stock_available', () => {
      const preset = INVENTORY_EXPORT_PRESETS.find((p) => p.id === 'name_and_stock');
      expect(preset).toBeDefined();
      expect(preset?.columns).toEqual(['name', 'stock_available']);
    });

    it('all preset includes all configured export columns', () => {
      const preset = INVENTORY_EXPORT_PRESETS.find((p) => p.id === 'all');
      expect(preset).toBeDefined();
      expect(preset?.columns.length).toBe(INVENTORY_EXPORT_COLUMNS.length);
    });
  });

  describe('Storage and Persistence', () => {
    it('returns DEFAULT_EXPORT_COLUMNS when localStorage is empty', () => {
      const stored = getStoredExportColumns(1);
      expect(stored).toEqual(DEFAULT_EXPORT_COLUMNS);
    });

    it('persists customized columns per tenant', () => {
      saveStoredExportColumns(['name', 'barcode'], 1);
      expect(getStoredExportColumns(1)).toEqual(['name', 'barcode']);

      // Tenant 2 still gets default
      expect(getStoredExportColumns(2)).toEqual(DEFAULT_EXPORT_COLUMNS);
    });

    it('always preserves name as required even if caller tries to omit it', () => {
      saveStoredExportColumns(['sku', 'barcode'], 1);
      const stored = getStoredExportColumns(1);
      expect(stored).toContain('name');
    });

    it('filters out invalid column keys safely from localStorage', () => {
      window.localStorage.setItem(
        'inventory_export_columns_1',
        JSON.stringify(['name', 'invalid_key', 'barcode']),
      );
      const stored = getStoredExportColumns(1);
      expect(stored).toEqual(['name', 'barcode']);
    });
  });
});
