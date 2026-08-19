import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { api, getOne } from '@/api/client';
import type { PrinterStation } from '@/features/printing/api';

export const PRINTER_AGENT_URL = 'http://127.0.0.1:17777/print';

export const ReportZSchema = z.object({
  z_number: z.number().int().nullable(),
  emitted_at: z.string().nullable().optional(),
  status: z.string(),
  tenant: z.object({ name: z.string(), slug: z.string() }),
  branch: z.string().nullable(),
  cash_register: z.string().nullable(),
  cashier: z.string().nullable(),
  opened_at: z.string().nullable(),
  closed_at: z.string().nullable(),
  totals: z.object({
    orders_count: z.number().int(),
    paid_base_amount: z.number(),
    paid_local_amount: z.number(),
    expected_base_amount: z.number(),
    expected_local_amount: z.number(),
    counted_base_amount: z.number(),
    counted_local_amount: z.number(),
    difference_base_amount: z.number(),
    difference_local_amount: z.number(),
    difference_cash_usd: z.number(),
    difference_cash_ves: z.number(),
  }),
  payments: z.array(
    z.object({
      name: z.string(),
      method: z.string(),
      currency: z.string(),
      payments_count: z.number().int(),
      amount_base: z.number(),
      amount_local: z.number(),
      exchange_rate: z.number().nullable().optional(),
    }),
  ),
  counts: z.array(
    z.object({
      currency: z.string(),
      denomination: z.number(),
      quantity: z.number(),
      total_amount: z.number(),
    }),
  ),
});
export type ReportZ = z.infer<typeof ReportZSchema>;

export function useReportZ(sessionId: number | null, enabled = true) {
  return useQuery<ReportZ>({
    queryKey: ['cash-register', 'report-z', sessionId] as const,
    queryFn: async () =>
      ReportZSchema.parse(await getOne<unknown>(`/cash-register/sessions/${sessionId}/report-z`)),
    enabled: enabled && sessionId != null,
    staleTime: 60_000,
  });
}

export async function openReportZPdf(sessionId: number): Promise<void> {
  const response = await api.get(`/cash-register/sessions/${sessionId}/report-z.pdf`, {
    responseType: 'blob',
  });
  const url = URL.createObjectURL(response.data as Blob);
  window.open(url, '_blank');
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export async function downloadReportZPdf(sessionId: number): Promise<void> {
  const response = await api.get(`/cash-register/sessions/${sessionId}/report-z.pdf`, {
    responseType: 'blob',
  });
  const url = URL.createObjectURL(response.data as Blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `reporte-z-${sessionId}.pdf`;
  anchor.click();
  URL.revokeObjectURL(url);
}

export interface ReportZPrintResult {
  ok?: boolean;
  status?: string;
  message?: string;
}

/**
 * Envia el Reporte Z al agente local de impresion termica (printer:serve).
 */
export async function printReportZThermal(
  z: ReportZ,
  station: PrinterStation,
): Promise<ReportZPrintResult> {
  const payload = {
    output: 'thermal',
    station: {
      printer_type: station.printer_type,
      printer_name: station.printer_name ?? null,
      network_host: station.network_host ?? null,
      network_port: station.network_port ?? null,
      digital_directory: station.digital_directory ?? null,
    },
    copy: false,
    payload: {
      doc: 'report_z',
      z_number: z.z_number,
      tenant: { name: z.tenant.name, slug: z.tenant.slug },
      cash_register: z.cash_register,
      branch: z.branch,
      cashier: z.cashier,
      opened_at: z.opened_at,
      closed_at: z.closed_at,
      totals: z.totals,
      payments: z.payments,
      profile: {
        paper_width_mm: station.profile?.paper_width_mm ?? 58,
        cut_paper: station.profile?.cut_paper ?? true,
        open_cash_drawer: station.profile?.open_cash_drawer ?? false,
        legal_text: station.profile?.legal_text ?? 'Documento no fiscal',
        show_non_fiscal_text: station.profile?.show_non_fiscal_text ?? true,
        footer_text: station.profile?.footer_text ?? null,
      },
    },
  };

  const response = await fetch(PRINTER_AGENT_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    throw new Error(`Agente local respondio ${response.status}`);
  }

  return response.json() as Promise<ReportZPrintResult>;
}
