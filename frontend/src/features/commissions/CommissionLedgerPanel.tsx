import { useMemo, useState } from 'react';
import { CheckCheck, Download, HandCoins, PlusCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { useExchangeRateTypes } from '@/features/inventory-center/api';
import { useUsers } from '@/features/users/api';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  downloadCommissionExport,
  useApproveCommissionEntries,
  useCommissionEntries,
  useCreateCommissionAdjustment,
  useCreateCommissionSettlement,
} from './api';

export function CommissionLedgerPanel({ ownOnly }: { ownOnly: boolean }) {
  const { data, isLoading } = useCommissionEntries(ownOnly);
  const canApprove = useCan(PERMISSIONS.COMMISSIONS_APPROVE);
  const canSettle = useCan(PERMISSIONS.COMMISSIONS_SETTLE);
  const canAdjust = useCan(PERMISSIONS.COMMISSIONS_ADJUST);
  const canExport = useCan(PERMISSIONS.COMMISSIONS_VIEW_ALL);
  const approve = useApproveCommissionEntries();
  const settle = useCreateCommissionSettlement();
  const adjustment = useCreateCommissionAdjustment();
  const { data: rateTypes = [] } = useExchangeRateTypes(!ownOnly && canSettle);
  const { data: usersPage } = useUsers({ search: '', status: 'active', scope: 'tenant', page: 1, per_page: 100 }, !ownOnly && canAdjust);
  const users = usersPage?.data ?? [];
  const [selected, setSelected] = useState<number[]>([]);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [adjustmentOpen, setAdjustmentOpen] = useState(false);
  const [paymentCurrency, setPaymentCurrency] = useState<'USD' | 'VES'>('USD');
  const [rateTypeId, setRateTypeId] = useState<number | null>(null);
  const [reference, setReference] = useState('');
  const [adjustmentForm, setAdjustmentForm] = useState({ beneficiary_user_id: 0, beneficiary_role: 'seller' as 'seller' | 'cashier', amount_base: 0, reason: '' });

  const entries = useMemo(() => data?.data ?? [], [data?.data]);
  const selectedEntries = entries.filter((entry) => selected.includes(entry.id));
  const selectedAvailable = selectedEntries.filter((entry) => entry.status === 'available');
  const selectedApproved = selectedEntries.filter((entry) => entry.status === 'approved');
  const approvedTotal = selectedApproved.reduce((total, entry) => total + Number(entry.commission_base_amount), 0);

  if (isLoading) return <Skeleton className="h-48 w-full" />;

  const clearSelection = () => setSelected([]);
  const toggle = (id: number, checked: boolean) => setSelected((current) => checked ? [...current, id] : current.filter((item) => item !== id));

  return (
    <section className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-4">
        <LedgerMetric label="Total generado" value={data?.summary.total_base_amount ?? '0'} />
        <LedgerMetric label="Disponible" value={data?.summary.available_base_amount ?? '0'} accent />
        <LedgerMetric label="Aprobado" value={data?.summary.approved_base_amount ?? '0'} />
        <LedgerMetric label="Pagado" value={data?.summary.paid_base_amount ?? '0'} />
      </div>

      {!ownOnly && (
        <div className="border-border bg-surface flex flex-wrap items-center gap-2 rounded-lg border p-3">
          {canApprove && <Button size="sm" variant="secondary" disabled={selectedAvailable.length === 0 || selectedAvailable.length !== selectedEntries.length} loading={approve.isPending} leftIcon={<CheckCheck className="size-4" />} onClick={async () => {
            try { await approve.mutateAsync(selectedAvailable.map((entry) => entry.id)); clearSelection(); toast.success('Comisiones aprobadas.'); }
            catch (error) { toast.error(error instanceof Error ? error.message : 'No se pudieron aprobar.'); }
          }}>Aprobar seleccionadas</Button>}
          {canSettle && <Button size="sm" disabled={selectedApproved.length === 0 || selectedApproved.length !== selectedEntries.length} leftIcon={<HandCoins className="size-4" />} onClick={() => setPaymentOpen(true)}>Registrar pago</Button>}
          {canAdjust && <Button size="sm" variant="ghost" leftIcon={<PlusCircle className="size-4" />} onClick={() => setAdjustmentOpen(true)}>Nuevo ajuste</Button>}
          {canExport && <Button size="sm" variant="ghost" leftIcon={<Download className="size-4" />} onClick={() => void downloadCommissionExport()}>Exportar CSV</Button>}
          {selected.length > 0 && <span className="text-text-muted ml-auto text-xs">{selected.length} movimiento(s) seleccionados</span>}
        </div>
      )}

      {entries.length === 0 ? (
        <EmptyState
          title="Sin comisiones todavía"
          description={ownOnly
            ? 'Aquí aparecerán cuando tus ventas cumplan un plan activo.'
            : 'Las ventas directas de caja corresponden al cajero. Para comisión de vendedor, la orden debe ser armada por ese vendedor antes del cobro.'}
        />
      ) : (
        <div className="border-border bg-surface overflow-x-auto rounded-xl border">
          <table className="table-dense w-full">
            <thead className="border-border bg-bg/60 border-b text-left">
              <tr>{!ownOnly && (canApprove || canSettle) && <th className="w-10 px-3 py-2" aria-label="Seleccionar" />}<th className="px-3 py-2">Venta</th>{!ownOnly && <th className="px-3 py-2">Persona</th>}<th className="px-3 py-2">Plan histórico</th><th className="px-3 py-2">Base USD</th><th className="px-3 py-2">Comisión</th><th className="px-3 py-2">Estado</th></tr>
            </thead>
            <tbody>
              {entries.map((entry) => {
                const selectable = entry.status === 'available' || entry.status === 'approved';
                return <tr key={entry.entry_uuid} className="border-border border-b last:border-b-0">
                  {!ownOnly && (canApprove || canSettle) && <td className="px-3 py-2"><Checkbox aria-label={`Seleccionar comisión ${entry.id}`} disabled={!selectable} checked={selected.includes(entry.id)} onCheckedChange={(checked) => toggle(entry.id, checked === true)} /></td>}
                  <td className="px-3 py-2 font-medium">{entry.sale_id ? `#${entry.sale_id}` : 'Ajuste'}</td>
                  {!ownOnly && <td className="px-3 py-2"><span className="block font-medium">{entry.beneficiary.name}</span><span className="text-text-muted text-xs">{entry.beneficiary_role === 'seller' ? 'Vendedor' : 'Cajero'}</span></td>}
                  <td className="px-3 py-2"><span className="block">{entry.plan_name_snapshot}</span><span className="text-text-muted text-xs">{entry.adjustment_reason ?? `${Number(entry.percentage_snapshot).toFixed(2)}%${entry.exchange_rate_type_code ? ` · ${entry.exchange_rate_type_code}` : ''}`}</span></td>
                  <td className="px-3 py-2 tabular-nums">${Number(entry.eligible_base_amount).toFixed(2)}</td>
                  <td className={`px-3 py-2 font-semibold tabular-nums ${Number(entry.commission_base_amount) < 0 ? 'text-danger' : ''}`}>${Number(entry.commission_base_amount).toFixed(2)}</td>
                  <td className="px-3 py-2"><Badge variant={entry.status === 'available' ? 'success' : 'default'}>{statusLabel(entry.status)}</Badge></td>
                </tr>;
              })}
            </tbody>
          </table>
        </div>
      )}

      {paymentOpen && <Dialog open onOpenChange={(open) => !open && setPaymentOpen(false)}><DialogContent><DialogHeader><DialogTitle>Registrar pago de comisiones</DialogTitle></DialogHeader><div className="space-y-4">
        <div className="bg-primary/5 rounded-lg p-4"><p className="text-text-muted text-xs uppercase tracking-wider">Total aprobado</p><p className="mt-1 text-2xl font-semibold">${approvedTotal.toFixed(2)}</p></div>
        <Field label="Moneda del pago"><Select value={paymentCurrency} onChange={(event) => setPaymentCurrency(event.target.value as 'USD' | 'VES')}><option value="USD">Dólares</option><option value="VES">Bolívares</option></Select></Field>
        {paymentCurrency === 'VES' && <Field label="Tasa del día"><Select value={rateTypeId ?? ''} onChange={(event) => setRateTypeId(event.target.value ? Number(event.target.value) : null)}><option value="">Seleccionar tasa</option>{rateTypes.filter((type) => type.is_active !== false).map((type) => <option key={type.id} value={type.id}>{type.code} — {type.name}</option>)}</Select></Field>}
        <Field label="Referencia"><Input value={reference} onChange={(event) => setReference(event.target.value)} placeholder="Transferencia, recibo o nota" /></Field>
      </div><DialogFooter><Button variant="ghost" onClick={() => setPaymentOpen(false)}>Cancelar</Button><Button loading={settle.isPending} onClick={async () => {
        if (paymentCurrency === 'VES' && !rateTypeId) { toast.error('Selecciona la tasa del pago.'); return; }
        try { const result = await settle.mutateAsync({ entry_ids: selectedApproved.map((entry) => entry.id), payment_currency: paymentCurrency, exchange_rate_type_id: paymentCurrency === 'VES' ? rateTypeId ?? undefined : undefined, reference: reference || undefined }); toast.success(`Pago registrado por ${result.payment_currency === 'VES' ? 'Bs ' : '$'}${Number(result.payment_amount).toFixed(2)}.`); clearSelection(); setPaymentOpen(false); setReference(''); }
        catch (error) { toast.error(error instanceof Error ? error.message : 'No se pudo registrar el pago.'); }
      }}>Confirmar pago</Button></DialogFooter></DialogContent></Dialog>}

      {adjustmentOpen && <Dialog open onOpenChange={(open) => !open && setAdjustmentOpen(false)}><DialogContent><DialogHeader><DialogTitle>Nuevo ajuste de comisión</DialogTitle></DialogHeader><div className="space-y-4">
        <Field label="Persona"><Select value={adjustmentForm.beneficiary_user_id || ''} onChange={(event) => setAdjustmentForm({ ...adjustmentForm, beneficiary_user_id: Number(event.target.value) })}><option value="">Seleccionar persona</option>{users.map((user) => <option key={user.id} value={user.id}>{user.name} — {user.email}</option>)}</Select></Field>
        <Field label="Responsabilidad"><Select value={adjustmentForm.beneficiary_role} onChange={(event) => setAdjustmentForm({ ...adjustmentForm, beneficiary_role: event.target.value as 'seller' | 'cashier' })}><option value="seller">Vendedor</option><option value="cashier">Cajero</option></Select></Field>
        <Field label="Monto USD (usa negativo para descuento)"><Input type="number" step="0.01" value={adjustmentForm.amount_base || ''} onChange={(event) => setAdjustmentForm({ ...adjustmentForm, amount_base: Number(event.target.value) })} /></Field>
        <Field label="Motivo"><Input value={adjustmentForm.reason} onChange={(event) => setAdjustmentForm({ ...adjustmentForm, reason: event.target.value })} /></Field>
      </div><DialogFooter><Button variant="ghost" onClick={() => setAdjustmentOpen(false)}>Cancelar</Button><Button loading={adjustment.isPending} onClick={async () => {
        if (!adjustmentForm.beneficiary_user_id || !adjustmentForm.amount_base || !adjustmentForm.reason.trim()) { toast.error('Completa persona, monto y motivo.'); return; }
        try { await adjustment.mutateAsync(adjustmentForm); toast.success('Ajuste registrado sin alterar el historial.'); setAdjustmentOpen(false); setAdjustmentForm({ beneficiary_user_id: 0, beneficiary_role: 'seller', amount_base: 0, reason: '' }); }
        catch (error) { toast.error(error instanceof Error ? error.message : 'No se pudo registrar el ajuste.'); }
      }}>Guardar ajuste</Button></DialogFooter></DialogContent></Dialog>}
    </section>
  );
}

function LedgerMetric({ label, value, accent = false }: { label: string; value: string; accent?: boolean }) {
  return <div className={`border-border rounded-lg border p-4 ${accent ? 'bg-primary/5' : 'bg-surface'}`}><p className="text-text-muted text-xs font-medium uppercase tracking-wider">{label}</p><p className="mt-2 text-2xl font-semibold tabular-nums">${Number(value).toFixed(2)}</p></div>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className="space-y-1.5"><Label>{label}</Label>{children}</div>;
}

function statusLabel(status: string): string {
  return ({ pending: 'En espera', available: 'Disponible', approved: 'Aprobada', paid: 'Pagada', reversed: 'Reversada' } as Record<string, string>)[status] ?? status;
}
