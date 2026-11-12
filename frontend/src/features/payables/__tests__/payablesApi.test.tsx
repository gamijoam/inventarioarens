import { describe, expect, it } from 'vitest';

import { buildPayablePaymentRequestsQuery, buildPayablesQuery } from '../api';
import { PayablePaymentRequestPayloadSchema, PayPayableSchema } from '../schemas';

describe('payables api', () => {
  it('builds the default open-balance query server-side', () => {
    expect(buildPayablesQuery({ status: 'open', page: 1, limit: 25 })).toBe(
      '/accounts-payable?status=open&page=1&limit=25',
    );
  });

  it('builds filters for paid audit view', () => {
    expect(
      buildPayablesQuery({
        search: 'proveedor',
        status: 'paid',
        supplier_id: 7,
        due_from: '2026-07-01',
        due_to: '2026-07-31',
      }),
    ).toBe(
      '/accounts-payable?search=proveedor&status=paid&supplier_id=7&due_from=2026-07-01&due_to=2026-07-31',
    );
  });

  it('accepts cash session data in payment payloads', () => {
    expect(
      PayPayableSchema.parse({
        payment_currency: 'USD',
        amount: 10,
        method: 'cash',
        cash_register_session_id: 5,
        reference: 'REC-1',
      }),
    ).toMatchObject({
      payment_currency: 'USD',
      amount: 10,
      method: 'cash',
      cash_register_session_id: 5,
    });
  });

  it('builds payment request list queries', () => {
    expect(
      buildPayablePaymentRequestsQuery({
        status: 'prepared',
        accounts_payable_id: 9,
        page: 2,
        limit: 10,
      }),
    ).toBe(
      '/accounts-payable-payment-requests?status=prepared&accounts_payable_id=9&page=2&limit=10',
    );
  });

  it('accepts prepared payment request payloads', () => {
    expect(
      PayablePaymentRequestPayloadSchema.parse({
        payment_currency: 'VES',
        amount: 1000,
        exchange_rate_type_id: 1,
        exchange_rate: 1000,
        method: 'transfer',
        reference: 'BANCO-1',
        scheduled_for: '2026-07-20',
      }),
    ).toMatchObject({
      payment_currency: 'VES',
      amount: 1000,
      method: 'transfer',
      scheduled_for: '2026-07-20',
    });
  });
});

describe('supplier credit api', () => {
  it('builds the supplier credit query', async () => {
    const { buildSupplierCreditQuery } = await import('../api');
    expect(buildSupplierCreditQuery(12)).toBe('/suppliers/12/credit');
  });

  it('parses the real supplier credit payload with nullable fields', async () => {
    const { SupplierCreditSchema } = await import('../schemas');
    const parsed = SupplierCreditSchema.parse({
      supplier_id: 12,
      available_base_amount: 20,
      available_local_amount: 2000,
      transactions: [
        {
          id: 1,
          type: 'return_credit',
          currency: 'USD',
          amount: 20,
          amount_base: 20,
          amount_local: 2000,
          exchange_rate: 100,
          accounts_payable_id: 3,
          purchase_return_id: 4,
          method: null,
          reference: null,
          notes: 'Devolucion de compra #4 posterior al pago',
          occurred_at: '2026-11-12T10:00:00.000000Z',
          created_at: null,
        },
      ],
    });

    expect(parsed.available_base_amount).toBe(20);
    expect(parsed.transactions[0]?.type).toBe('return_credit');
  });
});
