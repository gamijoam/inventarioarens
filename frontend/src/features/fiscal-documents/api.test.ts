import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetOne = vi.fn();
const mockGetPaginated = vi.fn();
const mockPostOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: (path: string): Promise<unknown> => mockGetOne(path) as Promise<unknown>,
  getPaginated: (path: string): Promise<unknown> => mockGetPaginated(path) as Promise<unknown>,
  postOne: (path: string, body: unknown): Promise<unknown> =>
    mockPostOne(path, body) as Promise<unknown>,
}));

import {
  buildFiscalDocumentPreviewsPath,
  createFiscalDocumentPreview,
  getFiscalDocumentPreview,
  getFiscalDocumentPreviews,
} from './api';

describe('fiscal documents api', () => {
  beforeEach(() => {
    mockGetOne.mockReset();
    mockGetPaginated.mockReset();
    mockPostOne.mockReset();
  });

  it('builds and parses the paginated preview history', async () => {
    mockGetPaginated.mockResolvedValue({
      data: [
        {
          id: 10,
          tenant_id: 1,
          sale_id: 20,
          document_type: 'internal_preview',
          document_mode: 'internal_preview',
          status: 'preview',
          officially_issued: false,
          company_snapshot: {},
          branch_snapshot: null,
          customer_snapshot: { name: 'Consumidor final' },
          totals_snapshot: {
            total_base_amount: 0,
            total_local_amount: 0,
            fiscal_taxable_base_amount: 0,
            fiscal_taxable_local_amount: 0,
            fiscal_exempt_base_amount: 0,
            fiscal_exempt_local_amount: 0,
            fiscal_exonerated_base_amount: 0,
            fiscal_exonerated_local_amount: 0,
            fiscal_non_taxable_base_amount: 0,
            fiscal_non_taxable_local_amount: 0,
            fiscal_tax_base_amount: 0,
            fiscal_tax_local_amount: 0,
          },
          snapshot_at: '2026-08-29T12:00:00.000000Z',
          items: [],
        },
      ],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 1, to: 1, total: 1 },
      links: { first: null, last: null, prev: null, next: null },
    });

    await expect(
      getFiscalDocumentPreviews({ sale_id: 20, status: 'preview', per_page: 1 }),
    ).resolves.toMatchObject({
      data: [{ id: 10, sale_id: 20 }],
    });
    expect(mockGetPaginated).toHaveBeenCalledWith(
      '/fiscal/documents?sale_id=20&status=preview&per_page=1',
    );
    expect(buildFiscalDocumentPreviewsPath({ date_from: '2026-08-01' })).toBe(
      '/fiscal/documents?date_from=2026-08-01',
    );
  });

  it('gets a single persisted preview for reopening', async () => {
    mockGetOne.mockResolvedValue({
      id: 10,
      tenant_id: 1,
      sale_id: 20,
      document_type: 'internal_preview',
      document_mode: 'internal_preview',
      status: 'preview',
      officially_issued: false,
      company_snapshot: {},
      branch_snapshot: null,
      customer_snapshot: { name: 'Consumidor final' },
      totals_snapshot: {
        total_base_amount: 0,
        total_local_amount: 0,
        fiscal_taxable_base_amount: 0,
        fiscal_taxable_local_amount: 0,
        fiscal_exempt_base_amount: 0,
        fiscal_exempt_local_amount: 0,
        fiscal_exonerated_base_amount: 0,
        fiscal_exonerated_local_amount: 0,
        fiscal_non_taxable_base_amount: 0,
        fiscal_non_taxable_local_amount: 0,
        fiscal_tax_base_amount: 0,
        fiscal_tax_local_amount: 0,
      },
      snapshot_at: '2026-08-29T12:00:00.000000Z',
      items: [],
    });

    await expect(getFiscalDocumentPreview(10)).resolves.toMatchObject({ id: 10 });
    expect(mockGetOne).toHaveBeenCalledWith('/fiscal/documents/10');
  });

  it('posts a sale id and parses the internal preview response', async () => {
    mockPostOne.mockResolvedValue({
      id: 10,
      tenant_id: 1,
      sale_id: 20,
      document_type: 'internal_preview',
      document_mode: 'internal_preview',
      status: 'preview',
      officially_issued: false,
      company_snapshot: {},
      branch_snapshot: null,
      customer_snapshot: { name: 'Consumidor final' },
      totals_snapshot: {
        total_base_amount: 116,
        total_local_amount: 116,
        fiscal_taxable_base_amount: 100,
        fiscal_taxable_local_amount: 100,
        fiscal_exempt_base_amount: 0,
        fiscal_exempt_local_amount: 0,
        fiscal_exonerated_base_amount: 0,
        fiscal_exonerated_local_amount: 0,
        fiscal_non_taxable_base_amount: 0,
        fiscal_non_taxable_local_amount: 0,
        fiscal_tax_base_amount: 16,
        fiscal_tax_local_amount: 16,
      },
      snapshot_at: '2026-08-29T12:00:00.000000Z',
      items: [],
    });

    await expect(createFiscalDocumentPreview(20)).resolves.toMatchObject({
      sale_id: 20,
      officially_issued: false,
    });
    expect(mockPostOne).toHaveBeenCalledWith('/fiscal/documents/previews', { sale_id: 20 });
  });
});
