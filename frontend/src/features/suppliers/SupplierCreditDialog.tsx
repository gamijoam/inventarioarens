import { useState } from 'react';

import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { useRegisterSupplierCreditRefund, useSupplierCredit } from '@/features/payables/api';
import { toast } from '@/lib/toast';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import type { Supplier } from './schemas';

const TYPE_LABELS: Record<string, string> = {
  return_credit: 'Devolucion',
  applied: 'Aplicado a CxP',
  refund_received: 'Reembolso recibido',
};

const REFUND_METHODS = [
  { value: 'transfer', label: 'Transferencia' },
  { value: 'mobile_payment', label: 'Pago movil' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'other', label: 'Otro' },
];

function money(value: number, prefix = '$'): string {
  return `${prefix}${value.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function SupplierCreditDialog({ supplier, onClose }: { supplier: Supplier; onClose: () => void }) {
  const { data, isLoading } = useSupplierCredit(supplier.id);
  const refund = useRegisterSupplierCreditRefund();
  const canPay = useCan(PERMISSIONS.ACCOUNTS_PAYABLE_PAY);
  const [currency, setCurrency] = useState<'USD' | 'VES'>('USD');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('transfer');
  const [reference, setReference] = useState('');
  const available = data?.available_base_amount ?? 0;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) {
      toast.error('Indica un monto mayor que cero.');
      return;
    }
    try {
      await refund.mutateAsync({
        supplierId: supplier.id,
        values: {
          payment_currency: currency,
          amount: value,
          method,
          ...(reference.trim() ? { reference: reference.trim() } : {}),
        },
      });
    } catch (error) {
      toast.error(error instanceof Error && error.message ? error.message : 'No se pudo registrar el reembolso.');
      return;
    }
    toast.success('Reembolso registrado.');
    onClose();
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Credito con {supplier.name}</DialogTitle>
          <DialogDescription>
            Saldo a favor por devoluciones hechas despues de pagar. Se aplica desde la cuenta por pagar o
            se registra aqui cuando el proveedor devuelve el dinero.
          </DialogDescription>
        </DialogHeader>
        <div className="border-border rounded border p-3">
          <p className="text-sm">
            Disponible <span className="font-semibold">{money(available)}</span>
            <span className="text-text-muted"> ({money(data?.available_local_amount ?? 0, 'Bs ')})</span>
          </p>
          <div className="mt-2 max-h-40 overflow-auto text-sm">
            {isLoading ? (
              <p className="text-text-muted">Cargando...</p>
            ) : (data?.transactions ?? []).length === 0 ? (
              <p className="text-text-muted">Sin movimientos.</p>
            ) : (
              data?.transactions.map((row) => (
                <div key={row.id} className="border-border flex justify-between gap-2 border-b py-1 last:border-0">
                  <div>
                    <p>{TYPE_LABELS[row.type] ?? row.type}</p>
                    {row.notes && <p className="text-text-muted text-xs">{row.notes}</p>}
                  </div>
                  <p className="tabular-nums">{money(row.amount_base)}</p>
                </div>
              ))
            )}
          </div>
        </div>
        {canPay && available > 0 && (
          <form className="space-y-3" onSubmit={(event) => void submit(event)}>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label htmlFor="supplier-refund-currency">Moneda</Label>
                <Select
                  id="supplier-refund-currency"
                  value={currency}
                  onChange={(event) => setCurrency(event.target.value as 'USD' | 'VES')}
                >
                  <option value="USD">USD</option>
                  <option value="VES">VES (tasa oficial)</option>
                </Select>
              </div>
              <div>
                <Label htmlFor="supplier-refund-amount">Monto recibido</Label>
                <Input
                  id="supplier-refund-amount"
                  type="number"
                  min="0"
                  step="0.01"
                  value={amount}
                  onChange={(event) => setAmount(event.target.value)}
                />
              </div>
              <div>
                <Label htmlFor="supplier-refund-method">Metodo</Label>
                <Select id="supplier-refund-method" value={method} onChange={(event) => setMethod(event.target.value)}>
                  {REFUND_METHODS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label htmlFor="supplier-refund-reference">Referencia</Label>
                <Input
                  id="supplier-refund-reference"
                  value={reference}
                  onChange={(event) => setReference(event.target.value)}
                />
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={onClose}>
                Cerrar
              </Button>
              <Button type="submit" loading={refund.isPending}>
                Registrar reembolso
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
