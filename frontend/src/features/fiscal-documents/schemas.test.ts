import { describe, expect, it } from 'vitest';

import { FiscalDocumentPreviewSchema } from './schemas';

describe('FiscalDocumentPreviewSchema', () => {
  it('parses an internal preview without official numbering', () => {
    const preview = FiscalDocumentPreviewSchema.parse({
      id: 10,
      tenant_id: 1,
      sale_id: 20,
      document_type: 'internal_preview',
      document_mode: 'internal_preview',
      status: 'preview',
      officially_issued: false,
      company_snapshot: { legal_name: 'Empresa', tax_id: 'J-12345678-9' },
      branch_snapshot: null,
      customer_snapshot: {
        name: 'Cliente',
        fiscal_name: 'Cliente Fiscal',
        document_type: 'V',
        document_number: '12345678',
      },
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
      items: [
        {
          id: 30,
          sale_item_id: 40,
          quantity: 1,
          sale_currency: 'USD',
          unit_price: 116,
          total_amount: 116,
          base_unit_price: 100,
          base_total_amount: 100,
          local_total_amount: 100,
          product_snapshot: { name: 'Producto', sku: 'SKU-1' },
          warehouse_snapshot: null,
          commercial_snapshot: null,
          fiscal_snapshot: { tax_code: 'IVA16', category: 'taxable', tax_rate: 16 },
        },
      ],
      created_by: 1,
      created_at: '2026-08-29T12:00:00.000000Z',
      updated_at: '2026-08-29T12:00:00.000000Z',
    });

    expect(preview.officially_issued).toBe(false);
    expect(preview.items[0]?.fiscal_snapshot.tax_code).toBe('IVA16');
    expect('control_number' in preview).toBe(false);
  });
});
