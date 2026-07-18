import type { ReceivableListFilters } from './schemas';

export const receivableKeys = {
  all: ['receivables'] as const,
  lists: () => [...receivableKeys.all, 'list'] as const,
  list: (filters: ReceivableListFilters = {}) => [...receivableKeys.lists(), filters] as const,
  details: () => [...receivableKeys.all, 'detail'] as const,
  detail: (id: number) => [...receivableKeys.details(), id] as const,
};
