import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { toast } from '@/lib/toast';
import { useApplySupplierCredit, useSupplierCredit } from './api';

function money(value: number, prefix = '$'): string {
  return `${prefix}${value.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function SupplierCreditPanel({
  payableId,
  supplierId,
  balance,
  canPay,
}: {
  payableId: number;
  supplierId: number | null | undefined;
  balance: number;
  canPay: boolean;
}) {
  const { data } = useSupplierCredit(supplierId);
  const apply = useApplySupplierCredit();
  const available = data?.available_base_amount ?? 0;
  const suggested = Math.round(Math.min(available, balance) * 100) / 100;
  const [amount, setAmount] = useState<string>(String(suggested));

  useEffect(() => setAmount(String(suggested)), [suggested]);

  if (!supplierId || available <= 0) return null;

  async function submit() {
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) {
      toast.error('Indica un monto mayor que cero.');
      return;
    }
    try {
      await apply.mutateAsync({ id: payableId, supplierId: Number(supplierId), amount: value });
    } catch (error) {
      toast.error(error instanceof Error && error.message ? error.message : 'No se pudo aplicar el credito.');
      return;
    }
    toast.success('Credito aplicado a la cuenta por pagar.');
  }

  return (
    <section className="border-border bg-surface rounded border p-3">
      <h3 className="text-sm font-semibold">Credito con este proveedor</h3>
      <p className="text-text-muted mt-1 text-sm">
        Disponible {money(available)} ({money(data?.available_local_amount ?? 0, 'Bs ')}) por devoluciones posteriores al pago.
      </p>
      {canPay && balance > 0 && (
        <div className="mt-3 flex flex-wrap items-end gap-2">
          <div>
            <Label htmlFor={`supplier-credit-${payableId}`}>Monto a aplicar (USD)</Label>
            <Input
              id={`supplier-credit-${payableId}`}
              type="number"
              min="0"
              step="0.01"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          </div>
          <Button type="button" onClick={() => void submit()} disabled={apply.isPending}>
            Aplicar credito
          </Button>
        </div>
      )}
    </section>
  );
}
