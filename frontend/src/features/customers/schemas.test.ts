import { describe, it, expect } from 'vitest';
import {
  StoreCustomerSchema,
  CUSTOMER_DOCUMENT_TYPES,
  CUSTOMER_DOCUMENT_LABELS,
} from './schemas';

describe('StoreCustomerSchema', () => {
  it('happy path: cliente venezolano', () => {
    const result = StoreCustomerSchema.parse({
      name: 'Juan Perez',
      document_type: 'V',
      document_number: '12345678',
      phone: '+58 412 1234567',
      email: 'juan@correo.com',
      fiscal_address: 'Av. Principal',
      is_active: true,
    });
    expect(result.name).toBe('Juan Perez');
    expect(result.document_type).toBe('V');
    expect(result.document_number).toBe('12345678');
    expect(result.is_active).toBe(true);
    expect(result.is_generic).toBe(false);
  });

  it('rechaza nombre vacio', () => {
    expect(() =>
      StoreCustomerSchema.parse({ name: '', document_type: 'V', document_number: '1' }),
    ).toThrow();
  });

  it('rechaza document_type invalido', () => {
    expect(() =>
      StoreCustomerSchema.parse({ name: 'X', document_type: 'XYZ', document_number: '1' }),
    ).toThrow();
  });

  it('rechaza document_number vacio', () => {
    expect(() =>
      StoreCustomerSchema.parse({ name: 'X', document_type: 'V', document_number: '' }),
    ).toThrow();
  });

  it('rechaza email invalido', () => {
    expect(() =>
      StoreCustomerSchema.parse({
        name: 'X',
        document_type: 'V',
        document_number: '1',
        email: 'no-es-email',
      }),
    ).toThrow();
  });

  it('default is_active=true e is_generic=false', () => {
    const result = StoreCustomerSchema.parse({
      name: 'X',
      document_type: 'J',
      document_number: '12345678-0',
    });
    expect(result.is_active).toBe(true);
    expect(result.is_generic).toBe(false);
  });

  it('is_generic=true para "Cliente generico"', () => {
    const result = StoreCustomerSchema.parse({
      name: 'Cliente generico',
      document_type: 'V',
      document_number: '0',
      is_generic: true,
    });
    expect(result.is_generic).toBe(true);
  });

  it('acepta todos los tipos de documento', () => {
    for (const t of CUSTOMER_DOCUMENT_TYPES) {
      const result = StoreCustomerSchema.parse({
        name: 'Test',
        document_type: t,
        document_number: '123',
      });
      expect(result.document_type).toBe(t);
    }
  });

  it('labels tienen todos los tipos', () => {
    for (const t of CUSTOMER_DOCUMENT_TYPES) {
      expect(CUSTOMER_DOCUMENT_LABELS[t]).toBeTruthy();
    }
  });
});
