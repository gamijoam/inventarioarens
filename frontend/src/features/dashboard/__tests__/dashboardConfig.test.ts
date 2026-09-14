import { beforeEach, describe, expect, it } from 'vitest';

import {
  DEFAULT_DASHBOARD_VISIBILITY,
  getStoredDashboardVisibility,
  resetStoredDashboardVisibility,
  saveStoredDashboardVisibility,
} from '../dashboardConfig';

describe('dashboardConfig persistence', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('devuelve la configuración por defecto si no hay nada guardado', () => {
    const visibility = getStoredDashboardVisibility(1);
    expect(visibility).toEqual(DEFAULT_DASHBOARD_VISIBILITY);
    expect(visibility.inventory_value).toBe(true);
    expect(visibility.sales).toBe(true);
  });

  it('guarda y recupera la configuración de visibilidad por tenant', () => {
    const custom = {
      ...DEFAULT_DASHBOARD_VISIBILITY,
      sales: false,
      payables: false,
      inventory_value: true,
    };

    saveStoredDashboardVisibility(custom, 5);

    // Tenant 5 tiene la personalizada
    expect(getStoredDashboardVisibility(5)).toEqual(custom);

    // Tenant 99 tiene los defaults (aislamiento multi-tenant)
    expect(getStoredDashboardVisibility(99)).toEqual(DEFAULT_DASHBOARD_VISIBILITY);
  });

  it('restablece la configuración por defecto para un tenant', () => {
    saveStoredDashboardVisibility(
      { ...DEFAULT_DASHBOARD_VISIBILITY, sales: false },
      10,
    );

    expect(getStoredDashboardVisibility(10).sales).toBe(false);

    resetStoredDashboardVisibility(10);

    expect(getStoredDashboardVisibility(10)).toEqual(DEFAULT_DASHBOARD_VISIBILITY);
  });
});
