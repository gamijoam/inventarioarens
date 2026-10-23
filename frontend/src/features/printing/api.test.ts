import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  getPrinterAgentUrl,
  sendJobToLocalAgent,
  shouldUseWorkstationDefaultPrinter,
  type PrintJob,
} from './api';

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

  it('uses the local Windows default printer for a POS opened from a private LAN address', () => {
    expect(shouldUseWorkstationDefaultPrinter({ hostname: '192.168.1.20', port: '8791' })).toBe(
      true,
    );
    expect(shouldUseWorkstationDefaultPrinter({ hostname: 'store.local', port: '8791' })).toBe(
      true,
    );
    expect(shouldUseWorkstationDefaultPrinter({ hostname: 'localhost', port: '8791' })).toBe(false);
    expect(shouldUseWorkstationDefaultPrinter({ hostname: '127.0.0.1', port: '8791' })).toBe(false);
    expect(shouldUseWorkstationDefaultPrinter({ hostname: '192.168.1.20', port: '8792' })).toBe(
      false,
    );
    expect(shouldUseWorkstationDefaultPrinter({ hostname: 'app.example.com', port: '8791' })).toBe(
      false,
    );
  });

  it('does not send the server printer name to a LAN workstation agent', async () => {
    vi.stubGlobal('window', {
      location: { hostname: '192.168.1.10', origin: 'http://192.168.1.10:8791', port: '8791' },
    });
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ ok: true })
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ status: 'printed' }) });
    vi.stubGlobal('fetch', fetchMock);

    const job = {
      id: 10,
      output: 'thermal',
      ticket_pdf_url: '/ticket.pdf',
      ticket_html_url: '/ticket.html',
      station: { printer_type: 'windows_printer', printer_name: 'SERVER-USB-PRINTER' },
    } as PrintJob;

    await sendJobToLocalAgent(job);

    const request = fetchMock.mock.calls[1]?.[1] as RequestInit;
    const body = JSON.parse(request.body as string) as { station: Record<string, unknown> };
    expect(body.station).toMatchObject({
      printer_type: 'windows_printer',
      printer_name: null,
      use_default_printer: true,
    });
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
