import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { api } from '@/api/client';
import { printTicketViaBrowser, printHtmlContent } from '../api';

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
  },
  postOne: vi.fn(),
  patchOne: vi.fn(),
  deleteOne: vi.fn(),
  getMany: vi.fn(),
  getPaginated: vi.fn(),
}));

describe('printTicketViaBrowser & printHtmlContent', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.getElementById('pos-ticket-print-frame')?.remove();
  });

  afterEach(() => {
    document.getElementById('pos-ticket-print-frame')?.remove();
  });

  it('obtiene el HTML del ticket desde la API y lo envía al diálogo de impresión del navegador', async () => {
    const mockHtml = '<html><body><h1>Ticket POS #10</h1><p>Descuento: $5.00</p></body></html>';
    vi.mocked(api.get).mockResolvedValueOnce({ data: mockHtml });

    const printSpy = vi.fn();
    vi.spyOn(HTMLIFrameElement.prototype, 'contentWindow', 'get').mockReturnValue({
      document: {
        open: vi.fn(),
        write: vi.fn(),
        close: vi.fn(),
      },
      focus: vi.fn(),
      print: printSpy,
    } as unknown as Window);

    await printTicketViaBrowser(42);

    expect(api.get).toHaveBeenCalledWith('/printing/jobs/42/ticket.html', {
      responseType: 'text',
    });
    expect(printSpy).toHaveBeenCalled();
  });

  it('printHtmlContent limpia cualquier iframe previo antes de inyectar el nuevo', async () => {
    const oldIframe = document.createElement('iframe');
    oldIframe.id = 'pos-ticket-print-frame';
    document.body.appendChild(oldIframe);

    expect(document.getElementById('pos-ticket-print-frame')).toBe(oldIframe);

    const promise = printHtmlContent('<div>Nuevo ticket</div>');
    await promise;

    // Se reemplazó el antiguo iframe
    expect(document.body.contains(oldIframe)).toBe(false);
  });
});
