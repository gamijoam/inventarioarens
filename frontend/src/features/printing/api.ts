import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { api, deleteOne, getMany, getPaginated, patchOne, postOne } from '@/api/client';

const nullableNumber = z.union([z.number(), z.string()]).nullable().optional().transform((value) => {
  if (value == null || value === '') return null;
  return Number(value);
});

export const PrintProfileSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  paper_width_mm: z.union([z.literal(58), z.literal(80)]),
  characters_per_line: z.number().int(),
  header_text: z.string().nullable().optional(),
  footer_text: z.string().nullable().optional(),
  warranty_policy_text: z.string().nullable().optional(),
  legal_text: z.string().nullable().optional(),
  logo_text: z.string().nullable().optional(),
  show_tenant_slug: z.boolean(),
  show_sale_number: z.boolean(),
  show_paid_at: z.boolean(),
  show_cashier: z.boolean(),
  show_cash_register: z.boolean(),
  show_branch: z.boolean(),
  show_customer: z.boolean(),
  show_item_sku: z.boolean(),
  show_item_discount: z.boolean(),
  show_item_serials: z.boolean(),
  show_warranty_summary: z.boolean(),
  show_total_local: z.boolean(),
  show_payment_rate: z.boolean(),
  show_payment_reference: z.boolean(),
  show_receivable_balance: z.boolean(),
  show_non_fiscal_text: z.boolean(),
  cut_paper: z.boolean(),
  open_cash_drawer: z.boolean(),
  copies: z.number().int(),
  is_default: z.boolean(),
  is_active: z.boolean(),
}).passthrough();
export type PrintProfile = z.infer<typeof PrintProfileSchema>;

export const PrinterStationSchema = z.object({
  id: z.number().int(),
  branch_id: z.number().int().nullable().optional(),
  cash_register_id: z.number().int().nullable().optional(),
  print_profile_id: z.number().int(),
  name: z.string(),
  code: z.string(),
  output_mode: z.enum(['thermal', 'digital', 'both']),
  printer_type: z.enum(['windows_printer', 'network']),
  printer_name: z.string().nullable().optional(),
  network_host: z.string().nullable().optional(),
  network_port: nullableNumber,
  digital_directory: z.string().nullable().optional(),
  save_html_copy: z.boolean(),
  is_active: z.boolean(),
  profile: PrintProfileSchema.optional(),
  branch_name: z.string().nullable().optional(),
  cash_register_name: z.string().nullable().optional(),
}).passthrough();
export type PrinterStation = z.infer<typeof PrinterStationSchema>;

export const PrintJobSchema = z.object({
  id: z.number().int(),
  printer_station_id: z.number().int().nullable().optional(),
  print_profile_id: z.number().int().nullable().optional(),
  pos_order_id: z.number().int().nullable().optional(),
  output: z.enum(['thermal', 'digital']),
  status: z.string(),
  is_copy: z.boolean(),
  attempts: z.number().int(),
  digital_pdf_path: z.string().nullable().optional(),
  digital_html_path: z.string().nullable().optional(),
  last_error: z.string().nullable().optional(),
  ticket_html_url: z.string(),
  ticket_pdf_url: z.string(),
  payload_snapshot: z.unknown().optional(),
  station: PrinterStationSchema.optional(),
  profile: PrintProfileSchema.optional(),
}).passthrough();
export type PrintJob = z.infer<typeof PrintJobSchema>;

export interface PrintProfilePayload {
  name: string;
  paper_width_mm: 58 | 80;
  characters_per_line: number;
  header_text?: string | null;
  footer_text?: string | null;
  warranty_policy_text?: string | null;
  legal_text?: string | null;
  logo_text?: string | null;
  show_tenant_slug?: boolean;
  show_sale_number?: boolean;
  show_paid_at?: boolean;
  show_cashier?: boolean;
  show_cash_register?: boolean;
  show_branch?: boolean;
  show_customer?: boolean;
  show_item_sku?: boolean;
  show_item_discount?: boolean;
  show_item_serials?: boolean;
  show_warranty_summary?: boolean;
  show_total_local?: boolean;
  show_payment_rate?: boolean;
  show_payment_reference?: boolean;
  show_receivable_balance?: boolean;
  show_non_fiscal_text?: boolean;
  cut_paper?: boolean;
  open_cash_drawer?: boolean;
  copies?: number;
  is_default?: boolean;
  is_active?: boolean;
}

