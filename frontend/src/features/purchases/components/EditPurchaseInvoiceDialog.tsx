/**
 * EditPurchaseInvoiceDialog: modal para corregir metadatos comerciales/fiscales
 * de una compra que ya fue recibida (parcial o totalmente).
 *
 * Permite corregir:
 * - Proveedor (supplier_id)
 * - N° Factura / Control (document_number)
 * - Fecha de emisión (issued_at)
 * - Fecha de vencimiento (due_date)
 *
 * Al guardar, el backend sincroniza automáticamente estos datos con la
 * Cuenta por Pagar asociada en accounts_payables.
 */
import { useEffect, useState } from 'react';
import { AlertCircle, FileEdit, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useUpdatePurchase } from '@/features/purchases/api';
import type { Purchase } from '@/features/purchases/schemas';
import { SupplierAutocomplete } from './SupplierAutocomplete';

interface EditPurchaseInvoiceDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  purchase: Purchase | null;
}

export function EditPurchaseInvoiceDialog({
  open,
  onOpenChange,
  purchase,
}: EditPurchaseInvoiceDialogProps) {
  const updatePurchase = useUpdatePurchase();

  const [supplierId, setSupplierId] = useState<number | null>(null);
  const [documentNumber, setDocumentNumber] = useState('');
  const [issuedAt, setIssuedAt] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (purchase) {
      setSupplierId(purchase.supplier_id ?? null);
      setDocumentNumber(purchase.document_number ?? '');
      setIssuedAt(purchase.issued_at ?? '');
      setDueDate(purchase.due_date ?? '');
    }
  }, [purchase, open]);

  if (!purchase) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!purchase) return;

    setSubmitting(true);
    try {
      await updatePurchase.mutateAsync({
        id: purchase.id,
        values: {
          supplier_id: supplierId ?? undefined,
          document_number: documentNumber.trim() || undefined,
          issued_at: issuedAt || undefined,
          due_date: dueDate || undefined,
        },
      });

      toast.success('Factura de compra actualizada exitosamente.');
      onOpenChange(false);
    } catch (err) {
      if (err instanceof Error) {
        toast.error(err.message);
      } else {
        toast.error('Error al actualizar los datos de la factura.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-3">
              <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                <FileEdit className="size-5" />
              </div>
              <div>
                <DialogTitle>Editar Factura de Compra</DialogTitle>
                <DialogDescription>
                  Compra <span className="font-semibold">{purchase.document_number ?? `#${purchase.id}`}</span>
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {/* Aviso informativo de integridad de stock */}
            <div className="flex items-start gap-2.5 rounded-lg border border-blue-500/20 bg-blue-500/10 p-3 text-xs text-blue-700 dark:text-blue-300">
              <AlertCircle className="size-4 shrink-0 mt-0.5" />
              <div>
                Esta orden ya ingresó al inventario. Solo se pueden modificar los datos fiscales y comerciales. Los cambios de factura, proveedor y fechas se sincronizarán automáticamente con la Cuenta por Pagar asociada.
              </div>
            </div>

            {/* Número de Factura / Control */}
            <div className="space-y-1.5">
              <Label htmlFor="edit-doc-number">N° de Factura / Documento</Label>
              <Input
                id="edit-doc-number"
                value={documentNumber}
                onChange={(e) => setDocumentNumber(e.target.value)}
                placeholder="Ej. FC-000123"
              />
            </div>

            {/* Proveedor */}
            <div className="space-y-1.5">
              <Label>Proveedor</Label>
              <SupplierAutocomplete
                value={supplierId}
                onChange={(id) => setSupplierId(id)}
                placeholder="Seleccionar proveedor..."
              />
            </div>

            {/* Fechas: Emisión y Vencimiento */}
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="edit-issued-at">Fecha de Emisión</Label>
                <Input
                  id="edit-issued-at"
                  type="date"
                  value={issuedAt}
                  onChange={(e) => setIssuedAt(e.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="edit-due-date">Fecha de Vencimiento</Label>
                <Input
                  id="edit-due-date"
                  type="date"
                  value={dueDate}
                  onChange={(e) => setDueDate(e.target.value)}
                />
              </div>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? (
                <>
                  <Loader2 className="mr-2 size-4 animate-spin" />
                  Guardando...
                </>
              ) : (
                'Guardar Cambios'
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
