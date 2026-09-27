/**
 * Parser de códigos de barras generados por balanzas comerciales (charcutería, carnicería, víveres).
 *
 * Estándar EAN-13 in-store:
 * - 13 dígitos numéricos
 * - Prefijo: '20' (identificador de peso variable en tienda)
 * - Dígitos 3 a 7 (5 dígitos): Código del producto o ítem interno
 * - Dígitos 8 a 12 (5 dígitos): Peso neto en gramos (ej: '01500' = 1500g = 1.500 kg)
 * - Dígito 13: Dígito verificador de paridad EAN-13
 */

export interface ParsedScaleBarcode {
  isScaleBarcode: boolean;
  rawBarcode: string;
  itemCode: string;
  weightGrams: number;
  weightKg: number;
}

export function parseScaleBarcode(barcode: string): ParsedScaleBarcode | null {
  if (!barcode || typeof barcode !== 'string') {
    return null;
  }

  const clean = barcode.trim();
  if (clean.length !== 13 || !/^\d{13}$/.test(clean)) {
    return null;
  }

  // Verificar si empieza con el prefijo comercial de peso variable '20'
  if (!clean.startsWith('20')) {
    return null;
  }

  const itemCode = clean.substring(2, 7);
  const weightStr = clean.substring(7, 12);
  const weightGrams = parseInt(weightStr, 10);

  if (isNaN(weightGrams) || weightGrams <= 0) {
    return null;
  }

  const weightKg = Number((weightGrams / 1000).toFixed(4));

  return {
    isScaleBarcode: true,
    rawBarcode: clean,
    itemCode,
    weightGrams,
    weightKg,
  };
}
