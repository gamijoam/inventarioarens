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
