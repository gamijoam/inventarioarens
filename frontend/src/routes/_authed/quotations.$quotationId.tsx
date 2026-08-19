import { useState } from 'react';
import { ArrowLeft, Download, RefreshCw, Trash2 } from 'lucide-react';
import { Link, createFileRoute } from '@tanstack/react-router';
import { toast } from 'sonner';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';
import { useQuotation, useCancelQuotation, useConvertQuotation, quotationPdfUrl } from '@/features/quotations/api';
import { QuotationCreateDialog } from '@/features/quotations/QuotationCreateDialog';

export const Route = createFileRoute('/_authed/quotations/$quotationId')({
  component: QuotationDetailPage,
});

const STATUS_LABELS: Record<string, { label: string; variant: 'default' | 'success' | 'warning' | 'danger' }> = {
  draft: { label: 'Borrador', variant: 'default' },
  issued: { label: 'Emitida', variant: 'success' },
  cancelled: { label: 'Cancelada', variant: 'danger' },
  converted: { label: 'Convertida', variant: 'warning' },
};

function QuotationDetailPage() {
  const { quotationId } = Route.useParams();
  const id = parseInt(quotationId, 10);
  const canConvert = useCan(PERMISSIONS.QUOTATIONS_CONVERT);
  const canCancel = useCan(PERMISSIONS.QUOTATIONS_DELETE);
  const { data: quotation, isLoading, isError } = useQuotation(id);
  const cancel = useCancelQuotation();
  const convert = useConvertQuotation();
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [confirmConvert, setConfirmConvert] = useState(false);
  const [editOpen, setEditOpen] = useState(false);

  if (isLoading) {
    return (
      <PageLayout title="Cargando cotizacion...">
        <Spinner label="Cargando..." />
      </PageLayout>
    );
  }

  if (isError || !quotation) {
    return (
      <PageLayout title="Cotizacion no encontrada">
        <EmptyState title="No se encontro la cotizacion" description="Puede haber sido eliminada o no tienes permiso." />
      </PageLayout>
    );
  }

  const meta = STATUS_LABELS[quotation.status] ?? { label: quotation.status, variant: 'default' as const };

  return (
    <PageLayout
      title={quotation.document_number}
      description={`Cliente: ${quotation.customer_name ?? 'Consumidor Final'}`}
      breadcrumb={
        <Link
          to="/quotations"
          className="inline-flex items-center gap-1 text-xs text-text-muted hover:text-primary"
        >
          <ArrowLeft className="size-3" /> Cotizaciones
        </Link>
      }
      actions={
        <div className="flex items-center gap-2">
          <a
            href={quotationPdfUrl(quotation.id)}
            target="_blank"
            rel="noreferrer"
            className="inline-flex"
          >
            <Button variant="outline" data-testid="quotation-pdf">
              <Download className="size-4" /> PDF
            </Button>
          </a>
          {quotation.status === 'issued' && canConvert && (
            <Button onClick={() => setConfirmConvert(true)} data-testid="quotation-convert">
              <RefreshCw className="size-4" /> Convertir a venta
            </Button>
          )}
          {(quotation.status === 'draft' || quotation.status === 'issued') && canCancel && (
              <Button variant="danger" onClick={() => setConfirmCancel(true)} data-testid="quotation-cancel">
                <Trash2 className="size-4" /> Cancelar
              </Button>
            )}
        </div>
      }
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <Badge variant={meta.variant} data-testid="quotation-status">
            {meta.label}
          </Badge>
          {quotation.valid_until && (
            <span className="text-text-muted text-sm">Valida hasta {quotation.valid_until}</span>
          )}
          {quotation.converted_pos_order_id && (
            <Link
              to="/pos"
              className="text-primary text-sm underline"
              title="Orden POS generada"
            >
              Orden POS #{quotation.converted_pos_order_id}
            </Link>
          )}
        </div>

        {quotation.notes && (
          <Card>
            <CardHeader><CardTitle>Notas</CardTitle></CardHeader>
            <CardContent className="text-sm whitespace-pre-wrap">{quotation.notes}</CardContent>
          </Card>
        )}

        <Card>
          <CardHeader>
            <CardTitle>Items ({quotation.items?.length ?? 0})</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Producto</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Variante</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Cant.</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Precio unit.</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Total</th>
                </tr>
              </thead>
              <tbody>
                {(quotation.items ?? []).map((item) => (
                  <tr key={item.id} className="border-b border-border last:border-b-0">
                    <td className="px-3 py-2">{item.product_name}</td>
                    <td className="px-3 py-2 text-text-muted">{item.product_variant_id ? `#${item.product_variant_id}` : '—'}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{item.quantity}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(item.unit_price_base)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(item.total_base)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <div className="space-y-1 text-right">
            <div className="text-sm text-text-muted">
              Subtotal: {formatMoney(quotation.subtotal_base_amount)}
            </div>
            <div className="text-lg font-bold">
              Total: {formatMoney(quotation.total_base_amount)}
            </div>
            {quotation.total_local_amount > 0 && (
              <div className="text-sm text-text-muted">
                Total Bs: {formatMoney(quotation.total_local_amount)}
              </div>
            )}
          </div>
        </div>
      </div>

      <ConfirmDialog
        open={confirmConvert}
        onOpenChange={setConfirmConvert}
        title="Convertir cotizacion en venta"
        description="Se creara una orden POS pendiente para cobrarla desde el terminal. ¿Continuar?"
        confirmLabel="Convertir"
        onConfirm={async () => {
          try {
            await convert.mutateAsync(quotation.id);
            toast.success('Cotizacion convertida a venta.');
          } catch (error) {
            toast.error(error instanceof Error ? error.message : 'No se pudo convertir.');
          }
        }}
      />

      <ConfirmDialog
        open={confirmCancel}
        onOpenChange={setConfirmCancel}
        title="Cancelar cotizacion"
        description="La cotizacion quedara en estado cancelada."
        confirmLabel="Cancelar cotizacion"
        variant="danger"
        onConfirm={async () => {
          try {
            await cancel.mutateAsync(quotation.id);
            toast.success('Cotizacion cancelada.');
          } catch (error) {
            toast.error(error instanceof Error ? error.message : 'No se pudo cancelar.');
          }
        }}
      />

      <QuotationCreateDialog open={editOpen} onOpenChange={setEditOpen} />
    </PageLayout>
  );
}