export interface PrinterStationPayload {
  branch_id?: number | null;
  cash_register_id?: number | null;
  print_profile_id: number;
  name: string;
  code: string;
  output_mode: 'thermal' | 'digital' | 'both';
  printer_type: 'windows_printer' | 'network';
  printer_name?: string | null;
  network_host?: string | null;
  network_port?: number | null;
  digital_directory?: string | null;
  save_html_copy?: boolean;
  is_active?: boolean;
}

export const printingKeys = {
  all: ['printing'] as const,
  profiles: () => [...printingKeys.all, 'profiles'] as const,
  stations: () => [...printingKeys.all, 'stations'] as const,
  jobs: (posOrderId?: number) => [...printingKeys.all, 'jobs', posOrderId ?? 'all'] as const,
};

export function usePrintProfiles() {
  return useQuery({
    queryKey: printingKeys.profiles(),
    queryFn: async () => z.array(PrintProfileSchema).parse(await getMany<unknown>('/printing/profiles')),
  });
}

export function usePrinterStations(options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: printingKeys.stations(),
    queryFn: async () => z.array(PrinterStationSchema).parse(await getMany<unknown>('/printing/stations')),
    enabled: options?.enabled ?? true,
  });
}

export function usePrintJobs(posOrderId?: number) {
  return useQuery({
    queryKey: printingKeys.jobs(posOrderId),
    queryFn: async () => {
      const suffix = posOrderId ? `?pos_order_id=${posOrderId}` : '';
      const response = await getPaginated<unknown>(`/printing/jobs${suffix}`);
      return z.array(PrintJobSchema).parse(response.data);
    },
  });
}

export function useCreatePrintProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: PrintProfilePayload) => postOne<PrintProfilePayload, PrintProfile>('/printing/profiles', payload),
    onSuccess: () => void qc.invalidateQueries({ queryKey: printingKeys.profiles() }),
  });
}

export function useUpdatePrintProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<PrintProfilePayload> }) =>
      patchOne<Partial<PrintProfilePayload>, PrintProfile>(`/printing/profiles/${id}`, payload),
    onSuccess: () => void qc.invalidateQueries({ queryKey: printingKeys.profiles() }),
  });
}

export function useCreatePrinterStation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: PrinterStationPayload) => postOne<PrinterStationPayload, PrinterStation>('/printing/stations', payload),
    onSuccess: () => void qc.invalidateQueries({ queryKey: printingKeys.stations() }),
  });
}

export function useUpdatePrinterStation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<PrinterStationPayload> }) =>
      patchOne<Partial<PrinterStationPayload>, PrinterStation>(`/printing/stations/${id}`, payload),
    onSuccess: () => void qc.invalidateQueries({ queryKey: printingKeys.stations() }),
  });
}

