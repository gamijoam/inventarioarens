export const payableKeys = {
  all: ['payables'] as const,
  lists: () => [...payableKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...payableKeys.lists(), filters] as const,
  details: () => [...payableKeys.all, 'detail'] as const,
  detail: (id: number) => [...payableKeys.details(), id] as const,
};
