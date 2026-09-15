/**
 * dashboardSecurity.ts
 *
 * Utilidades de seguridad y privacidad para el Dashboard.
 * Permite ocultar/enmascarar cifras sensibles mediante un PIN de acceso
 * con persistencia local por empresa (tenant-aware) y almacenamiento hash SHA-256.
 */

const PIN_STORAGE_PREFIX = 'dashboard_privacy_pin_hash';
const MASKED_STORAGE_PREFIX = 'dashboard_privacy_masked';

function getStorageKey(prefix: string, tenantId?: number | string | null): string {
  return tenantId ? `${prefix}_${tenantId}` : prefix;
}

/**
 * Genera el hash criptográfico SHA-256 del PIN utilizando una sal fija de aplicación.
 */
export async function hashPin(pin: string): Promise<string> {
  const normalized = pin.trim();
  const encoder = new TextEncoder();
  const data = encoder.encode(`inventarioarens_privacy_salt_${normalized}`);

  if (typeof crypto !== 'undefined' && crypto.subtle) {
    const hashBuffer = await crypto.subtle.digest('SHA-256', data);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  // Fallback para entornos sin Web Crypto API
  let hash = 0;
  for (let i = 0; i < normalized.length; i++) {
    const char = normalized.charCodeAt(i);
    hash = (hash << 5) - hash + char;
    hash |= 0;
  }
  return `fallback_${hash}`;
}

/**
 * Verifica si ya existe un PIN de privacidad configurado para el tenant actual.
 */
export function hasDashboardPin(tenantId?: number | string | null): boolean {
  if (typeof window === 'undefined') return false;
  try {
    const key = getStorageKey(PIN_STORAGE_PREFIX, tenantId);
    return Boolean(window.localStorage.getItem(key));
  } catch {
    return false;
  }
}

/**
 * Almacena el hash del nuevo PIN de privacidad.
 */
export async function setDashboardPin(pin: string, tenantId?: number | string | null): Promise<void> {
  if (typeof window === 'undefined') return;
  const key = getStorageKey(PIN_STORAGE_PREFIX, tenantId);
  const hash = await hashPin(pin);
  window.localStorage.setItem(key, hash);
}

/**
 * Valida si el PIN introducido coincide con el hash almacenado.
 */
export async function verifyDashboardPin(pin: string, tenantId?: number | string | null): Promise<boolean> {
  if (typeof window === 'undefined') return false;
  try {
    const key = getStorageKey(PIN_STORAGE_PREFIX, tenantId);
    const storedHash = window.localStorage.getItem(key);
    if (!storedHash) return false;
    const computedHash = await hashPin(pin);
    return storedHash === computedHash;
  } catch {
    return false;
  }
}

/**
 * Elimina el PIN de privacidad configurado.
 */
export function removeDashboardPin(tenantId?: number | string | null): void {
  if (typeof window === 'undefined') return;
  try {
    const key = getStorageKey(PIN_STORAGE_PREFIX, tenantId);
    window.localStorage.removeItem(key);
  } catch {
    // ignorar
  }
}

/**
 * Obtiene el estado de enmascaramiento/privacidad guardado en localStorage.
 */
export function getStoredDashboardMasked(tenantId?: number | string | null): boolean {
  if (typeof window === 'undefined') return false;
  try {
    const key = getStorageKey(MASKED_STORAGE_PREFIX, tenantId);
    return window.localStorage.getItem(key) === 'true';
  } catch {
    return false;
  }
}

/**
 * Guarda el estado de enmascaramiento/privacidad en localStorage.
 */
export function saveStoredDashboardMasked(isMasked: boolean, tenantId?: number | string | null): void {
  if (typeof window === 'undefined') return;
  try {
    const key = getStorageKey(MASKED_STORAGE_PREFIX, tenantId);
    window.localStorage.setItem(key, String(isMasked));
  } catch {
    // ignorar
  }
}
