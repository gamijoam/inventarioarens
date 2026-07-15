import { describe, it, expect } from 'vitest';
import { UserListResponseSchema, UserSchema } from '../schemas';

describe('UserListResponseSchema', () => {
  it('matchea el shape real del backend', () => {
    const real = {
      data: [
        {
          id: 5,
          name: 'Audit',
          email: 'audit2@local.test',
          status: 'active',
          roles: [],
          created_at: '2026-07-15T11:11:33.000000Z',
        },
        {
          id: 2,
          name: 'Gerente General',
          email: 'grupoprueba@grupoprueba.com',
          status: 'active',
          roles: [
            {
              id: 7,
              name: 'Owner',
              is_protected: true,
              permissions: ['accounts_payable.pay', 'branches.create', 'etc'],
            },
          ],
          created_at: '2026-07-15T10:00:00.000000Z',
        },
      ],
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        links: [
          { url: null, label: '&laquo; Previous', page: null, active: false },
          { url: 'http://localhost:8000/api/users?page=1', label: '1', page: 1, active: true },
          { url: null, label: 'Next &raquo;', page: null, active: false },
        ],
        path: 'http://localhost:8000/api/users',
        per_page: 25,
        to: 3,
        total: 3,
      },
    };

    const parsed = UserListResponseSchema.safeParse(real);
    if (!parsed.success) {
      // eslint-disable-next-line no-console
      console.log('FAIL:', JSON.stringify(parsed.error.flatten(), null, 2));
      // eslint-disable-next-line no-console
      console.log('ISSUES:', JSON.stringify(parsed.error.issues, null, 2));
    }
    expect(parsed.success).toBe(true);
  });

  it('matchea un user individual', () => {
    const real = {
      id: 2,
      name: 'X',
      email: 'x@x.com',
      status: 'active',
      roles: [{ id: 7, name: 'Owner' }],
      created_at: '2026-07-15T10:00:00.000000Z',
    };
    expect(UserSchema.safeParse(real).success).toBe(true);
  });
});