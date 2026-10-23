import { afterEach, describe, expect, it, vi } from 'vitest';

import { getPrinterAgentUrl } from './api';

describe('getPrinterAgentUrl', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('sends LAN browser printing to the agent on the current workstation', async () => {
    vi.stubGlobal('window', {
      location: { hostname: '192.168.1.20', origin: 'http://192.168.1.10:8791', port: '8791' },
    });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));

    await expect(getPrinterAgentUrl()).resolves.toBe('http://127.0.0.1:17777/print');
    expect(fetch).toHaveBeenCalledWith(
      'http://127.0.0.1:17777/health',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('reports when the local print agent is missing or blocked', async () => {
    vi.stubGlobal('window', {
      location: { hostname: '192.168.1.20', origin: 'http://192.168.1.10:8791', port: '8791' },
    });
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

    await expect(getPrinterAgentUrl()).rejects.toThrow(
      'No se detecta BalanzaPro Impresor Local en esta PC.',
    );
  });
});
