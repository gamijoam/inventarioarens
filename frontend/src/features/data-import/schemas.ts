/**
 * Schemas Zod del modulo de Importacion Masiva.
 */
import { z } from 'zod';

export const DataImportStatusSchema = z.enum([
  'pending',
  'running',
  'completed',
  'failed',
  'cancelled',
]);

export const DataImportEntityStatusSchema = z.enum([
  'pending',
  'running',
  'completed',
  'failed',
]);

export const DataImportRowStatusSchema = z.enum(['ok', 'skipped', 'failed']);

export const DataImportEntitySchema = z.object({
  id: z.number(),
  data_import_id: z.number(),
  entity: z.string(),
  status: DataImportEntityStatusSchema,
  total_rows: z.number(),
  succeeded_rows: z.number(),
  skipped_rows: z.number(),
  failed_rows: z.number(),
  error_summary: z.array(z.unknown()).nullable().optional(),
  started_at: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
});

export const DataImportSchema = z.object({
  id: z.number(),
  tenant_id: z.number(),
  user_id: z.number(),
  status: DataImportStatusSchema,
  total_entities: z.number(),
  total_rows: z.number(),
  processed_rows: z.number(),
  succeeded_rows: z.number(),
  skipped_rows: z.number(),
  failed_rows: z.number(),
  meta: z.unknown().nullable().optional(),
  started_at: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
  entities: z.array(DataImportEntitySchema).optional(),
});
