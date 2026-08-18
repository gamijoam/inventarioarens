import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne } from '@/api/client';

export const OrganizationCompanySchema = z.object({
  tenant_id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  sales: z.object({
    confirmed_count: z.number().int(),
    total_base_amount: z.number(),
  }),
  pos: z.object({
    paid_orders_count: z.number().int(),
    paid_base_amount: z.number(),
  }),
  cash_register: z.object({
    open_sessions_count: z.number().int(),
  }),
  inventory: z.object({
    low_stock_count: z.number().int(),
  }),
  finance: z.object({
    accounts_receivable_balance_base_amount: z.number(),
    accounts_payable_balance_base_amount: z.number(),
  }),
});

export const OrganizationDashboardSchema = z.object({
  scope: z.literal('organization'),
  group: z.object({
    id: z.number().int(),
    name: z.string(),
    slug: z.string(),
  }),
  period: z.object({
    from: z.string(),
    to: z.string(),
  }),
  totals: z.object({
    sales_count: z.number().int(),
    sales_total_base_amount: z.number(),
    pos_orders_count: z.number().int(),
    pos_paid_base_amount: z.number(),
    open_cash_sessions: z.number().int(),
    receivable_balance_base_amount: z.number(),
    payable_balance_base_amount: z.number(),
    low_stock_count: z.number().int(),
  }),
  companies: z.array(OrganizationCompanySchema),
});

export type OrganizationCompany = z.infer<typeof OrganizationCompanySchema>;
export type OrganizationDashboard = z.infer<typeof OrganizationDashboardSchema>;

export interface OrganizationDashboardParams {
  period: 'today' | 'week' | 'month' | 'custom';
  dateFrom?: string;
  dateTo?: string;
}

export function useOrganizationDashboard({
  period,
  dateFrom,
  dateTo,
}: OrganizationDashboardParams) {
  const query = new URLSearchParams();
  query.set('scope', 'organization');
  if (period !== 'custom') query.set('period', period);
  if (period === 'custom' && dateFrom && dateTo) {
    query.set('date_from', dateFrom);
    query.set('date_to', dateTo);
  }

  return useQuery({
    queryKey: ['dashboard', 'organization', period, dateFrom, dateTo],
    queryFn: async () => {
      const raw = await getOne<unknown>(`/dashboard/summary?${query.toString()}`);
      return OrganizationDashboardSchema.parse(raw);
    },
    refetchInterval: 30_000,
  });
}
