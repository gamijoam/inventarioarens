/**
 * ActionDialogs: dialogs condicionales para las acciones bulk.
 *  1. activate         (sin payload)
 *  2. deactivate       (sin payload)
 *  3. assign_warranty_policy (payload.warranty_policy_id)
 *  4. assign_exchange_rate_type (payload.sale_exchange_rate_type_id)
 *  5. fill_missing_price_list  (payload.price_list_id + strategy + price/percent + currency)
 *  6. update_price_list        (mismo que 5)
 *
 * El dialog muestra el formulario especifico segun la accion elegida
 * y solo envia el payload al backend cuando la accion lo requiere.
 */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Label } from '@/components/ui/Label';
import { Switch } from '@/components/ui/Switch';

import { useBulkAction, type BulkActionResponse } from './useBulkAction';
import {
  useWarrantyPolicies,
  useExchangeRateTypes,
  useFiscalTaxRates,
  usePriceLists,
} from '@/features/inventory-center/lookups';
import {
  FISCAL_TAX_CATEGORY_LABELS,
  type FiscalTaxRate,
} from '@/features/fiscal-identity/taxRates';
import { SALE_CURRENCIES, type BulkAction } from '@/features/inventory-center/schemas';

const ACTION_LABELS: Record<string, string> = {
  activate: 'Activar productos',
  deactivate: 'Desactivar productos',
  assign_warranty_policy: 'Asignar politica de garantia',
  assign_exchange_rate_type: 'Asignar tipo de tasa',
  assign_fiscal_tax_rate: 'Asignar tratamiento fiscal',
  fill_missing_price_list: 'Rellenar precios faltantes',
  update_price_list: 'Actualizar precios',
};

const ACTION_DESCRIPTIONS: Record<string, string> = {
  activate: 'Marca los productos seleccionados como activos. Seran visibles en ventas.',
  deactivate: 'Marca los productos seleccionados como inactivos. No podran venderse.',
  assign_warranty_policy: 'Asigna la politica de garantia seleccionada a todos los productos.',
  assign_exchange_rate_type: 'Asigna el tipo de tasa de cambio a todos los productos.',
  assign_fiscal_tax_rate:
    'Asigna IVA, exento, exonerado o no gravado a los productos seleccionados.',
  fill_missing_price_list:
    'Crea precio en la lista seleccionada para productos que no tienen aun (no sobreescribe).',
  update_price_list:
    'Crea o actualiza el precio en la lista seleccionada segun la estrategia elegida.',
};

const PRICE_STRATEGY_OPTIONS = [
  { value: 'base_price', label: 'Copiar precio base' },
  { value: 'fixed_price', label: 'Precio fijo' },
  { value: 'percent_over_base', label: 'Porcentaje sobre base' },
] as const;

const BulkActionFormSchema = z
  .object({
    warranty_policy_id: z.coerce.number().int().positive().optional(),
    sale_exchange_rate_type_id: z.coerce.number().int().positive().optional(),
    fiscal_tax_rate_id: z.coerce.number().int().positive().optional(),
    overwrite_existing: z.boolean().optional(),
    price_list_id: z.coerce.number().int().positive().optional(),
    strategy: z.enum(['base_price', 'fixed_price', 'percent_over_base']).optional(),
    price: z.coerce.number().min(0).optional(),
    percent: z.coerce.number().min(-99).max(10000).optional(),
    currency: z.enum(['USD', 'VES']).optional(),
  })
  .superRefine((data, ctx) => {
    if (data.strategy === 'fixed_price' && data.price == null) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['price'], message: 'Indica el monto.' });
    }
    if (data.strategy === 'percent_over_base' && data.percent == null) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['percent'],
        message: 'Indica el porcentaje.',
      });
    }
  });
type BulkActionFormValues = z.infer<typeof BulkActionFormSchema>;

export interface ActionDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  action: BulkAction;
  productIds: number[];
  allMatching?: boolean;
  filters?: Record<string, unknown>;
  onSuccess?: (result: BulkActionResponse) => void;
}

