import { describe, expect, it, vi } from 'vitest';

import { getPrintConnectors } from './api';

const getMany = vi.hoisted(() => vi.fn());

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  deleteOne: vi.fn(),
  getMany,
  getPaginated: vi.fn(),
  patchOne: vi.fn(),
  postOne: vi.fn(),
}));

describe('getPrintConnectors', () => {
  it('parses the real connector response with nullable timestamps', async () => {
    getMany.mockResolvedValue([
      {
        id: 7,
        uuid: 'connector-7',
        tenant_id: 3,
        name: 'Caja Principal',
        installation_id: 'INSTALL-7',
        version: '0.1.0',
        status: 'active',
        last_seen_at: null,
        created_at: null,
        stations_count: 1,
      },
    ]);

    await expect(getPrintConnectors()).resolves.toEqual([
      expect.objectContaining({ id: 7, last_seen_at: null, created_at: null }),
    ]);
  });
});
