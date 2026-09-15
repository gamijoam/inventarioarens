import { beforeEach, describe, expect, it } from 'vitest';
import {
  hashPin,
  hasDashboardPin,
  setDashboardPin,
  verifyDashboardPin,
  removeDashboardPin,
  getStoredDashboardMasked,
  saveStoredDashboardMasked,
} from '../dashboardSecurity';

describe('dashboardSecurity', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('genera un hash consistente para el mismo PIN y diferente para PINs distintos', async () => {
    const hash1 = await hashPin('1234');
    const hash2 = await hashPin('1234');
    const hash3 = await hashPin('5678');

    expect(hash1).toBeTruthy();
    expect(hash1).toBe(hash2);
    expect(hash1).not.toBe(hash3);
  });

  it('almacena, verifica y remueve el PIN por tenant', async () => {
    const tenantA = 101;
    const tenantB = 202;

    expect(hasDashboardPin(tenantA)).toBe(false);

    await setDashboardPin('4321', tenantA);
    expect(hasDashboardPin(tenantA)).toBe(true);
    expect(hasDashboardPin(tenantB)).toBe(false);

    // Verificación de PIN correcto vs incorrecto
    const isValidTenantA = await verifyDashboardPin('4321', tenantA);
    const isInvalidTenantA = await verifyDashboardPin('0000', tenantA);
    expect(isValidTenantA).toBe(true);
    expect(isInvalidTenantA).toBe(false);

    // Remoción de PIN
    removeDashboardPin(tenantA);
    expect(hasDashboardPin(tenantA)).toBe(false);
  });

  it('persiste el estado de ocultamiento (isMasked) por tenant', () => {
    const tenantId = 105;

    expect(getStoredDashboardMasked(tenantId)).toBe(false);

    saveStoredDashboardMasked(true, tenantId);
    expect(getStoredDashboardMasked(tenantId)).toBe(true);

    saveStoredDashboardMasked(false, tenantId);
    expect(getStoredDashboardMasked(tenantId)).toBe(false);
  });
});
