export const reportKeys = {
  all: ['reports'] as const,
  stock: (filters: unknown) => [...reportKeys.all, 'stock', filters] as const,
  lowStock: (filters: unknown) => [...reportKeys.all, 'low-stock', filters] as const,
  movements: (filters: unknown) => [...reportKeys.all, 'movements', filters] as const,
  financeSummary: (filters: unknown) => [...reportKeys.all, 'finance-summary', filters] as const,
  financeReceivables: (filters: unknown) =>
    [...reportKeys.all, 'finance-receivables', filters] as const,
  financePayables: (filters: unknown) => [...reportKeys.all, 'finance-payables', filters] as const,
};
