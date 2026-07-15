/**
 * TransferAssignDriverDialog: dialog para asignar/editar el
 * transportista (driver) de un traslado.
 */
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { useAssignDriver, useRemoveDriver } from '@/features/transfers/api';
import type { AssignDriverValues } from '@/features/transfers/schemas';

interface TransferAssignDriverDialogProps {
  transferId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function TransferAssignDriverDialog({
  transferId,
  open,
  onOpenChange,
}: TransferAssignDriverDialogProps) {
  const assign = useAssignDriver();
  const remove = useRemoveDriver();
  const [form, setForm] = useState<AssignDriverValues>({
    name: '',
    document_number: null,
    phone: null,
    vehicle_plate: null,
    carrier_company: null,
    picked_up_at: null,
    delivered_at: null,
    signed_by_driver_at: null,
    signature_driver_url: null,
    signed_by_receiver_at: null,
    signature_receiver_url: null,
    notes: null,
  });
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (open) {
      setForm({
        name: '',
        document_number: null,
        phone: null,
        vehicle_plate: null,
        carrier_company: null,
        picked_up_at: null,
        delivered_at: null,
        signed_by_driver_at: null,
        signature_driver_url: null,
        signed_by_receiver_at: null,
        signature_receiver_url: null,
        notes: null,
      });
    }
  }, [open]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await assign.mutateAsync({ id: transferId, values: form });
      toast.success('Transportista asignado.');
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al asignar transportista.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRemove() {
    if (!confirm('Quitar el transportista?')) return;
    setSubmitting(true);
    try {
      await remove.mutateAsync(transferId);
      toast.success('Transportista quitado.');
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al quitar transportista.');
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => onOpenChange(false)}>
      <div className="w-full max-w-2xl rounded-lg border border-border bg-surface max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="sticky top-0 flex items-center justify-between border-b border-border bg-surface px-5 py-3">
          <h2 className="text-lg font-semibold">Asignar transportista</h2>
          <button type="button" onClick={() => onOpenChange(false)} className="rounded p-1 text-text-muted hover:bg-bg" aria-label="Cerrar">
            <X className="size-4" />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="space-y-3 p-5">
          <div>
            <Label htmlFor="driver-name">Nombre <span className="text-danger">*</span></Label>
            <Input id="driver-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required maxLength={150} />
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="driver-doc">Documento</Label>
              <Input id="driver-doc" value={form.document_number ?? ''} onChange={(e) => setForm({ ...form, document_number: e.target.value || null })} maxLength={50} />
            </div>
            <div>
              <Label htmlFor="driver-phone">Telefono</Label>
              <Input id="driver-phone" value={form.phone ?? ''} onChange={(e) => setForm({ ...form, phone: e.target.value || null })} maxLength={50} />
            </div>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="driver-plate">Placa del vehiculo</Label>
              <Input id="driver-plate" value={form.vehicle_plate ?? ''} onChange={(e) => setForm({ ...form, vehicle_plate: e.target.value || null })} maxLength={20} />
            </div>
            <div>
              <Label htmlFor="driver-company">Empresa transportista</Label>
              <Input id="driver-company" value={form.carrier_company ?? ''} onChange={(e) => setForm({ ...form, carrier_company: e.target.value || null })} maxLength={150} />
            </div>
          </div>
          <div>
            <Label htmlFor="driver-notes">Notas</Label>
            <textarea
              id="driver-notes"
              value={form.notes ?? ''}
              onChange={(e) => setForm({ ...form, notes: e.target.value || null })}
              maxLength={2000}
              rows={2}
              className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
            />
          </div>
          <div className="flex justify-between gap-2 border-t border-border pt-3">
            <Button type="button" variant="ghost" onClick={handleRemove} disabled={submitting}>
              Quitar transportista
            </Button>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                Cancelar
              </Button>
              <Button type="submit" loading={submitting}>
                Asignar
              </Button>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}
