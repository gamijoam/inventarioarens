import { describe, expect, it, beforeEach, afterEach } from 'vitest';

import appMode from './app-mode.cjs';

const { detectAppMode, normalizeAppMode } = appMode;

const ORIGINAL_ENV = { ...process.env };
const ORIGINAL_EXEC_PATH = process.execPath;

beforeEach(() => {
  delete process.env.INVENTARIO_APP_MODE;
});

afterEach(() => {
  process.env = { ...ORIGINAL_ENV };
  Object.defineProperty(process, 'execPath', { value: ORIGINAL_EXEC_PATH, configurable: true });
});

describe('App mode detection', () => {
  describe('normalizeAppMode', () => {
    it('returns "pos" only for the exact "pos" value', () => {
      expect(normalizeAppMode('pos')).toBe('pos');
    });

    it('falls back to "admin" for unknown, null, or undefined values', () => {
      expect(normalizeAppMode(undefined)).toBe('admin');
      expect(normalizeAppMode(null)).toBe('admin');
      expect(normalizeAppMode('')).toBe('admin');
      expect(normalizeAppMode('unknown')).toBe('admin');
      expect(normalizeAppMode('POS')).toBe('admin');
    });
  });

  describe('detectAppMode', () => {
    it('prefers the INVENTARIO_APP_MODE env var when set', () => {
      process.env.INVENTARIO_APP_MODE = 'admin';
      Object.defineProperty(process, 'execPath', {
        value: 'C:\\Apps\\Sistema-de-Inventario-POS.exe',
        configurable: true,
      });
      expect(detectAppMode()).toBe('admin');
    });

    it('detects pos mode from an executable whose name contains "pos"', () => {
      Object.defineProperty(process, 'execPath', {
        value:
          'C:\\Users\\gafit\\AppData\\Local\\Programs\\InventarioArens POS\\Sistema-de-Inventario-POS.exe',
        configurable: true,
      });
      expect(detectAppMode()).toBe('pos');
    });

    it('detects admin mode from an executable whose name contains "administrativo"', () => {
      Object.defineProperty(process, 'execPath', {
        value:
          'C:\\Users\\gafit\\AppData\\Local\\Programs\\InventarioArens Administrativo\\Sistema-de-Inventario-Administrativo.exe',
        configurable: true,
      });
      expect(detectAppMode()).toBe('admin');
    });

    it('detects pos mode even when the path contains other words', () => {
      Object.defineProperty(process, 'execPath', {
        value: 'D:\\some\\other\\path\\POS.exe',
        configurable: true,
      });
      expect(detectAppMode()).toBe('pos');
    });

    it('falls back to admin when neither env var nor exe name matches', () => {
      Object.defineProperty(process, 'execPath', {
        value: 'C:\\Apps\\inventarioarens-frontend\\inventarioarens-frontend.exe',
        configurable: true,
      });
      expect(detectAppMode()).toBe('admin');
    });

    it('handles linux-style paths and posix separators', () => {
      Object.defineProperty(process, 'execPath', {
        value: '/opt/inventarioarens/Sistema-de-Inventario-POS',
        configurable: true,
      });
      expect(detectAppMode()).toBe('pos');
    });

    it('is case-insensitive when matching the executable name', () => {
      Object.defineProperty(process, 'execPath', {
        value: 'C:\\Apps\\POS.exe',
        configurable: true,
      });
      expect(detectAppMode()).toBe('pos');
    });
  });
});
