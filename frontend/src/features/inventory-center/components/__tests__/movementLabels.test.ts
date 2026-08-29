/**
 * Tests de movementLabels: traduccion de tipos de StockMovement y
 * reference_type para las columnas "Tipo" y "Ref." de Movimientos/Kardex.
 */
import { describe, it, expect } from 'vitest';
import {
  movementTypeLabel,
  MOVEMENT_IN_TYPES,
  referenceTypeLabel,
  referenceLink,
} from '../../movementLabels';

describe('movementTypeLabel', () => {
  it('traduce los tipos de movimiento mas comunes', () => {
    expect(movementTypeLabel('purchase')).toBe('Compra');
    expect(movementTypeLabel('sale')).toBe('Venta');
    expect(movementTypeLabel('adjustment_in')).toBe('Ajuste de entrada');
    expect(movementTypeLabel('adjustment_out')).toBe('Ajuste de salida');
    expect(movementTypeLabel('transfer_in')).toBe('Traslado (entrada)');
    expect(movementTypeLabel('transfer_out')).toBe('Traslado (salida)');
    expect(movementTypeLabel('transfer_request_in')).toBe('Transferencia inter-empresa (entrada)');
    expect(movementTypeLabel('transfer_request_out')).toBe('Transferencia inter-empresa (salida)');
    expect(movementTypeLabel('damaged')).toBe('Dañado');
    expect(movementTypeLabel('reserved')).toBe('Reservado');
    expect(movementTypeLabel('released')).toBe('Liberado');
  });

  it('transforma tipos desconocidos con guiones a espacios', () => {
    expect(movementTypeLabel('stock_take')).toBe('stock take');
  });
});

describe('MOVEMENT_IN_TYPES', () => {
  it('marca las entradas como in (verde)', () => {
    expect(MOVEMENT_IN_TYPES.has('purchase')).toBe(true);
    expect(MOVEMENT_IN_TYPES.has('sale_return')).toBe(true);
    expect(MOVEMENT_IN_TYPES.has('adjustment_in')).toBe(true);
    expect(MOVEMENT_IN_TYPES.has('transfer_in')).toBe(true);
    expect(MOVEMENT_IN_TYPES.has('transfer_request_in')).toBe(true);
  });

  it('no marca las salidas como in', () => {
    expect(MOVEMENT_IN_TYPES.has('sale')).toBe(false);
    expect(MOVEMENT_IN_TYPES.has('adjustment_out')).toBe(false);
    expect(MOVEMENT_IN_TYPES.has('transfer_out')).toBe(false);
    expect(MOVEMENT_IN_TYPES.has('damaged')).toBe(false);
  });
});

describe('referenceTypeLabel', () => {
  it('traduce nombres cortos de clase', () => {
    expect(referenceTypeLabel('InventoryTransfer')).toBe('Traslado');
    expect(referenceTypeLabel('PurchaseOrder')).toBe('Orden de compra');
    expect(referenceTypeLabel('Sale')).toBe('Venta');
    expect(referenceTypeLabel('sync_snapshot')).toBe('Sincronización');
  });

  it('traduce FQCN completos extrayendo el nombre corto', () => {
    expect(referenceTypeLabel('App\\Modules\\InventoryTransfers\\Models\\InventoryTransfer')).toBe('Traslado');
    expect(referenceTypeLabel('App\\Modules\\Sales\\Models\\PosOrder')).toBe('Venta POS');
  });

  it('devuelve — para valores nulos', () => {
    expect(referenceTypeLabel(null)).toBe('—');
    expect(referenceTypeLabel(undefined)).toBe('—');
  });

  it('mejora clases desconocidas con camelCase a espacios', () => {
    expect(referenceTypeLabel('SomeUnknownRef')).toBe('Some Unknown Ref');
  });
});

describe('referenceLink', () => {
  it('mapea traslados internos (FQCN incluido) a su ruta', () => {
    expect(referenceLink('InventoryTransfer', 7)).toEqual({
      label: 'Traslado',
      to: '/transfers/7',
    });
    expect(referenceLink('App\\Modules\\InventoryTransfers\\Models\\InventoryTransfer', 7)).toEqual({
      label: 'Traslado',
      to: '/transfers/7',
    });
  });

  it('mapea solicitudes inter-empresa y ventas', () => {
    expect(referenceLink('InventoryTransferRequest', 42)).toEqual({
      label: 'Solicitud inter-empresa',
      to: '/inventory-transfer-requests/42',
    });
    expect(referenceLink('PosOrder', 9)).toEqual({ label: 'Venta', to: '/sales/9' });
  });

  it('retorna null para referencias sin ruta o sin id', () => {
    expect(referenceLink('sync_snapshot', 1)).toBeNull();
    expect(referenceLink('InventoryTransfer', null)).toBeNull();
    expect(referenceLink(null, 1)).toBeNull();
  });
});
