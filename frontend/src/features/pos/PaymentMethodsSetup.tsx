import { useMemo, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Banknote, CreditCard, Loader2, Plus, Save, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Can } from '@/components/permissions/Can';
import { PageLayout } from '@/components/layout/PageLayout';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { PERMISSIONS } from '@/permissions/constants';
import {
  type PaymentMethod,
  type PaymentMethodPayload,
  type PosPaymentMethod,
  useCreatePaymentMethod,
  useDeletePaymentMethod,
  usePaymentMethods,
  useUpdatePaymentMethod,
} from './api';

const METHOD_OPTIONS: Array<{ value: PosPaymentMethod; label: string; reference?: boolean }> = [
  { value: 'cash', label: 'Efectivo' },
  { value: 'card', label: 'Tarjeta / punto' },
  { value: 'mobile_payment', label: 'Pago movil', reference: true },
  { value: 'transfer', label: 'Transferencia', reference: true },
  { value: 'zelle', label: 'Zelle', reference: true },
  { value: 'external_financing', label: 'Financiadora', reference: true },
  { value: 'other', label: 'Otro' },
];

const CURRENCY_OPTIONS = [
  { value: 'USD', label: 'Solo USD' },
  { value: 'VES', label: 'Solo VES' },
  { value: 'flexible', label: 'USD o VES' },
] as const;

