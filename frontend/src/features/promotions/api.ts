import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, patchOne, postOne } from '@/api/client';

import {
  PromotionSchema,
  type Promotion,
  type PromotionDomain,
  StorePromotionSchema,
  type StorePromotionInput,
} from './schemas';

interface PosPromotionDiscoveryOptions {
  warehouseId: number | null;
  productIds: number[];
  enabled?: boolean;
  selectable?: boolean;
}

const endpointByDomain: Record<PromotionDomain, string> = {
  invoice: '/invoice-promotions',
  combo: '/combos',
  product_offer: '/product-offers',
};

const posEndpointByDomain: Record<PromotionDomain, string> = {
  invoice: '/pos/invoice-promotions',
  combo: '/pos/combos',
  product_offer: '/pos/product-offers',
};

export const promotionKeys = {
  all: ['promotions'] as const,
  admin: () => [...promotionKeys.all, 'admin'] as const,
  invoicePromotions: (activeOnly?: boolean) =>
    [...promotionKeys.admin(), 'invoice', { activeOnly: activeOnly ?? false }] as const,
  combos: (activeOnly?: boolean) =>
    [...promotionKeys.admin(), 'combo', { activeOnly: activeOnly ?? false }] as const,
  productOffers: (activeOnly?: boolean) =>
    [...promotionKeys.admin(), 'product-offer', { activeOnly: activeOnly ?? false }] as const,
  pos: () => [...promotionKeys.all, 'pos'] as const,
  posInvoicePromotions: (warehouseId: number | null, productIds: number[], selectable: boolean) =>
    [...promotionKeys.pos(), 'invoice', warehouseId, productIds, selectable] as const,
  posCombos: (warehouseId: number | null, productIds: number[], selectable: boolean) =>
    [...promotionKeys.pos(), 'combo', warehouseId, productIds, selectable] as const,
  posProductOffers: (warehouseId: number | null, productIds: number[], selectable: boolean) =>
    [...promotionKeys.pos(), 'product-offer', warehouseId, productIds, selectable] as const,
  available: (warehouseId: number | null, productIds: number[], selectable: boolean) =>
    [...promotionKeys.all, 'available', warehouseId, productIds, selectable] as const,
};

const promotionListSchema = z.array(PromotionSchema);

function parsePromotions(data: unknown): Promotion[] {
  return promotionListSchema.parse(data);
}

function adminListPath(domain: PromotionDomain, activeOnly: boolean): string {
  return `${endpointByDomain[domain]}${activeOnly ? '?active_only=1' : ''}`;
}

function posDiscoveryPath(domain: PromotionDomain, options: PosPromotionDiscoveryOptions): string {
  const params = new URLSearchParams();
  params.set('warehouse_id', String(options.warehouseId));
  if (options.selectable) params.set('selectable', '1');
  options.productIds.forEach((id) => params.append('product_ids[]', String(id)));
  return `${posEndpointByDomain[domain]}?${params.toString()}`;
}

function useAdminPromotions(
  domain: PromotionDomain,
  queryKey: readonly unknown[],
  activeOnly: boolean,
) {
  return useQuery({
    queryKey,
    queryFn: async () => parsePromotions(await getMany<unknown>(adminListPath(domain, activeOnly))),
  });
}

export function useInvoicePromotions(activeOnly = false) {
  return useAdminPromotions('invoice', promotionKeys.invoicePromotions(activeOnly), activeOnly);
}

export function useCombos(activeOnly = false) {
  return useAdminPromotions('combo', promotionKeys.combos(activeOnly), activeOnly);
}

export function useProductOffers(activeOnly = false) {
  return useAdminPromotions('product_offer', promotionKeys.productOffers(activeOnly), activeOnly);
}

function usePosPromotionDiscovery(
  domain: PromotionDomain,
  queryKey: readonly unknown[],
  options: PosPromotionDiscoveryOptions,
) {
  return useQuery({
    queryKey,
    enabled: options.enabled !== false && options.warehouseId !== null,
    queryFn: async () => parsePromotions(await getMany<unknown>(posDiscoveryPath(domain, options))),
  });
}

export function usePosInvoicePromotions(options: PosPromotionDiscoveryOptions) {
  return usePosPromotionDiscovery(
    'invoice',
    promotionKeys.posInvoicePromotions(
      options.warehouseId,
      options.productIds,
      options.selectable ?? false,
    ),
    options,
  );
}

export function usePosCombos(options: PosPromotionDiscoveryOptions) {
  const comboOptions = { ...options, productIds: [] };

  return usePosPromotionDiscovery(
    'combo',
    promotionKeys.posCombos(
      comboOptions.warehouseId,
      comboOptions.productIds,
      comboOptions.selectable ?? false,
    ),
    comboOptions,
  );
}

export function usePosProductOffers(options: PosPromotionDiscoveryOptions) {
  return usePosPromotionDiscovery(
    'product_offer',
    promotionKeys.posProductOffers(
      options.warehouseId,
      options.productIds,
      options.selectable ?? false,
    ),
    options,
  );
}

// Retained until the current POS cart switches to the three scoped discovery hooks.
export function useAvailablePosPromotions(options: PosPromotionDiscoveryOptions) {
  return useQuery({
    queryKey: promotionKeys.available(
      options.warehouseId,
      options.productIds,
      options.selectable ?? false,
    ),
    enabled: options.enabled !== false && options.warehouseId !== null,
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('warehouse_id', String(options.warehouseId));
      if (options.selectable) params.set('selectable', '1');
      options.productIds.forEach((id) => params.append('product_ids[]', String(id)));
      return parsePromotions(
        await getMany<unknown>(`/pos/promotions/available?${params.toString()}`),
      );
    },
  });
}

function useCreateDomainPromotion(domain: PromotionDomain) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: StorePromotionInput) =>
      postOne<StorePromotionInput, Promotion>(
        endpointByDomain[domain],
        StorePromotionSchema.parse(input),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: promotionKeys.admin() });
    },
  });
}

function useUpdateDomainPromotion(domain: PromotionDomain) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...input }: StorePromotionInput & { id: number }) =>
      patchOne<StorePromotionInput, Promotion>(
        `${endpointByDomain[domain]}/${id}`,
        StorePromotionSchema.parse(input),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: promotionKeys.admin() });
    },
  });
}

function useDeleteDomainPromotion(domain: PromotionDomain) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) => deleteOne(`${endpointByDomain[domain]}/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: promotionKeys.admin() });
    },
  });
}

export function useCreateInvoicePromotion() {
  return useCreateDomainPromotion('invoice');
}

export function useUpdateInvoicePromotion() {
  return useUpdateDomainPromotion('invoice');
}

export function useDeleteInvoicePromotion() {
  return useDeleteDomainPromotion('invoice');
}

export function useCreateCombo() {
  return useCreateDomainPromotion('combo');
}

export function useUpdateCombo() {
  return useUpdateDomainPromotion('combo');
}

export function useDeleteCombo() {
  return useDeleteDomainPromotion('combo');
}

export function useCreateProductOffer() {
  return useCreateDomainPromotion('product_offer');
}

export function useUpdateProductOffer() {
  return useUpdateDomainPromotion('product_offer');
}

export function useDeleteProductOffer() {
  return useDeleteDomainPromotion('product_offer');
}
