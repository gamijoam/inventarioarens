import { beforeEach, describe, expect, it, vi } from 'vitest';

import { postOne } from '@/api/client';
import { reversePosSale } from './api';

vi.mock('@/api/client', () => ({
  postOne: vi.fn(),
}));

describe('sales reversal api', () => {
  beforeEach(() => vi.clearAllMocks());

  it('envía el tipo, motivo y sesión de caja al endpoint POS', async () => {
    vi.mocked(postOne).mockResolvedValueOnce({
      id: 9,
      type: 'reversal',
      reason: 'Corrección solicitada por el cliente',
      original_sale_id: 15,
      original_pos_order_id: 22,
      cash_register_session_id: 31,
      original_paid_at: '2026-08-20T14:00:00Z',
      effective_at: '2026-08-24T14:00:00Z',
      reversed_base_amount: 100,
      reversed_local_amount: 6000,
      created_by: 4,
      created_at: '2026-08-24T14:00:00Z',
    });

    const result = await reversePosSale(22, {
      type: 'reversal',
      reason: 'Corrección solicitada por el cliente',
      cash_register_session_id: 31,
    });

    expect(result.type).toBe('reversal');
    expect(postOne).toHaveBeenCalledWith('/pos/orders/22/reverse', {
      type: 'reversal',
      reason: 'Corrección solicitada por el cliente',
      cash_register_session_id: 31,
    });
  });
});
