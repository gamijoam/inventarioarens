import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { downloadVirtualTicket } from './api';

const post = vi.hoisted(() => vi.fn());

vi.mock('@/api/client', () => ({
  api: { post },
  deleteOne: vi.fn(),
  getMany: vi.fn(),
  getPaginated: vi.fn(),
  patchOne: vi.fn(),
  postOne: vi.fn(),
}));

describe('downloadVirtualTicket', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: vi.fn().mockReturnValue('blob:ticket'),
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: vi.fn(),
    });
  });

  it('requests the cloud preview and starts a PDF download', async () => {
    post.mockResolvedValue({ data: new Blob(['ticket']) });
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

    await downloadVirtualTicket({
      name: 'Prueba',
      paper_width_mm: 80,
      characters_per_line: 48,
      header_text: 'Tienda',
      footer_text: 'Gracias',
    });

    expect(post).toHaveBeenCalledWith(
      '/printing/profiles/preview.pdf',
      expect.objectContaining({ name: 'Prueba', paper_width_mm: 80 }),
      { responseType: 'blob' },
    );
    expect(click).toHaveBeenCalledOnce();
    expect(URL.createObjectURL).toHaveBeenCalledOnce();
    vi.advanceTimersByTime(60_000);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:ticket');
    click.mockRestore();
  });
});
