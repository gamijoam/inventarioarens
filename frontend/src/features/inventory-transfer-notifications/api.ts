import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getMany, getOne, postOne } from '@/api/client';

export const IntercompanyNotificationSchema = z.object({
  id: z.number(),
  inventory_transfer_request_id: z.number(),
  event_type: z.string(),
  title: z.string(),
  message: z.string(),
  action_url: z.string(),
  is_read: z.boolean(),
  occurred_at: z.string().nullable(),
});

export type IntercompanyNotification = z.infer<typeof IntercompanyNotificationSchema>;

export const intercompanyNotificationKeys = {
  all: ['inventory-transfer-notifications'] as const,
  list: () => [...intercompanyNotificationKeys.all, 'list'] as const,
  unread: () => [...intercompanyNotificationKeys.all, 'unread'] as const,
};

export function useIntercompanyNotifications(enabled = true) {
  return useQuery({
    queryKey: intercompanyNotificationKeys.list(),
    queryFn: async () => {
      const data = await getMany<unknown>('/inventory-transfer-notifications?per_page=15');
      return z.array(IntercompanyNotificationSchema).parse(data);
    },
    enabled,
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
    staleTime: 5_000,
  });
}

export function useUnreadIntercompanyNotificationsCount(enabled = true) {
  return useQuery({
    queryKey: intercompanyNotificationKeys.unread(),
    queryFn: async () => {
      const result = await getOne<{ count: number }>('/inventory-transfer-notifications/unread-count');
      return result.count;
    },
    enabled,
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
    staleTime: 5_000,
  });
}

export function useMarkIntercompanyNotificationRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => postOne(`/inventory-transfer-notifications/${id}/read`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: intercompanyNotificationKeys.all });
    },
  });
}

export function useMarkAllIntercompanyNotificationsRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => postOne('/inventory-transfer-notifications/read-all', {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: intercompanyNotificationKeys.all });
    },
  });
}
