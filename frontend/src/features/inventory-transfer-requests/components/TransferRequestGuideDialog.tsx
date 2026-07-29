import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { useUsers } from '@/features/users/api';
import { usePrepareTransferRequest, useReceiveTransferRequest } from '../api';
import type { GuideItemQuantity, TransferRequest } from '../schemas';

type Mode = 'prepare' | 'receive';

export function TransferRequestGuideDialog({
  request,
  mode,
  open,
  onOpenChange,
}: {
  request: TransferRequest;
  mode: Mode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const prepare = usePrepareTransferRequest();
  const receive = useReceiveTransferRequest();
  const { data: usersResponse } = useUsers({
    status: 'active',
    scope: 'tenant',
    page: 1,
    per_page: 100,
  });
  const carrierUsers = (usersResponse?.data ?? []).filter((user) =>
    user.roles.some((role) => role.name === 'Transportista'),
  );
  const [quantities, setQuantities] = useState<Record<number, number>>({});
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const [serials, setSerials] = useState<Record<number, string>>({});
  const [carrier, setCarrier] = useState({
    userId: '',
    name: '',
    document: '',
    phone: '',
    plate: '',
    company: '',
  });

  useEffect(() => {
    if (!open) return;
    const next: Record<number, number> = {};
    for (const item of request.items ?? []) {
      const guideItem = request.guide?.items?.find(
        (candidate) => candidate.inventory_transfer_request_item_id === item.id,
      );
      next[item.id] =
        mode === 'prepare'
          ? Number(guideItem?.prepared_quantity ?? item.quantity)
          : Number(guideItem?.prepared_quantity ?? item.quantity);
    }
    setQuantities(next);
    setReasons({});
    setSerials({});
    setCarrier({
      userId: request.guide?.carrier_user_id ? String(request.guide.carrier_user_id) : '',
      name: request.guide?.carrier_name ?? '',
      document: request.guide?.carrier_document_number ?? '',
      phone: request.guide?.carrier_phone ?? '',
      plate: request.guide?.vehicle_plate ?? '',
      company: request.guide?.carrier_company ?? '',
    });
  }, [mode, open, request]);

  if (!open) return null;

  const isPrepare = mode === 'prepare';
  const mutation = isPrepare ? prepare : receive;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const items: GuideItemQuantity[] = [];
    for (const item of request.items ?? []) {
      const quantity = quantities[item.id] ?? 0;
      if (quantity < Number(item.quantity) && !reasons[item.id]?.trim()) {
        toast.error(
          `Indica el motivo de la diferencia para ${item.origin_product?.name ?? `item #${item.id}`}.`,
        );
        return;
      }
      const serialValues =
        serials[item.id]
          ?.split(',')
          .map((value) => value.trim())
          .filter(Boolean) ?? [];
      if (item.origin_product?.tracking_type === 'serialized' && serialValues.length !== quantity) {
        toast.error(
          `Indica ${quantity} IMEI(s)/serial(es) para ${item.origin_product?.name ?? `item #${item.id}`}.`,
        );
        return;
      }
      items.push({
        request_item_id: item.id,
        ...(isPrepare ? { prepared_quantity: quantity } : { received_quantity: quantity }),
        difference_reason: reasons[item.id]?.trim() || undefined,
        ...(isPrepare
          ? {
              prepared_serial_units: serialValues.map((serial_number) => ({
                serial_type: 'imei' as const,
                serial_number,
              })),
            }
          : {
              received_serial_units: serialValues.map((serial_number) => ({
                serial_type: 'imei' as const,
                serial_number,
              })),
            }),
      });
    }

    try {
      await mutation.mutateAsync({
        id: request.id,
        items,
        ...(isPrepare
          ? {
              carrier_name: carrier.name.trim() || undefined,
              carrier_document_number: carrier.document.trim() || undefined,
              carrier_phone: carrier.phone.trim() || undefined,
              vehicle_plate: carrier.plate.trim() || undefined,
              carrier_company: carrier.company.trim() || undefined,
              carrier_user_id: Number(carrier.userId),
            }
          : {}),
      });
      toast.success(isPrepare ? 'Guía preparada.' : 'Recepción registrada y stock aplicado.');
      onOpenChange(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo actualizar la guía.');
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
    >
      <div className="bg-surface border-border max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border p-5">
        <h2 className="text-lg font-semibold">{isPrepare ? 'Preparar guía' : 'Recibir guía'}</h2>
        <p className="text-text-muted mt-1 text-sm">
          {isPrepare
            ? 'Confirma las cantidades que salen y registra diferencias.'
            : 'Confirma lo recibido. El stock se aplicará al cerrar la recepción.'}
        </p>
        <form onSubmit={submit} className="mt-5 space-y-4">
          {isPrepare && (
            <div className="border-border bg-bg/30 grid grid-cols-1 gap-3 rounded border p-3 sm:grid-cols-2">
              <div>
                <Label htmlFor="carrier-user">Usuario transportista</Label>
                <select
                  id="carrier-user"
                  value={carrier.userId}
                  onChange={(e) => setCarrier({ ...carrier, userId: e.target.value })}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                  required
                >
                  <option value="">Selecciona un usuario con rol Transportista...</option>
                  {carrierUsers.map((user) => (
                    <option key={user.id} value={user.id}>
                      {user.name} ({user.email})
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <Label htmlFor="carrier-name">Transportista</Label>
                <input
                  id="carrier-name"
                  value={carrier.name}
                  onChange={(e) => setCarrier({ ...carrier, name: e.target.value })}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                  required
                />
              </div>
              <div>
                <Label htmlFor="carrier-company">Empresa transportista</Label>
                <input
                  id="carrier-company"
                  value={carrier.company}
                  onChange={(e) => setCarrier({ ...carrier, company: e.target.value })}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <Label htmlFor="carrier-document">Documento</Label>
                <input
                  id="carrier-document"
                  value={carrier.document}
                  onChange={(e) => setCarrier({ ...carrier, document: e.target.value })}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <Label htmlFor="carrier-phone">Teléfono</Label>
                <input
                  id="carrier-phone"
                  value={carrier.phone}
                  onChange={(e) => setCarrier({ ...carrier, phone: e.target.value })}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <Label htmlFor="carrier-plate">Placa</Label>
                <input
                  id="carrier-plate"
                  value={carrier.plate}
                  onChange={(e) => setCarrier({ ...carrier, plate: e.target.value })}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                />
              </div>
            </div>
          )}
          {(request.items ?? []).map((item) => {
            const requested = Number(item.quantity);
            const quantity = quantities[item.id] ?? 0;
            return (
              <div key={item.id} className="border-border rounded border p-3">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <div className="font-medium">
                      {item.origin_product?.name ?? `Producto #${item.origin_product_id}`}
                    </div>
                    <div className="text-text-muted text-xs">Solicitado: {requested}</div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Label htmlFor={`guide-qty-${item.id}`} className="text-xs">
                      Cantidad
                    </Label>
                    <input
                      id={`guide-qty-${item.id}`}
                      type="number"
                      min={0}
                      max={requested}
                      step="0.0001"
                      value={quantity}
                      onChange={(event) =>
                        setQuantities((current) => ({
                          ...current,
                          [item.id]: Number(event.target.value),
                        }))
                      }
                      className="border-border-strong bg-surface w-24 rounded border px-2 py-1 text-sm"
                    />
                  </div>
                </div>
                {quantity < requested && (
                  <input
                    value={reasons[item.id] ?? ''}
                    onChange={(event) =>
                      setReasons((current) => ({ ...current, [item.id]: event.target.value }))
                    }
                    placeholder="Motivo de la diferencia"
                    maxLength={255}
                    required
                    className="border-border-strong bg-surface mt-3 w-full rounded border px-3 py-2 text-sm"
                  />
                )}
                {item.origin_product?.tracking_type === 'serialized' && (
                  <input
                    value={serials[item.id] ?? ''}
                    onChange={(event) =>
                      setSerials((current) => ({ ...current, [item.id]: event.target.value }))
                    }
                    placeholder="IMEIs/seriales separados por coma"
                    className="border-border-strong bg-surface mt-3 w-full rounded border px-3 py-2 text-sm"
                  />
                )}
              </div>
            );
          })}
          <div className="border-border flex justify-end gap-2 border-t pt-3">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              {isPrepare ? 'Confirmar preparación' : 'Confirmar recepción'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
