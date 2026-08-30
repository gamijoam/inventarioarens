import { useState } from 'react';
import { FileText } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import {
  getFiscalDocumentPreview,
  getFiscalDocumentPreviews,
  useCreateFiscalDocumentPreview,
  type FiscalDocumentPreview,
} from '@/features/fiscal-documents/api';
import type { Sale } from './schemas';

interface FiscalDocumentPreviewDialogProps {
  sale: Sale;
}

function formatMoney(value: number): string {
  return `$${value.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function snapshotText(snapshot: Record<string, unknown> | null | undefined): string {
  const value = snapshot?.name ?? snapshot?.branch_name ?? snapshot?.legal_name;
  return typeof value === 'string' && value.length > 0 ? value : '-';
}

export function FiscalDocumentPreviewDialog({ sale }: FiscalDocumentPreviewDialogProps) {
  const [open, setOpen] = useState(false);
  const [preview, setPreview] = useState<FiscalDocumentPreview | null>(null);
  const createPreview = useCreateFiscalDocumentPreview();

  async function handleCreate(): Promise<void> {
    try {
      const existing = await getFiscalDocumentPreviews({
        sale_id: sale.id,
        status: 'preview',
        per_page: 1,
      });
      const existingPreview = existing.data[0];

      if (existingPreview) {
        setPreview(await getFiscalDocumentPreview(existingPreview.id));
        setOpen(true);
        return;
      }

      const result = await createPreview.mutateAsync(sale.id);
      setPreview(result);
      setOpen(true);
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'No se pudo crear la vista previa interna.',
      );
    }
  }

  return (
    <>
      <Button
        variant="secondary"
        size="sm"
        loading={createPreview.isPending}
        leftIcon={<FileText className="size-4" />}
        onClick={() => void handleCreate()}
      >
        Vista previa interna
      </Button>

      <FiscalDocumentPreviewViewerDialog preview={preview} open={open} onOpenChange={setOpen} />
    </>
  );
}

export function FiscalDocumentPreviewViewerDialog({
  preview,
  open,
  onOpenChange,
}: {
  preview: FiscalDocumentPreview | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="fiscal-preview-print max-w-2xl">
        <DialogHeader>
          <DialogTitle>Vista previa interna</DialogTitle>
          <DialogDescription>
            Documento interno para revisión comercial. No constituye emisión fiscal oficial.
          </DialogDescription>
        </DialogHeader>

        {preview && (
          <div className="space-y-4 text-sm">
            <div className="border-warning/30 bg-warning/10 flex flex-wrap items-center justify-between gap-2 rounded border p-3">
              <div>
                <p className="font-semibold">
                  {preview.company_snapshot.legal_name ?? 'Empresa sin nombre fiscal'}
                </p>
                <p className="text-text-muted">
                  {preview.company_snapshot.tax_id ?? 'Sin identificación fiscal'}
                </p>
              </div>
              <Badge variant="warning">Interno · No emitido</Badge>
            </div>

            <div className="grid gap-3 md:grid-cols-3">
              <Info label="Venta" value={`#${preview.sale_id}`} />
              <Info label="Sucursal" value={snapshotText(preview.branch_snapshot)} />
              <Info
                label="Cliente"
                value={
                  preview.customer_snapshot?.fiscal_name ??
                  preview.customer_snapshot?.name ??
                  'Consumidor final'
                }
              />
            </div>

            <div className="border-border overflow-x-auto rounded border">
              <table className="table-dense w-full">
                <thead className="border-border bg-bg/60 border-b text-left">
                  <tr>
                    <th className="px-3 py-2">Producto</th>
                    <th className="px-3 py-2 text-right">Cantidad</th>
                    <th className="px-3 py-2 text-right">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.items.map((item) => (
                    <tr key={item.id} className="border-border border-b last:border-0">
                      <td className="px-3 py-2">
                        {item.product_snapshot.name ?? item.product_snapshot.sku ?? 'Producto'}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{item.quantity}</td>
                      <td className="px-3 py-2 text-right tabular-nums">
                        {formatMoney(item.total_amount)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="border-border bg-bg/40 grid gap-3 rounded border p-3 md:grid-cols-3">
              <Info
                label="Base gravable"
                value={formatMoney(preview.totals_snapshot.fiscal_taxable_base_amount)}
              />
              <Info
                label="IVA"
                value={formatMoney(preview.totals_snapshot.fiscal_tax_base_amount)}
              />
              <Info
                label="Total"
                value={formatMoney(preview.totals_snapshot.total_base_amount)}
                strong
              />
            </div>
          </div>
        )}

        <DialogFooter className="fiscal-preview-actions print:hidden">
          <Button variant="outline" onClick={() => window.print()} disabled={!preview}>
            Imprimir vista comercial
          </Button>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cerrar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Info({
  label,
  value,
  strong = false,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <div>
      <div className="text-text-muted text-xs uppercase">{label}</div>
      <div className={strong ? 'mt-1 font-semibold' : 'mt-1'}>{value}</div>
    </div>
  );
}
