import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetOne = vi.fn();
const mockPatchOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: (path: string): Promise<unknown> => mockGetOne(path) as Promise<unknown>,
  patchOne: (path: string, body: unknown): Promise<unknown> =>
    mockPatchOne(path, body) as Promise<unknown>,
}));

import {
  FiscalIdentitySchema,
  getFiscalIdentity,
  updateBranchFiscalIdentity,
  updateFiscalIdentity,
} from './api';

describe('fiscal identity api', () => {
  beforeEach(() => {
    mockGetOne.mockReset();
    mockPatchOne.mockReset();
  });

  it('parsea la respuesta real con campos fiscales y timestamps nullable', () => {
    const response = {
      tenant: {
        id: 1,
        legal_name: null,
        tax_id: 'J-12345678-9',
        fiscal_address: null,
        city: null,
        state: null,
        phone: null,
        email: null,
        tax_condition: 'ordinary',
      },
      branches: [
        {
          id: 10,
          tenant_id: 1,
          name: 'Sucursal Centro',
          code: 'CENTRO',
          status: 'active',
          fiscal_address: null,
          city: null,
          state: null,
          phone: null,
          email: null,
          tax_condition: null,
          created_at: null,
          updated_at: null,
        },
      ],
    };

    expect(FiscalIdentitySchema.parse(response)).toEqual(response);
  });

  it('getFiscalIdentity parsea la respuesta del endpoint', async () => {
    mockGetOne.mockResolvedValue({
      tenant: {
        id: 1,
        legal_name: 'Empresa Fiscal',
        tax_id: 'J-12345678-9',
        fiscal_address: 'Av. Principal',
        city: 'Caracas',
        state: 'Distrito Capital',
        phone: null,
        email: null,
        tax_condition: 'formal',
      },
      branches: [],
    });

    await expect(getFiscalIdentity()).resolves.toMatchObject({
      tenant: { legal_name: 'Empresa Fiscal', tax_condition: 'formal' },
      branches: [],
    });
    expect(mockGetOne).toHaveBeenCalledWith('/fiscal/identity');
  });

  it('envia la identidad de empresa y sucursal a sus endpoints', async () => {
    mockPatchOne.mockResolvedValueOnce({
      tenant: {
        id: 1,
        legal_name: 'Empresa Fiscal',
        tax_id: 'J-12345678-9',
        fiscal_address: null,
        city: null,
        state: null,
        phone: null,
        email: null,
        tax_condition: 'ordinary',
      },
      branches: [],
    });
    mockPatchOne.mockResolvedValueOnce({
      id: 10,
      tenant_id: 1,
      name: 'Sucursal Centro',
      code: 'CENTRO',
      status: 'active',
      fiscal_address: 'Local 1',
      city: null,
      state: null,
      phone: null,
      email: null,
      tax_condition: 'formal',
      created_at: null,
      updated_at: null,
    });

    await updateFiscalIdentity({ legal_name: 'Empresa Fiscal', tax_condition: 'ordinary' });
    await updateBranchFiscalIdentity(10, { fiscal_address: 'Local 1', tax_condition: 'formal' });

    expect(mockPatchOne).toHaveBeenNthCalledWith(1, '/fiscal/identity', {
      legal_name: 'Empresa Fiscal',
      tax_condition: 'ordinary',
    });
    expect(mockPatchOne).toHaveBeenNthCalledWith(2, '/fiscal/identity/branches/10', {
      fiscal_address: 'Local 1',
      tax_condition: 'formal',
    });
  });
});