export function useDeletePrinterStation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/printing/stations/${id}`),
    onSuccess: () => void qc.invalidateQueries({ queryKey: printingKeys.stations() }),
  });
}

export function useCreatePosPrintJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ orderId, output, copy, printerStationId }: { orderId: number; output: 'thermal' | 'digital' | 'both'; copy?: boolean; printerStationId?: number | null }) =>
      postOne<{ output: 'thermal' | 'digital' | 'both'; copy?: boolean; printer_station_id?: number | null }, PrintJob[]>(`/pos/orders/${orderId}/print-jobs`, {
        output,
        copy,
        printer_station_id: printerStationId ?? null,
      }),
    onSuccess: (_data, variables) => {
      void qc.invalidateQueries({ queryKey: printingKeys.jobs(variables.orderId) });
    },
  });
}

export function useUpdatePrintJobStatus() {
  return useMutation({
    mutationFn: async ({ jobId, status, message, digitalPdfPath, digitalHtmlPath }: { jobId: number; status: 'sent' | 'printed' | 'generated' | 'failed'; message?: string | null; digitalPdfPath?: string | null; digitalHtmlPath?: string | null }) =>
      patchOne(`/printing/jobs/${jobId}/status`, {
        status,
        message,
        digital_pdf_path: digitalPdfPath ?? null,
        digital_html_path: digitalHtmlPath ?? null,
      }),
  });
}

async function pdfBase64(job: PrintJob): Promise<string | null> {
  if (job.output !== 'digital') return null;

  const response = await api.get<ArrayBuffer>(`/printing/jobs/${job.id}/ticket.pdf`, {
    responseType: 'arraybuffer',
  });
  const bytes = new Uint8Array(response.data);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);

  return window.btoa(binary);
}

export async function sendJobToLocalAgent(job: PrintJob): Promise<{ status: string; pdf_path?: string; html_path?: string; message?: string }> {
  const encodedPdf = await pdfBase64(job);
  const response = await fetch('http://127.0.0.1:17777/print', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      job_id: job.id,
      output: job.output,
      ticket_pdf_url: job.ticket_pdf_url,
      ticket_html_url: job.ticket_html_url,
      pdf_base64: encodedPdf,
      station: job.station,
      payload: job.payload_snapshot,
    }),
  });

  if (!response.ok) {
    throw new Error(`Agente local respondio ${response.status}`);
  }

  return response.json() as Promise<{ status: string; pdf_path?: string; html_path?: string; message?: string }>;
}

export function ticketPdfUrl(job: PrintJob): string {
  return `/api/printing/jobs/${job.id}/ticket.pdf`;
}

export function exampleTicketPayload(profile: PrintProfilePayload | PrintProfile) {
  return {
    tenant: { name: 'DEMO CARACAS', slug: 'demo-caracas' },
    profile: {
      ...profile,
      legal_text: profile.legal_text || 'Documento no fiscal',
      warranty_policy_text: profile.warranty_policy_text || null,
    },
    copy: false,
    pos_order: {
      id: 'PRUEBA',
      sale_id: 'PRUEBA',
      paid_at: new Date().toISOString(),
      customer_name: 'Cliente de prueba',
      cashier_name: 'Cajero Demo',
      branch_name: 'Sucursal Principal',
      cash_register_name: 'Mostrador 1',
    },
    totals: {
      total_base_amount: 30.35,
      total_local_amount: 15175,
      paid_base_amount: 30.35,
      balance_base_amount: 0,
    },
    items: [
      {
        product_name: 'Forro iPhone 11 Transparente',
        sku: 'DEMO-21-CCS',
        quantity: 1,
        unit_price: 30.35,
        total: 30.35,
        discount: 0,
        serials: [{ serial_number: 'IMEI-DEMO-001' }],
        warranty: { name: 'Accesorios 7 dias', duration_days: 7, expires_at: '2026-07-25' },
      },
    ],
    payments: [
      {
        method: 'USD Efectivo',
        currency: 'USD',
        amount: 30.35,
        amount_base: 30.35,
        amount_local: 15175,
        exchange_rate_type_code: 'BCV',
        exchange_rate: 500,
        reference: 'REF-DEMO',
      },
    ],
  };
}

export async function sendTestTicketToLocalAgent(
  output: 'thermal' | 'digital',
  station: PrinterStation,
  profile: PrintProfilePayload | PrintProfile,
): Promise<{ status: string; pdf_path?: string; html_path?: string; message?: string }> {
  const encodedPdf = output === 'digital' ? await previewPdfBase64(profile) : null;
  const response = await fetch('http://127.0.0.1:17777/print', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      job_id: `test-${Date.now()}`,
      output,
      station,
      pdf_base64: encodedPdf,
      payload: exampleTicketPayload(profile),
    }),
  });

  if (!response.ok) {
    throw new Error(`Agente local respondio ${response.status}`);
  }

  return response.json() as Promise<{ status: string; pdf_path?: string; html_path?: string; message?: string }>;
}

async function previewPdfBase64(profile: PrintProfilePayload | PrintProfile): Promise<string> {
  const response = await api.post<ArrayBuffer>('/printing/profiles/preview.pdf', profile, {
    responseType: 'arraybuffer',
  });
  const bytes = new Uint8Array(response.data);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);

  return window.btoa(binary);
}
