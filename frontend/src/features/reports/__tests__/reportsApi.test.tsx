import { describe, expect, it } from 'vitest';

import {
  buildFinancePayablesQuery,
  buildFinanceReceivablesQuery,
  buildFinanceSummaryQuery,
  buildLowStockReportQuery,
  buildMovementReportQuery,
  buildStockReportQuery,
} from '../api';
import { FinanceSummarySchema, MovementReportRowSchema, StockReportRowSchema } from '../schemas';

describe('reports api', () => {
  it('builds inventory report filters', () => {
    expect(buildStockReportQuery({ warehouse_id: 2, product_id: 7 })).toBe(
      '/reports/stock?warehouse_id=2&product_id=7',
    );
    expect(buildLowStockReportQuery({ threshold: 4, warehouse_id: 2 })).toBe(
      '/reports/stock/low?warehouse_id=2&threshold=4',
    );
  });

  it('builds movement report filters without sending all as type', () => {
    expect(
      buildMovementReportQuery({
        type: 'all',
        date_from: '2026-07-01',
        date_to: '2026-07-18',
      }),
    ).toBe('/reports/movements?date_from=2026-07-01&date_to=2026-07-18');

    expect(buildMovementReportQuery({ type: 'sale_return' })).toBe(
      '/reports/movements?type=sale_return',
    );
  });

  it('builds finance report filters', () => {
    expect(buildFinanceSummaryQuery({ date_from: '2026-07-01', date_to: '2026-07-18' })).toBe(
      '/finance-reports/summary?date_from=2026-07-01&date_to=2026-07-18',
    );
    expect(buildFinanceReceivablesQuery({ status: 'partial' })).toBe(
      '/finance-reports/receivables?status=partial',
    );
    expect(buildFinancePayablesQuery({ status: 'paid' })).toBe(
      '/finance-reports/payables?status=paid',
    );
  });

  it('parses backend report contracts', () => {
    expect(
      StockReportRowSchema.parse({
        warehouse_id: 1,
        warehouse_name: 'Principal',
        product_id: 9,
        product_name: 'Caramelo',
        sku: 'CAM01',
        quantity_available: 10,
        quantity_reserved: 1,
        quantity_damaged: 0,
      }),
    ).toMatchObject({ product_id: 9, quantity_available: 10 });

    expect(
      MovementReportRowSchema.parse({
        id: 1,
        warehouse_id: 1,
        product_id: 9,
        type: 'sale_return',
        quantity: 1,
      }),
    ).toMatchObject({ type: 'sale_return' });

    expect(
      FinanceSummarySchema.parse({
        currency: 'USD',
        accounts_receivable: {
          total_balance_base_amount: 10,
          pending_count: 1,
          partial_count: 1,
          paid_count: 0,
          overdue_count: 0,
        },
        accounts_payable: {
          total_balance_base_amount: 4,
          pending_count: 1,
          partial_count: 0,
          paid_count: 0,
          overdue_count: 0,
        },
        cash_flow: {
          collections_base_amount: 5,
          supplier_payments_base_amount: 2,
        },
        net_balance_base_amount: 6,
      }),
    ).toMatchObject({ net_balance_base_amount: 6 });
  });
});
