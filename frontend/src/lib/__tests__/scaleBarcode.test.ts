import { describe, expect, it } from 'vitest';
import { parseScaleBarcode } from '../scaleBarcode';

describe('scaleBarcode parser', () => {
  it('identifica y desglosa un código EAN-13 válido de balanza con peso en gramos', () => {
    // 20 + 00123 (item 123) + 01500 (1.500 kg) + checksum
    // Checksum de "200012301500":
    // Pares e impares: calculado por mod-10
    const parsed = parseScaleBarcode('2000123015004');
    expect(parsed).not.toBeNull();
    expect(parsed?.isScaleBarcode).toBe(true);
    expect(parsed?.itemCode).toBe('00123');
    expect(parsed?.weightKg).toBe(1.5);
    expect(parsed?.weightGrams).toBe(1500);
  });

  it('desglosa correctamente peso decimal pequeño (ej: 250 gramos de queso)', () => {
    // 20 + 00045 + 00250 + checksum
    const parsed = parseScaleBarcode('2000045002500');
    expect(parsed).not.toBeNull();
    expect(parsed?.isScaleBarcode).toBe(true);
    expect(parsed?.itemCode).toBe('00045');
    expect(parsed?.weightKg).toBe(0.25);
    expect(parsed?.weightGrams).toBe(250);
  });

  it('ignora códigos que no tienen 13 dígitos o no empiezan con 20', () => {
    expect(parseScaleBarcode('7591234567890')).toBeNull(); // EAN-13 normal
    expect(parseScaleBarcode('20123')).toBeNull(); // Corto
    expect(parseScaleBarcode('12345678')).toBeNull(); // EAN-8
    expect(parseScaleBarcode('')).toBeNull();
    expect(parseScaleBarcode('ABC2000123015')).toBeNull();
  });
});
