/**
 * unitsOfMeasure.ts
 *
 * Gestión centralizada y persistente de unidades de medida / tipos de producto.
 * Permite unidades estándar (und, par, kg, lt, m, caja, paquete, etc.)
 * y permite al usuario definir unidades personalizadas persistentes (ej. "PAR", "DOCENA", "BULTO", etc.)
 * que se guardan en localStorage por tenant y se envían al backend.
 */

export interface UnitOfMeasureOption {
  value: string;
  label: string;
  isCustom?: boolean;
}

export const BASE_UNITS_OF_MEASURE: UnitOfMeasureOption[] = [
  { value: 'unit', label: 'Unidad (und)' },
  { value: 'par', label: 'Par (par)' },
  { value: 'kg', label: 'Kilogramo (kg)' },
  { value: 'lt', label: 'Litro (lt)' },
  { value: 'm', label: 'Metro (m)' },
  { value: 'caja', label: 'Caja (cja)' },
  { value: 'paquete', label: 'Paquete (paq)' },
  { value: 'juego', label: 'Juego (jgo)' },
  { value: 'rollo', label: 'Rollo (rlo)' },
  { value: 'saco', label: 'Saco (sco)' },
  { value: 'bulto', label: 'Bulto (blt)' },
  { value: 'docena', label: 'Docena (doc)' },
  { value: 'galon', label: 'Galón (gal)' },
  { value: 'servicio', label: 'Servicio (srv)' },
];

const STORAGE_KEY_PREFIX = 'sdi_custom_units_';

export function getCustomUnits(tenantId?: number | null): UnitOfMeasureOption[] {
  if (typeof window === 'undefined') return [];
  try {
    const key = `${STORAGE_KEY_PREFIX}${tenantId ?? 'default'}`;
    const raw = window.localStorage.getItem(key);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      return parsed.filter(
        (u): u is UnitOfMeasureOption =>
          typeof u?.value === 'string' && typeof u?.label === 'string',
      );
    }
  } catch {
    // fallback silente
  }
  return [];
}

export function saveCustomUnit(
  unitName: string,
  tenantId?: number | null,
): UnitOfMeasureOption {
  const trimmed = unitName.trim();
  const value = trimmed.toLowerCase();
  const label = trimmed.charAt(0).toUpperCase() + trimmed.slice(1);

  // Si ya existe en base units, devolver la opción base
  const existingBase = BASE_UNITS_OF_MEASURE.find(
    (u) => u.value.toLowerCase() === value || u.label.toLowerCase() === trimmed.toLowerCase(),
  );
  if (existingBase) return existingBase;

  const current = getCustomUnits(tenantId);
  const existingCustom = current.find((u) => u.value.toLowerCase() === value);
  if (existingCustom) return existingCustom;

  const newUnit: UnitOfMeasureOption = {
    value,
    label: `${label} (${value})`,
    isCustom: true,
  };

  const updated = [...current, newUnit];
  try {
    const key = `${STORAGE_KEY_PREFIX}${tenantId ?? 'default'}`;
    window.localStorage.setItem(key, JSON.stringify(updated));
  } catch {
    // ignore
  }

  return newUnit;
}

export function getAllUnits(tenantId?: number | null, currentValue?: string): UnitOfMeasureOption[] {
  const custom = getCustomUnits(tenantId);
  const unitsMap = new Map<string, UnitOfMeasureOption>();

  // Cargar base
  BASE_UNITS_OF_MEASURE.forEach((u) => unitsMap.set(u.value.toLowerCase(), u));

  // Cargar custom
  custom.forEach((u) => unitsMap.set(u.value.toLowerCase(), u));

  // Si el valor actual del producto no está en la lista, agregarlo para no perderlo
  if (currentValue && !unitsMap.has(currentValue.toLowerCase())) {
    const val = currentValue.trim();
    const formatted: UnitOfMeasureOption = {
      value: val,
      label: `${val.toUpperCase()} (${val})`,
      isCustom: true,
    };
    unitsMap.set(val.toLowerCase(), formatted);
  }

  return Array.from(unitsMap.values());
}

export function formatUnitLabel(value?: string | null): string {
  if (!value) return 'Unidad (und)';
  const val = value.toLowerCase();
  const match = BASE_UNITS_OF_MEASURE.find((u) => u.value.toLowerCase() === val);
  if (match) return match.label;
  return value.toUpperCase();
}
