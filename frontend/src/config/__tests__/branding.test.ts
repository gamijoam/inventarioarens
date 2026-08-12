import { describe, expect, it } from 'vitest';

import {
  APP_DEFINITIONS,
  APP_MODE,
  getAppDefinition,
  isRouteAllowedForAppMode,
  resolveAppMode,
} from '../branding';

describe('application branding modes', () => {
  it('define los nombres visibles del Administrativo y del POS', () => {
    expect(APP_DEFINITIONS.admin.name).toBe('Sistema de Inventario (Administrativo)');
    expect(APP_DEFINITIONS.pos.name).toBe('POS');
    expect(APP_DEFINITIONS.technician.name).toBe('Soporte Técnico');
    expect(getAppDefinition('admin')).toEqual(APP_DEFINITIONS.admin);
    expect(getAppDefinition('pos')).toEqual(APP_DEFINITIONS.pos);
  });

  it('usa Administrativo como modo seguro por defecto', () => {
    expect(resolveAppMode(undefined)).toBe('admin');
    expect(resolveAppMode('unknown')).toBe('admin');
    expect(APP_MODE).toBe('admin');
  });

  it('acepta POS como modo explícito de compilación', () => {
    expect(resolveAppMode('pos')).toBe('pos');
    expect(resolveAppMode('POS')).toBe('pos');
  });

  it('acepta Soporte Técnico como modo explícito de compilación', () => {
    expect(resolveAppMode('technician')).toBe('technician');
    expect(resolveAppMode('TECHNICIAN')).toBe('technician');
  });

  it('limita el modo POS a sus rutas operativas sin restringir el Administrativo', () => {
    expect(isRouteAllowedForAppMode('pos', '/pos')).toBe(true);
    expect(isRouteAllowedForAppMode('pos', '/pos/receipt')).toBe(true);
    expect(isRouteAllowedForAppMode('pos', '/pos/armar')).toBe(true);
    expect(isRouteAllowedForAppMode('pos', '/inventory')).toBe(false);
    expect(isRouteAllowedForAppMode('admin', '/inventory')).toBe(true);
    expect(isRouteAllowedForAppMode('admin', '/pos')).toBe(true);
    expect(isRouteAllowedForAppMode('technician', '/support')).toBe(true);
    expect(isRouteAllowedForAppMode('technician', '/dashboard')).toBe(false);
  });
});
