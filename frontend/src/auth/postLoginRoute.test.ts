import { describe, expect, it } from 'vitest';

import { PERMISSIONS } from '@/permissions/constants';

import { getPostLoginRoute } from './postLoginRoute';

describe('getPostLoginRoute', () => {
  it('lleva al POS de armado a un perfil clonado de vendedor por sus permisos', () => {
    expect(
      getPostLoginRoute(
        ['Vendedor 1'],
        [PERMISSIONS.POS_VIEW, PERMISSIONS.POS_CHECKOUT, PERMISSIONS.POS_ORDERS_HOLD],
      ),
    ).toBe('/pos/armar');
  });

  it('lleva al POS de venta a cajeros con permisos de cobro', () => {
    expect(
      getPostLoginRoute(['Cajero'], [PERMISSIONS.POS_VIEW, PERMISSIONS.POS_CHECKOUT]),
    ).toBe('/pos');
  });

  it('mantiene al cajero en POS aunque tenga sales.create, si no puede poner órdenes en espera', () => {
    expect(
      getPostLoginRoute(['Cajero personalizado'], [
        PERMISSIONS.POS_VIEW,
        PERMISSIONS.POS_CHECKOUT,
        PERMISSIONS.SALES_CREATE,
      ]),
    ).toBe('/pos');
  });

  it('mantiene el dashboard para administradores aunque tengan permisos POS', () => {
    expect(
      getPostLoginRoute(['Administrador'], [PERMISSIONS.POS_VIEW, PERMISSIONS.POS_CHECKOUT]),
    ).toBe('/dashboard');
  });

  it('mantiene el dashboard si al operador le falta un permiso POS', () => {
    expect(getPostLoginRoute(['Cajero'], [PERMISSIONS.POS_VIEW])).toBe('/dashboard');
  });
});
