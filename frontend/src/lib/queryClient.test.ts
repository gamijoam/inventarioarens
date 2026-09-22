import { describe, expect, it } from 'vitest';

import { createAppQueryClient, isLocalBackend } from './queryClient';

describe('createAppQueryClient (local / Electron)', () => {
  it('no pausa queries ni mutaciones por navigator.onLine (networkMode always)', () => {
    const options = createAppQueryClient({ local: true }).getDefaultOptions();

    expect(options.queries?.networkMode).toBe('always');
    expect(options.mutations?.networkMode).toBe('always');
  });

  it('conserva staleTime, gcTime, refetch y politicas de reintento', () => {
    const options = createAppQueryClient({ local: true }).getDefaultOptions();

    expect(options.queries?.staleTime).toBe(30_000);
    expect(options.queries?.gcTime).toBe(300_000);
    expect(options.queries?.retry).toBe(1);
    // Sin refetch masivo al reconectar (evita el burst que congela el server monohilo)
    expect(options.queries?.refetchOnReconnect).toBe(false);
    expect(options.queries?.refetchOnWindowFocus).toBe(true);
    expect(options.mutations?.retry).toBe(false);
  });
});

describe('createAppQueryClient (nube)', () => {
  it('mantiene el modo online y el refetch al reconectar/enfocar', () => {
    const options = createAppQueryClient({ local: false }).getDefaultOptions();

    expect(options.queries?.networkMode).toBeUndefined();
    expect(options.mutations?.networkMode).toBeUndefined();
    expect(options.queries?.refetchOnReconnect).toBe(true);
    expect(options.queries?.refetchOnWindowFocus).toBe(true);
  });
});

describe('isLocalBackend', () => {
  it('detecta el backend local (Electron) por hostname', () => {
    expect(isLocalBackend()).toBe(true);
  });
});
