/**
 * Polyfill de localStorage/sessionStorage para jsdom en Node 22+.
 * Se ejecuta ANTES de cargar los modulos de la app (zustand lo usa al importar).
 */
class MemoryStorage implements Storage {
  private store = new Map<string, string>();

  get length(): number {
    return this.store.size;
  }

  clear(): void {
    this.store.clear();
  }

  getItem(key: string): string | null {
    return this.store.get(key) ?? null;
  }

  key(index: number): string | null {
    return Array.from(this.store.keys())[index] ?? null;
  }

  removeItem(key: string): void {
    this.store.delete(key);
  }

  setItem(key: string, value: string): void {
    this.store.set(key, value);
  }
}

if (typeof globalThis.localStorage === 'undefined') {
  Object.defineProperty(globalThis, 'localStorage', {
    value: new MemoryStorage(),
    writable: true,
    configurable: true,
  });
}

if (typeof globalThis.sessionStorage === 'undefined') {
  Object.defineProperty(globalThis, 'sessionStorage', {
    value: new MemoryStorage(),
    writable: true,
    configurable: true,
  });
}

import '@testing-library/jest-dom/vitest';

// Polyfill ResizeObserver (usado por Radix UI para posicionamiento de Popovers, Dialogs, etc).
// jsdom no lo implementa, asi que lo definimos manualmente.
if (typeof globalThis.ResizeObserver === 'undefined') {
  // El polyfill de ResizeObserver no extiende la clase nativa (no tiene callback
  // en el constructor), asi que necesitamos un type cast para asignarlo.
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion
  globalThis.ResizeObserver = class ResizeObserver {
    // eslint-disable-next-line @typescript-eslint/no-empty-function
    observe() {}
    // eslint-disable-next-line @typescript-eslint/no-empty-function
    unobserve() {}
    // eslint-disable-next-line @typescript-eslint/no-empty-function
    disconnect() {}
  } as unknown as typeof ResizeObserver;
}