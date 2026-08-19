import { useState } from 'react';
import { Download, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card, CardContent } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useNavigate } from '@tanstack/react-router';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';
import { formatMoney } from '@/lib/money';
import {
  useQuotations,
  useCancelQuotation,
  useConvertQuotation,
  openQuotationPdf,
} from './api';
import { QuotationCreateDialog } from './QuotationCreateDialog';

const STATUS_LABELS: Record<string, { label: string; variant: 'default' | 'success' | 'warning' | 'danger' }> = {
  draft: { label: 'Borrador', variant: 'default' },
  issued: { label: 'Emitida', variant: 'success' },
  cancelled: { label: 'Cancelada', variant: 'danger' },
  converted: { label: 'Convertida', variant: 'warning' },
};

export function QuotationsManager() {
  const canConvert = useCan(PERMISSIONS.QUOTATIONS_CONVERT);
  const canCancel = useCan(PERMISSIONS.QUOTATIONS_DELETE);
  const canHold = useCan(PERMISSIONS.POS_ORDERS_HOLD);
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [createOpen, setCreateOpen] = useState(false);
  const [pendingConvert, setPendingConvert] = useState<number | null>(null);
  const [pendingCancel, setPendingCancel] = useState<number | null>(null);

  const { data: quotations = [], isLoading } = useQuotations({ search, status });
  const convert = useConvertQuotation();
  const cancel = useCancelQuotation();

  return (
    <PageLayout
      title="Cotizaciones"
      description="Presupuestos de venta para clientes. Convierte una emitida en orden POS para cobrar."
      actions={
        <Button onClick={() => setCreateOpen(true)} data-testid="new-quotation">
          <Plus className="size-4" /> Nueva cotizacion
        </Button>
      }
    >
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar por numero o cliente..."
          className="max-w-xs"
          data-testid="quotation-search"
        />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="border-border-strong bg-surface rounded border px-3 py-2 text-sm"
          data-testid="quotation-status-filter"
        >
          <option value="">Todos los estados</option>
          <option value="draft">Borrador</option>
          <option value="issued">Emitida</option>
          <option value="converted">Convertida</option>
          <option value="cancelled">Cancelada</option>
        </select>
      </div>

      {isLoading ? (
        <Spinner label="Cargando cotizaciones..." />
      ) : quotations.length === 0 ? (
        <EmptyState
          title="Sin cotizaciones"
          description="Crea una cotizacion para presupuestar una venta a un cliente."
          action={
            <Button onClick={() => setCreateOpen(true)} data-testid="empty-new-quotation">
              <Plus className="size-4" /> Nueva cotizacion
            </Button>
          }
        />
      ) : (
        <Card>
          <CardContent className="p-0">
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Numero</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Cliente</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Total</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Vence</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Creada</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {quotations.map((q) => {
                  const meta = STATUS_LABELS[q.status] ?? { label: q.status, variant: 'default' as const };
                  return (
                    <tr key={q.id} className="border-b border-border last:border-b-0">
                      <td className="px-3 py-2">
                        <button
                          type="button"
                          className="text-primary font-mono text-sm hover:underline"
                          onClick={() => navigate({ to: `/quotations/${q.id}` })}
                        >
                          {q.document_number}
                        </button>
                      </td>
                      <td className="px-3 py-2">{q.customer_name ?? '—'}</td>
                      <td className="px-3 py-2">
                        <Badge variant={meta.variant} data-testid={`quotation-status-${q.id}`}>
                          {meta.label}
                        </Badge>
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(q.total_base_amount)}</td>
                      <td className="px-3 py-2">{q.valid_until ?? '—'}</td>
                      <td className="px-3 py-2 text-text-muted">
                        {q.created_at ? new Date(q.created_at).toLocaleDateString() : '—'}
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center justify-end gap-1">
                          <button
                            type="button"
                            className="inline-flex items-center gap-1 rounded p-1.5 text-sm hover:bg-bg"
                            title="Descargar PDF"
                            data-testid={`quotation-pdf-${q.id}`}
                            onClick={() => {
                              void openQuotationPdf(q.id).catch(() =>
                                toast.error('No se pudo abrir el PDF de la cotizacion.'),
                              );
                            }}
                          >
                            <Download className="size-4" />
                          </button>
                          {q.status === 'issued' && canConvert && (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              onClick={() => setPendingConvert(q.id)}
                              data-testid={`quotation-convert-${q.id}`}
                            >
                              Convertir a venta
                            </Button>
                          )}
                          {(q.status === 'draft' || q.status === 'issued') && canCancel && (
                              <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                onClick={() => setPendingCancel(q.id)}
                                title="Cancelar cotizacion"
                                data-testid={`quotation-cancel-${q.id}`}
                              >
                                <Trash2 className="text-danger size-4" />
                              </Button>
                            )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

      <QuotationCreateDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreated={(id) => navigate({ to: `/quotations/${id}` })}
      />

      <ConfirmDialog
        open={pendingConvert !== null}
        onOpenChange={(open) => !open && setPendingConvert(null)}
        title="Convertir cotizacion en venta"
        description="Se creara una orden POS pendiente con los items de la cotizacion para cobrarla desde el terminal. ¿Continuar?"
        confirmLabel="Convertir"
        onConfirm={async () => {
          if (pendingConvert == null) return;
          try {
            const result = (await convert.mutateAsync(pendingConvert)) as {
              quotation?: { document_number?: string };
            };
            toast.success(`Cotizacion ${result.quotation?.document_number ?? ''} convertida a venta.`);
            if (canHold) {
              navigate({ to: '/pos' });
            }
          } catch (error) {
            toast.error(error instanceof Error ? error.message : 'No se pudo convertir.');
          }
        }}
      />

      <ConfirmDialog
        open={pendingCancel !== null}
        onOpenChange={(open) => !open && setPendingCancel(null)}
        title="Cancelar cotizacion"
        description="La cotizacion quedara en estado cancelada y no podra convertirse en venta."
        confirmLabel="Cancelar cotizacion"
        variant="danger"
        onConfirm={async () => {
          if (pendingCancel == null) return;
          try {
            await cancel.mutateAsync(pendingCancel);
            toast.success('Cotizacion cancelada.');
          } catch (error) {
            toast.error(error instanceof Error ? error.message : 'No se pudo cancelar.');
          }
        }}
      />
    </PageLayout>
  );
}
