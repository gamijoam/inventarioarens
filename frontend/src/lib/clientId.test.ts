import { afterEach, describe, expect, it, vi } from 'vitest';

import { createClientId } from './clientId';

describe('createClientId', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('uses crypto.randomUUID when the browser provides it', () => {
    vi.stubGlobal('crypto', {
      randomUUID: () => 'native-uuid',
    });

    expect(createClientId()).toBe('native-uuid');
  });

  it('creates different UUID-compatible ids when randomUUID is unavailable', () => {
    let seed = 0;
    vi.stubGlobal('crypto', {
      getRandomValues: (bytes: Uint8Array) => {
        bytes.forEach((_, index) => {
          bytes[index] = (seed + index) % 256;
        });
        seed += 16;
        return bytes;
      },
    });

    const first = createClientId();
    const second = createClientId();

    expect(first).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(second).not.toBe(first);
  });
});