export function PaymentMethodsSetup() {
  const { data: methods = [], isLoading } = usePaymentMethods();
  const createPaymentMethod = useCreatePaymentMethod();
  const updatePaymentMethod = useUpdatePaymentMethod();
  const deletePaymentMethod = useDeletePaymentMethod();
  const [form, setForm] = useState<PaymentMethodPayload>({
    name: '',
    code: '',
    method: 'cash',
    currency_mode: 'USD',
    requires_reference: false,
    is_active: true,
    sort_order: 0,
  });

  const sortedMethods = useMemo(
    () => [...methods].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name)),
    [methods],
  );

  return (
    <PageLayout
      title="Metodos de pago"
      description="Configura las formas de cobro disponibles para POS. Las tasas BCV/Paralelo se gestionan en Tipos de tasa y se seleccionan al cobrar."
      actions={
        <Button asChild variant="outline">
          <Link to="/inventory/currency">
            <Banknote className="size-4" /> Tipos de tasa
          </Link>
        </Button>
      }
    >
      <div className="grid gap-4 xl:grid-cols-[420px_1fr]">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Plus className="size-4" /> Nuevo metodo
            </CardTitle>
            <CardDescription>Define moneda, referencia obligatoria y orden de aparicion en POS.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Can I={PERMISSIONS.PAYMENT_METHODS_UPDATE} fallback={<p className="text-sm text-text-muted">No tienes permiso para editar metodos de pago.</p>}>
              <Input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} placeholder="Nombre visible" />
              <Input value={form.code} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))} placeholder="Codigo interno" />
              <Select
                value={form.method}
                onChange={(event) => {
                  const method = event.target.value as PosPaymentMethod;
                  const option = METHOD_OPTIONS.find((item) => item.value === method);
                  setForm((current) => ({ ...current, method, requires_reference: option?.reference ?? current.requires_reference }));
                }}
              >
                {METHOD_OPTIONS.map((method) => <option key={method.value} value={method.value}>{method.label}</option>)}
              </Select>
              <Select value={form.currency_mode} onChange={(event) => setForm((current) => ({ ...current, currency_mode: event.target.value as PaymentMethodPayload['currency_mode'] }))}>
                {CURRENCY_OPTIONS.map((currency) => <option key={currency.value} value={currency.value}>{currency.label}</option>)}
              </Select>
              <Input type="number" min="0" value={form.sort_order ?? 0} onChange={(event) => setForm((current) => ({ ...current, sort_order: Number(event.target.value || 0) }))} placeholder="Orden" />
              <ToggleLine label="Requiere referencia" checked={Boolean(form.requires_reference)} onChange={(checked) => setForm((current) => ({ ...current, requires_reference: checked }))} />
              <ToggleLine label="Activo" checked={Boolean(form.is_active)} onChange={(checked) => setForm((current) => ({ ...current, is_active: checked }))} />
              <Button
                className="w-full"
                disabled={createPaymentMethod.isPending}
                onClick={() => {
                  if (!form.name.trim() || !form.code.trim()) {
                    toast.error('Indica nombre y codigo del metodo.');
                    return;
                  }
                  createPaymentMethod.mutate({
                    ...form,
                    name: form.name.trim(),
                    code: form.code.trim().toUpperCase(),
                  }, {
                    onSuccess: () => setForm({ name: '', code: '', method: 'cash', currency_mode: 'USD', requires_reference: false, is_active: true, sort_order: 0 }),
                  });
                }}
              >
                {createPaymentMethod.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                Guardar metodo
              </Button>
            </Can>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <CreditCard className="size-4" /> Disponibles en POS
            </CardTitle>
            <CardDescription>Si un metodo exige referencia, el POS la pedira antes de cobrar.</CardDescription>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="flex items-center gap-2 rounded border border-border p-3 text-sm text-text-muted">
                <Loader2 className="size-4 animate-spin" /> Cargando metodos
              </div>
            ) : sortedMethods.length === 0 ? (
              <div className="rounded border border-dashed border-border p-4 text-sm text-text-muted">
                Todavia no hay metodos configurados. Crea al menos Efectivo USD y un metodo VES si vas a cobrar en bolivares.
              </div>
            ) : (
              <div className="divide-y divide-border rounded border border-border">
                {sortedMethods.map((method) => (
                  <PaymentMethodRow
                    key={method.id}
                    method={method}
                    busy={updatePaymentMethod.isPending || deletePaymentMethod.isPending}
                    onPatch={(patch) => updatePaymentMethod.mutate({ id: method.id, ...patch })}
                    onDelete={() => deletePaymentMethod.mutate(method.id)}
                  />
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </PageLayout>
  );
}

function PaymentMethodRow({ method, busy, onPatch, onDelete }: {
  method: PaymentMethod;
  busy: boolean;
  onPatch: (patch: Partial<PaymentMethodPayload>) => void;
  onDelete: () => void;
}) {
  return (
    <div className="grid gap-3 p-3 lg:grid-cols-[minmax(180px,1fr)_180px_180px_140px_40px] lg:items-center">
      <div className="min-w-0">
        <p className="truncate font-medium">{method.name}</p>
        <p className="font-mono text-xs text-text-muted">{method.code}</p>
      </div>
      <Badge variant={method.is_active === false ? 'default' : 'success'}>
        {method.is_active === false ? 'inactivo' : methodLabel(method.method)}
      </Badge>
      <Badge variant="info">{currencyLabel(method.currency_mode)}</Badge>
      <div className="flex items-center gap-3">
        <ToggleLine small label="Ref." checked={Boolean(method.requires_reference)} onChange={(checked) => onPatch({ requires_reference: checked })} />
        <ToggleLine small label="Activo" checked={method.is_active !== false} onChange={(checked) => onPatch({ is_active: checked })} />
      </div>
      <Can I={PERMISSIONS.PAYMENT_METHODS_UPDATE} fallback={null}>
        <Button size="icon-sm" variant="ghost" disabled={busy} onClick={onDelete} aria-label="Eliminar metodo">
          <Trash2 className="size-4" />
        </Button>
      </Can>
    </div>
  );
}

function ToggleLine({ label, checked, onChange, small = false }: { label: string; checked: boolean; onChange: (checked: boolean) => void; small?: boolean }) {
  return (
    <label className="flex items-center justify-between gap-3 rounded border border-border px-3 py-2 text-sm">
      <span className={small ? 'text-xs text-text-muted' : ''}>{label}</span>
      <Switch checked={checked} onCheckedChange={onChange} />
    </label>
  );
}

function methodLabel(method?: string | null): string {
  return METHOD_OPTIONS.find((item) => item.value === method)?.label ?? method ?? 'Metodo';
}

function currencyLabel(currency?: string | null): string {
  return CURRENCY_OPTIONS.find((item) => item.value === currency)?.label ?? currency ?? 'Flexible';
}
