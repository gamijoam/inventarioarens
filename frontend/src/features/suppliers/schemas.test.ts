import { describe, it, expect } from 'vitest';
import {
  StoreSupplierSchema,
  SUPPLIER_DOCUMENT_TYPES,
  SUPPLIER_DOCUMENT_LABELS,
} from './schemas';

describe('StoreSupplierSchema', () => {
  it('happy path: proveedor con documento J', () => {
    const result = StoreSupplierSchema.parse({
      name: 'Distribuidora XYZ',
      document_type: 'J',
      document_number: '12345678-0',
      phone: '+58 212 1234567',
      email: 'ventas@xyz.com',
      notes: 'Pago a 30 dias',
      is_active: true,
    });
    expect(result.name).toBe('Distribuidora XYZ');
    expect(result.document_type).toBe('J');
    expect(result.document_number).toBe('12345678-0');
    expect(result.is_active).toBe(true);
  });

  it('proveedor informal sin documento (no envia campos)', () => {
    const result = StoreSupplierSchema.parse({
      name: 'Vendedor ambulante',
    });
    expect(result.document_type).toBeUndefined();
    expect(result.document_number).toBeNull();
  });

  it('rechaza nombre vacio', () => {
    expect(() => StoreSupplierSchema.parse({ name: '' })).toThrow();
  });

  it('acepta sin email', () => {
    const result = StoreSupplierSchema.parse({
      name: 'X',
      email: '',
    });
    expect(result.email).toBeNull();
  });

  it('rechaza email invalido', () => {
    expect(() =>
      StoreSupplierSchema.parse({ name: 'X', email: 'no-es-email' }),
    ).toThrow();
  });

  it('default is_active=true', () => {
    const result = StoreSupplierSchema.parse({ name: 'X' });
    expect(result.is_active).toBe(true);
  });

  it('acepta todos los tipos de documento', () => {
    for (const t of SUPPLIER_DOCUMENT_TYPES) {
      const result = StoreSupplierSchema.parse({
        name: 'Test',
        document_type: t,
        document_number: '123',
      });
      expect(result.document_type).toBe(t);
    }
  });

  it('labels tienen todos los tipos', () => {
    for (const t of SUPPLIER_DOCUMENT_TYPES) {
      expect(SUPPLIER_DOCUMENT_LABELS[t]).toBeTruthy();
    }
  });

  it('phone y notes vacios se normalizan a null', () => {
    const result = StoreSupplierSchema.parse({
      name: 'X',
      phone: '   ',
      notes: '',
    });
    expect(result.phone).toBeNull();
    expect(result.notes).toBeNull();
  });
});
