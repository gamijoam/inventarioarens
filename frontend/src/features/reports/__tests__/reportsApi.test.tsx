import { describe, expect, it } from 'vitest';

import {
  buildCashSessionsQuery,
  buildDailyOperationsQuery,
  buildFinancePayablesQuery,
  buildFinanceReceivablesQuery,
  buildFinanceSummaryQuery,
  buildLowStockReportQuery,
  buildMovementReportQuery,
  buildPaymentMethodsReportQuery,
  buildSalesDetailQuery,
  buildStockReportQuery,
} from '../api';
import {
  CashSessionsSchema,
  DailyOperationsSchema,
  FinanceSummarySchema,
  MovementReportRowSchema,
  SalesDetailSchema,
  StockReportRowSchema,
} from '../schemas';

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

  it('builds modular v2 report filters', () => {
    expect(buildDailyOperationsQuery({ date: '2026-07-18', branch_id: 4 })).toBe(
      '/reports/daily-operations?date=2026-07-18&branch_id=4',
    );
    expect(buildSalesDetailQuery({ date_from: '2026-07-01', date_to: '2026-07-18', customer_id: 9 })).toBe(
      '/reports/sales-detail?customer_id=9&date_from=2026-07-01&date_to=2026-07-18',
    );
    expect(buildCashSessionsQuery({ status: 'open', cash_register_id: 2 })).toBe(
      '/reports/cash-sessions?cash_register_id=2&status=open',
    );
    expect(buildPaymentMethodsReportQuery({ cashier_id: 7 })).toBe(
      '/reports/payment-methods?cashier_id=7',
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

  it('parses modular v2 report contracts', () => {
    expect(
      DailyOperationsSchema.parse({
        period: {
          from: '2026-07-18',
          to: '2026-07-18',
          from_datetime: '2026-07-18T00:00:00.000000Z',
          to_datetime: '2026-07-18T23:59:59.000000Z',
        },
        currency: 'USD',
        sales: {
          confirmed_count: 1,
          confirmed_base_amount: 10,
          pos_paid_count: 1,
          pos_paid_base_amount: 10,
          pos_open_count: 0,
          pos_open_base_amount: 0,
          credit_count: 1,
          credit_balance_base_amount: 2,
        },
        returns: { requested_count: 1, processed_count: 0 },
        cash: {
          open_count: 1,
          closed_count: 0,
          expected_base_amount: 10,
          expected_local_amount: 0,
          difference_base_amount: 0,
        },
        payment_methods: [],
        alerts: {
          stale_open_sessions: 0,
          closed_sessions_with_difference: 0,
          payments_missing_reference: 0,
          paid_pos_without_cash_session: 0,
        },
        generated_at: '2026-07-18T12:00:00.000000Z',
      }),
    ).toMatchObject({ sales: { confirmed_count: 1 } });

    expect(
      SalesDetailSchema.parse({
        period: {
          from: '2026-07-18',
          to: '2026-07-18',
          from_datetime: '2026-07-18T00:00:00.000000Z',
          to_datetime: '2026-07-18T23:59:59.000000Z',
        },
        rows: [
          {
            id: 1,
            status: 'confirmed',
            origin: 'POS',
            customer_name: 'Gabriel',
            total_base_amount: 10,
            total_local_amount: 10000,
            items_count: 1,
            collection: { status: 'partial', balance_base_amount: 2, collected_base_amount: 8 },
            items: [],
            payments: [],
            returns: [],
          },
        ],
      }),
    ).toMatchObject({ rows: [{ collection: { status: 'partial' } }] });

    expect(
      CashSessionsSchema.parse({
        period: {
          from: '2026-07-18',
          to: '2026-07-18',
          from_datetime: '2026-07-18T00:00:00.000000Z',
          to_datetime: '2026-07-18T23:59:59.000000Z',
        },
        summary: {
          open_count: 1,
          closed_count: 0,
          expected_base_amount: 10,
          expected_local_amount: 0,
          difference_base_amount: 0,
        },
        rows: [],
        movement_breakdown: [],
      }),
    ).toMatchObject({ summary: { open_count: 1 } });
  });
});
