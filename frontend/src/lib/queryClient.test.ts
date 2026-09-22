import { describe, expect, it } from 'vitest';

import { createAppQueryClient } from './queryClient';

describe('createAppQueryClient', () => {
  it('usa la configuracion estandar de TanStack Query (modo online)', () => {
    const options = createAppQueryClient().getDefaultOptions();

    expect(options.queries?.networkMode).toBeUndefined();
    expect(options.queries?.refetchOnReconnect).toBe(true);
    expect(options.queries?.refetchOnWindowFocus).toBe(true);
    expect(options.queries?.staleTime).toBe(30_000);
    expect(options.queries?.gcTime).toBe(300_000);
    expect(options.queries?.retry).toBe(1);
    expect(options.mutations?.retry).toBe(false);
  });
});
