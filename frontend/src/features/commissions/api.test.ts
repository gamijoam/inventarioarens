import { beforeEach, describe, expect, it, vi } from 'vitest';

import { api, getMany, postOne } from '@/api/client';
import {
  approveCommissionEntries,
  createCommissionAdjustment,
  createCommissionSettlement,
  fetchCommissionEntries,
  fetchCommissionPlans,
  simulateCommission,
} from './api';
import {
  defaultControlColumns,
  presetControlColumns,
  reconcileControlColumns,
} from './controlColumns';

vi.mock('@/api/client', () => ({
  getMany: vi.fn(),
  postOne: vi.fn(),
  patchOne: vi.fn(),
  deleteOne: vi.fn(),
  api: { get: vi.fn() },
}));

describe('commissions api contract', () => {
  beforeEach(() => vi.clearAllMocks());

  it('parses the real plan response including nullable timestamps', async () => {
    vi.mocked(getMany).mockResolvedValueOnce([
      {
        id: 1,
        name: 'Vendedores 3%',
        beneficiary_role: 'seller',
        percentage: '3.0000',
        conversion_policy: 'configured_rate',
        exchange_rate_type_id: 4,
        exchange_rate_type: { id: 4, code: 'BCV', name: 'Banco Central' },
        credit_policy: 'proportional_collections',
        maturation_days: 7,
        allow_self_stacking: false,
        include_combos: true,
        include_discounts: true,
        is_active: true,
        starts_at: '2026-08-01T00:00:00.000000Z',
        ends_at: null,
        assignments: [
          {
            id: 8,
            user_id: 12,
            is_active: true,
            starts_at: null,
            ends_at: null,
            user: { id: 12, name: 'Ana', email: 'ana@example.test' },
          },
        ],
        created_at: '2026-08-14T10:00:00.000000Z',
        updated_at: null,
      },
    ]);

    const plans = await fetchCommissionPlans();

    expect(plans).toHaveLength(1);
    expect(plans[0]?.percentage).toBe('3.0000');
    expect(plans[0]?.updated_at).toBeNull();
    expect(plans[0]?.assignments[0]?.user.name).toBe('Ana');
  });

  it('parses the simulator response with its frozen exchange-rate snapshot', async () => {
    vi.mocked(postOne).mockResolvedValueOnce({
      currency: 'VES',
      input_amount: '6000.0000',
      percentage: '3.0000',
      exchange_rate_type_id: 4,
      exchange_rate_type_code: 'BCV',
      exchange_rate: '60.000000',
      rate_effective_at: '2026-08-14T08:00:00.000000Z',
      eligible_base_amount: '100.0000',
      commission_base_amount: '3.0000',
    });

    const result = await simulateCommission({
      amount: 6000,
      currency: 'VES',
      percentage: 3,
      exchange_rate_type_id: 4,
    });

    expect(result.exchange_rate_type_code).toBe('BCV');
    expect(result.commission_base_amount).toBe('3.0000');
  });

  it('parses my commission ledger and summary from the real envelope', async () => {
    vi.spyOn(api, 'get').mockResolvedValueOnce({
      data: {
        data: [
          {
            id: 7,
            entry_uuid: '26ceda39-6f38-4ce1-96da-44498e0a9734',
            sale_id: 10,
            pos_order_id: 11,
            sale_item_id: 12,
            beneficiary_role: 'seller',
            beneficiary: { id: 3, name: 'Ana', email: 'ana@example.test' },
            entry_type: 'earning',
            plan_name_snapshot: 'Vendedores 3%',
            percentage_snapshot: '3.0000',
            sale_currency: 'VES',
            source_amount: '6000.0000',
            eligible_base_amount: '100.0000',
            exchange_rate_type_code: 'BCV',
            exchange_rate: '60.000000',
            commission_base_amount: '3.0000',
            status: 'available',
            earned_at: '2026-08-14T10:00:00Z',
            available_at: '2026-08-14T10:00:00Z',
            created_at: '2026-08-14T10:00:00Z',
            updated_at: null,
          },
        ],
        summary: {
          total_base_amount: '3.0000',
          available_base_amount: '3.0000',
          pending_base_amount: '0.0000',
          approved_base_amount: '0.0000',
          paid_base_amount: '0.0000',
          currency_breakdown: {
            total_usd: '0.0000',
            total_ves: '180.0000',
            available_usd: '0.0000',
            available_ves: '180.0000',
            approved_usd: '0.0000',
            approved_ves: '0.0000',
            paid_usd: '0.0000',
            paid_ves: '0.0000',
          },
          payables: [
            {
              user_id: 3,
              name: 'Ana',
              email: 'ana@example.test',
              available_usd: '0.0000',
              available_ves: '180.0000',
              approved_usd: '0.0000',
              approved_ves: '0.0000',
              paid_usd: '0.0000',
              paid_ves: '0.0000',
              total_usd: '0.0000',
              total_ves: '180.0000',
            },
          ],
        },
      },
    });

    const ledger = await fetchCommissionEntries(true);

    expect(ledger.data[0]?.status).toBe('available');
    expect(ledger.summary.available_base_amount).toBe('3.0000');
    expect(ledger.data[0]?.updated_at).toBeNull();
  });

  it('accepts an adjustment without sale references from the real API shape', async () => {
    vi.mocked(postOne).mockResolvedValueOnce({
      id: 8,
      entry_uuid: '36ceda39-6f38-4ce1-96da-44498e0a9734',
      sale_id: null,
      pos_order_id: null,
      sale_item_id: null,
      beneficiary_role: 'seller',
      beneficiary: { id: 3, name: 'Ana', email: 'ana@example.test' },
      entry_type: 'adjustment',
      plan_name_snapshot: 'Ajuste manual',
      percentage_snapshot: '0.0000',
      sale_currency: 'USD',
      source_amount: '-3.5000',
      eligible_base_amount: '-3.5000',
      exchange_rate_type_code: null,
      exchange_rate: null,
      commission_base_amount: '-3.5000',
      adjustment_reason: 'Diferencia verificada',
      status: 'available',
      approved_at: null,
      earned_at: '2026-08-14T10:00:00Z',
      available_at: '2026-08-14T10:00:00Z',
      created_at: '2026-08-14T10:00:00Z',
      updated_at: null,
    });

    const adjustment = await createCommissionAdjustment({
      beneficiary_user_id: 3,
      beneficiary_role: 'seller',
      amount_base: -3.5,
      reason: 'Diferencia verificada',
    });

    expect(adjustment.sale_id).toBeNull();
    expect(adjustment.adjustment_reason).toBe('Diferencia verificada');
    expect(postOne).toHaveBeenCalledWith('/commissions/adjustments', expect.any(Object));
  });

  it('parses approval and VES settlement snapshots', async () => {
    const approvedEntry = {
      id: 7,
      entry_uuid: '26ceda39-6f38-4ce1-96da-44498e0a9734',
      sale_id: 10,
      pos_order_id: 11,
      sale_item_id: 12,
      beneficiary_role: 'seller',
      beneficiary: { id: 3, name: 'Ana', email: 'ana@example.test' },
      entry_type: 'earning',
      plan_name_snapshot: 'Vendedores 3%',
      percentage_snapshot: '3.0000',
      sale_currency: 'VES',
      source_amount: '6000.0000',
      eligible_base_amount: '100.0000',
      exchange_rate_type_code: 'BCV',
      exchange_rate: '60.000000',
      commission_base_amount: '3.0000',
      adjustment_reason: null,
      status: 'approved',
      approved_at: '2026-08-14T11:00:00Z',
      earned_at: '2026-08-14T10:00:00Z',
      available_at: '2026-08-14T10:00:00Z',
      created_at: '2026-08-14T10:00:00Z',
      updated_at: '2026-08-14T11:00:00Z',
    };
    vi.mocked(postOne)
      .mockResolvedValueOnce([approvedEntry])
      .mockResolvedValueOnce({
        id: 2,
        settlement_uuid: '46ceda39-6f38-4ce1-96da-44498e0a9734',
        status: 'paid',
        payment_currency: 'VES',
        total_base_amount: '3.0000',
        total_local_amount: '180.0000',
        payment_amount: '180.0000',
        exchange_rate_type_code: 'BCV',
        exchange_rate: '60.000000',
        reference: null,
        notes: null,
        beneficiary: { id: 3, name: 'Ana', email: 'ana@example.test' },
        entry_uuids: [approvedEntry.entry_uuid],
        paid_at: '2026-08-14T12:00:00Z',
        created_at: '2026-08-14T12:00:00Z',
        updated_at: null,
      });

    const approved = await approveCommissionEntries([7]);
    const settlement = await createCommissionSettlement({
      entry_ids: [7],
      payment_currency: 'VES',
      exchange_rate_type_id: 4,
    });

    expect(approved[0]?.status).toBe('approved');
    expect(settlement.total_local_amount).toBe('180.0000');
    expect(settlement.updated_at).toBeNull();
  });
});

describe('commission control columns', () => {
  const columns = [
    { key: 'quantity', label: 'Cant.', default_visible: true },
    { key: 'amount_usd', label: '$', default_visible: true },
    { key: 'amount_ves', label: 'Bs', default_visible: true },
    { key: 'commission_usd', label: 'Comision $', default_visible: true },
    { key: 'commission_ves', label: 'Comision Bs', default_visible: true },
    { key: 'payment_method_1', label: 'P.M.', default_visible: true },
  ];

  it('mantiene todas las columnas por defecto y permite una vista solo de comisiones en Bs', () => {
    expect(defaultControlColumns(columns)).toHaveLength(columns.length);
    expect(presetControlColumns(columns, 'commission_ves')).toEqual(['quantity', 'commission_ves']);
  });

  it('descarta columnas guardadas que ya no existen', () => {
    expect(reconcileControlColumns(columns, ['amount_ves', 'deleted_column'])).toEqual([
      'amount_ves',
    ]);
  });
});