export function ActionDialog({
  open,
  onOpenChange,
  action,
  productIds,
  allMatching = false,
  filters = {},
  onSuccess,
}: ActionDialogProps) {
  const { data: warrantyPolicies = [] } = useWarrantyPolicies();
  const { data: rateTypes = [] } = useExchangeRateTypes();
  const { data: fiscalTaxRates = [] } = useFiscalTaxRates();
  const { data: priceLists = [] } = usePriceLists();
  const bulkAction = useBulkAction();

  const form = useForm<BulkActionFormValues>({
    resolver: zodResolver(BulkActionFormSchema),
    defaultValues: {
      strategy: 'base_price',
      currency: 'USD',
    },
  });

  // Reset form cuando cambia la accion o se cierra el dialog.
  useEffect(() => {
    if (open) {
      form.reset({ strategy: 'base_price', currency: 'USD' });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, action]);

  const needsWarranty = action === 'assign_warranty_policy';
  const needsRate = action === 'assign_exchange_rate_type';
  const needsFiscalRate = action === 'assign_fiscal_tax_rate';
  const needsPriceList = action === 'fill_missing_price_list' || action === 'update_price_list';

  const handleConfirm = form.handleSubmit(async (values) => {
    const payload: Record<string, unknown> = {};
    if (needsWarranty) payload.warranty_policy_id = values.warranty_policy_id;
    if (needsRate) payload.sale_exchange_rate_type_id = values.sale_exchange_rate_type_id;
    if (needsFiscalRate) {
      payload.fiscal_tax_rate_id = values.fiscal_tax_rate_id;
      payload.overwrite_existing = values.overwrite_existing ?? false;
    }
    if (needsPriceList) {
      payload.price_list_id = values.price_list_id;
      payload.strategy = values.strategy;
      payload.currency = values.currency;
      if (values.strategy === 'fixed_price') payload.price = values.price;
      if (values.strategy === 'percent_over_base') payload.percent = values.percent;
    }
    const result = await bulkAction.mutateAsync({
      product_ids: productIds,
      action,
      payload,
      all_matching: allMatching,
      filters: allMatching ? filters : undefined,
    });
    onOpenChange(false);
    onSuccess?.(result);
  });

  const title = ACTION_LABELS[action] ?? action;
  const description = ACTION_DESCRIPTIONS[action] ?? '';

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            {description} Aplica a{' '}
            <strong>
              {allMatching
                ? 'todos los resultados filtrados'
                : `${productIds.length} producto(s) seleccionado(s)`}
            </strong>
            .
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleConfirm} className="space-y-4">
          {needsWarranty && (
            <Field label="Politica de garantia" required>
              <Select
                value={form.watch('warranty_policy_id')?.toString() ?? ''}
                onChange={(e) =>
                  form.setValue('warranty_policy_id', Number(e.target.value) || undefined, {
                    shouldValidate: true,
                  })
                }
              >
                <option value="">— Selecciona —</option>
                {warrantyPolicies.map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
              </Select>
            </Field>
          )}

          {needsRate && (
            <Field label="Tipo de tasa" required>
              <Select
                value={form.watch('sale_exchange_rate_type_id')?.toString() ?? ''}
                onChange={(e) =>
                  form.setValue('sale_exchange_rate_type_id', Number(e.target.value) || undefined, {
                    shouldValidate: true,
                  })
                }
              >
                <option value="">— Selecciona —</option>
                {rateTypes.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.code} ({r.name})
                  </option>
                ))}
              </Select>
            </Field>
          )}

          {needsFiscalRate && (
            <>
              <Field
                label="Tratamiento fiscal"
                required
                error={form.formState.errors.fiscal_tax_rate_id?.message}
              >
                <Select
                  value={form.watch('fiscal_tax_rate_id')?.toString() ?? ''}
                  onChange={(e) =>
                    form.setValue('fiscal_tax_rate_id', Number(e.target.value) || undefined, {
                      shouldValidate: true,
                    })
                  }
                >
                  <option value="">— Selecciona —</option>
                  {fiscalTaxRates
                    .filter((rate) => rate.is_active)
                    .map((rate: FiscalTaxRate) => (
                      <option key={rate.id} value={rate.id}>
                        {rate.code} · {rate.name} ({rate.rate}%) ·{' '}
                        {FISCAL_TAX_CATEGORY_LABELS[rate.category]}
                      </option>
                    ))}
                </Select>
              </Field>
              <div className="flex items-start gap-2">
                <Switch
                  id="bulk-fiscal-overwrite"
                  checked={Boolean(form.watch('overwrite_existing'))}
                  onCheckedChange={(value) => form.setValue('overwrite_existing', value)}
                />
                <div className="space-y-0.5">
                  <Label htmlFor="bulk-fiscal-overwrite">
                    Sobrescribir clasificaciones existentes
                  </Label>
                  <p className="text-text-muted text-xs">
                    Desactivado conserva productos ya marcados como exentos, exonerados o no
                    gravados.
                  </p>
                </div>
              </div>
            </>
          )}

          {needsPriceList && (
            <>
              <Field label="Lista de precio" required>
                <Select
                  value={form.watch('price_list_id')?.toString() ?? ''}
                  onChange={(e) =>
                    form.setValue('price_list_id', Number(e.target.value) || undefined, {
                      shouldValidate: true,
                    })
                  }
                >
                  <option value="">— Selecciona —</option>
                  {priceLists.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} ({p.code})
                    </option>
                  ))}
                </Select>
              </Field>

              <Field label="Estrategia" required>
                <Select
                  value={form.watch('strategy') ?? 'base_price'}
                  onChange={(e) =>
                    form.setValue(
                      'strategy',
                      e.target.value as 'base_price' | 'fixed_price' | 'percent_over_base',
                      { shouldValidate: true },
                    )
                  }
                >
                  {PRICE_STRATEGY_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Field>

              {form.watch('strategy') === 'fixed_price' && (
                <Field label="Precio fijo" required error={form.formState.errors.price?.message}>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    {...form.register('price', { valueAsNumber: true })}
                  />
                </Field>
              )}

              {form.watch('strategy') === 'percent_over_base' && (
                <Field
                  label="Porcentaje (%)"
                  required
                  error={form.formState.errors.percent?.message}
                >
                  <Input
                    type="number"
                    step="0.01"
                    {...form.register('percent', { valueAsNumber: true })}
                  />
                </Field>
              )}

              <Field label="Moneda" required>
                <Select
                  value={form.watch('currency') ?? 'USD'}
                  onChange={(e) =>
                    form.setValue('currency', e.target.value as 'USD' | 'VES', {
                      shouldValidate: true,
                    })
                  }
                >
                  {SALE_CURRENCIES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </Select>
              </Field>
            </>
          )}

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={bulkAction.isPending}
            >
              Cancelar
            </Button>
            <Button type="submit" loading={bulkAction.isPending}>
              Aplicar
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  label,
  required,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label className="flex items-center gap-1">
        {label}
        {required && <span className="text-danger">*</span>}
      </Label>
      {children}
      {error && <p className="text-danger text-xs">{error}</p>}
    </div>
  );
}
