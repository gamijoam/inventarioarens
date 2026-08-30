import { useState } from 'react';
import { ChevronLeft, ChevronRight, FileText, Search } from 'lucide-react';

import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { FiscalDocumentPreviewViewerDialog } from '@/features/sales/FiscalDocumentPreviewDialog';
import { PERMISSIONS } from '@/permissions/constants';
import { useCanAny } from '@/permissions/useCan';
import type { PaginationMeta } from '@/types/api';
import {
  useFiscalDocumentPreviews,
  type FiscalDocumentPreview,
  type FiscalDocumentPreviewFilters,
} from './api';

export type FiscalDocumentsSearch = FiscalDocumentPreviewFilters;

function formatMoney(value: number): string {
  return `$${value.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '-'
    : date.toLocaleString('es-VE', { dateStyle: 'short', timeStyle: 'short' });
}

export function FiscalDocumentsManager({
  search,
  onSearchChange,
}: {
  search: FiscalDocumentsSearch;
  onSearchChange: (patch: FiscalDocumentsSearch) => void;
}) {
  const canView = useCanAny([
    PERMISSIONS.SALES_VIEW,
    PERMISSIONS.REPORTS_VIEW,
    PERMISSIONS.REPORTS_SALES_VIEW,
  ]);
  const [selected, setSelected] = useState<FiscalDocumentPreview | null>(null);
  const filters: FiscalDocumentPreviewFilters = {
    ...search,
    status: 'preview',
    page: search.page ?? 1,
    per_page: search.per_page ?? 25,
  };
  const previews = useFiscalDocumentPreviews(filters, canView);
  const meta = previews.data?.meta;

  if (!canView) {
    return (
      <PermissionDenied
        permission={`${PERMISSIONS.SALES_VIEW} / ${PERMISSIONS.REPORTS_VIEW}`}
        message="No tienes permiso para consultar documentos internos."
      />
    );
  }

  function updateFilter<K extends keyof FiscalDocumentsSearch>(
    key: K,
    value: FiscalDocumentsSearch[K] | undefined,
  ) {
    onSearchChange({ ...search, [key]: value, ...(key === 'page' ? {} : { page: 1 }) });
  }

  function openPreview(preview: FiscalDocumentPreview) {
    setSelected(preview);
  }

  if (previews.isLoading && !previews.data) return <Skeleton className="h-72 w-full" />;

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="grid gap-3 p-4 md:grid-cols-5 md:items-end">
          <div className="md:col-span-2">
            <Label htmlFor="fiscal-documents-sale" className="text-text-muted text-xs">
              Venta
            </Label>
            <div className="relative mt-1">
              <Search className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
              <Input
                id="fiscal-documents-sale"
                type="number"
                min="1"
                value={search.sale_id ?? ''}
                onChange={(event) => updateFilter('sale_id', toPositiveInt(event.target.value))}
                placeholder="Número de venta"
                className="pl-9"
              />
            </div>
          </div>
          <div>
            <Label htmlFor="fiscal-documents-from" className="text-text-muted text-xs">
              Desde
            </Label>
            <Input
              id="fiscal-documents-from"
              type="date"
              value={search.date_from ?? ''}
              onChange={(event) => updateFilter('date_from', event.target.value || undefined)}
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="fiscal-documents-to" className="text-text-muted text-xs">
              Hasta
            </Label>
            <Input
              id="fiscal-documents-to"
              type="date"
              value={search.date_to ?? ''}
              onChange={(event) => updateFilter('date_to', event.target.value || undefined)}
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="fiscal-documents-per-page" className="text-text-muted text-xs">
              Por página
            </Label>
            <Select
              id="fiscal-documents-per-page"
              value={String(filters.per_page)}
              onChange={(event) => updateFilter('per_page', Number(event.target.value))}
              className="mt-1"
            >
              {[25, 50, 100].map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </Select>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-3 md:grid-cols-3">
        <InfoTile label="Previews encontrados" value={String(meta?.total ?? 0)} />
        <InfoTile label="Estado" value="Interno · No emitido" />
        <InfoTile label="Alcance" value="Tenant actual" />
      </div>

      {previews.isError ? (
        <EmptyState
          title="No se pudo cargar el historial"
          description="Revisa tu conexión o intenta nuevamente."
          action={<Button onClick={() => void previews.refetch()}>Reintentar</Button>}
        />
      ) : previews.data?.data.length === 0 ? (
        <EmptyState
          icon={<FileText className="size-8" aria-hidden="true" />}
          title="Sin previews internos"
          description="Las vistas previas creadas desde ventas confirmadas aparecerán aquí."
        />
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="table-dense w-full">
              <thead className="border-border bg-bg/60 border-b text-left">
                <tr>
                  <th className="px-3 py-2">Fecha</th>
                  <th className="px-3 py-2">Venta</th>
                  <th className="px-3 py-2">Cliente</th>
                  <th className="px-3 py-2">Empresa</th>
                  <th className="px-3 py-2 text-right">Total USD</th>
                  <th className="px-3 py-2">Estado</th>
                  <th className="px-3 py-2 text-right">Acción</th>
                </tr>
              </thead>
              <tbody>
                {previews.data?.data.map((preview) => (
                  <PreviewRow
                    key={preview.id}
                    preview={preview}
                    onOpen={() => openPreview(preview)}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={(page) => updateFilter('page', page)} />}
        </Card>
      )}

      <FiscalDocumentPreviewViewerDialog
        preview={selected}
        open={selected !== null}
        onOpenChange={(open) => {
          if (!open) setSelected(null);
        }}
      />
    </div>
  );
}

function PreviewRow({ preview, onOpen }: { preview: FiscalDocumentPreview; onOpen: () => void }) {
  return (
    <tr className="border-border border-b last:border-0">
      <td className="text-text-muted px-3 py-2">{formatDate(preview.snapshot_at)}</td>
      <td className="px-3 py-2 font-medium">#{preview.sale_id}</td>
      <td className="px-3 py-2">
        {preview.customer_snapshot?.fiscal_name ??
          preview.customer_snapshot?.name ??
          'Consumidor final'}
      </td>
      <td className="px-3 py-2">{preview.company_snapshot.legal_name ?? '-'}</td>
      <td className="px-3 py-2 text-right tabular-nums">
        {formatMoney(preview.totals_snapshot.total_base_amount)}
      </td>
      <td className="px-3 py-2">
        <Badge variant="warning">No emitido</Badge>
      </td>
      <td className="px-3 py-2 text-right">
        <Button size="sm" variant="outline" onClick={onOpen}>
          Abrir
        </Button>
      </td>
    </tr>
  );
}

function Pagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
}) {
  return (
    <div className="border-border text-text-muted flex items-center justify-between border-t px-4 py-3 text-sm">
      <span>
        Página {meta.current_page} de {meta.last_page}
      </span>
      <div className="flex gap-2">
        <Button
          size="sm"
          variant="secondary"
          disabled={meta.current_page <= 1}
          leftIcon={<ChevronLeft className="size-4" />}
          onClick={() => onPageChange(meta.current_page - 1)}
        >
          Anterior
        </Button>
        <Button
          size="sm"
          variant="secondary"
          disabled={meta.current_page >= meta.last_page}
          rightIcon={<ChevronRight className="size-4" />}
          onClick={() => onPageChange(meta.current_page + 1)}
        >
          Siguiente
        </Button>
      </div>
    </div>
  );
}

function InfoTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-border bg-surface rounded border px-3 py-2">
      <div className="text-text-muted text-xs uppercase">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}

function toPositiveInt(value: string): number | undefined {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : undefined;
}
