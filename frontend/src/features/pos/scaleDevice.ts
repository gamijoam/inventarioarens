export type ScaleTransportStatus = 'ready' | 'waiting_for_reading' | 'stale';

export interface ScaleReading {
  ok: boolean;
  status: ScaleTransportStatus;
  weight?: number;
  unit?: string;
  is_stable?: boolean;
  timestamp?: string;
}

const weightUnitFactors: Record<string, number> = {
  kg: 1,
  kilogram: 1,
  kilograms: 1,
  kilogramo: 1,
  kilogramos: 1,
  kgs: 1,
  g: 0.001,
  gr: 0.001,
  gram: 0.001,
  grams: 0.001,
  gramo: 0.001,
  gramos: 0.001,
  mg: 0.000001,
  lb: 0.45359237,
  lbs: 0.45359237,
  libra: 0.45359237,
  libras: 0.45359237,
  oz: 0.028349523125,
  ounce: 0.028349523125,
  ounces: 0.028349523125,
  onza: 0.028349523125,
  onzas: 0.028349523125,
  t: 1000,
  ton: 1000,
  tonne: 1000,
};

function normalizedUnit(unit: string | null | undefined): string {
  return String(unit ?? '').trim().toLowerCase();
}

export function isWeightUnit(unit: string | null | undefined): boolean {
  return Object.hasOwn(weightUnitFactors, normalizedUnit(unit));
}

export function convertWeightQuantity(
  quantity: number,
  sourceUnit: string | null | undefined,
  targetUnit: string | null | undefined,
): number | null {
  const sourceFactor = weightUnitFactors[normalizedUnit(sourceUnit)];
  const targetFactor = weightUnitFactors[normalizedUnit(targetUnit)];
  if (!Number.isFinite(quantity) || quantity <= 0 || !sourceFactor || !targetFactor) return null;
  const converted = (quantity * sourceFactor) / targetFactor;
  return Number.isFinite(converted) && converted > 0
    ? Math.round(converted * 1_000_000) / 1_000_000
    : null;
}

export function quantityForScaleReading(
  reading: ScaleReading | null | undefined,
  productUnit: string | null | undefined,
  now = Date.now(),
): number | null {
  const targetUnit = normalizedUnit(productUnit);
  const sourceUnit = normalizedUnit(reading?.unit);
  if (
    !reading ||
    reading.status !== 'ready' ||
    reading.is_stable !== true ||
    !Number.isFinite(reading.weight) ||
    Number(reading.weight) <= 0 ||
    !reading.timestamp ||
    !Number.isFinite(Date.parse(reading.timestamp)) ||
    now < Date.parse(reading.timestamp) - 1000 ||
    now - Date.parse(reading.timestamp) > 5000 ||
    !Object.hasOwn(weightUnitFactors, sourceUnit) ||
    !Object.hasOwn(weightUnitFactors, targetUnit)
  ) {
    return null;
  }

  return convertWeightQuantity(Number(reading.weight), sourceUnit, targetUnit);
}
