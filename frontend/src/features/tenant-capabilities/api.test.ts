import { describe, expect, it, vi } from 'vitest';

import { getOne, patchOne } from '@/api/client';

import { getTenantCapabilities, TenantCapabilitiesSchema, updateTenantCapabilities } from './api';

vi.mock('@/api/client', () => ({
  getOne: vi.fn(),
  patchOne: vi.fn(),
}));

const response = {
  tenant_id: 7,
  enabled: ['dashboard', 'catalog', 'inventory', 'customers', 'suppliers', 'pos'],
  capabilities: [
    {
      key: 'dashboard',
      label: 'Dashboard',
      description: 'Resumen operativo.',
      required: true,
      enabled: true,
    },
    {
      key: 'pos',
      label: 'POS',
      description: 'Venta de mostrador.',
      required: false,
      enabled: true,
    },
  ],
};

describe('tenant capabilities API contract', () => {
  it('parses the real GET response shape', async () => {
    vi.mocked(getOne).mockResolvedValue(response);

    const result = await getTenantCapabilities();

    expect(result).toEqual(response);
    expect(getOne).toHaveBeenCalledWith('/tenant-capabilities');
  });

  it('sends the complete enabled capability list and parses the response', async () => {
    vi.mocked(patchOne).mockResolvedValue(response);

    const result = await updateTenantCapabilities(['pos']);

    expect(result).toEqual(response);
    expect(patchOne).toHaveBeenCalledWith('/tenant-capabilities', { capabilities: ['pos'] });
  });

  it('rejects malformed capability responses before they reach the UI', () => {
    const parsed = TenantCapabilitiesSchema.safeParse({
      tenant_id: 7,
      enabled: ['pos'],
      capabilities: [{ key: 'pos', enabled: true }],
    });

    expect(parsed.success).toBe(false);
  });
});
